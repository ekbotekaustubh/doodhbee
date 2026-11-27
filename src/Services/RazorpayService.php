<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;

final class RazorpayService
{
    private Database $db;
    private QuotaService $quotaService;

    public function __construct(?Database $db = null, ?QuotaService $quotaService = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->quotaService = $quotaService ?? new QuotaService();
    }

    /**
     * Resolve Razorpay credentials from system_settings with .env fallback.
     *
     * @return array{enabled: bool, configured: bool, key_id: string, key_secret: string, webhook_secret: string, company_name: string}
     */
    public function getCredentials(): array
    {
        $sys = $this->quotaService->getSystemSettings();
        $keyId = trim((string)($sys['razorpay_key_id'] ?? ''));
        if ($keyId === '') {
            $keyId = trim((string)Env::get('RAZORPAY_KEY_ID', ''));
        }
        $keySecret = trim((string)($sys['razorpay_key_secret'] ?? ''));
        if ($keySecret === '') {
            $keySecret = trim((string)Env::get('RAZORPAY_KEY_SECRET', ''));
        }
        $webhookSecret = trim((string)($sys['razorpay_webhook_secret'] ?? ''));
        if ($webhookSecret === '') {
            $webhookSecret = trim((string)Env::get('RAZORPAY_WEBHOOK_SECRET', ''));
        }

        $enabled = !empty($sys['razorpay_enabled']);
        $configured = $enabled && $keyId !== '' && $keySecret !== '';

        return [
            'enabled' => $enabled,
            'configured' => $configured,
            'key_id' => $keyId,
            'key_secret' => $keySecret,
            'webhook_secret' => $webhookSecret,
            'company_name' => (string)($sys['company_legal_name'] ?? 'Shri Ram Open Source Technologies'),
        ];
    }

    public function isConfigured(): bool
    {
        return $this->getCredentials()['configured'];
    }

    /**
     * Public-safe configuration exposed to tenant checkout UIs (never exposes key_secret).
     *
     * @return array{enabled: bool, configured: bool, key_id: string}
     */
    public function getPublicConfig(): array
    {
        $creds = $this->getCredentials();
        return [
            'enabled' => $creds['enabled'],
            'configured' => $creds['configured'],
            'key_id' => $creds['configured'] ? $creds['key_id'] : '',
        ];
    }

    public function createSubscriptionOrder(int $tenantId, int $planId, string $billingCycle = 'monthly'): array
    {
        $creds = $this->getCredentials();
        if (!$creds['configured']) {
            throw new \RuntimeException('Razorpay payment gateway is not enabled or configured.');
        }

        $preview = $this->quotaService->previewPlanSelection($tenantId, $planId, $billingCycle);
        if (!$preview['allowed']) {
            throw new \InvalidArgumentException((string)$preview['reason']);
        }

        $plan = $preview['plan'];
        $cycle = (string)$preview['billing_cycle'];
        $totalAmount = round((float)$preview['amount'], 2);
        $amountPaise = (int)round($totalAmount * 100);

        if ($amountPaise < 100) {
            throw new \InvalidArgumentException('Payable amount must be at least ₹1.00 for Razorpay checkout.');
        }

        $tenant = $this->db->fetchOne(
            "SELECT id, business_name, owner_name, phone, email FROM tenants WHERE id = ?",
            [$tenantId]
        );
        if (!$tenant) {
            throw new \InvalidArgumentException('Tenant account not found.');
        }

        $receipt = sprintf('db_t%d_p%d_%d', $tenantId, $plan['id'], time());
        $orderPayload = [
            'amount' => $amountPaise,
            'currency' => 'INR',
            'receipt' => $receipt,
            'notes' => [
                'tenant_id' => (string)$tenantId,
                'plan_id' => (string)$plan['id'],
                'plan_code' => (string)$plan['code'],
                'billing_cycle' => $cycle,
                'action_type' => (string)$preview['action_type'],
            ],
        ];

        $rzpOrderId = $this->callRazorpayCreateOrder($creds['key_id'], $creds['key_secret'], $orderPayload);

        $this->db->execute(
            "INSERT INTO razorpay_orders (
                tenant_id, plan_id, billing_cycle, action_type, razorpay_order_id,
                base_amount, gst_percentage, gst_amount, total_amount, amount_paise, currency, status
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'INR', 'created')",
            [
                $tenantId,
                $plan['id'],
                $cycle,
                $preview['action_type'],
                $rzpOrderId,
                $preview['base_amount'],
                $preview['gst_percentage'],
                $preview['gst_amount'],
                $totalAmount,
                $amountPaise,
            ]
        );

        return [
            'order_id' => $rzpOrderId,
            'amount' => $totalAmount,
            'amount_paise' => $amountPaise,
            'base_amount' => (float)$preview['base_amount'],
            'gst_percentage' => (float)$preview['gst_percentage'],
            'gst_amount' => (float)$preview['gst_amount'],
            'currency' => 'INR',
            'key_id' => $creds['key_id'],
            'plan_id' => (int)$plan['id'],
            'plan_name' => (string)$plan['name'],
            'billing_cycle' => $cycle,
            'action_type' => (string)$preview['action_type'],
            'note' => (string)($preview['note'] ?? ''),
            'company_name' => $creds['company_name'],
            'description' => "DoodhBee {$plan['name']} Plan (" . ucfirst($cycle) . ")",
            'prefill' => [
                'name' => (string)$tenant['owner_name'],
                'email' => (string)($tenant['email'] ?? ''),
                'contact' => (string)$tenant['phone'],
            ],
        ];
    }

    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool
    {
        $creds = $this->getCredentials();
        if ($creds['key_secret'] === '' || $orderId === '' || $paymentId === '' || $signature === '') {
            return false;
        }
        $expected = hash_hmac('sha256', "{$orderId}|{$paymentId}", $creds['key_secret']);
        return hash_equals($expected, $signature);
    }

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $creds = $this->getCredentials();
        $secret = $creds['webhook_secret'];
        if ($secret === '' || $signature === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $signature);
    }

    public function verifyAndFulfillOrder(int $tenantId, string $orderId, string $paymentId, string $signature): array
    {
        $order = $this->db->fetchOne(
            "SELECT * FROM razorpay_orders WHERE razorpay_order_id = ? AND tenant_id = ?",
            [$orderId, $tenantId]
        );
        if (!$order) {
            throw new \InvalidArgumentException('Razorpay order not found for this account.');
        }

        if ($order['status'] === 'paid') {
            $invSvc = new SubscriptionInvoiceService($this->db);
            $existingInv = !empty($order['subscription_invoice_id'])
                ? $invSvc->getInvoiceById((int)$order['subscription_invoice_id'], $tenantId)
                : null;
            return [
                'order' => $order,
                'quota' => $this->quotaService->getTenantQuota($tenantId),
                'invoice' => $existingInv,
                'already_processed' => true,
            ];
        }

        if (!$this->verifyPaymentSignature($orderId, $paymentId, $signature)) {
            $this->db->execute(
                "UPDATE razorpay_orders SET status = 'failed', failure_reason = 'Invalid Razorpay signature' WHERE id = ? AND status = 'created'",
                [(int)$order['id']]
            );
            throw new \InvalidArgumentException('Razorpay payment signature verification failed.');
        }

        return $this->fulfillPaidOrder($order, $paymentId, $signature);
    }

    public function handleWebhook(string $rawBody, string $signature): array
    {
        if (!$this->verifyWebhookSignature($rawBody, $signature)) {
            throw new \InvalidArgumentException('Invalid Razorpay webhook signature.');
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid webhook JSON payload.');
        }

        $event = (string)($payload['event'] ?? '');
        if (!in_array($event, ['payment.captured', 'order.paid'], true)) {
            return ['handled' => false, 'event' => $event, 'reason' => 'Event ignored'];
        }

        $paymentEntity = $payload['payload']['payment']['entity'] ?? [];
        $orderEntity = $payload['payload']['order']['entity'] ?? [];
        $orderId = (string)($paymentEntity['order_id'] ?? $orderEntity['id'] ?? '');
        $paymentId = (string)($paymentEntity['id'] ?? '');

        if ($orderId === '' || $paymentId === '') {
            return ['handled' => false, 'event' => $event, 'reason' => 'Missing order_id or payment_id'];
        }

        $order = $this->db->fetchOne("SELECT * FROM razorpay_orders WHERE razorpay_order_id = ?", [$orderId]);
        if (!$order) {
            return ['handled' => false, 'event' => $event, 'reason' => 'Order not found'];
        }

        if ($order['status'] === 'paid') {
            return ['handled' => true, 'event' => $event, 'already_processed' => true, 'order_id' => $orderId];
        }

        $result = $this->fulfillPaidOrder($order, $paymentId, null);
        return [
            'handled' => true,
            'event' => $event,
            'already_processed' => false,
            'order_id' => $orderId,
            'invoice_id' => $result['invoice']['id'] ?? null,
        ];
    }

    private function fulfillPaidOrder(array $order, string $paymentId, ?string $signature): array
    {
        $tenantId = (int)$order['tenant_id'];
        $planId = (int)$order['plan_id'];
        $cycle = (string)$order['billing_cycle'];

        $this->db->execute(
            "UPDATE razorpay_orders
             SET status = 'paid', razorpay_payment_id = ?, razorpay_signature = ?, paid_at = NOW(), failure_reason = NULL
             WHERE id = ?",
            [$paymentId, $signature, (int)$order['id']]
        );

        $freshQuota = $this->quotaService->applyPlanSubscription($tenantId, $planId, $cycle, [
            'payment_method' => 'razorpay',
            'utr_number' => $paymentId,
        ]);

        $invoice = $this->quotaService->getLastGeneratedInvoice();
        if ($invoice && !empty($invoice['id'])) {
            $this->db->execute(
                "UPDATE razorpay_orders SET subscription_invoice_id = ? WHERE id = ?",
                [(int)$invoice['id'], (int)$order['id']]
            );
        }

        $updatedOrder = $this->db->fetchOne("SELECT * FROM razorpay_orders WHERE id = ?", [(int)$order['id']]) ?? $order;

        return [
            'order' => $updatedOrder,
            'quota' => $freshQuota,
            'invoice' => $invoice,
            'already_processed' => false,
        ];
    }

    private function callRazorpayCreateOrder(string $keyId, string $keySecret, array $payload): string
    {
        if (defined('DOODHBEE_TEST_RUNNER') && DOODHBEE_TEST_RUNNER === true) {
            return 'order_test_' . bin2hex(random_bytes(6));
        }

        $ch = curl_init('https://api.razorpay.com/v1/orders');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_USERPWD => "{$keyId}:{$keySecret}",
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \RuntimeException("Razorpay API connection error: {$curlError}");
        }

        $data = json_decode((string)$response, true);
        if ($httpCode >= 200 && $httpCode < 300 && !empty($data['id'])) {
            return (string)$data['id'];
        }

        $errMsg = $data['error']['description'] ?? $data['error']['reason'] ?? "Razorpay API returned HTTP {$httpCode}";
        throw new \RuntimeException("Failed to create Razorpay order: {$errMsg}");
    }
}

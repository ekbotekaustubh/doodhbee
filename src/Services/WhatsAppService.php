<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;

final class WhatsAppService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Sanitize phone number to international format without + or spaces (e.g. 919876543210).
     */
    public static function sanitizePhone(mixed $phone): string
    {
        $digits = preg_replace('/[^\d]/', '', (string)($phone ?? '')) ?? '';

        // Handle Indian 10-digit numbers
        if (strlen($digits) === 10) {
            return '91' . $digits;
        }

        // Handle 0-prefixed 10-digit numbers (e.g. 09876543210 -> 919876543210)
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return '91' . substr($digits, 1);
        }

        return $digits;
    }

    /**
     * Generate a cryptographic verification token for public customer bill access.
     */
    public static function generateInvoiceToken(int $invoiceId, int $tenantId): string
    {
        $secret = (string)Env::get('APP_KEY', 'doodhbee_saas_secure_bill_token_key_2026');
        return substr(hash_hmac('sha256', "doodhbee_bill_{$invoiceId}_{$tenantId}", $secret), 0, 32);
    }

    /**
     * Verify whether a token matches the invoice and tenant.
     */
    public static function verifyInvoiceToken(int $invoiceId, int $tenantId, string $token): bool
    {
        if (empty($token) || strlen($token) !== 32) {
            return false;
        }
        return hash_equals(self::generateInvoiceToken($invoiceId, $tenantId), $token);
    }

    /**
     * Build the public signed PDF URL for customer direct access without login.
     */
    public static function getPublicPdfUrl(int $invoiceId, int $tenantId): string
    {
        $token = self::generateInvoiceToken($invoiceId, $tenantId);
        $baseUrl = rtrim((string)Env::get('APP_URL', 'http://localhost/doodhbee'), '/');

        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'] ?? 'http';
        $host = $parsed['host'] ?? 'localhost';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $origin = "{$scheme}://{$host}{$port}";

        $path = url("/bill/{$invoiceId}/pdf");
        return "{$origin}{$path}?token={$token}";
    }

    /**
     * Format a complete, professional WhatsApp invoice message text.
     */
    public function formatInvoiceMessage(array $invoice, array $tenant, array $settings): string
    {
        $monthName = date('F Y', mktime(0, 0, 0, (int)$invoice['billing_month'], 10, (int)$invoice['billing_year']));
        $finalAmt = number_format((float)$invoice['final_amount'], 2);
        $totalUnits = number_format((float)$invoice['total_units'], 1);
        $businessName = !empty($tenant['business_name']) ? $tenant['business_name'] : 'DoodhBee Dairy';
        $customerName = !empty($invoice['customer_name']) ? $invoice['customer_name'] : 'Valued Customer';
        $statusLabel = match ($invoice['payment_status'] ?? 'unpaid') {
            'paid' => 'Paid in Full ✓',
            'partial' => 'Partially Paid',
            default => 'Payment Pending ⏳',
        };

        $lines = [
            "🥛 *{$businessName}*",
            "━━━━━━━━━━━━━━━━━━━━",
            "Namaste *{$customerName}*,",
            "",
            "Your milk delivery bill for *{$monthName}* is ready:",
            "",
            "• Total Delivered: *{$totalUnits} L*",
            "• Total Amount: *₹{$finalAmt}*",
            "• Bill Status: *{$statusLabel}*",
        ];

        // Previous balance note if any
        if (!empty($invoice['previous_balance']) && (float)$invoice['previous_balance'] > 0) {
            $prevBal = number_format((float)$invoice['previous_balance'], 2);
            $lines[] = "• Previous Outstanding: *₹{$prevBal}*";
        }

        // UPI Payment details if available
        if (!empty($settings['upi_id'])) {
            $upiId = $settings['upi_id'];
            $upiLink = "upi://pay?pa={$upiId}&pn=" . urlencode($businessName) . "&am={$finalAmt}&cu=INR&tn=" . urlencode("Milk Bill #{$invoice['id']}");
            $lines[] = "";
            $lines[] = "💳 *Instant UPI Payment:*";
            $lines[] = "UPI ID: *{$upiId}*";
            $lines[] = "Tap to Pay: {$upiLink}";
        }

        // Public secure PDF download link for customer one-tap access without login
        $pdfUrl = self::getPublicPdfUrl((int)$invoice['id'], (int)($invoice['tenant_id'] ?? 1));
        $lines[] = "";
        $lines[] = "📄 *Download Full Bill PDF:*";
        $lines[] = $pdfUrl;

        $lines[] = "";
        $lines[] = "Thank you for choosing pure & fresh milk daily!";
        $lines[] = "━━━━━━━━━━━━━━━━━━━━";

        return implode("\n", $lines);
    }

    /**
     * Generate the direct wa.me / WhatsApp Web Click-to-Chat URL.
     */
    public function getDirectWhatsAppUrl(string $phone, string $messageText): string
    {
        $cleanPhone = self::sanitizePhone($phone);
        return "https://api.whatsapp.com/send?phone=" . $cleanPhone . "&text=" . urlencode($messageText);
    }

    /**
     * Build invoice share payload for an invoice ID.
     */
    public function getInvoiceSharePayload(int $tenantId, int $invoiceId): ?array
    {
        $invoice = $this->db->fetchOne(
            "SELECT i.*, c.name as customer_name, c.phone as customer_phone, r.name as route_name
             FROM invoices i
             JOIN customers c ON c.id = i.customer_id
             JOIN routes r ON r.id = c.route_id
             WHERE i.id = ? AND i.tenant_id = ?",
            [$invoiceId, $tenantId]
        );

        if (!$invoice) {
            return null;
        }

        $tenant = $this->db->fetchOne("SELECT * FROM tenants WHERE id = ?", [$tenantId]) ?? [];
        $settings = $this->db->fetchOne("SELECT * FROM tenant_settings WHERE tenant_id = ?", [$tenantId]) ?? [];

        $messageText = $this->formatInvoiceMessage($invoice, $tenant, $settings);
        $cleanPhone = self::sanitizePhone((string)$invoice['customer_phone']);
        $whatsappUrl = $this->getDirectWhatsAppUrl($cleanPhone, $messageText);

        return [
            'invoice_id' => $invoice['id'],
            'customer_id' => $invoice['customer_id'],
            'customer_name' => $invoice['customer_name'],
            'phone' => $cleanPhone,
            'amount' => (float)$invoice['final_amount'],
            'units' => (float)$invoice['total_units'],
            'payment_status' => $invoice['payment_status'],
            'message_text' => $messageText,
            'whatsapp_url' => $whatsappUrl,
        ];
    }

    /**
     * Enqueue a batch of invoices into the whatsapp_messages queue.
     *
     * @param int $tenantId
     * @param int[] $invoiceIds
     * @return array{enqueued_count: int, skipped_count: int, message_ids: int[]}
     */
    public function enqueueInvoices(int $tenantId, array $invoiceIds): array
    {
        if (empty($invoiceIds)) {
            return ['enqueued_count' => 0, 'skipped_count' => 0, 'message_ids' => []];
        }

        $tenant = $this->db->fetchOne("SELECT * FROM tenants WHERE id = ?", [$tenantId]) ?? [];
        $settings = $this->db->fetchOne("SELECT * FROM tenant_settings WHERE tenant_id = ?", [$tenantId]) ?? [];
        $provider = !empty($settings['whatsapp_provider']) ? $settings['whatsapp_provider'] : 'browser';

        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
        $params = array_merge([$tenantId], $invoiceIds);

        $invoices = $this->db->fetchAll(
            "SELECT i.*, c.name as customer_name, c.phone as customer_phone
             FROM invoices i
             JOIN customers c ON c.id = i.customer_id
             WHERE i.tenant_id = ? AND i.id IN ({$placeholders})",
            $params
        );

        $enqueuedCount = 0;
        $skippedCount = 0;
        $enqueuedIds = [];

        foreach ($invoices as $inv) {
            $phone = self::sanitizePhone((string)$inv['customer_phone']);
            if (empty($phone)) {
                $skippedCount++;
                continue;
            }

            // Check if there is already a pending message for this invoice to prevent duplicates
            $existing = $this->db->fetchOne(
                "SELECT id FROM whatsapp_messages 
                 WHERE tenant_id = ? AND invoice_id = ? AND status IN ('pending', 'processing')",
                [$tenantId, (int)$inv['id']]
            );

            if ($existing) {
                $skippedCount++;
                continue;
            }

            $messageText = $this->formatInvoiceMessage($inv, $tenant, $settings);

            $this->db->execute(
                "INSERT INTO whatsapp_messages (tenant_id, invoice_id, customer_id, recipient_phone, recipient_name, message_body, status, attempts, provider, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending', 0, ?, ?)",
                [
                    $tenantId,
                    (int)$inv['id'],
                    (int)$inv['customer_id'],
                    $phone,
                    (string)$inv['customer_name'],
                    $messageText,
                    $provider,
                    date('Y-m-d H:i:s'),
                ]
            );

            $id = $this->db->lastInsertId();
            $enqueuedCount++;
            $enqueuedIds[] = (int)$id;
        }

        return [
            'enqueued_count' => $enqueuedCount,
            'skipped_count' => $skippedCount,
            'message_ids' => $enqueuedIds,
        ];
    }

    /**
     * Dispatch a single queued message record immediately through the provider pipeline.
     * Shared by both the background cron loop and real-time single-send actions.
     *
     * @param int $messageId
     * @param string|null $overrideProvider Optional provider override (e.g. 'meta_cloud')
     * @return array{success: bool, message_id?: string, status?: string, error?: string, provider?: string}
     */
    public function processSingleMessage(int $messageId, ?string $overrideProvider = null): array
    {
        $msg = $this->db->fetchOne(
            "SELECT wm.*, ts.whatsapp_provider, ts.whatsapp_api_token, ts.whatsapp_phone_number_id
             FROM whatsapp_messages wm
             LEFT JOIN tenant_settings ts ON ts.tenant_id = wm.tenant_id
             WHERE wm.id = ?",
            [$messageId]
        );

        if (!$msg) {
            return ['success' => false, 'error' => 'WhatsApp message record not found.'];
        }

        $provider = $overrideProvider ?: ($msg['whatsapp_provider'] ?: ($msg['provider'] ?: 'browser'));

        // Mark as processing and increment attempts
        $this->db->execute(
            "UPDATE whatsapp_messages SET status = 'processing', provider = ?, attempts = attempts + 1 WHERE id = ?",
            [$provider, $messageId]
        );

        // Update local array for dispatch
        $msg['provider'] = $provider;

        try {
            $sendResult = $this->dispatchToProvider($msg, $provider);

            if ($sendResult['success']) {
                $this->db->execute(
                    "UPDATE whatsapp_messages SET status = 'sent', provider_message_id = ?, sent_at = ?, error_message = NULL WHERE id = ?",
                    [$sendResult['message_id'] ?? null, date('Y-m-d H:i:s'), $messageId]
                );
                return [
                    'success' => true,
                    'message_id' => $sendResult['message_id'] ?? null,
                    'status' => 'sent',
                    'provider' => $provider,
                ];
            } else {
                $newStatus = ((int)$msg['attempts'] + 1 >= (int)$msg['max_attempts']) ? 'failed' : 'pending';
                $this->db->execute(
                    "UPDATE whatsapp_messages SET status = ?, error_message = ? WHERE id = ?",
                    [$newStatus, $sendResult['error'] ?? 'Delivery failed.', $messageId]
                );
                return [
                    'success' => false,
                    'error' => $sendResult['error'] ?? 'Delivery failed.',
                    'status' => $newStatus,
                    'provider' => $provider,
                ];
            }
        } catch (\Throwable $e) {
            $newStatus = ((int)$msg['attempts'] + 1 >= (int)$msg['max_attempts']) ? 'failed' : 'pending';
            $this->db->execute(
                "UPDATE whatsapp_messages SET status = ?, error_message = ? WHERE id = ?",
                [$newStatus, $e->getMessage(), $messageId]
            );
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status' => $newStatus,
                'provider' => $provider,
            ];
        }
    }

    /**
     * Enqueue and immediately send a single invoice using the exact same cron pipeline.
     *
     * @param int $tenantId
     * @param int $invoiceId
     * @param string|null $forceProvider Force 'meta_cloud', 'browser', etc.
     * @return array{success: bool, message_id?: string, status?: string, error?: string, provider?: string, recipient_phone?: string}
     */
    public function sendSingleInvoiceNow(int $tenantId, int $invoiceId, ?string $forceProvider = null): array
    {
        // 1. Enqueue using existing enqueueInvoices()
        $enqueueRes = $this->enqueueInvoices($tenantId, [$invoiceId]);

        $messageId = $enqueueRes['message_ids'][0] ?? null;
        if (!$messageId) {
            // Check if there is an existing message for this invoice
            $existing = $this->db->fetchOne(
                "SELECT id, status, attempts, max_attempts FROM whatsapp_messages 
                 WHERE tenant_id = ? AND invoice_id = ? 
                 ORDER BY id DESC LIMIT 1",
                [$tenantId, $invoiceId]
            );

            if ($existing) {
                $messageId = (int)$existing['id'];
                // Reset failed message so it can be dispatched again immediately
                if ($existing['status'] === 'failed' || (int)$existing['attempts'] >= (int)$existing['max_attempts']) {
                    $this->db->execute(
                        "UPDATE whatsapp_messages SET status = 'pending', attempts = 0, error_message = NULL WHERE id = ?",
                        [$messageId]
                    );
                }
            }
        }

        if (!$messageId) {
            return [
                'success' => false,
                'error' => 'Unable to queue invoice. Please check that the customer has a valid phone number.',
            ];
        }

        // 2. Immediately process through the exact same engine
        return $this->processSingleMessage($messageId, $forceProvider);
    }

    /**
     * Get the latest WhatsApp message status for a specific invoice.
     *
     * @param int $tenantId
     * @param int $invoiceId
     * @return array|null
     */
    public function getInvoiceMessageStatus(int $tenantId, int $invoiceId): ?array
    {
        return $this->db->fetchOne(
            "SELECT id, status, provider, provider_message_id, sent_at, error_message, created_at, attempts
             FROM whatsapp_messages
             WHERE tenant_id = ? AND invoice_id = ?
             ORDER BY id DESC LIMIT 1",
            [$tenantId, $invoiceId]
        );
    }

    /**
     * Process pending messages in the queue (invoked by Cron or CLI).
     *
     * @param int $limit Maximum messages to process per run
     * @param int|null $tenantId Optional tenant filter
     * @param bool $dryRun If true, does not send or change status
     * @return array{processed: int, sent: int, failed: int, details: array}
     */
    public function processQueue(int $limit = 50, ?int $tenantId = null, bool $dryRun = false): array
    {
        $where = "wm.status = 'pending' AND wm.attempts < wm.max_attempts";
        $params = [];

        if ($tenantId !== null) {
            $where .= " AND wm.tenant_id = ?";
            $params[] = $tenantId;
        }

        $limit = max(1, min(500, $limit));

        $messages = $this->db->fetchAll(
            "SELECT wm.*, ts.whatsapp_provider, ts.whatsapp_api_token, ts.whatsapp_phone_number_id
             FROM whatsapp_messages wm
             LEFT JOIN tenant_settings ts ON ts.tenant_id = wm.tenant_id
             WHERE {$where}
             ORDER BY wm.id ASC
             LIMIT {$limit}",
            $params
        );

        $sentCount = 0;
        $failedCount = 0;
        $details = [];

        foreach ($messages as $msg) {
            $msgId = (int)$msg['id'];
            $provider = $msg['whatsapp_provider'] ?: $msg['provider'] ?: 'browser';

            if ($dryRun) {
                $details[] = [
                    'id' => $msgId,
                    'phone' => $msg['recipient_phone'],
                    'provider' => $provider,
                    'action' => 'dry_run_simulated',
                ];
                continue;
            }

            $res = $this->processSingleMessage($msgId);

            if ($res['success']) {
                $sentCount++;
                $details[] = ['id' => $msgId, 'status' => 'sent', 'provider' => $res['provider'] ?? $provider];
            } else {
                $failedCount++;
                $details[] = [
                    'id' => $msgId,
                    'status' => $res['status'] ?? 'failed',
                    'provider' => $res['provider'] ?? $provider,
                    'error' => $res['error'] ?? 'Delivery failed.',
                ];
            }

            // Throttle between messages to protect gateway limits
            if ($provider !== 'browser') {
                usleep(500000); // 0.5s pause
            }
        }

        return [
            'processed' => count($messages),
            'sent' => $sentCount,
            'failed' => $failedCount,
            'details' => $details,
        ];
    }

    /**
     * Dispatch a message via the selected provider.
     */
    private function dispatchToProvider(array $message, string $provider): array
    {
        switch ($provider) {
            case 'meta_cloud':
                return $this->sendViaMetaCloud($message);

            case 'custom_webhook':
                return $this->sendViaWebhook($message);

            case 'browser':
            default:
                // In browser/local mode, background queue simulation marks message as queued/sent
                // with audit trail.
                return [
                    'success' => true,
                    'message_id' => 'BROWSER-' . uniqid(),
                ];
        }
    }

    /**
     * Send via Meta WhatsApp Cloud API (Graph API).
     */
    private function sendViaMetaCloud(array $message): array
    {
        $token = $message['whatsapp_api_token'] ?? Env::get('WHATSAPP_CLOUD_API_TOKEN');
        $phoneId = $message['whatsapp_phone_number_id'] ?? Env::get('WHATSAPP_PHONE_NUMBER_ID');

        if (empty($token) || empty($phoneId)) {
            return [
                'success' => false,
                'error' => 'Meta Cloud API credentials missing (Token or Phone Number ID). Please enter your Meta keys in Settings -> WhatsApp Gateway, or use "WhatsApp Web" to send manually.',
            ];
        }

        $pdfUrl = self::getPublicPdfUrl((int)$message['invoice_id'], (int)$message['tenant_id']);
        $custName = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($message['recipient_name'] ?? 'Bill'));
        $monthName = date('M_Y');
        $filename = "Milk_Bill_{$custName}_{$monthName}.pdf";

        $url = "https://graph.facebook.com/v21.0/{$phoneId}/messages";
        // Deliver directly as a PDF document attachment with invoice caption
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $message['recipient_phone'],
            'type' => 'document',
            'document' => [
                'link' => $pdfUrl,
                'caption' => mb_strimwidth($message['message_body'], 0, 1024, '...'),
                'filename' => $filename,
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'cURL error: ' . $curlError];
        }

        $data = json_decode((string)$response, true);
        if ($httpCode >= 200 && $httpCode < 300 && !empty($data['messages'][0]['id'])) {
            return [
                'success' => true,
                'message_id' => $data['messages'][0]['id'],
            ];
        }

        $errMsg = $data['error']['message'] ?? "Meta API returned HTTP {$httpCode}";
        return ['success' => false, 'error' => $errMsg];
    }

    /**
     * Send via custom HTTP webhook gateway.
     */
    private function sendViaWebhook(array $message): array
    {
        $webhookUrl = Env::get('WHATSAPP_WEBHOOK_URL');
        if (empty($webhookUrl)) {
            return ['success' => false, 'error' => 'WHATSAPP_WEBHOOK_URL not configured.'];
        }

        $payload = [
            'phone' => $message['recipient_phone'],
            'recipient_name' => $message['recipient_name'],
            'message' => $message['message_body'],
            'invoice_id' => $message['invoice_id'],
        ];

        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'Webhook error: ' . $curlError];
        }

        return ($httpCode >= 200 && $httpCode < 300)
            ? ['success' => true, 'message_id' => 'WH-' . uniqid()]
            : ['success' => false, 'error' => "Webhook responded with HTTP {$httpCode}: {$response}"];
    }

    /**
     * Fetch WhatsApp Queue Statistics for a tenant.
     */
    public function getQueueStats(int $tenantId): array
    {
        $counts = $this->db->fetchAll(
            "SELECT status, COUNT(*) as cnt FROM whatsapp_messages WHERE tenant_id = ? GROUP BY status",
            [$tenantId]
        );

        $stats = [
            'pending' => 0,
            'processing' => 0,
            'sent' => 0,
            'failed' => 0,
            'total' => 0,
        ];

        foreach ($counts as $c) {
            $status = $c['status'];
            $cnt = (int)$c['cnt'];
            if (isset($stats[$status])) {
                $stats[$status] = $cnt;
            }
            $stats['total'] += $cnt;
        }

        return $stats;
    }

    /**
     * Retry a failed message.
     */
    public function retryMessage(int $messageId, int $tenantId): bool
    {
        return $this->db->execute(
            "UPDATE whatsapp_messages SET status = 'pending', attempts = 0, error_message = NULL WHERE id = ? AND tenant_id = ?",
            [$messageId, $tenantId]
        ) > 0;
    }

    /**
     * Format & dispatch (or build direct link for) a SaaS Subscription GST Invoice via WhatsApp.
     *
     * @return array{status: string, whatsapp_url: string, error?: string}
     */
    public function sendSubscriptionInvoiceNotification(array $subInvoice, string $pdfUrl): array
    {
        $phone = self::sanitizePhone($subInvoice['buyer_phone'] ?? '');
        $sellerName = (string)($subInvoice['seller_name'] ?? 'Shri Ram Open Source Technologies');
        $sellerGstin = (string)($subInvoice['seller_gstin'] ?? '27AARPE1865G1ZJ');
        $buyerName = (string)($subInvoice['buyer_business_name'] ?? 'Dairy Partner');
        $invNo = (string)($subInvoice['invoice_number'] ?? '');
        $planName = (string)($subInvoice['plan_name'] ?? 'Paid');
        $cycle = ucfirst((string)($subInvoice['billing_cycle'] ?? 'monthly'));
        $baseAmt = number_format((float)($subInvoice['base_amount'] ?? 0), 2);
        $gstPct = number_format((float)($subInvoice['gst_percentage'] ?? 18), 0);
        $gstAmt = number_format((float)($subInvoice['gst_amount'] ?? 0), 2);
        $totalAmt = number_format((float)($subInvoice['total_amount'] ?? 0), 2);
        $periodStart = (string)($subInvoice['period_start'] ?? '');
        $periodEnd = (string)($subInvoice['period_end'] ?? '');
        $limit = (int)($subInvoice['customer_limit'] ?? 100);

        $lines = [
            "🧾 *{$sellerName}* (GST Tax Invoice)",
            "GSTIN: *{$sellerGstin}*",
            "━━━━━━━━━━━━━━━━━━━━",
            "Namaste *{$buyerName}*,",
            "",
            "Thank you! Your DoodhBee SaaS subscription invoice is ready:",
            "",
            "• Invoice No: *{$invNo}*",
            "• Plan: *{$planName} ({$cycle})* — up to {$limit} customers",
            "• Validity: *{$periodStart}* to *{$periodEnd}*",
            "• Base Amount: ₹{$baseAmt}",
            "• GST ({$gstPct}%): ₹{$gstAmt}",
            "• *Total Paid: ₹{$totalAmt}*",
            "",
            "📄 *Download GST Tax Invoice PDF:*",
            $pdfUrl,
            "",
            "All rights reserved to {$sellerName}.",
        ];

        $text = implode("\n", $lines);
        $waUrl = $this->getDirectWhatsAppUrl($phone, $text);

        if ($phone === '') {
            return ['status' => 'failed', 'whatsapp_url' => $waUrl, 'error' => 'Tenant phone number missing.'];
        }

        if (defined('DOODHBEE_TEST_RUNNER') && DOODHBEE_TEST_RUNNER === true) {
            return ['status' => 'queued_link', 'whatsapp_url' => $waUrl];
        }

        $token = (string)Env::get('WHATSAPP_CLOUD_API_TOKEN', '');
        $phoneId = (string)Env::get('WHATSAPP_PHONE_NUMBER_ID', '');
        if ($token !== '' && $phoneId !== '') {
            $url = "https://graph.facebook.com/v21.0/{$phoneId}/messages";
            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $phone,
                'type' => 'document',
                'document' => [
                    'link' => $pdfUrl,
                    'caption' => mb_strimwidth($text, 0, 1024, '...'),
                    'filename' => "{$invNo}.pdf",
                ],
            ];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if (!$curlErr && $httpCode >= 200 && $httpCode < 300) {
                return ['status' => 'sent', 'whatsapp_url' => $waUrl];
            }
            return [
                'status' => 'queued_link',
                'whatsapp_url' => $waUrl,
                'error' => $curlErr ?: "Meta API HTTP {$httpCode}: {$response}",
            ];
        }

        $webhookUrl = (string)Env::get('WHATSAPP_WEBHOOK_URL', '');
        if ($webhookUrl !== '') {
            $ch = curl_init($webhookUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'phone' => $phone,
                    'recipient_name' => $buyerName,
                    'message' => $text,
                    'pdf_url' => $pdfUrl,
                    'subscription_invoice_id' => $subInvoice['id'] ?? null,
                ]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_exec($ch);
            curl_close($ch);
            if (!$curlErr && $httpCode >= 200 && $httpCode < 300) {
                return ['status' => 'sent', 'whatsapp_url' => $waUrl];
            }
        }

        return ['status' => 'queued_link', 'whatsapp_url' => $waUrl];
    }
}

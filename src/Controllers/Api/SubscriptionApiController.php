<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Services\QuotaService;
use App\Services\RazorpayService;
use App\Services\SubscriptionInvoiceService;

final class SubscriptionApiController extends ApiBaseController
{
    private QuotaService $quotaService;
    private SubscriptionInvoiceService $invoiceService;
    private RazorpayService $razorpayService;

    public function __construct()
    {
        parent::__construct();
        Auth::requireApiTenantAdmin();
        $this->quotaService = new QuotaService();
        $this->invoiceService = new SubscriptionInvoiceService();
        $this->razorpayService = new RazorpayService();
    }

    public function getSubscription(Request $request): void
    {
        $tenantId = $this->tenantId();
        $quota = $this->quotaService->getTenantQuota($tenantId);
        $settings = $this->quotaService->getPlatformSettings();

        $plans = $this->quotaService->getPlans(true);
        foreach ($plans as &$plan) {
            if (!empty($plan['is_free'])) {
                $plan['previews'] = null;
                continue;
            }
            $plan['previews'] = [
                'monthly' => $this->quotaService->previewPlanSelection($tenantId, (int)$plan['id'], 'monthly'),
                'yearly' => $this->quotaService->previewPlanSelection($tenantId, (int)$plan['id'], 'yearly'),
            ];
        }
        unset($plan);

        $invoices = [];
        try {
            $invoices = $this->invoiceService->getTenantInvoices($tenantId);
        } catch (\Throwable $e) {
            error_log('Subscription invoices fetch failed: ' . $e->getMessage());
        }

        $this->jsonSuccess([
            'quota' => $quota,
            'plans' => $plans,
            'pricing' => [
                'free_customer_limit' => $settings['free_customer_limit'],
                'paid_tier_unit_size' => $settings['paid_tier_unit_size'],
                'paid_tier_unit_price' => $settings['paid_tier_unit_price'],
                'paid_tier_monthly_price' => $settings['paid_tier_monthly_price'],
                'paid_tier_yearly_price' => $settings['paid_tier_yearly_price'],
                'subscription_grace_days' => $settings['subscription_grace_days'],
                'subscription_gst_enabled' => !empty($settings['subscription_gst_enabled']),
                'subscription_gst_percentage' => (float)($settings['subscription_gst_percentage'] ?? 18.0),
                'company_legal_name' => $settings['company_legal_name'] ?? 'Shri Ram Open Source Technologies',
                'company_gstin' => $settings['company_gstin'] ?? '27AARPE1865G1ZJ',
                'company_sac_code' => $settings['company_sac_code'] ?? '998314',
                'currency_symbol' => $settings['currency_symbol'],
            ],
            'invoices' => $invoices,
            'razorpay' => $this->razorpayService->getPublicConfig(),
        ]);
    }

    public function upgradeQuota(Request $request): void
    {
        $tenantId = $this->tenantId();
        $planId = (int)$request->input('plan_id', 0);
        $planCode = trim((string)$request->input('plan_code', ''));
        if ($planId <= 0 && $planCode !== '') {
            $foundPlan = $this->quotaService->getPlanByCode($planCode);
            $planId = $foundPlan ? (int)$foundPlan['id'] : 0;
        }

        $blocks = max(1, (int)$request->input('blocks', 1));
        $billingCycle = trim((string)$request->input('billing_cycle', 'monthly')) ?: 'monthly';
        $requestType = trim((string)$request->input('request_type', 'add_blocks')) ?: 'add_blocks';

        $planSuccess = null;
        $blockResult = null;
        try {
            if ($planId > 0) {
                $preview = $this->quotaService->previewPlanSelection($tenantId, $planId, $billingCycle);
                $freshQuota = $this->quotaService->applyPlanSubscription($tenantId, $planId, $billingCycle, [
                    'payment_method' => (string)$request->input('payment_method', 'upi'),
                    'utr_number' => $request->input('utr_number') ?: null,
                ]);
                $cycleLabel = ucfirst($this->quotaService->normalizeBillingCycle($billingCycle));
                $planName = (string)($preview['plan']['name'] ?? 'Plan');
                $planSuccess = [
                    'data' => [
                        'request_type' => $preview['action_type'],
                        'billing_cycle' => $preview['billing_cycle'],
                        'plan_id' => $planId,
                        'plan_name' => $planName,
                        'base_amount' => $preview['base_amount'],
                        'gst_amount' => $preview['gst_amount'],
                        'amount_charged' => $preview['amount'],
                        'quota' => $freshQuota,
                        'invoice' => $this->quotaService->getLastGeneratedInvoice(),
                    ],
                    'message' => "{$planName} ({$cycleLabel}) activated! Tax invoice generated. Valid until {$freshQuota['valid_until']}.",
                ];
            } else {
                if ($requestType === 'renew') {
                    $freshQuota = $this->quotaService->renewSubscription($tenantId, $billingCycle);
                    $effectiveBlocks = (int)($freshQuota['purchased_blocks'] ?? 0);
                    $addedCapacity = 0;
                } else {
                    $freshQuota = $this->quotaService->upgradeCapacity($tenantId, $blocks, $billingCycle);
                    $effectiveBlocks = $blocks;
                    $settings = $this->quotaService->getPlatformSettings();
                    $addedCapacity = $blocks * (int)$settings['paid_tier_unit_size'];
                }
                $unitPrice = $this->quotaService->getBlockPrice($billingCycle);
                $totalCost = round($effectiveBlocks * $unitPrice, 2);
                $cycleLabel = ucfirst($this->quotaService->normalizeBillingCycle($billingCycle));
                $blockResult = [
                    'data' => [
                        'request_type' => $requestType,
                        'billing_cycle' => $this->quotaService->normalizeBillingCycle($billingCycle),
                        'added_blocks' => $requestType === 'renew' ? 0 : $effectiveBlocks,
                        'added_capacity' => $addedCapacity,
                        'amount_charged' => $totalCost,
                        'quota' => $freshQuota,
                    ],
                    'message' => $requestType === 'renew'
                        ? "Plan renewed ({$cycleLabel}) successfully! Valid until {$freshQuota['valid_until']}."
                        : "Plan upgraded ({$cycleLabel}) successfully! +{$effectiveBlocks} block ({$addedCapacity} slots) added.",
                ];
            }
        } catch (\InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422, 'VALIDATION_ERROR');
            return;
        }

        if ($planSuccess !== null) {
            $this->jsonSuccess($planSuccess['data'], $planSuccess['message']);
            return;
        }

        $this->jsonSuccess($blockResult['data'], $blockResult['message']);
    }

    public function getInvoices(Request $request): void
    {
        $tenantId = $this->tenantId();
        $invoices = [];
        try {
            $invoices = $this->invoiceService->getTenantInvoices($tenantId);
        } catch (\Throwable $e) {
            error_log('Subscription invoices fetch failed: ' . $e->getMessage());
        }
        foreach ($invoices as &$inv) {
            $inv['public_pdf_url'] = SubscriptionInvoiceService::getPublicPdfUrl((int)$inv['id'], $tenantId);
        }
        $this->jsonSuccess($invoices);
    }

    public function getInvoice(Request $request, array $params): void
    {
        $tenantId = $this->tenantId();
        $id = (int)($params['id'] ?? 0);
        $inv = $this->invoiceService->getInvoiceById($id, $tenantId);
        if (!$inv) {
            $this->jsonError('Subscription invoice not found.', 404, 'NOT_FOUND');
            return;
        }
        $inv['public_pdf_url'] = SubscriptionInvoiceService::getPublicPdfUrl($id, $tenantId);
        $this->jsonSuccess($inv);
    }

    public function resendInvoice(Request $request, array $params): void
    {
        $tenantId = $this->tenantId();
        $id = (int)($params['id'] ?? 0);
        $inv = $this->invoiceService->getInvoiceById($id, $tenantId);
        if (!$inv) {
            $this->jsonError('Subscription invoice not found.', 404, 'NOT_FOUND');
            return;
        }
        $updated = $this->invoiceService->dispatchNotifications($id);
        $updated['public_pdf_url'] = SubscriptionInvoiceService::getPublicPdfUrl($id, $tenantId);
        $this->jsonSuccess($updated, 'Tax invoice email & WhatsApp notification dispatched.');
    }

    public function createRazorpayOrder(Request $request): void
    {
        $tenantId = $this->tenantId();
        $planId = (int)$request->input('plan_id', 0);
        $planCode = trim((string)$request->input('plan_code', ''));
        if ($planId <= 0 && $planCode !== '') {
            $foundPlan = $this->quotaService->getPlanByCode($planCode);
            $planId = $foundPlan ? (int)$foundPlan['id'] : 0;
        }
        $billingCycle = trim((string)$request->input('billing_cycle', 'monthly')) ?: 'monthly';

        if ($planId <= 0) {
            $this->jsonError('A valid subscription plan_id is required.', 422, 'VALIDATION_ERROR');
            return;
        }

        $order = null;
        try {
            $order = $this->razorpayService->createSubscriptionOrder($tenantId, $planId, $billingCycle);
        } catch (\InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422, 'VALIDATION_ERROR');
            return;
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 502, 'PAYMENT_GATEWAY_ERROR');
            return;
        }

        $this->jsonSuccess($order, 'Razorpay order created successfully.', 201);
    }

    public function verifyRazorpayPayment(Request $request): void
    {
        $tenantId = $this->tenantId();
        $orderId = trim((string)$request->input('razorpay_order_id', ''));
        $paymentId = trim((string)$request->input('razorpay_payment_id', ''));
        $signature = trim((string)$request->input('razorpay_signature', ''));

        if ($orderId === '' || $paymentId === '' || $signature === '') {
            $this->jsonError('razorpay_order_id, razorpay_payment_id, and razorpay_signature are required.', 422, 'VALIDATION_ERROR');
            return;
        }

        $result = null;
        try {
            $result = $this->razorpayService->verifyAndFulfillOrder($tenantId, $orderId, $paymentId, $signature);
        } catch (\InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422, 'SIGNATURE_VERIFICATION_FAILED');
            return;
        } catch (\Throwable $e) {
            $this->jsonError('Payment verification failed: ' . $e->getMessage(), 500, 'SERVER_ERROR');
            return;
        }

        $planName = $result['quota']['plan_name'] ?? 'Subscription';
        $invNo = $result['invoice']['invoice_number'] ?? '';
        $msg = "Payment verified! {$planName} plan activated." . ($invNo !== '' ? " GST Tax Invoice {$invNo} generated and dispatched." : '');
        $this->jsonSuccess($result, $msg);
    }
}

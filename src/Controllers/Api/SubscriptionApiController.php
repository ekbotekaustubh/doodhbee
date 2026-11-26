<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Services\QuotaService;
use App\Services\SubscriptionInvoiceService;

final class SubscriptionApiController extends ApiBaseController
{
    private QuotaService $quotaService;
    private SubscriptionInvoiceService $invoiceService;

    public function __construct()
    {
        parent::__construct();
        Auth::requireApiTenantAdmin();
        $this->quotaService = new QuotaService();
        $this->invoiceService = new SubscriptionInvoiceService();
    }

    public function getSubscription(Request $request): void
    {
        $tenantId = $this->tenantId();
        $quota = $this->quotaService->getTenantQuota($tenantId);
        $settings = $this->quotaService->getPlatformSettings();
        $pendingRequest = $this->quotaService->getTenantPendingRequest($tenantId);
        $upgradeRequests = $this->quotaService->getTenantUpgradeRequests($tenantId);

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

        $invoices = $this->invoiceService->getTenantInvoices($tenantId);

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
            'bank_details' => [
                'upi_id' => $settings['payment_upi_id'] ?? 'kaustubh51-3@okicici',
                'account_name' => $settings['payment_account_name'] ?? 'Shri Ram Open Source Technologies',
                'bank_name' => $settings['payment_bank_name'] ?? 'Saraswat Co-operative Bank Ltd.',
                'account_number' => $settings['payment_account_number'] ?? '610000000039273',
                'ifsc_code' => $settings['payment_ifsc_code'] ?? 'srcb0000370',
                'qr_image_url' => $settings['payment_qr_image_url'] ?? '',
            ],
            'pending_request' => $pendingRequest,
            'upgrade_requests' => $upgradeRequests,
            'invoices' => $invoices,
        ]);
    }

    public function requestUpgrade(Request $request): void
    {
        $tenantId = $this->tenantId();
        $planId = (int)$request->input('plan_id', 0);
        $planCode = trim((string)$request->input('plan_code', ''));
        if ($planId <= 0 && $planCode !== '') {
            $foundPlan = $this->quotaService->getPlanByCode($planCode);
            $planId = $foundPlan ? (int)$foundPlan['id'] : 0;
        }

        $blocks = max(1, (int)$request->input('blocks', 1));
        $utrNumber = trim((string)$request->input('utr_number', ''));
        $paymentMethod = trim((string)$request->input('payment_method', 'upi'));
        $notes = trim((string)$request->input('notes', ''));
        $billingCycle = trim((string)$request->input('billing_cycle', 'monthly')) ?: 'monthly';
        $requestType = trim((string)$request->input('request_type', 'add_blocks')) ?: 'add_blocks';

        if (empty($utrNumber)) {
            $this->jsonError('Payment Transaction ID / UTR number is required.', 422, 'VALIDATION_ERROR');
            return;
        }

        $created = null;
        $isPlanRequest = false;
        try {
            if ($planId > 0) {
                $isPlanRequest = true;
                $created = $this->quotaService->createPlanRequest(
                    $tenantId,
                    $planId,
                    $utrNumber,
                    $paymentMethod,
                    $notes,
                    null,
                    $billingCycle
                );
            } else {
                $created = $this->quotaService->createUpgradeRequest(
                    $tenantId,
                    $blocks,
                    $utrNumber,
                    $paymentMethod,
                    $notes,
                    null,
                    $billingCycle,
                    $requestType
                );
            }
        } catch (\InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422, 'VALIDATION_ERROR');
            return;
        } catch (\Throwable $e) {
            $this->jsonError('Failed to submit upgrade request: ' . $e->getMessage(), 500, 'SERVER_ERROR');
            return;
        }

        if ($isPlanRequest) {
            $cycleLabel = ucfirst((string)($created['billing_cycle'] ?? $billingCycle));
            $planName = (string)($created['plan_name'] ?? 'Selected');
            $msg = "Subscription request for {$planName} ({$cycleLabel}) submitted successfully. SuperAdmin will verify payment and update your plan.";
            $this->jsonSuccess($created, $msg, 201);
            return;
        }

        $settings = $this->quotaService->getPlatformSettings();
        $unitSize = (int)$settings['paid_tier_unit_size'];
        $reqBlocks = (int)($created['blocks_requested'] ?? $blocks);
        $cycleLabel = ucfirst($created['billing_cycle'] ?? $billingCycle);
        $msg = ($created['request_type'] ?? $requestType) === 'renew'
            ? "Renewal request ({$cycleLabel}) for {$reqBlocks} block(s) submitted successfully. SuperAdmin will verify payment and extend your plan."
            : "Upgrade request ({$cycleLabel}) for +{$reqBlocks} block (" . ($reqBlocks * $unitSize) . " slots) submitted successfully. SuperAdmin will verify payment and activate your plan.";

        $this->jsonSuccess($created, $msg, 201);
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
        $invoices = $this->invoiceService->getTenantInvoices($tenantId);
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
}

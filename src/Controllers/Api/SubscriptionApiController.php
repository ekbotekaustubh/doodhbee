<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Services\QuotaService;

final class SubscriptionApiController extends ApiBaseController
{
    private QuotaService $quotaService;

    public function __construct()
    {
        parent::__construct();
        Auth::requireApiTenantAdmin();
        $this->quotaService = new QuotaService();
    }

    public function getSubscription(Request $request): void
    {
        $tenantId = $this->tenantId();
        $quota = $this->quotaService->getTenantQuota($tenantId);
        $settings = $this->quotaService->getPlatformSettings();
        $pendingRequest = $this->quotaService->getTenantPendingRequest($tenantId);
        $upgradeRequests = $this->quotaService->getTenantUpgradeRequests($tenantId);

        $this->jsonSuccess([
            'quota' => $quota,
            'pricing' => [
                'free_customer_limit' => $settings['free_customer_limit'],
                'paid_tier_unit_size' => $settings['paid_tier_unit_size'],
                'paid_tier_unit_price' => $settings['paid_tier_unit_price'],
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
        ]);
    }

    public function requestUpgrade(Request $request): void
    {
        $tenantId = $this->tenantId();
        $blocks = max(1, (int)$request->input('blocks', 1));
        $utrNumber = trim((string)$request->input('utr_number', ''));
        $paymentMethod = trim((string)$request->input('payment_method', 'upi'));
        $notes = trim((string)$request->input('notes', ''));

        if (empty($utrNumber)) {
            $this->jsonError('Payment Transaction ID / UTR number is required.', 422, 'VALIDATION_ERROR');
        }

        try {
            $created = $this->quotaService->createUpgradeRequest($tenantId, $blocks, $utrNumber, $paymentMethod, $notes);
        } catch (\InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422, 'VALIDATION_ERROR');
        } catch (\Throwable $e) {
            $this->jsonError('Failed to submit upgrade request: ' . $e->getMessage(), 500, 'SERVER_ERROR');
        }

        $settings = $this->quotaService->getPlatformSettings();
        $unitSize = (int)$settings['paid_tier_unit_size'];
        $this->jsonSuccess(
            $created,
            "Upgrade request for +{$blocks} block (" . ($blocks * $unitSize) . " slots) submitted successfully. SuperAdmin will verify payment and activate your plan.",
            201
        );
    }

    public function upgradeQuota(Request $request): void
    {
        $tenantId = $this->tenantId();
        $blocks = max(1, (int)$request->input('blocks', 1));

        $freshQuota = $this->quotaService->upgradeCapacity($tenantId, $blocks);

        $settings = $this->quotaService->getPlatformSettings();
        $unitPrice = (float)$settings['paid_tier_unit_price'];
        $unitSize = (int)$settings['paid_tier_unit_size'];
        $totalCost = round($blocks * $unitPrice, 2);

        $this->jsonSuccess([
            'added_blocks' => $blocks,
            'added_capacity' => $blocks * $unitSize,
            'amount_charged' => $totalCost,
            'quota' => $freshQuota,
        ], "Plan upgraded successfully! +{$blocks} block (" . ($blocks * $unitSize) . " slots) added.");
    }
}

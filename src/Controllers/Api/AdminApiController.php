<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Services\QuotaService;

final class AdminApiController extends ApiBaseController
{
    private QuotaService $quotaService;

    public function __construct()
    {
        parent::__construct();
        $this->quotaService = new QuotaService();
    }

    public function stats(Request $request): void
    {
        $tenantStats = $this->db->fetchOne(
            "SELECT 
                COUNT(*) as total_tenants,
                COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) as active_tenants,
                COALESCE(SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END), 0) as suspended_tenants
             FROM tenants"
        );

        $totalCustomers = (int)$this->db->fetchValue("SELECT COUNT(*) FROM customers");
        $activeCustomers = (int)$this->db->fetchValue("SELECT COUNT(*) FROM customers WHERE status = 'active'");
        $totalInvoices = (int)$this->db->fetchValue("SELECT COUNT(*) FROM invoices");
        $totalRevenueBilled = (float)$this->db->fetchValue("SELECT COALESCE(SUM(final_amount), 0) FROM invoices");

        $this->jsonSuccess([
            'tenants' => [
                'total' => (int)($tenantStats['total_tenants'] ?? 0),
                'active' => (int)($tenantStats['active_tenants'] ?? 0),
                'suspended' => (int)($tenantStats['suspended_tenants'] ?? 0),
            ],
            'customers' => [
                'total' => $totalCustomers,
                'active' => $activeCustomers,
            ],
            'platform_volume' => [
                'total_invoices' => $totalInvoices,
                'total_billed_amount' => round($totalRevenueBilled, 2),
            ]
        ]);
    }

    public function getSettings(Request $request): void
    {
        $settings = $this->quotaService->getPlatformSettings();
        $settings['razorpay_key_secret_configured'] = !empty($settings['razorpay_key_secret']);
        $settings['razorpay_webhook_secret_configured'] = !empty($settings['razorpay_webhook_secret']);
        unset($settings['razorpay_key_secret'], $settings['razorpay_webhook_secret']);
        $this->jsonSuccess($settings);
    }

    public function updateSettings(Request $request): void
    {
        $freeLimit = (int)$request->input('free_customer_limit', 5);
        $unitSize = (int)$request->input('paid_tier_unit_size', 100);
        $monthlyPrice = $request->has('paid_tier_monthly_price')
            ? (float)$request->input('paid_tier_monthly_price', 200.0)
            : (float)$request->input('paid_tier_unit_price', 200.0);
        $yearlyPrice = (float)$request->input('paid_tier_yearly_price', 2000.0);
        $graceDays = max(0, (int)$request->input('subscription_grace_days', 7));
        $gstEnabled = $request->has('subscription_gst_enabled') ? (!empty($request->input('subscription_gst_enabled')) ? '1' : '0') : '1';
        $gstPct = max(0.0, (float)$request->input('subscription_gst_percentage', 18.0));
        $companyName = trim((string)$request->input('company_legal_name', 'Shri Ram Open Source Technologies'));
        $companyGstin = trim((string)$request->input('company_gstin', '27AARPE1865G1ZJ'));
        $companySac = trim((string)$request->input('company_sac_code', '998314'));
        $companyAddr = trim((string)$request->input('company_address', 'Maharashtra, India'));
        $currencySymbol = trim((string)$request->input('currency_symbol', '₹'));
        $platformName = trim((string)$request->input('platform_name', 'DoodhBee SaaS'));
        $supportEmail = trim((string)$request->input('support_email', 'support@doodhbee.com'));
        $supportPhone = trim((string)$request->input('support_phone', ''));

        $settings = [
            'free_customer_limit' => (string)$freeLimit,
            'paid_tier_unit_size' => (string)$unitSize,
            'paid_tier_unit_price' => (string)$monthlyPrice,
            'paid_tier_monthly_price' => (string)$monthlyPrice,
            'paid_tier_yearly_price' => (string)$yearlyPrice,
            'subscription_grace_days' => (string)$graceDays,
            'subscription_gst_enabled' => $gstEnabled,
            'subscription_gst_percentage' => (string)$gstPct,
            'company_legal_name' => $companyName ?: 'Shri Ram Open Source Technologies',
            'company_gstin' => $companyGstin ?: '27AARPE1865G1ZJ',
            'company_sac_code' => $companySac ?: '998314',
            'company_address' => $companyAddr ?: 'Maharashtra, India',
            'currency_symbol' => $currencySymbol,
            'platform_name' => $platformName,
            'support_email' => $supportEmail,
            'support_phone' => $supportPhone,
        ];

        if ($request->has('razorpay_enabled')) {
            $settings['razorpay_enabled'] = !empty($request->input('razorpay_enabled')) ? '1' : '0';
        }
        if ($request->has('razorpay_key_id')) {
            $settings['razorpay_key_id'] = trim((string)$request->input('razorpay_key_id', ''));
        }
        if ($request->has('razorpay_key_secret')) {
            $rzpSecret = trim((string)$request->input('razorpay_key_secret', ''));
            if ($rzpSecret !== '') {
                $settings['razorpay_key_secret'] = $rzpSecret;
            }
        }
        if ($request->has('razorpay_webhook_secret')) {
            $whSecret = trim((string)$request->input('razorpay_webhook_secret', ''));
            if ($whSecret !== '') {
                $settings['razorpay_webhook_secret'] = $whSecret;
            }
        }

        $stmt = $this->db->getPdo()->prepare(
            "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );

        foreach ($settings as $k => $v) {
            $stmt->execute([$k, $v]);
        }

        $fresh = $this->quotaService->getPlatformSettings();
        $fresh['razorpay_key_secret_configured'] = !empty($fresh['razorpay_key_secret']);
        $fresh['razorpay_webhook_secret_configured'] = !empty($fresh['razorpay_webhook_secret']);
        unset($fresh['razorpay_key_secret'], $fresh['razorpay_webhook_secret']);
        $this->jsonSuccess($fresh, 'Platform settings updated successfully');
    }

    public function getTenants(Request $request): void
    {
        $search = trim((string)$request->get('q', ''));
        $status = trim((string)$request->get('status', ''));

        $sql = "SELECT t.id, t.business_name, t.owner_name, t.phone, t.email, t.status, t.created_at,
                       ts.plan_id, sp.code as plan_code, sp.name as plan_name,
                       ts.purchased_blocks, ts.max_customers_allowed, ts.amount_paid, ts.payment_status,
                       ts.billing_cycle, ts.started_at, ts.valid_until,
                       (SELECT COUNT(*) FROM customers c WHERE c.tenant_id = t.id AND c.status = 'active') as active_customers,
                       (SELECT COUNT(*) FROM customers c WHERE c.tenant_id = t.id) as total_customers
                FROM tenants t
                LEFT JOIN tenant_subscriptions ts ON ts.tenant_id = t.id
                LEFT JOIN subscription_plans sp ON sp.id = ts.plan_id
                WHERE 1=1";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (t.business_name LIKE ? OR t.owner_name LIKE ? OR t.phone LIKE ? OR t.email LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        if (!empty($status)) {
            $sql .= " AND t.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY t.created_at DESC";
        $tenants = $this->db->fetchAll($sql, $params);

        foreach ($tenants as &$t) {
            $t['id'] = (int)$t['id'];
            $t['active_customers'] = (int)$t['active_customers'];
            $t['total_customers'] = (int)$t['total_customers'];
            $t['purchased_blocks'] = (int)($t['purchased_blocks'] ?? 0);
            $t['max_customers_allowed'] = (int)($t['max_customers_allowed'] ?? 5);
            $t['amount_paid'] = (float)($t['amount_paid'] ?? 0.0);
            $t['remaining_slots'] = max(0, $t['max_customers_allowed'] - $t['active_customers']);
        }

        $this->jsonSuccess($tenants, 'Tenants retrieved', 200, ['total_count' => count($tenants)]);
    }

    public function toggleTenantStatus(Request $request, array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $tenant = $this->db->fetchOne("SELECT id, business_name, status FROM tenants WHERE id = ?", [$id]);

        if (!$tenant) {
            $this->jsonError('Tenant not found.', 404, 'NOT_FOUND');
        }

        $newStatus = $tenant['status'] === 'active' ? 'suspended' : 'active';
        $this->db->execute("UPDATE tenants SET status = ? WHERE id = ?", [$newStatus, $id]);

        $this->jsonSuccess([
            'id' => $id,
            'business_name' => $tenant['business_name'],
            'status' => $newStatus,
        ], "Tenant account {$newStatus}.");
    }

    public function adjustQuota(Request $request, array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $planId = (int)$request->input('plan_id', 0);
        $purchasedBlocks = max(0, (int)$request->input('purchased_blocks', 0));
        $billingCycle = $request->has('billing_cycle') ? (string)$request->input('billing_cycle', 'monthly') : null;
        $validUntil = $request->has('valid_until') ? trim((string)$request->input('valid_until', '')) : null;

        $tenant = $this->db->fetchOne("SELECT id, business_name FROM tenants WHERE id = ?", [$id]);
        if (!$tenant) {
            $this->jsonError('Tenant not found.', 404, 'NOT_FOUND');
            return;
        }

        if ($planId > 0) {
            $plan = $this->quotaService->getPlanById($planId);
            if (!$plan) {
                $this->jsonError('Subscription plan not found.', 404, 'NOT_FOUND');
                return;
            }
            if (!empty($plan['is_free'])) {
                $this->db->execute(
                    "INSERT INTO tenant_subscriptions (tenant_id, plan_id, purchased_blocks, max_customers_allowed, billing_cycle, valid_until, payment_status)
                     VALUES (?, ?, 0, ?, NULL, NULL, 'free')
                     ON DUPLICATE KEY UPDATE
                        plan_id = VALUES(plan_id),
                        purchased_blocks = 0,
                        max_customers_allowed = VALUES(max_customers_allowed),
                        billing_cycle = NULL,
                        valid_until = NULL,
                        payment_status = 'free'",
                    [$id, $plan['id'], $plan['customer_limit']]
                );
            } else {
                $cycle = $this->quotaService->normalizeBillingCycle($billingCycle ?: 'monthly');
                $targetValidUntil = !empty($validUntil) ? $validUntil : $this->quotaService->calculateCycleExpiry($cycle);
                $today = date('Y-m-d');
                $this->db->execute(
                    "INSERT INTO tenant_subscriptions (tenant_id, plan_id, purchased_blocks, max_customers_allowed, billing_cycle, started_at, valid_until, payment_status)
                     VALUES (?, ?, 1, ?, ?, ?, ?, 'active')
                     ON DUPLICATE KEY UPDATE
                        plan_id = VALUES(plan_id),
                        max_customers_allowed = VALUES(max_customers_allowed),
                        billing_cycle = VALUES(billing_cycle),
                        started_at = COALESCE(started_at, VALUES(started_at)),
                        valid_until = VALUES(valid_until),
                        payment_status = 'active'",
                    [$id, $plan['id'], $plan['customer_limit'], $cycle, $today, $targetValidUntil]
                );
            }
            $quota = $this->quotaService->getTenantQuota($id);
            $this->jsonSuccess($quota, "Plan updated to {$plan['name']} for {$tenant['business_name']}.");
            return;
        }

        $settings = $this->quotaService->getPlatformSettings();
        $freeLimit = $this->quotaService->getFreeCustomerLimit();
        $unitSize = (int)$settings['paid_tier_unit_size'];
        $newAllowed = $freeLimit + ($purchasedBlocks * $unitSize);

        if ($purchasedBlocks === 0) {
            $freePlan = $this->quotaService->getFreePlan();
            $this->db->execute(
                "INSERT INTO tenant_subscriptions (tenant_id, plan_id, purchased_blocks, max_customers_allowed, billing_cycle, valid_until, payment_status)
                 VALUES (?, ?, 0, ?, NULL, NULL, 'free')
                 ON DUPLICATE KEY UPDATE 
                    plan_id = VALUES(plan_id),
                    purchased_blocks = 0,
                    max_customers_allowed = VALUES(max_customers_allowed),
                    billing_cycle = NULL,
                    valid_until = NULL,
                    payment_status = 'free'",
                [$id, $freePlan ? $freePlan['id'] : null, $newAllowed]
            );
        } else {
            $cycle = $this->quotaService->normalizeBillingCycle($billingCycle ?: 'monthly');
            $targetValidUntil = !empty($validUntil) ? $validUntil : $this->quotaService->calculateCycleExpiry($cycle);
            $today = date('Y-m-d');
            $this->db->execute(
                "INSERT INTO tenant_subscriptions (tenant_id, purchased_blocks, max_customers_allowed, billing_cycle, started_at, valid_until, payment_status)
                 VALUES (?, ?, ?, ?, ?, ?, 'active')
                 ON DUPLICATE KEY UPDATE 
                    purchased_blocks = VALUES(purchased_blocks),
                    max_customers_allowed = VALUES(max_customers_allowed),
                    billing_cycle = COALESCE(?, billing_cycle, 'monthly'),
                    started_at = COALESCE(started_at, VALUES(started_at)),
                    valid_until = COALESCE(?, valid_until, VALUES(valid_until)),
                    payment_status = 'active'",
                [
                    $id,
                    $purchasedBlocks,
                    $newAllowed,
                    $cycle,
                    $today,
                    $targetValidUntil,
                    $billingCycle !== null ? $cycle : null,
                    !empty($validUntil) ? $validUntil : null,
                ]
            );
        }

        $quota = $this->quotaService->getTenantQuota($id);

        $this->jsonSuccess($quota, "Quota updated for {$tenant['business_name']}.");
    }

    public function getPlans(Request $request): void
    {
        $plans = $this->quotaService->getPlans(false);
        $this->jsonSuccess($plans);
    }

    public function savePlan(Request $request, array $params = []): void
    {
        $planId = isset($params['id']) ? (int)$params['id'] : (int)$request->input('id', 0);
        try {
            $saved = $this->quotaService->savePlan([
                'code' => $request->input('code', ''),
                'name' => $request->input('name', ''),
                'description' => $request->input('description', ''),
                'customer_limit' => (int)$request->input('customer_limit', 100),
                'monthly_price' => (float)$request->input('monthly_price', 0.0),
                'yearly_price' => (float)$request->input('yearly_price', 0.0),
                'is_active' => $request->has('is_active') ? (bool)$request->input('is_active') : true,
                'sort_order' => (int)$request->input('sort_order', 10),
            ], $planId > 0 ? $planId : null);
        } catch (\InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422, 'VALIDATION_ERROR');
            return;
        }

        $this->jsonSuccess($saved, 'Subscription plan saved successfully.', $planId > 0 ? 200 : 201);
    }

    public function togglePlan(Request $request, array $params): void
    {
        $planId = (int)($params['id'] ?? 0);
        try {
            $updated = $this->quotaService->togglePlan($planId);
        } catch (\InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422, 'VALIDATION_ERROR');
            return;
        }
        $this->jsonSuccess($updated, 'Plan availability toggled.');
    }
}

<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class QuotaService
{
    public const RENEWAL_WINDOW_DAYS = 7;

    private Database $db;
    private ?array $lastGeneratedInvoice = null;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getLastGeneratedInvoice(): ?array
    {
        return $this->lastGeneratedInvoice;
    }

    public function ensureDefaultPlans(): void
    {
        $count = (int)$this->db->fetchValue("SELECT COUNT(*) FROM subscription_plans");
        if ($count > 0) {
            return;
        }

        $defaults = [
            ['free', 'Free', 'Up to 5 active customers free forever', 5, 0.00, 0.00, 1, 1, 1],
            ['starter', 'Starter', 'Ideal for growing milk routes up to 100 customers', 100, 200.00, 2000.00, 0, 1, 2],
            ['growth', 'Growth', 'For established dairies up to 300 active customers', 300, 500.00, 5000.00, 0, 1, 3],
            ['pro', 'Pro', 'High-volume dairy operations up to 1,000 customers', 1000, 1200.00, 12000.00, 0, 1, 4],
        ];

        $stmt = $this->db->getPdo()->prepare(
            "INSERT INTO subscription_plans (code, name, description, customer_limit, monthly_price, yearly_price, is_free, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($defaults as $row) {
            $stmt->execute($row);
        }
    }

    public function getPlans(bool $activeOnly = true): array
    {
        $this->ensureDefaultPlans();
        $sql = "SELECT * FROM subscription_plans";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY sort_order ASC, customer_limit ASC, id ASC";

        $rows = $this->db->fetchAll($sql);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['customer_limit'] = (int)$r['customer_limit'];
            $r['monthly_price'] = (float)$r['monthly_price'];
            $r['yearly_price'] = (float)$r['yearly_price'];
            $r['is_free'] = (bool)$r['is_free'];
            $r['is_active'] = (bool)$r['is_active'];
            $r['sort_order'] = (int)$r['sort_order'];
        }
        return $rows;
    }

    public function getPlanById(int $planId): ?array
    {
        $this->ensureDefaultPlans();
        $row = $this->db->fetchOne("SELECT * FROM subscription_plans WHERE id = ?", [$planId]);
        if (!$row) {
            return null;
        }
        $row['id'] = (int)$row['id'];
        $row['customer_limit'] = (int)$row['customer_limit'];
        $row['monthly_price'] = (float)$row['monthly_price'];
        $row['yearly_price'] = (float)$row['yearly_price'];
        $row['is_free'] = (bool)$row['is_free'];
        $row['is_active'] = (bool)$row['is_active'];
        $row['sort_order'] = (int)$row['sort_order'];
        return $row;
    }

    public function getPlanByCode(string $code): ?array
    {
        $this->ensureDefaultPlans();
        $row = $this->db->fetchOne("SELECT * FROM subscription_plans WHERE code = ?", [strtolower(trim($code))]);
        return $row ? $this->getPlanById((int)$row['id']) : null;
    }

    public function getFreePlan(): ?array
    {
        $this->ensureDefaultPlans();
        $row = $this->db->fetchOne("SELECT id FROM subscription_plans WHERE is_free = 1 ORDER BY id ASC LIMIT 1");
        return $row ? $this->getPlanById((int)$row['id']) : null;
    }

    public function savePlan(array $data, ?int $planId = null): array
    {
        $this->ensureDefaultPlans();
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Plan name is required.');
        }

        $customerLimit = max(1, (int)($data['customer_limit'] ?? 100));
        $monthlyPrice = max(0.0, round((float)($data['monthly_price'] ?? 0.0), 2));
        $yearlyPrice = max(0.0, round((float)($data['yearly_price'] ?? 0.0), 2));
        $description = trim((string)($data['description'] ?? ''));
        $isActive = isset($data['is_active']) ? (!empty($data['is_active']) ? 1 : 0) : 1;
        $sortOrder = (int)($data['sort_order'] ?? 10);

        if ($planId !== null && $planId > 0) {
            $existing = $this->getPlanById($planId);
            if (!$existing) {
                throw new \InvalidArgumentException('Subscription plan not found.');
            }
            if ($existing['is_free']) {
                $monthlyPrice = 0.0;
                $yearlyPrice = 0.0;
                $isActive = 1;
            }
            $this->db->execute(
                "UPDATE subscription_plans
                 SET name = ?, description = ?, customer_limit = ?, monthly_price = ?, yearly_price = ?, is_active = ?, sort_order = ?
                 WHERE id = ?",
                [$name, $description ?: null, $customerLimit, $monthlyPrice, $yearlyPrice, $isActive, $sortOrder, $planId]
            );

            if ($existing['is_free']) {
                $this->db->execute(
                    "INSERT INTO system_settings (setting_key, setting_value) VALUES ('free_customer_limit', ?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                    [(string)$customerLimit]
                );
            }

            return $this->getPlanById($planId) ?? [];
        }

        $rawCode = trim((string)($data['code'] ?? $name));
        $code = preg_replace('/[^a-z0-9_-]/', '_', strtolower($rawCode)) ?: ('plan_' . time());
        $dup = $this->db->fetchOne("SELECT id FROM subscription_plans WHERE code = ?", [$code]);
        if ($dup) {
            $code .= '_' . substr((string)time(), -4);
        }

        $this->db->execute(
            "INSERT INTO subscription_plans (code, name, description, customer_limit, monthly_price, yearly_price, is_free, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)",
            [$code, $name, $description ?: null, $customerLimit, $monthlyPrice, $yearlyPrice, $isActive, $sortOrder]
        );
        $newId = (int)$this->db->getPdo()->lastInsertId();
        return $this->getPlanById($newId) ?? [];
    }

    public function togglePlan(int $planId): array
    {
        $plan = $this->getPlanById($planId);
        if (!$plan) {
            throw new \InvalidArgumentException('Subscription plan not found.');
        }
        if ($plan['is_free']) {
            throw new \InvalidArgumentException('The Free tier plan cannot be disabled.');
        }
        $newStatus = $plan['is_active'] ? 0 : 1;
        $this->db->execute("UPDATE subscription_plans SET is_active = ? WHERE id = ?", [$newStatus, $planId]);
        return $this->getPlanById($planId) ?? [];
    }

    public function getSystemSettings(): array
    {
        $rows = $this->db->fetchAll("SELECT setting_key, setting_value FROM system_settings");
        $settings = [
            'free_customer_limit' => 5,
            'paid_tier_unit_size' => 100,
            'paid_tier_unit_price' => 200.00,
            'paid_tier_monthly_price' => 200.00,
            'paid_tier_yearly_price' => 2000.00,
            'subscription_grace_days' => 7,
            'subscription_gst_enabled' => 1,
            'subscription_gst_percentage' => 18.00,
            'company_legal_name' => 'Shri Ram Open Source Technologies',
            'company_gstin' => '27AARPE1865G1ZJ',
            'company_sac_code' => '998314',
            'company_address' => 'Maharashtra, India',
            'razorpay_enabled' => 0,
            'razorpay_key_id' => '',
            'razorpay_key_secret' => '',
            'razorpay_webhook_secret' => '',
            'currency_symbol' => '₹',
            'platform_name' => 'DoodhBee SaaS',
            'support_email' => 'support@doodhbee.com',
            'support_phone' => '+91 98000 11223',
        ];
        $hasMonthly = false;
        $hasUnit = false;
        foreach ($rows as $r) {
            $key = $r['setting_key'];
            $val = $r['setting_value'];
            if (in_array($key, ['free_customer_limit', 'paid_tier_unit_size', 'subscription_grace_days', 'subscription_gst_enabled', 'razorpay_enabled'], true)) {
                $settings[$key] = (int)$val;
            } elseif (in_array($key, ['paid_tier_unit_price', 'paid_tier_monthly_price', 'paid_tier_yearly_price', 'subscription_gst_percentage'], true)) {
                $settings[$key] = (float)$val;
                if ($key === 'paid_tier_monthly_price') {
                    $hasMonthly = true;
                } elseif ($key === 'paid_tier_unit_price') {
                    $hasUnit = true;
                }
            } else {
                $settings[$key] = $val;
            }
        }
        if ($hasMonthly) {
            $settings['paid_tier_unit_price'] = $settings['paid_tier_monthly_price'];
        } elseif ($hasUnit) {
            $settings['paid_tier_monthly_price'] = $settings['paid_tier_unit_price'];
        }
        return $settings;
    }

    public function getPlatformSettings(): array
    {
        return $this->getSystemSettings();
    }

    public function getFreeCustomerLimit(): int
    {
        $freePlan = $this->getFreePlan();
        if ($freePlan) {
            return (int)$freePlan['customer_limit'];
        }
        $settings = $this->getSystemSettings();
        return (int)($settings['free_customer_limit'] ?? 5);
    }

    public function getPaidTierUnitSize(): int
    {
        $settings = $this->getSystemSettings();
        return (int)($settings['paid_tier_unit_size'] ?? 100);
    }

    public function getPaidTierUnitPrice(): float
    {
        $settings = $this->getSystemSettings();
        return (float)($settings['paid_tier_monthly_price'] ?? $settings['paid_tier_unit_price'] ?? 200.00);
    }

    public function normalizeBillingCycle(string $cycle): string
    {
        $clean = strtolower(trim($cycle));
        if ($clean === '') {
            return 'monthly';
        }
        if (!in_array($clean, ['monthly', 'yearly'], true)) {
            throw new \InvalidArgumentException("Invalid billing cycle '{$cycle}'. Must be 'monthly' or 'yearly'.");
        }
        return $clean;
    }

    public function getBlockPrice(string $billingCycle = 'monthly'): float
    {
        $cycle = $this->normalizeBillingCycle($billingCycle);
        $settings = $this->getSystemSettings();
        if ($cycle === 'yearly') {
            return (float)($settings['paid_tier_yearly_price'] ?? 2000.00);
        }
        return (float)($settings['paid_tier_monthly_price'] ?? $settings['paid_tier_unit_price'] ?? 200.00);
    }

    public function getPlanPrice(array $plan, string $billingCycle = 'monthly'): float
    {
        $cycle = $this->normalizeBillingCycle($billingCycle);
        return $cycle === 'yearly' ? (float)$plan['yearly_price'] : (float)$plan['monthly_price'];
    }

    public function calculateCycleExpiry(string $billingCycle, ?string $baseDate = null): string
    {
        $cycle = $this->normalizeBillingCycle($billingCycle);
        $base = new \DateTimeImmutable($baseDate ?: 'today');
        $modifier = $cycle === 'yearly' ? '+1 year' : '+1 month';
        return $base->modify($modifier)->format('Y-m-d');
    }

    public function getTenantQuota(int $tenantId): array
    {
        $this->ensureDefaultPlans();
        $sys = $this->getSystemSettings();
        $freePlan = $this->getFreePlan();
        $freeLimit = $freePlan ? (int)$freePlan['customer_limit'] : (int)$sys['free_customer_limit'];
        $unitSize = (int)$sys['paid_tier_unit_size'];
        $monthlyPrice = (float)($sys['paid_tier_monthly_price'] ?? $sys['paid_tier_unit_price']);
        $yearlyPrice = (float)($sys['paid_tier_yearly_price'] ?? ($monthlyPrice * 10));
        $graceDays = max(0, (int)($sys['subscription_grace_days'] ?? 7));

        $sub = $this->db->fetchOne(
            "SELECT ts.*, sp.code as sp_code, sp.name as sp_name, sp.customer_limit as sp_customer_limit,
                    sp.monthly_price as sp_monthly_price, sp.yearly_price as sp_yearly_price, sp.is_free as sp_is_free
             FROM tenant_subscriptions ts
             LEFT JOIN subscription_plans sp ON sp.id = ts.plan_id
             WHERE ts.tenant_id = ?
             ORDER BY ts.id DESC LIMIT 1",
            [$tenantId]
        );

        $purchasedBlocks = $sub ? (int)$sub['purchased_blocks'] : 0;
        $paymentStatus = $sub ? (string)$sub['payment_status'] : 'free';
        $billingCycle = $sub['billing_cycle'] ?? null;
        $startedAt = $sub['started_at'] ?? null;
        $validUntil = $sub['valid_until'] ?? null;

        $planId = ($sub && !empty($sub['plan_id'])) ? (int)$sub['plan_id'] : ($freePlan ? (int)$freePlan['id'] : null);
        $planCode = ($sub && !empty($sub['sp_code'])) ? (string)$sub['sp_code'] : ($purchasedBlocks > 0 ? 'starter' : 'free');
        $planName = ($sub && !empty($sub['sp_name'])) ? (string)$sub['sp_name'] : ($purchasedBlocks > 0 ? 'Starter' : 'Free');
        $planIsFree = $sub && isset($sub['sp_is_free']) ? (bool)$sub['sp_is_free'] : ($purchasedBlocks <= 0);
        $hasPaidPlan = (!$planIsFree && $paymentStatus !== 'free') || $purchasedBlocks > 0;

        $today = date('Y-m-d');
        $isExpired = false;
        $isInGrace = false;
        $graceEndsOn = null;
        $graceDaysRemaining = 0;
        $daysRemaining = 0;

        if ($hasPaidPlan) {
            if ($paymentStatus === 'expired') {
                $isExpired = true;
            } elseif (!empty($validUntil)) {
                $todayDt = new \DateTimeImmutable($today);
                $validDt = new \DateTimeImmutable($validUntil);

                if ($today <= $validUntil) {
                    $daysRemaining = (int)$todayDt->diff($validDt)->days;
                } else {
                    $graceEndDt = $graceDays > 0 ? $validDt->modify("+{$graceDays} days") : $validDt;
                    $graceEndsOn = $graceEndDt->format('Y-m-d');

                    if ($graceDays > 0 && $today <= $graceEndsOn) {
                        $isInGrace = true;
                        $graceDaysRemaining = (int)$todayDt->diff($graceEndDt)->days;
                    } else {
                        $isExpired = true;
                        $paymentStatus = 'expired';
                    }
                }
            }
        }

        $isPaidActive = $hasPaidPlan && !$isExpired;
        $inRenewalWindow = !$isPaidActive || $isInGrace || empty($validUntil) || $daysRemaining <= self::RENEWAL_WINDOW_DAYS;

        if ($isExpired || !$hasPaidPlan) {
            $maxAllowed = $freeLimit;
            $effectiveBlocks = 0;
        } elseif ($sub && !empty($sub['sp_customer_limit']) && !$planIsFree) {
            $maxAllowed = (int)$sub['sp_customer_limit'];
            $effectiveBlocks = $purchasedBlocks;
        } else {
            $maxAllowed = max($freeLimit, (int)($sub['max_customers_allowed'] ?? ($freeLimit + ($purchasedBlocks * $unitSize))));
            $effectiveBlocks = $purchasedBlocks;
        }

        $activeCustomers = (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM customers WHERE tenant_id = ? AND status = 'active'",
            [$tenantId]
        );
        $totalCustomers = (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM customers WHERE tenant_id = ?",
            [$tenantId]
        );

        $canAdd = $activeCustomers < $maxAllowed;
        $usagePercent = $maxAllowed > 0 ? min(100, round(($activeCustomers / $maxAllowed) * 100, 1)) : 100;

        return [
            'tenant_id' => $tenantId,
            'plan_id' => $planId,
            'plan_code' => $planCode,
            'plan_name' => $planName,
            'plan_is_free' => $planIsFree,
            'plan_monthly_price' => isset($sub['sp_monthly_price']) ? (float)$sub['sp_monthly_price'] : 0.0,
            'plan_yearly_price' => isset($sub['sp_yearly_price']) ? (float)$sub['sp_yearly_price'] : 0.0,
            'is_paid_active' => $isPaidActive,
            'in_renewal_window' => $inRenewalWindow,
            'renewal_window_days' => self::RENEWAL_WINDOW_DAYS,
            'active_customers' => $activeCustomers,
            'total_customers' => $totalCustomers,
            'max_allowed' => $maxAllowed,
            'free_limit' => $freeLimit,
            'purchased_blocks' => $purchasedBlocks,
            'effective_blocks' => $effectiveBlocks,
            'unit_size' => $unitSize,
            'unit_price' => $monthlyPrice,
            'monthly_unit_price' => $monthlyPrice,
            'yearly_unit_price' => $yearlyPrice,
            'currency_symbol' => $sys['currency_symbol'],
            'payment_status' => $paymentStatus,
            'billing_cycle' => $billingCycle,
            'started_at' => $startedAt,
            'valid_until' => $validUntil,
            'grace_days' => $graceDays,
            'grace_ends_on' => $graceEndsOn,
            'grace_days_remaining' => $graceDaysRemaining,
            'days_remaining' => $daysRemaining,
            'is_in_grace' => $isInGrace,
            'is_expired' => $isExpired,
            'can_add' => $canAdd,
            'remaining_slots' => max(0, $maxAllowed - $activeCustomers),
            'usage_percent' => $usagePercent,
            'is_near_limit' => ($maxAllowed - $activeCustomers) <= 1,
            'is_at_limit' => $activeCustomers >= $maxAllowed,
        ];
    }

    public function canCreateCustomer(int $tenantId): bool
    {
        $quota = $this->getTenantQuota($tenantId);
        return $quota['can_add'];
    }

    /**
     * Evaluate selecting a named plan & billing cycle for a tenant.
     * Supports:
     * - New / expired subscription -> starts today
     * - Same-pack renewal before expiry -> starts after current expiry, same pack price
     * - Downgrade to smaller pack -> allowed within 7 days of expiry, in grace, or expired; requires active_customers <= limit
     * - Upgrade to larger pack -> immediate capacity unlock; same-cycle mid-cycle charges difference keeping expiry,
     *   while renewal-window or cycle-switch charges full plan price and extends after current expiry.
     */
    public function previewPlanSelection(int $tenantId, int $targetPlanId, string $billingCycle = 'monthly'): array
    {
        $cycle = $this->normalizeBillingCycle($billingCycle);
        $plan = $this->getPlanById($targetPlanId);
        if (!$plan || !$plan['is_active']) {
            throw new \InvalidArgumentException('Selected subscription plan is not available.');
        }
        if ($plan['is_free']) {
            throw new \InvalidArgumentException('The Free plan does not require a paid subscription request.');
        }

        $quota = $this->getTenantQuota($tenantId);
        $sys = $this->getSystemSettings();
        $gstEnabled = !empty($sys['subscription_gst_enabled']);
        $gstPct = $gstEnabled ? max(0.0, (float)($sys['subscription_gst_percentage'] ?? 18.0)) : 0.0;

        $buildBreakdown = static function (float $base) use ($gstPct): array {
            $base = round(max(0.0, $base), 2);
            $gst = round($base * ($gstPct / 100), 2);
            $cgst = round($gst / 2, 2);
            $sgst = round($gst - $cgst, 2);
            $total = round($base + $gst, 2);
            return [
                'base_amount' => $base,
                'gst_percentage' => $gstPct,
                'cgst_amount' => $cgst,
                'sgst_amount' => $sgst,
                'gst_amount' => $gst,
                'total_amount' => $total,
                'amount' => $total,
            ];
        };

        $activeCustomers = (int)$quota['active_customers'];
        $targetLimit = (int)$plan['customer_limit'];
        $targetPrice = $this->getPlanPrice($plan, $cycle);
        $today = date('Y-m-d');

        if ($activeCustomers > $targetLimit) {
            $excess = $activeCustomers - $targetLimit;
            return array_merge([
                'allowed' => false,
                'reason' => "You currently have {$activeCustomers} active customers, which exceeds the {$plan['name']} plan limit ({$targetLimit}). Deactivate at least {$excess} customer(s) first.",
                'plan' => $plan,
                'billing_cycle' => $cycle,
                'action_type' => 'blocked',
                'starts_from' => $today,
                'new_valid_until' => $this->calculateCycleExpiry($cycle, $today),
            ], $buildBreakdown($targetPrice));
        }

        $isPaidActive = !empty($quota['is_paid_active']);
        $currentPlanId = $quota['plan_id'] ?? null;
        $currentLimit = (int)$quota['max_allowed'];
        $currentCycle = $quota['billing_cycle'] ?? null;
        $currentValidUntil = $quota['valid_until'] ?? null;
        $hasFutureExpiry = $isPaidActive && !empty($currentValidUntil) && $currentValidUntil >= $today;
        $inRenewalWindow = !empty($quota['in_renewal_window']);

        if (!$isPaidActive) {
            $newValidUntil = $this->calculateCycleExpiry($cycle, $today);
            $tax = $buildBreakdown($targetPrice);
            return array_merge([
                'allowed' => true,
                'reason' => null,
                'plan' => $plan,
                'billing_cycle' => $cycle,
                'action_type' => 'subscribe',
                'starts_from' => $today,
                'new_valid_until' => $newValidUntil,
                'note' => "Activates {$plan['name']} ({$targetLimit} customers) through {$newValidUntil} (incl. {$gstPct}% GST).",
            ], $tax);
        }

        if ((int)$currentPlanId === (int)$plan['id']) {
            $baseDate = $hasFutureExpiry ? (string)$currentValidUntil : $today;
            $newValidUntil = $this->calculateCycleExpiry($cycle, $baseDate);
            $tax = $buildBreakdown($targetPrice);
            return array_merge([
                'allowed' => true,
                'reason' => null,
                'plan' => $plan,
                'billing_cycle' => $cycle,
                'action_type' => 'renew',
                'starts_from' => $baseDate,
                'new_valid_until' => $newValidUntil,
                'note' => $hasFutureExpiry
                    ? "Starts after current expiry ({$currentValidUntil}) and extends to {$newValidUntil}."
                    : "Renews {$plan['name']} through {$newValidUntil}.",
            ], $tax);
        }

        // Switching to a smaller pack
        if ($targetLimit < $currentLimit) {
            if (!$inRenewalWindow) {
                $opensOn = (new \DateTimeImmutable((string)$currentValidUntil))
                    ->modify('-' . self::RENEWAL_WINDOW_DAYS . ' days')
                    ->format('Y-m-d');
                return array_merge([
                    'allowed' => false,
                    'reason' => "Switching to a smaller plan ({$plan['name']}) is allowed within " . self::RENEWAL_WINDOW_DAYS . " days of expiry (from {$opensOn}) or after your current plan expires.",
                    'plan' => $plan,
                    'billing_cycle' => $cycle,
                    'action_type' => 'downgrade_locked',
                    'starts_from' => $currentValidUntil ?: $today,
                    'new_valid_until' => $this->calculateCycleExpiry($cycle, $currentValidUntil ?: $today),
                ], $buildBreakdown($targetPrice));
            }

            $baseDate = $hasFutureExpiry ? (string)$currentValidUntil : $today;
            $newValidUntil = $this->calculateCycleExpiry($cycle, $baseDate);
            return array_merge([
                'allowed' => true,
                'reason' => null,
                'plan' => $plan,
                'billing_cycle' => $cycle,
                'action_type' => 'downgrade',
                'starts_from' => $baseDate,
                'new_valid_until' => $newValidUntil,
                'note' => "Switches to {$plan['name']} ({$targetLimit} customers) and extends validity to {$newValidUntil}.",
            ], $buildBreakdown($targetPrice));
        }

        // Upgrading to a larger pack
        $sameCycle = ($currentCycle === $cycle);
        if (!$inRenewalWindow && $hasFutureExpiry && $sameCycle) {
            $currentPlan = $currentPlanId ? $this->getPlanById((int)$currentPlanId) : null;
            $currentPlanPrice = $currentPlan ? $this->getPlanPrice($currentPlan, $cycle) : 0.0;
            $diffBase = max(0.0, round($targetPrice - $currentPlanPrice, 2));
            $tax = $buildBreakdown($diffBase);
            return array_merge([
                'allowed' => true,
                'reason' => null,
                'plan' => $plan,
                'billing_cycle' => $cycle,
                'action_type' => 'upgrade',
                'starts_from' => $today,
                'new_valid_until' => (string)$currentValidUntil,
                'note' => "Mid-cycle upgrade: base difference ₹" . number_format($diffBase, 2) . " + {$gstPct}% GST = ₹" . number_format($tax['total_amount'], 2) . " (keeps expiry {$currentValidUntil}).",
            ], $tax);
        }

        $baseDate = $hasFutureExpiry ? (string)$currentValidUntil : $today;
        $newValidUntil = $this->calculateCycleExpiry($cycle, $baseDate);
        return array_merge([
            'allowed' => true,
            'reason' => null,
            'plan' => $plan,
            'billing_cycle' => $cycle,
            'action_type' => 'upgrade',
            'starts_from' => $baseDate,
            'new_valid_until' => $newValidUntil,
            'note' => $hasFutureExpiry
                ? "Upgrades to {$plan['name']} ({$targetLimit} customers) and extends validity after {$currentValidUntil} to {$newValidUntil}."
                : "Upgrades to {$plan['name']} ({$targetLimit} customers) through {$newValidUntil}.",
        ], $buildBreakdown($targetPrice));
    }

    public function applyPlanSubscription(int $tenantId, int $planId, string $billingCycle = 'monthly', array $invoiceMeta = []): array
    {
        $preview = $this->previewPlanSelection($tenantId, $planId, $billingCycle);
        if (!$preview['allowed']) {
            throw new \InvalidArgumentException((string)$preview['reason']);
        }

        $plan = $preview['plan'];
        $cycle = $preview['billing_cycle'];
        $totalAmount = (float)$preview['amount'];
        $newValidUntil = (string)$preview['new_valid_until'];
        $newLimit = (int)$plan['customer_limit'];
        $today = date('Y-m-d');

        $sub = $this->db->fetchOne("SELECT * FROM tenant_subscriptions WHERE tenant_id = ?", [$tenantId]);
        $startedAt = ($sub && !empty($sub['started_at'])) ? (string)$sub['started_at'] : $today;

        if ($sub) {
            $this->db->execute(
                "UPDATE tenant_subscriptions
                 SET plan_id = ?, max_customers_allowed = ?, amount_paid = amount_paid + ?,
                     billing_cycle = ?, started_at = ?, valid_until = ?, payment_status = 'active'
                 WHERE tenant_id = ?",
                [$plan['id'], $newLimit, $totalAmount, $cycle, $startedAt, $newValidUntil, $tenantId]
            );
        } else {
            $this->db->execute(
                "INSERT INTO tenant_subscriptions (tenant_id, plan_id, purchased_blocks, max_customers_allowed, amount_paid, billing_cycle, started_at, valid_until, payment_status)
                 VALUES (?, ?, 0, ?, ?, ?, ?, ?, 'active')",
                [$tenantId, $plan['id'], $newLimit, $totalAmount, $cycle, $startedAt, $newValidUntil]
            );
        }

        $this->lastGeneratedInvoice = null;
        if (empty($invoiceMeta['skip_invoice'])) {
            $sys = $this->getSystemSettings();
            $invService = new SubscriptionInvoiceService($this->db);
            $this->lastGeneratedInvoice = $invService->generateInvoice($tenantId, [
                'upgrade_request_id' => $invoiceMeta['upgrade_request_id'] ?? null,
                'plan_id' => $plan['id'],
                'plan_name' => $plan['name'],
                'billing_cycle' => $cycle,
                'customer_limit' => $newLimit,
                'action_type' => $preview['action_type'],
                'period_start' => (string)$preview['starts_from'],
                'period_end' => $newValidUntil,
                'base_amount' => $preview['base_amount'],
                'gst_percentage' => $preview['gst_percentage'],
                'gst_amount' => $preview['gst_amount'],
                'total_amount' => $totalAmount,
                'payment_method' => $invoiceMeta['payment_method'] ?? 'upi',
                'utr_number' => $invoiceMeta['utr_number'] ?? null,
                'seller_name' => $sys['company_legal_name'] ?? 'Shri Ram Open Source Technologies',
                'seller_gstin' => $sys['company_gstin'] ?? '27AARPE1865G1ZJ',
                'sac_code' => $sys['company_sac_code'] ?? '998314',
            ]);
        }

        return $this->getTenantQuota($tenantId);
    }

    /**
     * Legacy block-based upgrade helper (kept for backwards compatibility)
     */
    public function upgradeCapacity(int $tenantId, int $additionalBlocks, string $billingCycle = 'monthly'): array
    {
        if ($additionalBlocks < 1) {
            throw new \InvalidArgumentException('At least 1 capacity block is required.');
        }

        $cycle = $this->normalizeBillingCycle($billingCycle);
        $sys = $this->getSystemSettings();
        $unitSize = (int)$sys['paid_tier_unit_size'];
        $unitPrice = $this->getBlockPrice($cycle);

        $sub = $this->db->fetchOne("SELECT * FROM tenant_subscriptions WHERE tenant_id = ?", [$tenantId]);
        $currentBlocks = $sub ? (int)$sub['purchased_blocks'] : 0;
        $newBlocks = $currentBlocks + $additionalBlocks;
        $freeLimit = $this->getFreeCustomerLimit();
        $newMax = $freeLimit + ($newBlocks * $unitSize);
        $totalCost = round($additionalBlocks * $unitPrice, 2);

        $today = date('Y-m-d');
        $hasActiveSharedExpiry = $sub
            && $currentBlocks > 0
            && ($sub['payment_status'] ?? 'free') !== 'expired'
            && !empty($sub['valid_until'])
            && $sub['valid_until'] > $today;

        $validUntil = $hasActiveSharedExpiry
            ? (string)$sub['valid_until']
            : $this->calculateCycleExpiry($cycle, $today);
        $effectiveCycle = ($hasActiveSharedExpiry && !empty($sub['billing_cycle']))
            ? (string)$sub['billing_cycle']
            : $cycle;
        $startedAt = ($sub && !empty($sub['started_at'])) ? (string)$sub['started_at'] : $today;

        if ($sub) {
            $this->db->execute(
                "UPDATE tenant_subscriptions 
                 SET purchased_blocks = ?, max_customers_allowed = ?, amount_paid = amount_paid + ?,
                     billing_cycle = ?, started_at = ?, valid_until = ?, payment_status = 'active'
                 WHERE tenant_id = ?",
                [$newBlocks, $newMax, $totalCost, $effectiveCycle, $startedAt, $validUntil, $tenantId]
            );
        } else {
            $this->db->execute(
                "INSERT INTO tenant_subscriptions (tenant_id, purchased_blocks, max_customers_allowed, amount_paid, billing_cycle, started_at, valid_until, payment_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'active')",
                [$tenantId, $newBlocks, $newMax, $totalCost, $effectiveCycle, $startedAt, $validUntil]
            );
        }

        return $this->getTenantQuota($tenantId);
    }

    public function renewSubscription(int $tenantId, string $billingCycle = 'monthly'): array
    {
        $quota = $this->getTenantQuota($tenantId);
        if (!empty($quota['plan_id']) && empty($quota['plan_is_free'])) {
            return $this->applyPlanSubscription($tenantId, (int)$quota['plan_id'], $billingCycle);
        }

        $cycle = $this->normalizeBillingCycle($billingCycle);
        $sys = $this->getSystemSettings();
        $unitSize = (int)$sys['paid_tier_unit_size'];
        $freeLimit = $this->getFreeCustomerLimit();
        $unitPrice = $this->getBlockPrice($cycle);

        $sub = $this->db->fetchOne("SELECT * FROM tenant_subscriptions WHERE tenant_id = ?", [$tenantId]);
        $blocks = $sub ? (int)$sub['purchased_blocks'] : 0;
        if ($blocks < 1) {
            throw new \InvalidArgumentException('No paid plan found to renew. Please choose a paid plan first.');
        }

        $today = date('Y-m-d');
        $baseDate = (!empty($sub['valid_until']) && $sub['valid_until'] >= $today && ($sub['payment_status'] ?? '') !== 'expired')
            ? (string)$sub['valid_until']
            : $today;

        $newValidUntil = $this->calculateCycleExpiry($cycle, $baseDate);
        $newMax = $freeLimit + ($blocks * $unitSize);
        $totalCost = round($blocks * $unitPrice, 2);
        $startedAt = !empty($sub['started_at']) ? (string)$sub['started_at'] : $today;

        $this->db->execute(
            "UPDATE tenant_subscriptions
             SET max_customers_allowed = ?, amount_paid = amount_paid + ?,
                 billing_cycle = ?, started_at = ?, valid_until = ?, payment_status = 'active'
             WHERE tenant_id = ?",
            [$newMax, $totalCost, $cycle, $startedAt, $newValidUntil, $tenantId]
        );

        return $this->getTenantQuota($tenantId);
    }
}

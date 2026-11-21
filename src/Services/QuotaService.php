<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class QuotaService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getSystemSettings(): array
    {
        $rows = $this->db->fetchAll("SELECT setting_key, setting_value FROM system_settings");
        $settings = [
            'free_customer_limit' => 5,
            'paid_tier_unit_size' => 100,
            'paid_tier_unit_price' => 200.00,
            'currency_symbol' => '₹',
            'platform_name' => 'DoodhBee SaaS',
            'support_email' => 'support@doodhbee.com',
            'support_phone' => '+91 98000 11223',
            'payment_upi_id' => 'kaustubh51-3@okicici',
            'payment_account_name' => 'Shri Ram Open Source Technologies',
            'payment_bank_name' => 'Saraswat Co-operative Bank Ltd.',
            'payment_account_number' => '610000000039273',
            'payment_ifsc_code' => 'srcb0000370',
            'payment_qr_image_url' => '',
        ];
        foreach ($rows as $r) {
            $key = $r['setting_key'];
            $val = $r['setting_value'];
            if (in_array($key, ['free_customer_limit', 'paid_tier_unit_size'])) {
                $settings[$key] = (int)$val;
            } elseif ($key === 'paid_tier_unit_price') {
                $settings[$key] = (float)$val;
            } else {
                $settings[$key] = $val;
            }
        }
        return $settings;
    }

    public function getPlatformSettings(): array
    {
        return $this->getSystemSettings();
    }

    public function getFreeCustomerLimit(): int
    {
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
        return (float)($settings['paid_tier_unit_price'] ?? 200.00);
    }

    public function getTenantQuota(int $tenantId): array
    {
        $sys = $this->getSystemSettings();
        $freeLimit = (int)$sys['free_customer_limit'];
        $unitSize = (int)$sys['paid_tier_unit_size'];
        $unitPrice = (float)$sys['paid_tier_unit_price'];

        // Get tenant subscription
        $sub = $this->db->fetchOne(
            "SELECT * FROM tenant_subscriptions WHERE tenant_id = ? ORDER BY id DESC LIMIT 1",
            [$tenantId]
        );

        $purchasedBlocks = $sub ? (int)$sub['purchased_blocks'] : 0;
        $paymentStatus = $sub ? (string)$sub['payment_status'] : 'free';
        $validUntil = $sub['valid_until'] ?? null;

        // Max allowed customers
        $maxAllowed = $freeLimit + ($purchasedBlocks * $unitSize);

        // Count current active customers
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
            'active_customers' => $activeCustomers,
            'total_customers' => $totalCustomers,
            'max_allowed' => $maxAllowed,
            'free_limit' => $freeLimit,
            'purchased_blocks' => $purchasedBlocks,
            'unit_size' => $unitSize,
            'unit_price' => $unitPrice,
            'currency_symbol' => $sys['currency_symbol'],
            'payment_status' => $paymentStatus,
            'valid_until' => $validUntil,
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

    public function upgradeCapacity(int $tenantId, int $additionalBlocks): array
    {
        $sys = $this->getSystemSettings();
        $unitSize = (int)$sys['paid_tier_unit_size'];
        $unitPrice = (float)$sys['paid_tier_unit_price'];

        $sub = $this->db->fetchOne("SELECT * FROM tenant_subscriptions WHERE tenant_id = ?", [$tenantId]);
        $currentBlocks = $sub ? (int)$sub['purchased_blocks'] : 0;
        $newBlocks = $currentBlocks + $additionalBlocks;
        $freeLimit = (int)$sys['free_customer_limit'];
        $newMax = $freeLimit + ($newBlocks * $unitSize);
        $totalCost = $additionalBlocks * $unitPrice;
        $validUntil = date('Y-m-d', strtotime('+1 year'));

        if ($sub) {
            $this->db->execute(
                "UPDATE tenant_subscriptions 
                 SET purchased_blocks = ?, max_customers_allowed = ?, amount_paid = amount_paid + ?, valid_until = ?, payment_status = 'active'
                 WHERE tenant_id = ?",
                [$newBlocks, $newMax, $totalCost, $validUntil, $tenantId]
            );
        } else {
            $this->db->execute(
                "INSERT INTO tenant_subscriptions (tenant_id, purchased_blocks, max_customers_allowed, amount_paid, valid_until, payment_status)
                 VALUES (?, ?, ?, ?, ?, 'active')",
                [$tenantId, $newBlocks, $newMax, $totalCost, $validUntil]
            );
        }

        return $this->getTenantQuota($tenantId);
    }

    public function createUpgradeRequest(
        int $tenantId,
        int $blocks,
        string $utrNumber,
        string $paymentMethod = 'upi',
        ?string $notes = null,
        ?string $proofPath = null
    ): array {
        if ($blocks < 1) {
            throw new \InvalidArgumentException('At least 1 capacity block is required.');
        }

        $cleanUtr = trim($utrNumber);
        if (empty($cleanUtr)) {
            throw new \InvalidArgumentException('Payment Transaction ID / UTR number is required.');
        }

        // Prevent duplicate pending submission with identical UTR
        $existing = $this->db->fetchOne(
            "SELECT id FROM subscription_upgrade_requests WHERE utr_number = ? AND status = 'pending'",
            [$cleanUtr]
        );
        if ($existing) {
            throw new \InvalidArgumentException('An upgrade request with this UTR number is already pending verification.');
        }

        $sys = $this->getSystemSettings();
        $unitSize = (int)$sys['paid_tier_unit_size'];
        $unitPrice = (float)$sys['paid_tier_unit_price'];

        $customerSlots = $blocks * $unitSize;
        $amount = round($blocks * $unitPrice, 2);

        $this->db->execute(
            "INSERT INTO subscription_upgrade_requests 
             (tenant_id, blocks_requested, customer_slots, amount, payment_method, utr_number, notes, payment_proof_path, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
            [
                $tenantId,
                $blocks,
                $customerSlots,
                $amount,
                $paymentMethod ?: 'upi',
                $cleanUtr,
                $notes ?: null,
                $proofPath ?: null,
            ]
        );

        $id = (int)$this->db->getPdo()->lastInsertId();
        return $this->getUpgradeRequestById($id) ?? [];
    }

    public function getUpgradeRequestById(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT r.*, t.business_name, t.owner_name, t.phone, t.email
             FROM subscription_upgrade_requests r
             JOIN tenants t ON t.id = r.tenant_id
             WHERE r.id = ?",
            [$id]
        );
    }

    public function getTenantPendingRequest(int $tenantId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM subscription_upgrade_requests WHERE tenant_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1",
            [$tenantId]
        );
    }

    public function getTenantUpgradeRequests(int $tenantId, int $limit = 20): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM subscription_upgrade_requests WHERE tenant_id = ? ORDER BY id DESC LIMIT ?",
            [$tenantId, $limit]
        );
    }

    public function getAllUpgradeRequests(?string $status = null, int $limit = 100): array
    {
        $sql = "SELECT r.*, t.business_name, t.owner_name, t.phone, t.email,
                       ts.purchased_blocks as current_blocks, ts.max_customers_allowed as current_max
                FROM subscription_upgrade_requests r
                JOIN tenants t ON t.id = r.tenant_id
                LEFT JOIN tenant_subscriptions ts ON ts.tenant_id = t.id";
        $params = [];

        if ($status !== null && in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $sql .= " WHERE r.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY CASE WHEN r.status = 'pending' THEN 0 ELSE 1 END, r.id DESC LIMIT ?";
        $params[] = $limit;

        return $this->db->fetchAll($sql, $params);
    }

    public function approveUpgradeRequest(int $requestId, int $adminId, ?string $adminNotes = null): array
    {
        $req = $this->db->fetchOne(
            "SELECT * FROM subscription_upgrade_requests WHERE id = ?",
            [$requestId]
        );

        if (!$req) {
            throw new \InvalidArgumentException('Upgrade request not found.');
        }

        if ($req['status'] !== 'pending') {
            throw new \InvalidArgumentException("Cannot approve request: status is already '{$req['status']}'.");
        }

        // Apply capacity upgrade to tenant
        $tenantId = (int)$req['tenant_id'];
        $blocks = (int)$req['blocks_requested'];
        $freshQuota = $this->upgradeCapacity($tenantId, $blocks);

        // Mark request approved
        $this->db->execute(
            "UPDATE subscription_upgrade_requests 
             SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), admin_notes = ?
             WHERE id = ?",
            [$adminId, $adminNotes ?: 'Approved by SuperAdmin', $requestId]
        );

        return [
            'request' => $this->getUpgradeRequestById($requestId),
            'quota' => $freshQuota,
        ];
    }

    public function rejectUpgradeRequest(int $requestId, int $adminId, string $reason): array
    {
        $req = $this->db->fetchOne(
            "SELECT * FROM subscription_upgrade_requests WHERE id = ?",
            [$requestId]
        );

        if (!$req) {
            throw new \InvalidArgumentException('Upgrade request not found.');
        }

        if ($req['status'] !== 'pending') {
            throw new \InvalidArgumentException("Cannot reject request: status is already '{$req['status']}'.");
        }

        $this->db->execute(
            "UPDATE subscription_upgrade_requests 
             SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), admin_notes = ?
             WHERE id = ?",
            [$adminId, $reason, $requestId]
        );

        return [
            'request' => $this->getUpgradeRequestById($requestId),
        ];
    }
}

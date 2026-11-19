<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class StaffService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get all staff/delivery members for a tenant.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getStaffMembers(int $tenantId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT u.id, u.tenant_id, u.role, u.assigned_route_id, u.name, u.phone, u.email,
                    u.is_active, u.created_at, u.updated_at,
                    r.name AS assigned_route_name
             FROM tenant_users u
             LEFT JOIN routes r ON r.id = u.assigned_route_id
             WHERE u.tenant_id = ?
             ORDER BY u.role ASC, u.name ASC",
            [$tenantId]
        );

        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['tenant_id'] = (int)$r['tenant_id'];
            $r['assigned_route_id'] = $r['assigned_route_id'] ? (int)$r['assigned_route_id'] : null;
            $r['is_active'] = (int)$r['is_active'];
        }

        return $rows;
    }

    /**
     * Get a single staff member by ID and tenant ID.
     *
     * @return array<string, mixed>|null
     */
    public function getStaffById(int $id, int $tenantId): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT u.id, u.tenant_id, u.role, u.assigned_route_id, u.name, u.phone, u.email,
                    u.is_active, u.created_at, u.updated_at,
                    r.name AS assigned_route_name
             FROM tenant_users u
             LEFT JOIN routes r ON r.id = u.assigned_route_id
             WHERE u.id = ? AND u.tenant_id = ?",
            [$id, $tenantId]
        );

        if (!$row) {
            return null;
        }

        $row['id'] = (int)$row['id'];
        $row['tenant_id'] = (int)$row['tenant_id'];
        $row['assigned_route_id'] = $row['assigned_route_id'] ? (int)$row['assigned_route_id'] : null;
        $row['is_active'] = (int)$row['is_active'];

        return $row;
    }

    /**
     * Create a new staff/delivery user for a tenant.
     *
     * @return array<string, mixed>
     */
    public function createStaff(
        int $tenantId,
        string $name,
        string $phone,
        string $password,
        ?int $assignedRouteId = null,
        string $role = 'delivery',
        ?string $email = null
    ): array {
        $name = trim($name);
        $phone = preg_replace('/[^0-9]/', '', $phone) ?? '';
        $email = !empty($email) ? trim(strtolower($email)) : null;

        if (empty($name)) {
            throw new \InvalidArgumentException('Staff name is required.');
        }

        if (strlen($phone) < 10) {
            throw new \InvalidArgumentException('A valid 10-digit mobile number is required.');
        }

        if (strlen($password) < 4) {
            throw new \InvalidArgumentException('Password must be at least 4 characters long.');
        }

        if (!in_array($role, ['admin', 'delivery'], true)) {
            $role = 'delivery';
        }

        // Check unique phone within this tenant
        $existing = $this->db->fetchOne(
            "SELECT id FROM tenant_users WHERE tenant_id = ? AND phone = ?",
            [$tenantId, $phone]
        );

        if ($existing) {
            throw new \InvalidArgumentException("A staff member with phone '{$phone}' already exists in your dairy.");
        }

        // Validate assigned route if given
        if ($assignedRouteId !== null && $assignedRouteId > 0) {
            $routeCheck = $this->db->fetchOne(
                "SELECT id FROM routes WHERE id = ? AND tenant_id = ?",
                [$assignedRouteId, $tenantId]
            );
            if (!$routeCheck) {
                $assignedRouteId = null;
            }
        } else {
            $assignedRouteId = null;
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        $this->db->execute(
            "INSERT INTO tenant_users (tenant_id, role, assigned_route_id, name, phone, email, password_hash, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)",
            [$tenantId, $role, $assignedRouteId, $name, $phone, $email, $passwordHash]
        );

        $id = (int)$this->db->lastInsertId();

        return $this->getStaffById($id, $tenantId) ?? [];
    }

    /**
     * Update an existing staff member.
     *
     * @return array<string, mixed>
     */
    public function updateStaff(
        int $id,
        int $tenantId,
        string $name,
        string $phone,
        ?string $password = null,
        ?int $assignedRouteId = null,
        string $role = 'delivery',
        ?string $email = null
    ): array {
        $existing = $this->getStaffById($id, $tenantId);
        if (!$existing) {
            throw new \RuntimeException('Staff member not found.');
        }

        $name = trim($name);
        $phone = preg_replace('/[^0-9]/', '', $phone) ?? '';
        $email = !empty($email) ? trim(strtolower($email)) : null;

        if (empty($name)) {
            throw new \InvalidArgumentException('Staff name is required.');
        }

        if (strlen($phone) < 10) {
            throw new \InvalidArgumentException('A valid 10-digit mobile number is required.');
        }

        if (!in_array($role, ['admin', 'delivery'], true)) {
            $role = 'delivery';
        }

        // Check unique phone constraint (ignoring current user)
        $dup = $this->db->fetchOne(
            "SELECT id FROM tenant_users WHERE tenant_id = ? AND phone = ? AND id != ?",
            [$tenantId, $phone, $id]
        );

        if ($dup) {
            throw new \InvalidArgumentException("Another staff member already has the phone number '{$phone}'.");
        }

        // Validate assigned route if given
        if ($assignedRouteId !== null && $assignedRouteId > 0) {
            $routeCheck = $this->db->fetchOne(
                "SELECT id FROM routes WHERE id = ? AND tenant_id = ?",
                [$assignedRouteId, $tenantId]
            );
            if (!$routeCheck) {
                $assignedRouteId = null;
            }
        } else {
            $assignedRouteId = null;
        }

        if (!empty($password)) {
            if (strlen($password) < 4) {
                throw new \InvalidArgumentException('Password must be at least 4 characters long.');
            }
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $this->db->execute(
                "UPDATE tenant_users 
                 SET name = ?, phone = ?, email = ?, role = ?, assigned_route_id = ?, password_hash = ?
                 WHERE id = ? AND tenant_id = ?",
                [$name, $phone, $email, $role, $assignedRouteId, $passwordHash, $id, $tenantId]
            );
        } else {
            $this->db->execute(
                "UPDATE tenant_users 
                 SET name = ?, phone = ?, email = ?, role = ?, assigned_route_id = ?
                 WHERE id = ? AND tenant_id = ?",
                [$name, $phone, $email, $role, $assignedRouteId, $id, $tenantId]
            );
        }

        return $this->getStaffById($id, $tenantId) ?? [];
    }

    /**
     * Toggle staff member active/inactive status.
     *
     * @return array<string, mixed>
     */
    public function toggleStaff(int $id, int $tenantId): array
    {
        $existing = $this->db->fetchOne(
            "SELECT * FROM tenant_users WHERE id = ? AND tenant_id = ?",
            [$id, $tenantId]
        );

        if (!$existing) {
            throw new \RuntimeException('Staff member not found.');
        }

        $newStatus = $existing['is_active'] ? 0 : 1;

        $this->db->execute(
            "UPDATE tenant_users SET is_active = ? WHERE id = ? AND tenant_id = ?",
            [$newStatus, $id, $tenantId]
        );

        // If deactivated, revoke all their active tokens
        if ($newStatus === 0) {
            $this->db->execute(
                "DELETE FROM api_tokens WHERE user_id = ?",
                [$id]
            );
        }

        return $this->getStaffById($id, $tenantId) ?? [];
    }

    /**
     * Delete a staff member.
     *
     * @return bool
     */
    public function deleteStaff(int $id, int $tenantId): bool
    {
        $existing = $this->db->fetchOne(
            "SELECT * FROM tenant_users WHERE id = ? AND tenant_id = ?",
            [$id, $tenantId]
        );

        if (!$existing) {
            throw new \RuntimeException('Staff member not found.');
        }

        // Delete user (foreign keys on api_tokens delete cascade)
        $this->db->execute(
            "DELETE FROM tenant_users WHERE id = ? AND tenant_id = ?",
            [$id, $tenantId]
        );

        return true;
    }
}

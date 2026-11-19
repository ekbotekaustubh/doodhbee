<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class RouteService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Ensure the default "Main Route" exists for a tenant.
     *
     * @return array<string, mixed> The default route record
     */
    public function ensureDefaultRoute(int $tenantId): array
    {
        $mainRoute = $this->db->fetchOne(
            "SELECT * FROM routes WHERE tenant_id = ? AND name = 'Main Route'",
            [$tenantId]
        );

        if (!$mainRoute) {
            $this->db->execute(
                "INSERT INTO routes (tenant_id, name, description, is_active, sort_order)
                 VALUES (?, 'Main Route', 'Default primary delivery route', 1, 1)",
                [$tenantId]
            );

            $id = (int)$this->db->lastInsertId();
            $mainRoute = $this->db->fetchOne("SELECT * FROM routes WHERE id = ?", [$id]);
        }

        return $mainRoute;
    }

    /**
     * Get all delivery routes for a tenant with customer counts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTenantRoutes(int $tenantId, bool $activeOnly = false): array
    {
        $this->ensureDefaultRoute($tenantId);

        $sql = "SELECT r.*, 
                       COUNT(c.id) AS customer_count,
                       SUM(CASE WHEN c.status = 'active' THEN 1 ELSE 0 END) AS active_customer_count
                FROM routes r
                LEFT JOIN customers c ON c.route_id = r.id
                WHERE r.tenant_id = ?";

        if ($activeOnly) {
            $sql .= " AND r.is_active = 1";
        }

        $sql .= " GROUP BY r.id ORDER BY r.sort_order ASC, r.name ASC";

        $routes = $this->db->fetchAll($sql, [$tenantId]);

        return array_map(function ($route) {
            $route['customer_count'] = (int)($route['customer_count'] ?? 0);
            $route['active_customer_count'] = (int)($route['active_customer_count'] ?? 0);
            $route['is_active'] = (bool)$route['is_active'];
            $route['is_default'] = ($route['name'] === 'Main Route');
            return $route;
        }, $routes);
    }

    /**
     * Get a specific route by ID for a tenant.
     *
     * @return array<string, mixed>|null
     */
    public function getRouteById(int $id, int $tenantId): ?array
    {
        $route = $this->db->fetchOne(
            "SELECT r.*, 
                    COUNT(c.id) AS customer_count,
                    SUM(CASE WHEN c.status = 'active' THEN 1 ELSE 0 END) AS active_customer_count
             FROM routes r
             LEFT JOIN customers c ON c.route_id = r.id
             WHERE r.id = ? AND r.tenant_id = ?
             GROUP BY r.id",
            [$id, $tenantId]
        );

        if (!$route) {
            return null;
        }

        $route['customer_count'] = (int)($route['customer_count'] ?? 0);
        $route['active_customer_count'] = (int)($route['active_customer_count'] ?? 0);
        $route['is_active'] = (bool)$route['is_active'];
        $route['is_default'] = ($route['name'] === 'Main Route');

        return $route;
    }

    /**
     * Create a new route for a tenant.
     *
     * @return array<string, mixed>
     */
    public function createRoute(int $tenantId, string $name, ?string $description = null, int $sortOrder = 0): array
    {
        $name = trim($name);
        if (empty($name)) {
            throw new \InvalidArgumentException('Route name cannot be empty.');
        }

        $existing = $this->db->fetchOne(
            "SELECT id FROM routes WHERE tenant_id = ? AND name = ?",
            [$tenantId, $name]
        );

        if ($existing) {
            throw new \InvalidArgumentException("Route with name '{$name}' already exists for this tenant.");
        }

        if ($sortOrder === 0) {
            $maxOrder = (int)$this->db->fetchValue(
                "SELECT COALESCE(MAX(sort_order), 0) FROM routes WHERE tenant_id = ?",
                [$tenantId]
            );
            $sortOrder = $maxOrder + 1;
        }

        $this->db->execute(
            "INSERT INTO routes (tenant_id, name, description, is_active, sort_order)
             VALUES (?, ?, ?, 1, ?)",
            [$tenantId, $name, $description, $sortOrder]
        );

        $newId = (int)$this->db->lastInsertId();
        return $this->getRouteById($newId, $tenantId) ?? [];
    }

    /**
     * Update an existing route.
     *
     * @return array<string, mixed>
     */
    public function updateRoute(int $id, int $tenantId, string $name, ?string $description = null, ?int $sortOrder = null): array
    {
        $name = trim($name);
        if (empty($name)) {
            throw new \InvalidArgumentException('Route name cannot be empty.');
        }

        $route = $this->db->fetchOne(
            "SELECT * FROM routes WHERE id = ? AND tenant_id = ?",
            [$id, $tenantId]
        );

        if (!$route) {
            throw new \RuntimeException('Route not found.');
        }

        $existing = $this->db->fetchOne(
            "SELECT id FROM routes WHERE tenant_id = ? AND name = ? AND id != ?",
            [$tenantId, $name, $id]
        );

        if ($existing) {
            throw new \InvalidArgumentException("Another route with name '{$name}' already exists.");
        }

        $order = $sortOrder !== null ? $sortOrder : (int)$route['sort_order'];

        $this->db->execute(
            "UPDATE routes SET name = ?, description = ?, sort_order = ? WHERE id = ? AND tenant_id = ?",
            [$name, $description, $order, $id, $tenantId]
        );

        return $this->getRouteById($id, $tenantId) ?? [];
    }

    /**
     * Toggle route active status.
     *
     * @return array<string, mixed>
     */
    public function toggleRoute(int $id, int $tenantId): array
    {
        $route = $this->db->fetchOne(
            "SELECT * FROM routes WHERE id = ? AND tenant_id = ?",
            [$id, $tenantId]
        );

        if (!$route) {
            throw new \RuntimeException('Route not found.');
        }

        $newStatus = $route['is_active'] ? 0 : 1;

        $this->db->execute(
            "UPDATE routes SET is_active = ? WHERE id = ? AND tenant_id = ?",
            [$newStatus, $id, $tenantId]
        );

        return $this->getRouteById($id, $tenantId) ?? [];
    }

    /**
     * Delete a route.
     *
     * @return bool
     */
    public function deleteRoute(int $id, int $tenantId): bool
    {
        $route = $this->db->fetchOne(
            "SELECT * FROM routes WHERE id = ? AND tenant_id = ?",
            [$id, $tenantId]
        );

        if (!$route) {
            throw new \RuntimeException('Route not found.');
        }

        if ($route['name'] === 'Main Route') {
            throw new \InvalidArgumentException("Default 'Main Route' cannot be deleted. You can rename or deactivate it instead.");
        }

        $assignedCustomers = (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM customers WHERE route_id = ? AND tenant_id = ?",
            [$id, $tenantId]
        );

        if ($assignedCustomers > 0) {
            throw new \InvalidArgumentException("Cannot delete route '{$route['name']}': {$assignedCustomers} customer(s) are currently assigned to it. Reassign customers before deleting.");
        }

        $this->db->execute(
            "DELETE FROM routes WHERE id = ? AND tenant_id = ?",
            [$id, $tenantId]
        );

        return true;
    }
}

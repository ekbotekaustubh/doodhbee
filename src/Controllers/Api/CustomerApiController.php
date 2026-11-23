<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Services\DeliveryService;
use App\Services\ProductService;
use App\Services\QuotaService;
use App\Services\RouteService;

final class CustomerApiController extends ApiBaseController
{
    private QuotaService $quotaService;
    private ProductService $productService;
    private RouteService $routeService;
    private DeliveryService $deliveryService;

    public function __construct()
    {
        parent::__construct();
        $this->quotaService = new QuotaService();
        $this->productService = new ProductService();
        $this->routeService = new RouteService();
        $this->deliveryService = new DeliveryService();
    }

    public function index(Request $request): void
    {
        $tenantId = $this->tenantId();
        $search = trim((string)$request->get('q', ''));
        $route = trim((string)$request->get('route', ''));
        $routeId = (int)$request->get('route_id', 0);
        $status = trim((string)$request->get('status', ''));
        $milkType = trim((string)$request->get('milk_type', ''));

        $sql = "SELECT c.*, r.name AS route_name 
                FROM customers c 
                LEFT JOIN routes r ON r.id = c.route_id 
                WHERE c.tenant_id = ?";
        $params = [$tenantId];

        if (!empty($search)) {
            $sql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        if ($routeId > 0) {
            $sql .= " AND c.route_id = ?";
            $params[] = $routeId;
        } elseif (!empty($route)) {
            if (is_numeric($route)) {
                $sql .= " AND (c.route_id = ? OR r.name = ?)";
                $params[] = (int)$route;
                $params[] = $route;
            } else {
                $sql .= " AND r.name = ?";
                $params[] = $route;
            }
        }

        if (!empty($status)) {
            $sql .= " AND c.status = ?";
            $params[] = $status;
        }

        if (!empty($milkType)) {
            if ($milkType === 'both') {
                $sql .= " AND (c.milk_types LIKE '%cow%' AND c.milk_types LIKE '%buffalo%')";
            } elseif ($milkType === 'cow') {
                $sql .= " AND c.milk_types LIKE '%cow%'";
            } elseif ($milkType === 'buffalo') {
                $sql .= " AND c.milk_types LIKE '%buffalo%'";
            }
        }

        $sql .= " ORDER BY r.name ASC, c.name ASC";
        $customers = $this->db->fetchAll($sql, $params);

        // Fetch subscriptions for all customers
        $subscriptions = $this->db->fetchAll(
            "SELECT cs.*, p.name as product_name, p.code as product_code, p.unit, p.default_rate as product_default_rate,
                    COALESCE(cs.custom_rate, p.default_rate) as effective_rate
             FROM customer_subscriptions cs
             JOIN products p ON p.id = cs.product_id
             WHERE cs.tenant_id = ? AND cs.is_active = 1",
            [$tenantId]
        );

        $subsMap = [];
        foreach ($subscriptions as $s) {
            $subsMap[(int)$s['customer_id']][] = [
                'id' => (int)$s['id'],
                'product_id' => (int)$s['product_id'],
                'product_name' => $s['product_name'],
                'product_code' => $s['product_code'],
                'unit' => $s['unit'],
                'default_quantity' => (float)$s['default_quantity'],
                'custom_rate' => $s['custom_rate'] !== null ? (float)$s['custom_rate'] : null,
                'effective_rate' => (float)$s['effective_rate'],
                'product_default_rate' => (float)$s['product_default_rate'],
                'shift' => $s['shift'],
            ];
        }

        foreach ($customers as &$c) {
            $cid = (int)$c['id'];
            $c['subscriptions'] = $subsMap[$cid] ?? [];
            $c['total_daily_quantity'] = array_sum(array_column($c['subscriptions'], 'default_quantity'));
        }

        $quota = $this->quotaService->getTenantQuota($tenantId);

        $this->jsonSuccess($customers, 'Customers retrieved', 200, [
            'total_count' => count($customers),
            'quota' => $quota,
        ]);
    }

    public function show(Request $request, array $params): void
    {
        $tenantId = $this->tenantId();
        $id = (int)($params['id'] ?? 0);

        $customer = $this->db->fetchOne(
            "SELECT c.*, r.name AS route_name 
             FROM customers c 
             LEFT JOIN routes r ON r.id = c.route_id 
             WHERE c.id = ? AND c.tenant_id = ?",
            [$id, $tenantId]
        );

        if (!$customer) {
            $this->jsonError('Customer not found.', 404, 'NOT_FOUND');
        }

        $subscriptions = $this->db->fetchAll(
            "SELECT cs.*, p.name as product_name, p.code as product_code, p.unit, p.default_rate as product_default_rate,
                    COALESCE(cs.custom_rate, p.default_rate) as effective_rate
             FROM customer_subscriptions cs
             JOIN products p ON p.id = cs.product_id
             WHERE cs.tenant_id = ? AND cs.customer_id = ?",
            [$tenantId, $id]
        );

        if (empty($subscriptions)) {
            $subscriptions = $this->productService->getCustomerSubscriptions($tenantId, $id);
        }

        $leaves = $this->db->fetchAll(
            "SELECT * FROM customer_leaves WHERE tenant_id = ? AND customer_id = ? ORDER BY start_date DESC",
            [$tenantId, $id]
        );

        $recentInvoices = $this->db->fetchAll(
            "SELECT id, billing_month, billing_year, total_units, final_amount, payment_status, generated_at
             FROM invoices WHERE tenant_id = ? AND customer_id = ? ORDER BY billing_year DESC, billing_month DESC LIMIT 6",
            [$tenantId, $id]
        );

        $customer['subscriptions'] = $subscriptions;
        $customer['leaves'] = $leaves;
        $customer['recent_invoices'] = $recentInvoices;

        $this->jsonSuccess($customer);
    }

    public function store(Request $request): void
    {
        Auth::requireApiTenantAdmin();
        $tenantId = $this->tenantId();
        $name = trim((string)$request->input('name', ''));
        $phone = trim((string)$request->input('phone', ''));
        $address = trim((string)$request->input('address', ''));
        $routeId = (int)$request->input('route_id', 0);
        $routeName = trim((string)$request->input('route_name', ''));
        $status = strtolower(trim((string)$request->input('status', 'active')));
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        if ($routeId <= 0) {
            if (!empty($routeName)) {
                $existingRoute = $this->db->fetchOne("SELECT id FROM routes WHERE tenant_id = ? AND name = ?", [$tenantId, $routeName]);
                if ($existingRoute) {
                    $routeId = (int)$existingRoute['id'];
                } else {
                    $newR = $this->routeService->createRoute($tenantId, $routeName);
                    $routeId = (int)$newR['id'];
                }
            } else {
                $mainRoute = $this->routeService->ensureDefaultRoute($tenantId);
                $routeId = (int)$mainRoute['id'];
            }
        } else {
            $rCheck = $this->db->fetchOne("SELECT id FROM routes WHERE id = ? AND tenant_id = ?", [$routeId, $tenantId]);
            if (!$rCheck) {
                $mainRoute = $this->routeService->ensureDefaultRoute($tenantId);
                $routeId = (int)$mainRoute['id'];
            }
        }

        $errors = $this->validate([
            'name' => $name,
            'phone' => $phone,
        ], [
            'name' => 'required',
            'phone' => 'required',
        ]);

        if (!empty($errors)) {
            $this->jsonError('Validation failed', 422, 'VALIDATION_ERROR', $errors);
        }

        // Quota Guard Check
        if ($status === 'active' && !$this->quotaService->canCreateCustomer($tenantId)) {
            $quota = $this->quotaService->getTenantQuota($tenantId);
            $this->jsonError(
                'Customer limit reached for your current subscription plan. Please upgrade your quota capacity.',
                403,
                'QUOTA_EXCEEDED',
                ['quota' => $quota, 'upgrade_url' => '/api/subscription/upgrade']
            );
        }

        // Subscriptions payload parsing
        $subsInput = $request->input('subscriptions', []);
        $subsList = [];

        if (is_array($subsInput) && !empty($subsInput)) {
            foreach ($subsInput as $sub) {
                $pid = (int)($sub['product_id'] ?? 0);
                $qty = (float)($sub['quantity'] ?? 0.0);
                $rate = isset($sub['custom_rate']) && $sub['custom_rate'] !== '' && (float)$sub['custom_rate'] > 0 ? (float)$sub['custom_rate'] : null;
                $shift = (string)($sub['shift'] ?? 'both');
                $isActive = !isset($sub['is_active']) || !empty($sub['is_active']);

                if ($pid > 0 && $qty > 0 && $isActive) {
                    $subsList[] = [
                        'product_id' => $pid,
                        'quantity' => round($qty, 2),
                        'custom_rate' => $rate,
                        'shift' => in_array($shift, ['morning', 'evening', 'both'], true) ? $shift : 'both',
                        'is_active' => true,
                    ];
                }
            }
        }

        // Fallback default cow subscription if none provided
        if (empty($subsList)) {
            $this->productService->ensureDefaultProducts($tenantId);
            $cowProd = $this->db->fetchOne("SELECT id FROM products WHERE tenant_id = ? AND code = 'cow'", [$tenantId]);
            if ($cowProd) {
                $subsList[] = [
                    'product_id' => (int)$cowProd['id'],
                    'quantity' => 1.0,
                    'custom_rate' => null,
                    'shift' => 'both',
                    'is_active' => true,
                ];
            }
        }

        $newCustomerId = 0;
        $this->db->transaction(function () use ($tenantId, $routeId, $name, $phone, $address, $status, $subsList, &$newCustomerId) {
            $this->db->execute(
                "INSERT INTO customers (tenant_id, route_id, name, phone, address, milk_types, status)
                 VALUES (?, ?, ?, ?, ?, 'cow', ?)",
                [$tenantId, $routeId, $name, $phone, $address, $status]
            );
            $newCustomerId = (int)$this->db->lastInsertId();
            $this->productService->saveCustomerSubscriptions($tenantId, $newCustomerId, $subsList);
        });

        $created = $this->db->fetchOne(
            "SELECT c.*, r.name AS route_name 
             FROM customers c 
             LEFT JOIN routes r ON r.id = c.route_id 
             WHERE c.id = ? AND c.tenant_id = ?",
            [$newCustomerId, $tenantId]
        );
        $created['subscriptions'] = $subsList;

        $this->jsonSuccess($created, 'Customer created successfully', 201);
    }

    public function update(Request $request, array $params): void
    {
        Auth::requireApiTenantAdmin();
        $tenantId = $this->tenantId();
        $id = (int)($params['id'] ?? 0);

        $customer = $this->db->fetchOne(
            "SELECT c.*, r.name AS route_name 
             FROM customers c 
             LEFT JOIN routes r ON r.id = c.route_id 
             WHERE c.id = ? AND c.tenant_id = ?",
            [$id, $tenantId]
        );

        if (!$customer) {
            $this->jsonError('Customer not found.', 404, 'NOT_FOUND');
        }

        $name = trim((string)$request->input('name', $customer['name']));
        $phone = trim((string)$request->input('phone', $customer['phone']));
        $address = trim((string)$request->input('address', $customer['address']));
        $routeId = (int)$request->input('route_id', 0);
        $routeName = trim((string)$request->input('route_name', ''));
        $status = strtolower(trim((string)$request->input('status', $customer['status'])));
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        if ($routeId <= 0) {
            if (!empty($routeName)) {
                $existingRoute = $this->db->fetchOne("SELECT id FROM routes WHERE tenant_id = ? AND name = ?", [$tenantId, $routeName]);
                if ($existingRoute) {
                    $routeId = (int)$existingRoute['id'];
                } else {
                    $newR = $this->routeService->createRoute($tenantId, $routeName);
                    $routeId = (int)$newR['id'];
                }
            } else {
                $routeId = (int)($customer['route_id'] ?? 0);
                if ($routeId <= 0) {
                    $mainRoute = $this->routeService->ensureDefaultRoute($tenantId);
                    $routeId = (int)$mainRoute['id'];
                }
            }
        } else {
            $rCheck = $this->db->fetchOne("SELECT id FROM routes WHERE id = ? AND tenant_id = ?", [$routeId, $tenantId]);
            if (!$rCheck) {
                $routeId = (int)($customer['route_id'] ?? 0);
            }
        }

        $errors = $this->validate([
            'name' => $name,
            'phone' => $phone,
        ], [
            'name' => 'required',
            'phone' => 'required',
        ]);

        if (!empty($errors)) {
            $this->jsonError('Validation failed', 422, 'VALIDATION_ERROR', $errors);
        }

        // Quota Guard Check on activation
        if ($customer['status'] === 'inactive' && $status === 'active' && !$this->quotaService->canCreateCustomer($tenantId)) {
            $quota = $this->quotaService->getTenantQuota($tenantId);
            $this->jsonError(
                'Cannot activate customer: Active customer limit reached for your plan.',
                403,
                'QUOTA_EXCEEDED',
                ['quota' => $quota]
            );
        }

        $subsInput = $request->input('subscriptions', null);

        $this->db->transaction(function () use ($tenantId, $id, $routeId, $name, $phone, $address, $status, $subsInput) {
            $this->db->execute(
                "UPDATE customers 
                 SET route_id = ?, name = ?, phone = ?, address = ?, status = ?
                 WHERE id = ? AND tenant_id = ?",
                [$routeId, $name, $phone, $address, $status, $id, $tenantId]
            );

            if ($subsInput !== null && is_array($subsInput)) {
                $subsList = [];
                foreach ($subsInput as $sub) {
                    $pid = (int)($sub['product_id'] ?? 0);
                    $qty = (float)($sub['quantity'] ?? 0.0);
                    $rate = isset($sub['custom_rate']) && $sub['custom_rate'] !== '' && (float)$sub['custom_rate'] > 0 ? (float)$sub['custom_rate'] : null;
                    $shift = (string)($sub['shift'] ?? 'both');
                    $isActive = !isset($sub['is_active']) || !empty($sub['is_active']);

                    if ($pid > 0 && $qty > 0 && $isActive) {
                        $subsList[] = [
                            'product_id' => $pid,
                            'quantity' => round($qty, 2),
                            'custom_rate' => $rate,
                            'shift' => in_array($shift, ['morning', 'evening', 'both'], true) ? $shift : 'both',
                            'is_active' => true,
                        ];
                    }
                }
                if (!empty($subsList)) {
                    $this->productService->saveCustomerSubscriptions($tenantId, $id, $subsList);
                }
            }
        });

        $updated = $this->db->fetchOne(
            "SELECT c.*, r.name AS route_name 
             FROM customers c 
             LEFT JOIN routes r ON r.id = c.route_id 
             WHERE c.id = ? AND c.tenant_id = ?",
            [$id, $tenantId]
        );
        $updated['subscriptions'] = $this->db->fetchAll("SELECT * FROM customer_subscriptions WHERE customer_id = ? AND tenant_id = ?", [$id, $tenantId]);

        $this->jsonSuccess($updated, 'Customer updated successfully');
    }

    public function toggleStatus(Request $request, array $params): void
    {
        Auth::requireApiTenantAdmin();
        $tenantId = $this->tenantId();
        $id = (int)($params['id'] ?? 0);

        $customer = $this->db->fetchOne(
            "SELECT c.*, r.name AS route_name 
             FROM customers c 
             LEFT JOIN routes r ON r.id = c.route_id 
             WHERE c.id = ? AND c.tenant_id = ?",
            [$id, $tenantId]
        );

        if (!$customer) {
            $this->jsonError('Customer not found.', 404, 'NOT_FOUND');
        }

        $newStatus = $customer['status'] === 'active' ? 'inactive' : 'active';

        if ($newStatus === 'active' && !$this->quotaService->canCreateCustomer($tenantId)) {
            $quota = $this->quotaService->getTenantQuota($tenantId);
            $this->jsonError('Cannot activate customer: quota limit reached.', 403, 'QUOTA_EXCEEDED', ['quota' => $quota]);
        }

        $this->db->execute(
            "UPDATE customers SET status = ? WHERE id = ? AND tenant_id = ?",
            [$newStatus, $id, $tenantId]
        );

        $customer['status'] = $newStatus;
        $this->jsonSuccess($customer, "Customer status updated to {$newStatus}");
    }

    public function routes(Request $request): void
    {
        $tenantId = $this->tenantId();
        $routes = $this->routeService->getTenantRoutes($tenantId, true);
        $list = array_column($routes, 'name');
        $this->jsonSuccess($list);
    }

    public function monthDeliveries(Request $request, array $params): void
    {
        $tenantId = $this->tenantId();
        $customerId = (int)($params['id'] ?? 0);
        $year = (int)$request->get('year', date('Y'));
        $month = (int)$request->get('month', date('n'));

        if ($year < 2000 || $year > 2100) {
            $this->jsonError('Invalid year parameter.', 422, 'VALIDATION_ERROR');
            return;
        }
        if ($month < 1 || $month > 12) {
            $this->jsonError('Invalid month parameter.', 422, 'VALIDATION_ERROR');
            return;
        }

        try {
            $sheet = $this->deliveryService->getCustomerMonthSheet($tenantId, $customerId, $year, $month);
        } catch (\InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 404, 'NOT_FOUND');
            return;
        } catch (\Throwable $e) {
            $this->jsonError('Failed to fetch customer month deliveries: ' . $e->getMessage(), 500, 'SERVER_ERROR');
            return;
        }

        $this->jsonSuccess($sheet);
    }

    public function saveMonthDeliveries(Request $request, array $params): void
    {
        $tenantId = $this->tenantId();
        $customerId = (int)($params['id'] ?? 0);
        $year = (int)$request->input('year', date('Y'));
        $month = (int)$request->input('month', date('n'));
        $days = (array)$request->input('days', []);

        if ($year < 2000 || $year > 2100) {
            $this->jsonError('Invalid year parameter.', 422, 'VALIDATION_ERROR');
            return;
        }
        if ($month < 1 || $month > 12) {
            $this->jsonError('Invalid month parameter.', 422, 'VALIDATION_ERROR');
            return;
        }

        try {
            $sheet = $this->deliveryService->saveCustomerMonthSheet($tenantId, $customerId, $year, $month, $days);
        } catch (\InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 404, 'NOT_FOUND');
            return;
        } catch (\Throwable $e) {
            $this->jsonError('Failed to save customer month deliveries: ' . $e->getMessage(), 500, 'SERVER_ERROR');
            return;
        }

        $this->jsonSuccess($sheet, 'Customer monthly milk card updated successfully.');
    }
}

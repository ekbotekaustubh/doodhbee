<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Enums\DeliveryShift;
use App\Enums\DeliveryStatus;

final class DeliveryService
{
    private Database $db;
    private ProductService $productService;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->productService = new ProductService();
    }

    public function getRunSheet(
        int $tenantId,
        string $date,
        string $shift,
        ?string $routeFilter = null,
        ?string $search = null,
        ?int $page = null,
        int $perPage = 25
    ): array
    {
        // Ensure default products exist for this tenant
        $this->productService->ensureDefaultProducts($tenantId);

        // 1. Fetch all active customers for this tenant
        $sql = "SELECT c.*, r.name AS route_name,
                    cl.id AS leave_id, cl.reason AS leave_reason,
                    dd.id AS delivery_id, dd.quantity AS recorded_quantity, 
                    dd.status AS recorded_status, dd.notes AS delivery_notes,
                    dd.updated_at AS delivery_updated_at
                FROM customers c
                LEFT JOIN routes r ON r.id = c.route_id
                LEFT JOIN customer_leaves cl ON cl.customer_id = c.id 
                    AND cl.tenant_id = c.tenant_id 
                    AND ? BETWEEN cl.start_date AND cl.end_date
                LEFT JOIN daily_deliveries dd ON dd.customer_id = c.id 
                    AND dd.tenant_id = c.tenant_id 
                    AND dd.delivery_date = ? 
                    AND dd.shift = ?
                WHERE c.tenant_id = ? AND c.status = 'active'";
        
        $params = [$date, $date, $shift, $tenantId];

        if (!empty($routeFilter)) {
            if (is_numeric($routeFilter)) {
                $sql .= " AND (c.route_id = ? OR r.name = ?)";
                $params[] = (int)$routeFilter;
                $params[] = $routeFilter;
            } else {
                $sql .= " AND r.name = ?";
                $params[] = $routeFilter;
            }
        }

        if (!empty($search)) {
            $searchWildcard = '%' . trim($search) . '%';
            $sql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ?)";
            $params[] = $searchWildcard;
            $params[] = $searchWildcard;
            $params[] = $searchWildcard;
        }

        $sql .= " ORDER BY r.sort_order ASC, r.name ASC, c.name ASC";

        $rows = $this->db->fetchAll($sql, $params);

        // Fetch all active subscriptions for this tenant
        $allSubs = $this->db->fetchAll(
            "SELECT cs.*, p.name AS product_name, p.code AS product_code, p.unit, p.default_rate AS product_default_rate
             FROM customer_subscriptions cs
             JOIN products p ON p.id = cs.product_id
             WHERE cs.tenant_id = ? AND cs.is_active = 1
             ORDER BY p.sort_order ASC, p.name ASC",
            [$tenantId]
        );
        $subsByCust = [];
        foreach ($allSubs as $s) {
            $subsByCust[(int)$s['customer_id']][] = $s;
        }

        // Fetch existing daily_delivery_items for this date and shift
        $allDelivItems = $this->db->fetchAll(
            "SELECT ddi.*, p.name as product_name, p.code as product_code, p.unit
             FROM daily_delivery_items ddi
             JOIN daily_deliveries dd ON dd.id = ddi.delivery_id
             JOIN products p ON p.id = ddi.product_id
             WHERE dd.tenant_id = ? AND dd.delivery_date = ? AND dd.shift = ?",
            [$tenantId, $date, $shift]
        );
        $itemsByDeliv = [];
        foreach ($allDelivItems as $di) {
            $itemsByDeliv[(int)$di['delivery_id']][] = $di;
        }

        // Process rows into normalized delivery items
        $items = [];
        $deliveredCount = 0;
        $skippedCount = 0;
        $leaveCount = 0;
        $pendingCount = 0;
        $totalLiters = 0.0;
        $productTotals = [];

        foreach ($rows as $row) {
            $cId = (int)$row['id'];
            $isOnLeave = !empty($row['leave_id']);
            $hasRecord = !empty($row['delivery_id']);

            $status = null;
            $quantity = 0.0;
            $notes = $row['delivery_notes'] ?? '';

            // Get customer subscriptions for this shift
            $custSubs = $subsByCust[$cId] ?? [];
            if (empty($custSubs)) {
                // Auto-sync if not present yet
                $this->productService->syncSubscriptionsFromLegacyCustomer($tenantId, $row);
                $custSubs = $this->productService->getCustomerSubscriptions($tenantId, $cId);
            }

            // Filter subscriptions valid for this shift
            $shiftSubs = array_filter($custSubs, function($s) use ($shift) {
                return in_array($s['shift'] ?? 'both', ['both', $shift], true);
            });
            if (empty($shiftSubs)) {
                $shiftSubs = $custSubs;
            }

            // Prepare products list for this row
            $rowProducts = [];
            if ($hasRecord) {
                $status = (string)$row['recorded_status'];
                $quantity = (float)$row['recorded_quantity'];

                $delivItems = $itemsByDeliv[(int)$row['delivery_id']] ?? [];
                if (!empty($delivItems)) {
                    foreach ($delivItems as $di) {
                        $pQty = (float)$di['quantity'];
                        $rowProducts[] = [
                            'product_id' => (int)$di['product_id'],
                            'name' => (string)$di['product_name'],
                            'code' => (string)$di['product_code'],
                            'unit' => (string)$di['unit'],
                            'quantity' => $pQty,
                            'unit_price' => (float)$di['unit_price'],
                            'status' => (string)$di['status'],
                        ];
                    }
                } else {
                    // Fallback from subscriptions
                    foreach ($shiftSubs as $sub) {
                        $pQty = ($status === DeliveryStatus::Delivered->value) ? (float)$sub['default_quantity'] : 0.0;
                        $rowProducts[] = [
                            'product_id' => (int)$sub['product_id'],
                            'name' => (string)$sub['product_name'],
                            'code' => (string)$sub['product_code'],
                            'unit' => (string)$sub['unit'],
                            'quantity' => $pQty,
                            'unit_price' => (float)($sub['custom_rate'] ?? $sub['product_default_rate']),
                            'status' => $status,
                        ];
                    }
                }
            } elseif ($isOnLeave) {
                $status = DeliveryStatus::Leave->value;
                $quantity = 0.00;
                $notes = $row['leave_reason'] ?? 'Customer on scheduled leave';
                foreach ($shiftSubs as $sub) {
                    $rowProducts[] = [
                        'product_id' => (int)$sub['product_id'],
                        'name' => (string)$sub['product_name'],
                        'code' => (string)$sub['product_code'],
                        'unit' => (string)$sub['unit'],
                        'quantity' => 0.00,
                        'unit_price' => (float)($sub['custom_rate'] ?? $sub['product_default_rate']),
                        'status' => 'leave',
                    ];
                }
            } else {
                $status = 'pending';
                // Sum default quantity from shift subscriptions
                $defaultSum = 0.0;
                foreach ($shiftSubs as $sub) {
                    $pQty = (float)$sub['default_quantity'];
                    $defaultSum += $pQty;
                    $rowProducts[] = [
                        'product_id' => (int)$sub['product_id'],
                        'name' => (string)$sub['product_name'],
                        'code' => (string)$sub['product_code'],
                        'unit' => (string)$sub['unit'],
                        'quantity' => $pQty,
                        'unit_price' => (float)($sub['custom_rate'] ?? $sub['product_default_rate']),
                        'status' => 'pending',
                    ];
                }
                $quantity = $defaultSum;
            }

            // Stats
            match ($status) {
                DeliveryStatus::Delivered->value => ($deliveredCount++ && ($totalLiters += $quantity)),
                DeliveryStatus::Skipped->value => $skippedCount++,
                DeliveryStatus::Leave->value => $leaveCount++,
                default => $pendingCount++,
            };

            // Aggregate product-level delivered metrics for packing/procurement
            if ($status === DeliveryStatus::Delivered->value) {
                foreach ($rowProducts as $rp) {
                    $pName = $rp['name'];
                    if (!isset($productTotals[$pName])) {
                        $productTotals[$pName] = ['quantity' => 0.0, 'unit' => $rp['unit']];
                    }
                    $productTotals[$pName]['quantity'] += (float)$rp['quantity'];
                }
            }

            $items[] = [
                'customer_id' => $cId,
                'customer_name' => (string)$row['name'],
                'phone' => (string)$row['phone'],
                'address' => (string)($row['address'] ?? ''),
                'route_name' => (string)($row['route_name'] ?? 'Main Route'),
                'milk_types' => (string)($row['milk_types'] ?? 'cow'),
                'is_on_leave' => $isOnLeave,
                'leave_reason' => $row['leave_reason'] ?? null,
                'delivery_id' => $row['delivery_id'] ? (int)$row['delivery_id'] : null,
                'status' => $status,
                'quantity' => $quantity,
                'notes' => $notes,
                'products' => $rowProducts,
                'updated_at' => $row['delivery_updated_at'] ?? null,
            ];
        }

        // Available routes for filter dropdown
        $routes = $this->db->fetchAll(
            "SELECT id, name FROM routes WHERE tenant_id = ? AND is_active = 1 ORDER BY sort_order ASC, name ASC",
            [$tenantId]
        );
        $routeList = array_column($routes, 'name');

        $totalHouses = count($items);
        $pagination = null;
        $returnedItems = $items;

        if ($page !== null) {
            $page = max(1, $page);
            $perPage = ($perPage > 0) ? $perPage : 25;
            $totalPages = max(1, (int)ceil($totalHouses / $perPage));
            $offset = ($page - 1) * $perPage;
            $returnedItems = array_slice($items, $offset, $perPage);

            $pagination = [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_items' => $totalHouses,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1,
            ];
        } else {
            $pagination = [
                'current_page' => 1,
                'per_page' => $totalHouses,
                'total_items' => $totalHouses,
                'total_pages' => 1,
                'has_next' => false,
                'has_prev' => false,
            ];
        }

        return [
            'date' => $date,
            'shift' => $shift,
            'items' => $returnedItems,
            'routes' => $routeList,
            'route_details' => $routes,
            'stats' => [
                'total_houses' => $totalHouses,
                'delivered' => $deliveredCount,
                'skipped' => $skippedCount,
                'leave' => $leaveCount,
                'pending' => $pendingCount,
                'total_liters' => round($totalLiters, 2),
                'product_totals' => $productTotals,
            ],
            'pagination' => $pagination,
            'pending_alert' => $pendingCount > 0 ? "{$pendingCount} houses are still pending verification for this " . ($shift === 'morning' ? 'Morning' : 'Evening') . " shift!" : null,
        ];
    }

    public function getShiftStats(int $tenantId, string $date, string $shift): array
    {
        $sheet = $this->getRunSheet($tenantId, $date, $shift);
        return $sheet['stats'];
    }

    public function recordDelivery(
        int $tenantId,
        int $customerId,
        string $date,
        string $shift,
        string $status,
        ?float $quantity = null,
        ?string $notes = null,
        ?array $productItems = null
    ): array {
        // Multi-tenant isolation guard: Verify customer belongs to tenant
        $customer = $this->db->fetchOne(
            "SELECT id FROM customers WHERE id = ? AND tenant_id = ?",
            [$customerId, $tenantId]
        );
        if (!$customer) {
            throw new \InvalidArgumentException("Customer #{$customerId} does not exist or unauthorized for tenant #{$tenantId}.");
        }

        $this->productService->ensureDefaultProducts($tenantId);

        // Fetch customer subscriptions
        $subs = $this->productService->getCustomerSubscriptions($tenantId, $customerId);

        // Enforce Zero-Charge Rule: Missed or paused days are strictly logged with 0 quantity billed
        $isZeroCharge = ($status === DeliveryStatus::Skipped->value || $status === DeliveryStatus::Leave->value);

        $lineItemsToSave = [];
        $finalTotalQty = 0.0;

        if ($isZeroCharge) {
            $finalTotalQty = 0.00;
            foreach ($subs as $sub) {
                $lineItemsToSave[] = [
                    'product_id' => (int)$sub['product_id'],
                    'quantity' => 0.00,
                    'unit_price' => (float)($sub['custom_rate'] ?? $sub['product_default_rate']),
                    'status' => $status,
                    'notes' => $notes,
                ];
            }
        } elseif (!empty($productItems)) {
            // Explicit product items passed (e.g. from multi-product adjustment)
            foreach ($subs as $sub) {
                $pId = (int)$sub['product_id'];
                $pRate = (float)($sub['custom_rate'] ?? $sub['product_default_rate']);

                $pQty = isset($productItems[$pId]) ? (float)$productItems[$pId] : (float)$sub['default_quantity'];
                $pQty = max(0.0, round($pQty, 2));
                $pStatus = $pQty > 0 ? DeliveryStatus::Delivered->value : DeliveryStatus::Skipped->value;

                $finalTotalQty += $pQty;
                $lineItemsToSave[] = [
                    'product_id' => $pId,
                    'quantity' => $pQty,
                    'unit_price' => $pRate,
                    'status' => $pStatus,
                    'notes' => $notes,
                ];
            }
        } else {
            // Delivered with either explicit total quantity or default subscriptions
            if ($quantity !== null && $quantity >= 0) {
                $finalTotalQty = round($quantity, 2);
                // Distribute or assign to subscriptions
                $defaultTotal = array_sum(array_column($subs, 'default_quantity'));
                foreach ($subs as $sub) {
                    $defaultSub = (float)$sub['default_quantity'];
                    $ratio = $defaultTotal > 0 ? ($defaultSub / $defaultTotal) : (1.0 / max(1, count($subs)));
                    $pQty = round($finalTotalQty * $ratio, 2);
                    $lineItemsToSave[] = [
                        'product_id' => (int)$sub['product_id'],
                        'quantity' => $pQty,
                        'unit_price' => (float)($sub['custom_rate'] ?? $sub['product_default_rate']),
                        'status' => $pQty > 0 ? DeliveryStatus::Delivered->value : DeliveryStatus::Skipped->value,
                        'notes' => $notes,
                    ];
                }
            } else {
                foreach ($subs as $sub) {
                    $pQty = (float)$sub['default_quantity'];
                    $finalTotalQty += $pQty;
                    $lineItemsToSave[] = [
                        'product_id' => (int)$sub['product_id'],
                        'quantity' => $pQty,
                        'unit_price' => (float)($sub['custom_rate'] ?? $sub['product_default_rate']),
                        'status' => DeliveryStatus::Delivered->value,
                        'notes' => $notes,
                    ];
                }
            }
        }

        // 1. Insert or update daily_deliveries header
        $sql = "INSERT INTO daily_deliveries (tenant_id, customer_id, delivery_date, shift, quantity, status, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    quantity = VALUES(quantity),
                    status = VALUES(status),
                    notes = VALUES(notes),
                    updated_at = CURRENT_TIMESTAMP";

        $this->db->execute($sql, [
            $tenantId,
            $customerId,
            $date,
            $shift,
            $finalTotalQty,
            $status,
            $notes
        ]);

        $deliveryId = (int)$this->db->fetchValue(
            "SELECT id FROM daily_deliveries WHERE tenant_id = ? AND customer_id = ? AND delivery_date = ? AND shift = ?",
            [$tenantId, $customerId, $date, $shift]
        );

        // 2. Insert or update daily_delivery_items
        if ($deliveryId > 0 && !empty($lineItemsToSave)) {
            foreach ($lineItemsToSave as $item) {
                $this->db->execute(
                    "INSERT INTO daily_delivery_items (tenant_id, delivery_id, product_id, quantity, unit_price, status, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE 
                        quantity = VALUES(quantity),
                        unit_price = VALUES(unit_price),
                        status = VALUES(status),
                        notes = VALUES(notes)",
                    [
                        $tenantId,
                        $deliveryId,
                        $item['product_id'],
                        $item['quantity'],
                        $item['unit_price'],
                        $item['status'],
                        $item['notes']
                    ]
                );
            }
        }

        // Return updated row state
        $delivery = $this->db->fetchOne(
            "SELECT dd.*, c.name AS customer_name, r.name AS route_name, c.milk_types
             FROM daily_deliveries dd
             JOIN customers c ON c.id = dd.customer_id
             JOIN routes r ON r.id = c.route_id
             WHERE dd.tenant_id = ? AND dd.customer_id = ? AND dd.delivery_date = ? AND dd.shift = ?",
            [$tenantId, $customerId, $date, $shift]
        );

        return $delivery ?: [];
    }

    public function markAllPendingAsDelivered(int $tenantId, string $date, string $shift): int
    {
        $pendingCustomers = $this->db->fetchAll(
            "SELECT c.id 
             FROM customers c
             LEFT JOIN customer_leaves cl ON cl.customer_id = c.id 
                AND cl.tenant_id = c.tenant_id 
                AND ? BETWEEN cl.start_date AND cl.end_date
             LEFT JOIN daily_deliveries dd ON dd.customer_id = c.id 
                AND dd.tenant_id = c.tenant_id 
                AND dd.delivery_date = ? 
                AND dd.shift = ?
             WHERE c.tenant_id = ? AND c.status = 'active' AND cl.id IS NULL AND dd.id IS NULL",
            [$date, $date, $shift, $tenantId]
        );

        $count = 0;
        foreach ($pendingCustomers as $pc) {
            $this->recordDelivery(
                $tenantId,
                (int)$pc['id'],
                $date,
                $shift,
                DeliveryStatus::Delivered->value,
                null,
                'Bulk confirmed delivered'
            );
            $count++;
        }

        return $count;
    }

    /**
     * Bulk auto-fill deliveries for an entire month from active customer subscriptions.
     *
     * @param int $tenantId
     * @param int $year
     * @param int $month
     * @param array $options [
     *   'route_id' => ?int,
     *   'route_name' => ?string,
     *   'shift' => 'both'|'morning'|'evening',
     *   'overwrite' => bool
     * ]
     * @return array
     */
    public function fillMonthDeliveries(int $tenantId, int $year, int $month, array $options = []): array
    {
        if ($year < 2000 || $year > 2100) {
            throw new \InvalidArgumentException("Invalid year: {$year}");
        }
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException("Invalid month: {$month}");
        }

        $daysInMonth = (int)cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

        $routeFilter = $options['route_id'] ?? $options['route'] ?? $options['route_name'] ?? null;
        $customerId = !empty($options['customer_id']) ? (int)$options['customer_id'] : null;
        $shiftOption = strtolower((string)($options['shift'] ?? 'both'));
        $shiftsToProcess = ($shiftOption === 'both') ? ['morning', 'evening'] : (in_array($shiftOption, ['morning', 'evening'], true) ? [$shiftOption] : ['morning', 'evening']);
        $overwrite = !empty($options['overwrite']);

        // 1. Ensure products exist for this tenant
        $this->productService->ensureDefaultProducts($tenantId);

        // 2. Fetch active customers
        $custSql = "SELECT c.*, r.name AS route_name 
                    FROM customers c 
                    LEFT JOIN routes r ON r.id = c.route_id 
                    WHERE c.tenant_id = ? AND c.status = 'active'";
        $custParams = [$tenantId];

        if ($customerId !== null && $customerId > 0) {
            $custSql .= " AND c.id = ?";
            $custParams[] = $customerId;
        }

        if (!empty($routeFilter)) {
            if (is_numeric($routeFilter)) {
                $custSql .= " AND (c.route_id = ? OR r.name = ?)";
                $custParams[] = (int)$routeFilter;
                $custParams[] = (string)$routeFilter;
            } else {
                $custSql .= " AND r.name = ?";
                $custParams[] = (string)$routeFilter;
            }
        }
        $custSql .= " ORDER BY c.id ASC";
        $customers = $this->db->fetchAll($custSql, $custParams);

        if (empty($customers)) {
            return [
                'year' => $year,
                'month' => $month,
                'days_in_month' => $daysInMonth,
                'customers_processed' => 0,
                'records_created' => 0,
                'records_skipped' => 0,
                'total_litres' => 0.0,
                'overwrite' => $overwrite,
            ];
        }

        // Preload subscriptions
        $allSubs = $this->db->fetchAll(
            "SELECT cs.*, p.name AS product_name, p.code AS product_code, p.unit, p.default_rate AS product_default_rate
             FROM customer_subscriptions cs
             JOIN products p ON p.id = cs.product_id
             WHERE cs.tenant_id = ? AND cs.is_active = 1
             ORDER BY p.sort_order ASC, p.name ASC",
            [$tenantId]
        );
        $subsByCust = [];
        foreach ($allSubs as $s) {
            $subsByCust[(int)$s['customer_id']][] = $s;
        }

        // Preload leaves overlapping this month
        $allLeaves = $this->db->fetchAll(
            "SELECT id, customer_id, start_date, end_date, reason 
             FROM customer_leaves 
             WHERE tenant_id = ? AND start_date <= ? AND end_date >= ?",
            [$tenantId, $endDate, $startDate]
        );
        $leavesByCust = [];
        foreach ($allLeaves as $l) {
            $leavesByCust[(int)$l['customer_id']][] = $l;
        }

        // Preload existing daily deliveries in this month
        $existingRows = $this->db->fetchAll(
            "SELECT id, customer_id, delivery_date, shift, quantity, status 
             FROM daily_deliveries 
             WHERE tenant_id = ? AND delivery_date BETWEEN ? AND ?",
            [$tenantId, $startDate, $endDate]
        );
        $existingMap = [];
        foreach ($existingRows as $er) {
            $key = $er['customer_id'] . '_' . $er['delivery_date'] . '_' . $er['shift'];
            $existingMap[$key] = $er;
        }

        $recordsCreated = 0;
        $recordsSkipped = 0;
        $totalLitres = 0.0;
        $processedCust = [];

        $this->db->transaction(function() use (
            $tenantId, $year, $month, $daysInMonth, $customers, &$subsByCust, $leavesByCust,
            $existingMap, $shiftsToProcess, $overwrite,
            &$recordsCreated, &$recordsSkipped, &$totalLitres, &$processedCust
        ) {
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);

                foreach ($customers as $c) {
                    $cId = (int)$c['id'];
                    $processedCust[$cId] = true;

                    // Check leaves for customer on this date
                    $isOnLeave = false;
                    $leaveReason = null;
                    if (!empty($leavesByCust[$cId])) {
                        foreach ($leavesByCust[$cId] as $lv) {
                            if ($dateStr >= $lv['start_date'] && $dateStr <= $lv['end_date']) {
                                $isOnLeave = true;
                                $leaveReason = $lv['reason'] ?? 'Customer on scheduled leave';
                                break;
                            }
                        }
                    }

                    // Get subscriptions for customer
                    $custSubs = $subsByCust[$cId] ?? [];
                    if (empty($custSubs)) {
                        $this->productService->syncSubscriptionsFromLegacyCustomer($tenantId, $c);
                        $custSubs = $this->productService->getCustomerSubscriptions($tenantId, $cId);
                        $subsByCust[$cId] = $custSubs;
                    }

                    foreach ($shiftsToProcess as $sh) {
                        $key = $cId . '_' . $dateStr . '_' . $sh;
                        $hasExisting = isset($existingMap[$key]);

                        if ($hasExisting && !$overwrite) {
                            $recordsSkipped++;
                            continue;
                        }

                        // Filter subscriptions for this shift
                        $shiftSubs = array_filter($custSubs, function($s) use ($sh) {
                            return in_array($s['shift'] ?? 'both', ['both', $sh], true);
                        });
                        if (empty($shiftSubs)) {
                            $shiftSubs = $custSubs;
                        }

                        $lineItems = [];
                        $dayShiftQty = 0.0;
                        $status = '';
                        $notes = '';

                        if ($isOnLeave) {
                            $status = DeliveryStatus::Leave->value;
                            $dayShiftQty = 0.00;
                            $notes = $leaveReason ?: 'Leave';
                            foreach ($shiftSubs as $sub) {
                                $lineItems[] = [
                                    'product_id' => (int)$sub['product_id'],
                                    'quantity' => 0.00,
                                    'unit_price' => (float)($sub['custom_rate'] ?? $sub['product_default_rate']),
                                    'status' => 'leave',
                                    'notes' => $notes,
                                ];
                            }
                        } else {
                            $status = DeliveryStatus::Delivered->value;
                            $notes = 'Auto-filled monthly delivery';
                            foreach ($shiftSubs as $sub) {
                                $pQty = (float)$sub['default_quantity'];
                                $dayShiftQty += $pQty;
                                $lineItems[] = [
                                    'product_id' => (int)$sub['product_id'],
                                    'quantity' => $pQty,
                                    'unit_price' => (float)($sub['custom_rate'] ?? $sub['product_default_rate']),
                                    'status' => $pQty > 0 ? DeliveryStatus::Delivered->value : DeliveryStatus::Skipped->value,
                                    'notes' => $notes,
                                ];
                            }
                        }

                        // Upsert header
                        $this->db->execute(
                            "INSERT INTO daily_deliveries (tenant_id, customer_id, delivery_date, shift, quantity, status, notes)
                             VALUES (?, ?, ?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE 
                                quantity = VALUES(quantity),
                                status = VALUES(status),
                                notes = VALUES(notes),
                                updated_at = CURRENT_TIMESTAMP",
                            [$tenantId, $cId, $dateStr, $sh, $dayShiftQty, $status, $notes]
                        );

                        $deliveryId = (int)$this->db->fetchValue(
                            "SELECT id FROM daily_deliveries WHERE tenant_id = ? AND customer_id = ? AND delivery_date = ? AND shift = ?",
                            [$tenantId, $cId, $dateStr, $sh]
                        );

                        if ($deliveryId > 0 && !empty($lineItems)) {
                            foreach ($lineItems as $item) {
                                $this->db->execute(
                                    "INSERT INTO daily_delivery_items (tenant_id, delivery_id, product_id, quantity, unit_price, status, notes)
                                     VALUES (?, ?, ?, ?, ?, ?, ?)
                                     ON DUPLICATE KEY UPDATE 
                                        quantity = VALUES(quantity),
                                        unit_price = VALUES(unit_price),
                                        status = VALUES(status),
                                        notes = VALUES(notes)",
                                    [
                                        $tenantId,
                                        $deliveryId,
                                        $item['product_id'],
                                        $item['quantity'],
                                        $item['unit_price'],
                                        $item['status'],
                                        $item['notes']
                                    ]
                                );
                            }
                        }

                        $recordsCreated++;
                        $totalLitres += $dayShiftQty;
                    }
                }
            }
        });

        return [
            'year' => $year,
            'month' => $month,
            'days_in_month' => $daysInMonth,
            'customers_processed' => count($processedCust),
            'records_created' => $recordsCreated,
            'records_skipped' => $recordsSkipped,
            'total_litres' => round($totalLitres, 2),
            'overwrite' => $overwrite,
        ];
    }

    /**
     * Get customer interactive monthly milk card matrix (1 to 31 days).
     *
     * @param int $tenantId
     * @param int $customerId
     * @param int $year
     * @param int $month
     * @return array
     */
    public function getCustomerMonthSheet(int $tenantId, int $customerId, int $year, int $month): array
    {
        $customer = $this->db->fetchOne(
            "SELECT c.*, r.name AS route_name 
             FROM customers c
             LEFT JOIN routes r ON r.id = c.route_id
             WHERE c.id = ? AND c.tenant_id = ?",
            [$customerId, $tenantId]
        );
        if (!$customer) {
            throw new \InvalidArgumentException("Customer #{$customerId} not found or unauthorized.");
        }

        $this->productService->ensureDefaultProducts($tenantId);

        $daysInMonth = (int)cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

        // Fetch subscriptions
        $subs = $this->productService->getCustomerSubscriptions($tenantId, $customerId);
        if (empty($subs)) {
            $this->productService->syncSubscriptionsFromLegacyCustomer($tenantId, $customer);
            $subs = $this->productService->getCustomerSubscriptions($tenantId, $customerId);
        }

        // Fetch leaves
        $leaves = $this->db->fetchAll(
            "SELECT id, start_date, end_date, reason 
             FROM customer_leaves 
             WHERE tenant_id = ? AND customer_id = ? AND start_date <= ? AND end_date >= ?",
            [$tenantId, $customerId, $endDate, $startDate]
        );

        // Fetch existing daily deliveries
        $deliveries = $this->db->fetchAll(
            "SELECT * FROM daily_deliveries 
             WHERE tenant_id = ? AND customer_id = ? AND delivery_date BETWEEN ? AND ?
             ORDER BY delivery_date ASC, shift ASC",
            [$tenantId, $customerId, $startDate, $endDate]
        );
        $delivMap = [];
        foreach ($deliveries as $d) {
            $delivMap[$d['delivery_date'] . '_' . $d['shift']] = $d;
        }

        // Fetch existing daily delivery items
        $delivItems = $this->db->fetchAll(
            "SELECT ddi.*, dd.delivery_date, dd.shift, p.name as product_name, p.code as product_code, p.unit
             FROM daily_delivery_items ddi
             JOIN daily_deliveries dd ON dd.id = ddi.delivery_id
             JOIN products p ON p.id = ddi.product_id
             WHERE ddi.tenant_id = ? AND dd.customer_id = ? AND dd.delivery_date BETWEEN ? AND ?",
            [$tenantId, $customerId, $startDate, $endDate]
        );
        $itemsByDeliveryId = [];
        foreach ($delivItems as $di) {
            $itemsByDeliveryId[(int)$di['delivery_id']][] = $di;
        }

        $days = [];
        $totalDeliveredShifts = 0;
        $totalSkippedShifts = 0;
        $totalLeaveShifts = 0;
        $totalPendingShifts = 0;
        $totalLitres = 0.0;
        $totalAmount = 0.0;
        $productTotals = [];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $dayOfWeek = date('D', strtotime($dateStr));

            $isOnLeave = false;
            $leaveReason = null;
            foreach ($leaves as $lv) {
                if ($dateStr >= $lv['start_date'] && $dateStr <= $lv['end_date']) {
                    $isOnLeave = true;
                    $leaveReason = $lv['reason'] ?? 'Customer on scheduled leave';
                    break;
                }
            }

            $shiftsData = [];
            $dayTotalQty = 0.0;
            $dayTotalAmount = 0.0;

            foreach (['morning', 'evening'] as $shift) {
                $key = $dateStr . '_' . $shift;
                $hasRecord = isset($delivMap[$key]);

                $shiftSubs = array_filter($subs, function($s) use ($shift) {
                    return in_array($s['shift'] ?? 'both', ['both', $shift], true);
                });
                if (empty($shiftSubs)) {
                    $shiftSubs = $subs;
                }

                $recordId = null;
                $status = 'pending';
                $qty = 0.0;
                $notes = '';
                $products = [];
                $shiftAmount = 0.0;

                if ($hasRecord) {
                    $rec = $delivMap[$key];
                    $recordId = (int)$rec['id'];
                    $status = (string)$rec['status'];
                    $qty = (float)$rec['quantity'];
                    $notes = (string)($rec['notes'] ?? '');

                    $dItems = $itemsByDeliveryId[$recordId] ?? [];
                    if (!empty($dItems)) {
                        foreach ($dItems as $di) {
                            $pQty = (float)$di['quantity'];
                            $pPrice = (float)$di['unit_price'];
                            $pAmount = round($pQty * $pPrice, 2);
                            $shiftAmount += $pAmount;
                            $products[] = [
                                'product_id' => (int)$di['product_id'],
                                'name' => (string)$di['product_name'],
                                'code' => (string)$di['product_code'],
                                'unit' => (string)$di['unit'],
                                'quantity' => $pQty,
                                'unit_price' => $pPrice,
                                'amount' => $pAmount,
                                'status' => (string)$di['status'],
                            ];
                        }
                    } else {
                        foreach ($shiftSubs as $sub) {
                            $pQty = ($status === 'delivered') ? (float)$sub['default_quantity'] : 0.0;
                            $pPrice = (float)($sub['custom_rate'] ?? $sub['product_default_rate']);
                            $pAmount = round($pQty * $pPrice, 2);
                            $shiftAmount += $pAmount;
                            $products[] = [
                                'product_id' => (int)$sub['product_id'],
                                'name' => (string)$sub['product_name'],
                                'code' => (string)$sub['product_code'],
                                'unit' => (string)$sub['unit'],
                                'quantity' => $pQty,
                                'unit_price' => $pPrice,
                                'amount' => $pAmount,
                                'status' => $status,
                            ];
                        }
                    }
                } elseif ($isOnLeave) {
                    $status = 'leave';
                    $qty = 0.00;
                    $notes = $leaveReason ?: 'Leave';
                    foreach ($shiftSubs as $sub) {
                        $pPrice = (float)($sub['custom_rate'] ?? $sub['product_default_rate']);
                        $products[] = [
                            'product_id' => (int)$sub['product_id'],
                            'name' => (string)$sub['product_name'],
                            'code' => (string)$sub['product_code'],
                            'unit' => (string)$sub['unit'],
                            'quantity' => 0.00,
                            'unit_price' => $pPrice,
                            'amount' => 0.00,
                            'status' => 'leave',
                        ];
                    }
                } else {
                    $status = 'pending';
                    $defSum = 0.0;
                    foreach ($shiftSubs as $sub) {
                        $pQty = (float)$sub['default_quantity'];
                        $pPrice = (float)($sub['custom_rate'] ?? $sub['product_default_rate']);
                        $pAmount = round($pQty * $pPrice, 2);
                        $defSum += $pQty;
                        $shiftAmount += $pAmount;
                        $products[] = [
                            'product_id' => (int)$sub['product_id'],
                            'name' => (string)$sub['product_name'],
                            'code' => (string)$sub['product_code'],
                            'unit' => (string)$sub['unit'],
                            'quantity' => $pQty,
                            'unit_price' => $pPrice,
                            'amount' => $pAmount,
                            'status' => 'pending',
                        ];
                    }
                    $qty = $defSum;
                }

                // Stats tally
                if ($status === 'delivered') {
                    $totalDeliveredShifts++;
                    $totalLitres += $qty;
                    $totalAmount += $shiftAmount;
                    $dayTotalQty += $qty;
                    $dayTotalAmount += $shiftAmount;

                    foreach ($products as $pr) {
                        $pName = $pr['name'];
                        if (!isset($productTotals[$pName])) {
                            $productTotals[$pName] = ['quantity' => 0.0, 'amount' => 0.0, 'unit' => $pr['unit']];
                        }
                        $productTotals[$pName]['quantity'] += (float)$pr['quantity'];
                        $productTotals[$pName]['amount'] += (float)$pr['amount'];
                    }
                } elseif ($status === 'skipped') {
                    $totalSkippedShifts++;
                } elseif ($status === 'leave') {
                    $totalLeaveShifts++;
                } else {
                    $totalPendingShifts++;
                }

                $shiftsData[$shift] = [
                    'delivery_id' => $recordId,
                    'status' => $status,
                    'quantity' => $qty,
                    'amount' => round($shiftAmount, 2),
                    'notes' => $notes,
                    'products' => $products,
                ];
            }

            $days[] = [
                'day' => $day,
                'date' => $dateStr,
                'day_of_week' => $dayOfWeek,
                'is_on_leave' => $isOnLeave,
                'leave_reason' => $leaveReason,
                'total_quantity' => round($dayTotalQty, 2),
                'total_amount' => round($dayTotalAmount, 2),
                'shifts' => $shiftsData,
            ];
        }

        return [
            'customer' => [
                'id' => (int)$customer['id'],
                'name' => (string)$customer['name'],
                'phone' => (string)$customer['phone'],
                'address' => (string)($customer['address'] ?? ''),
                'route_name' => (string)($customer['route_name'] ?? 'Main Route'),
                'milk_types' => (string)($customer['milk_types'] ?? 'cow'),
                'subscriptions' => $subs,
            ],
            'month' => $month,
            'year' => $year,
            'days_in_month' => $daysInMonth,
            'days' => $days,
            'summary' => [
                'delivered_shifts' => $totalDeliveredShifts,
                'skipped_shifts' => $totalSkippedShifts,
                'leave_shifts' => $totalLeaveShifts,
                'pending_shifts' => $totalPendingShifts,
                'total_litres' => round($totalLitres, 2),
                'total_amount' => round($totalAmount, 2),
                'product_totals' => $productTotals,
            ],
        ];
    }

    /**
     * Batch save/upsert a customer's monthly milk card changes.
     *
     * @param int $tenantId
     * @param int $customerId
     * @param int $year
     * @param int $month
     * @param array $daysData
     * @return array
     */
    public function saveCustomerMonthSheet(int $tenantId, int $customerId, int $year, int $month, array $daysData): array
    {
        $customer = $this->db->fetchOne(
            "SELECT id FROM customers WHERE id = ? AND tenant_id = ?",
            [$customerId, $tenantId]
        );
        if (!$customer) {
            throw new \InvalidArgumentException("Customer #{$customerId} not found or unauthorized.");
        }

        $monthPrefix = sprintf('%04d-%02d', $year, $month);

        $this->db->transaction(function() use ($tenantId, $customerId, $monthPrefix, $daysData) {
            foreach ($daysData as $item) {
                $date = trim((string)($item['date'] ?? ''));
                $shift = strtolower(trim((string)($item['shift'] ?? 'morning')));
                $status = strtolower(trim((string)($item['status'] ?? 'delivered')));
                $quantity = isset($item['quantity']) ? (float)$item['quantity'] : null;
                $notes = isset($item['notes']) ? (string)$item['notes'] : null;
                $productItems = isset($item['product_items']) && is_array($item['product_items']) ? $item['product_items'] : null;

                if (!str_starts_with($date, $monthPrefix)) {
                    continue; // Skip out of range date
                }
                if (!in_array($shift, ['morning', 'evening'], true)) {
                    $shift = 'morning';
                }
                if (!in_array($status, ['delivered', 'skipped', 'leave'], true)) {
                    $status = 'delivered';
                }

                $this->recordDelivery(
                    $tenantId,
                    $customerId,
                    $date,
                    $shift,
                    $status,
                    $quantity,
                    $notes,
                    $productItems
                );
            }
        });

        return $this->getCustomerMonthSheet($tenantId, $customerId, $year, $month);
    }
}

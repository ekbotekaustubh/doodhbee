<?php
declare(strict_types=1);

if (!defined('DOODHBEE_TEST_RUNNER')) {
    define('DOODHBEE_TEST_RUNNER', true);
}

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Enums\DeliveryShift;
use App\Enums\DeliveryStatus;
use App\Services\BillingService;
use App\Services\DeliveryService;
use App\Services\QuotaService;

echo "========================================================\n";
echo "   DOODHBEE MULTI-TENANT SAAS AUTOMATED TEST SUITE    \n";
echo "========================================================\n\n";

$db = Database::getInstance();
$deliveryService = new DeliveryService();
$billingService = new BillingService();
$quotaService = new QuotaService();

$passed = 0;
$failed = 0;

function runTest(string $name, callable $testFn, int &$passed, int &$failed): void
{
    echo "• Running: {$name}... ";
    try {
        $testFn();
        echo "[\033[32mPASS\033[0m]\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "[\033[31mFAIL\033[0m]\n";
        echo "  Error: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
        $failed++;
    }
}

// TEST 1: Tenant Data Isolation
runTest("Tenancy Data Isolation (Tenant 1 vs Tenant 2)", function() use ($db, $deliveryService, $billingService) {
    // Tenant 1 has 6 customers, Tenant 2 has 4 customers
    $t1Customers = $db->fetchAll("SELECT id FROM customers WHERE tenant_id = 1");
    $t2Customers = $db->fetchAll("SELECT id FROM customers WHERE tenant_id = 2");

    if (count($t1Customers) === 0 || count($t2Customers) === 0) {
        throw new \Exception("Customers missing for tenants");
    }

    $t1Ids = array_column($t1Customers, 'id');
    $t2Ids = array_column($t2Customers, 'id');

    // No overlap in customer IDs
    $overlap = array_intersect($t1Ids, $t2Ids);
    if (!empty($overlap)) {
        throw new \Exception("Data isolation breach: Customer IDs overlap between tenants!");
    }

    // Invoices isolation
    $t1Invoices = $db->fetchAll("SELECT id FROM invoices WHERE tenant_id = 1");
    $t2Invoices = $db->fetchAll("SELECT id FROM invoices WHERE tenant_id = 2");
    $invOverlap = array_intersect(array_column($t1Invoices, 'id'), array_column($t2Invoices, 'id'));
    if (!empty($invOverlap)) {
        throw new \Exception("Data isolation breach: Invoices overlap between tenants!");
    }

    // Service-level Tenant Isolation Guards:
    // 1. DeliveryService: Tenant 1 cannot record delivery for Tenant 2's customer
    $caughtDeliveryLeak = false;
    try {
        $deliveryService->recordDelivery(1, $t2Ids[0], date('Y-m-d'), 'morning', DeliveryStatus::Delivered->value, 2.0);
    } catch (\InvalidArgumentException $e) {
        $caughtDeliveryLeak = true;
    }
    if (!$caughtDeliveryLeak) {
        throw new \Exception("Data isolation breach: Tenant 1 was able to record delivery for Tenant 2 customer!");
    }

    // 2. BillingService: Tenant 1 cannot calculate or generate invoice for Tenant 2's customer
    $caughtBillingLeak = false;
    try {
        $billingService->calculateCustomerBill(1, $t2Ids[0], (int)date('Y'), (int)date('m'));
    } catch (\InvalidArgumentException $e) {
        $caughtBillingLeak = true;
    }
    if (!$caughtBillingLeak) {
        throw new \Exception("Data isolation breach: Tenant 1 was able to calculate bill for Tenant 2 customer!");
    }

    // 3. ProductService: Tenant 1 cannot subscribe to Tenant 2's product
    $t2Products = $db->fetchAll("SELECT id FROM products WHERE tenant_id = 2");
    if (!empty($t2Products)) {
        $productService = new \App\Services\ProductService();
        $t2ProdId = (int)$t2Products[0]['id'];
        $testCustId = 999999;
        $productService->saveCustomerSubscriptions(1, $testCustId, [
            ['product_id' => $t2ProdId, 'quantity' => 5.0, 'shift' => 'both', 'is_active' => true]
        ]);
        $check = $db->fetchOne("SELECT id FROM customer_subscriptions WHERE tenant_id = 1 AND product_id = ?", [$t2ProdId]);
        $db->execute("DELETE FROM customer_subscriptions WHERE customer_id = ?", [$testCustId]);
        if ($check) {
            throw new \Exception("Data isolation breach: Tenant 1 was able to subscribe customer to Tenant 2's product!");
        }
    }
}, $passed, $failed);

// TEST 2: Core Anti-Error Engine & Zero-Charge Rule
runTest("Zero-Charge Rule: Missed & Leave Days strictly record 0.00L", function() use ($deliveryService) {
    $today = date('Y-m-d');
    
    // Mark delivered with 3.5L
    $del = $deliveryService->recordDelivery(1, 1, $today, 'morning', DeliveryStatus::Delivered->value, 3.5);
    if ((float)$del['quantity'] !== 3.5) {
        throw new \Exception("Expected delivered qty 3.5, got " . $del['quantity']);
    }

    // Mark skipped (even if quantity is provided, must be forced to 0.00)
    $skip = $deliveryService->recordDelivery(1, 1, $today, 'morning', DeliveryStatus::Skipped->value, 3.5);
    if ((float)$skip['quantity'] !== 0.0) {
        throw new \Exception("Zero-charge rule violated on Skipped! Expected 0.00, got " . $skip['quantity']);
    }

    // Mark leave (must be forced to 0.00)
    $leave = $deliveryService->recordDelivery(1, 1, $today, 'morning', DeliveryStatus::Leave->value, 2.0);
    if ((float)$leave['quantity'] !== 0.0) {
        throw new \Exception("Zero-charge rule violated on Leave! Expected 0.00, got " . $leave['quantity']);
    }

    // Restore to default delivered
    $deliveryService->recordDelivery(1, 1, $today, 'morning', DeliveryStatus::Delivered->value, 2.0);
}, $passed, $failed);

// TEST 3: Scheduled Leave Auto-Detection
runTest("Vacation / Leave Auto-Detection in Daily Run-Sheet", function() use ($deliveryService, $db) {
    // Customer 2 (Priya Verma) has a scheduled leave in customer_leaves
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));

    $db->execute("INSERT INTO customer_leaves (tenant_id, customer_id, start_date, end_date, reason) VALUES (1, 2, ?, ?, 'Out of Town') ON DUPLICATE KEY UPDATE reason = VALUES(reason)", [$today, $tomorrow]);

    $runSheet = $deliveryService->getRunSheet(1, $today, 'morning');
    $found = false;
    foreach ($runSheet['items'] as $item) {
        if ($item['customer_id'] === 2) {
            $found = true;
            if (!$item['is_on_leave']) {
                throw new \Exception("Customer 2 should be detected as is_on_leave = true!");
            }
            break;
        }
    }
    if (!$found) {
        throw new \Exception("Customer 2 not found in run sheet");
    }
}, $passed, $failed);

// TEST 4: Customer Quota Guard & Paywall
runTest("Customer Quota Guard: Free Tier 5-Limit Enforcement", function() use ($quotaService, $db) {
    // Reset Tenant 2 to free tier
    $freePlanId = (int)$db->fetchValue("SELECT id FROM subscription_plans WHERE is_free = 1 LIMIT 1");
    $db->execute("UPDATE tenant_subscriptions SET plan_id = ?, purchased_blocks = 0, max_customers_allowed = 5, billing_cycle = NULL, valid_until = NULL, payment_status = 'free' WHERE tenant_id = 2", [$freePlanId ?: null]);

    $quota = $quotaService->getTenantQuota(2);
    if ($quota['max_allowed'] !== 5) {
        throw new \Exception("Expected max_allowed = 5 on free tier, got " . $quota['max_allowed']);
    }
    if ($quota['active_customers'] !== 4) {
        throw new \Exception("Expected 4 active customers, got " . $quota['active_customers']);
    }
    if (!$quota['can_add']) {
        throw new \Exception("Tenant 2 should be able to add 1 more customer (4/5)");
    }

    // Add 5th customer to fill quota
    $r2 = (int)$db->fetchValue("SELECT id FROM routes WHERE tenant_id = 2 LIMIT 1");
    $db->execute("INSERT INTO customers (tenant_id, route_id, name, phone, address, status) VALUES (2, ?, 'Fifth Test Customer', '9999900005', 'Test Address', 'active')", [$r2]);

    $quotaFull = $quotaService->getTenantQuota(2);
    if ($quotaFull['active_customers'] !== 5 || $quotaFull['can_add'] !== false) {
        throw new \Exception("Quota guard failed! Customer slots should be full (5/5), can_add should be false");
    }

    // Test Upgrade Simulation: purchase 1 block (+100 slots)
    $upgraded = $quotaService->upgradeCapacity(2, 1);
    if ($upgraded['max_allowed'] !== 105 || $upgraded['can_add'] !== true || $upgraded['payment_status'] !== 'active') {
        throw new \Exception("Upgrade capacity failed! Expected 105 max, active payment status.");
    }

    // Clean up test customer and reset
    $db->execute("DELETE FROM customers WHERE tenant_id = 2 AND phone = '9999900005'");
    $db->execute("UPDATE tenant_subscriptions SET plan_id = ?, purchased_blocks = 0, max_customers_allowed = 5, billing_cycle = NULL, valid_until = NULL, payment_status = 'free' WHERE tenant_id = 2", [$freePlanId ?: null]);
}, $passed, $failed);

// TEST 5: Exact 4-Step Invoice Billing Engine Formula
runTest("Billing Engine 4-Step Invoice Calculation Formula", function() use ($billingService) {
    $now = new DateTime('now');
    $prevMonthDate = (clone $now)->modify('-1 month');
    $py = (int)$prevMonthDate->format('Y');
    $pm = (int)$prevMonthDate->format('m');

    $bill = $billingService->calculateCustomerBill(1, 1, $py, $pm);

    // Formula Step 1: Base Milk Subtotal = sum(Product Subtotals) or (Delivered Liters * Customer Rate)
    $expectedSubtotal = !empty($bill['products'])
        ? round((float)array_sum(array_column($bill['products'], 'subtotal')), 2)
        : round($bill['total_units'] * $bill['customer_rate'], 2);
    if (abs($bill['milk_subtotal'] - $expectedSubtotal) > 0.01) {
        throw new \Exception("Step 1 failed! Milk subtotal mismatch");
    }

    // Formula Step 2: Custom Surcharges sum
    $chargesSum = 0.0;
    foreach ($bill['charges'] as $c) {
        $chargesSum += $c['amount'];
    }
    if (abs($bill['additional_charges_total'] - round($chargesSum, 2)) > 0.01) {
        throw new \Exception("Step 2 failed! Additional charges sum mismatch");
    }

    // Formula Step 3: Tax Amount = (Base Subtotal + Taxable Charges) * (Tax% / 100)
    $taxableBase = $bill['milk_subtotal'];
    foreach ($bill['charges'] as $c) {
        if ($c['is_taxable']) {
            $taxableBase += $c['amount'];
        }
    }
    $expectedTax = round($taxableBase * ($bill['tax_percentage'] / 100), 2);
    if (abs($bill['tax_amount'] - $expectedTax) > 0.01) {
        throw new \Exception("Step 3 failed! Tax amount mismatch");
    }

    // Formula Step 4: Total Payable = Base + Charges + Tax + Prev Balance - Payments
    $expectedFinal = round($bill['milk_subtotal'] + $bill['additional_charges_total'] + $bill['tax_amount'] + $bill['previous_balance'] - $bill['payments_received'], 2);
    if (abs($bill['final_amount'] - $expectedFinal) > 0.01) {
        throw new \Exception("Step 4 failed! Final payable amount mismatch");
    }
}, $passed, $failed);

// TEST 6: Super Admin Global SaaS Configuration
runTest("Super Admin Global Platform Settings (system_settings)", function() use ($quotaService, $db) {
    $sys = $quotaService->getSystemSettings();
    if (!isset($sys['free_customer_limit'], $sys['paid_tier_unit_size'], $sys['paid_tier_unit_price'])) {
        throw new \Exception("System settings missing core tier parameters!");
    }
}, $passed, $failed);

// TEST 7: Customer Milk Type Multiselect (Cow, Buffalo, and Both)
runTest("Customer Milk Type Multiselect: Cow Milk, Buffalo Milk, and Both", function() use ($db) {
    // 1. Verify Enum
    if (\App\Enums\MilkType::Cow->value !== 'cow' || \App\Enums\MilkType::Buffalo->value !== 'buffalo') {
        throw new \Exception("MilkType enum values mismatch");
    }

    // 2. Insert customer with Cow milk only
    $r1 = (int)$db->fetchValue("SELECT id FROM routes WHERE tenant_id = 1 LIMIT 1");
    $db->execute("INSERT INTO customers (tenant_id, route_id, name, phone, address, milk_types, status) 
                  VALUES (1, ?, 'Test Cow Only', '9999911111', 'Block A', 'cow', 'active')", [$r1]);
    $c1 = $db->fetchOne("SELECT * FROM customers WHERE phone = '9999911111'");
    if (!$c1 || $c1['milk_types'] !== 'cow') {
        throw new \Exception("Expected milk_types 'cow', got: " . ($c1['milk_types'] ?? 'null'));
    }
    $types1 = parseMilkTypes($c1['milk_types']);
    if ($types1 !== ['cow']) {
        throw new \Exception("parseMilkTypes mismatch for cow: " . json_encode($types1));
    }

    // 3. Insert customer with Buffalo milk only
    $db->execute("INSERT INTO customers (tenant_id, route_id, name, phone, address, milk_types, status) 
                  VALUES (1, ?, 'Test Buffalo Only', '9999922222', 'Block B', 'buffalo', 'active')", [$r1]);
    $c2 = $db->fetchOne("SELECT * FROM customers WHERE phone = '9999922222'");
    if (!$c2 || $c2['milk_types'] !== 'buffalo') {
        throw new \Exception("Expected milk_types 'buffalo', got: " . ($c2['milk_types'] ?? 'null'));
    }
    $types2 = parseMilkTypes($c2['milk_types']);
    if ($types2 !== ['buffalo']) {
        throw new \Exception("parseMilkTypes mismatch for buffalo: " . json_encode($types2));
    }

    // 4. Insert customer with BOTH Cow and Buffalo (Multiselect)
    $db->execute("INSERT INTO customers (tenant_id, route_id, name, phone, address, milk_types, status) 
                  VALUES (1, ?, 'Test Both Milks', '9999933333', 'Block C', 'cow,buffalo', 'active')", [$r1]);
    $c3 = $db->fetchOne("SELECT * FROM customers WHERE phone = '9999933333'");
    if (!$c3 || $c3['milk_types'] !== 'cow,buffalo') {
        throw new \Exception("Expected milk_types 'cow,buffalo', got: " . ($c3['milk_types'] ?? 'null'));
    }
    $types3 = parseMilkTypes($c3['milk_types']);
    if (count($types3) !== 2 || !in_array('cow', $types3) || !in_array('buffalo', $types3)) {
        throw new \Exception("parseMilkTypes mismatch for both: " . json_encode($types3));
    }

    // 5. Update customer from single milk to both milks
    $db->execute("UPDATE customers SET milk_types = ? WHERE id = ?", ['cow,buffalo', $c1['id']]);
    $c1Updated = $db->fetchOne("SELECT * FROM customers WHERE id = ?", [$c1['id']]);
    if ($c1Updated['milk_types'] !== 'cow,buffalo') {
        throw new \Exception("Updating customer milk types failed!");
    }

    // Clean up test rows
    $db->execute("DELETE FROM customers WHERE phone IN ('9999911111', '9999922222', '9999933333')");
}, $passed, $failed);

// TEST 8: Delivery Calendar Matrix & Milk Type on Invoices
runTest("Delivery Calendar Matrix & Milk Type on Invoices", function() use ($db, $billingService) {
    // 1. Check helper milk text formatting
    if (formatMilkTypeText('cow') !== 'Cow Milk') {
        throw new \Exception("formatMilkTypeText('cow') failed");
    }
    if (formatMilkTypeText('buffalo') !== 'Buffalo Milk') {
        throw new \Exception("formatMilkTypeText('buffalo') failed");
    }
    if (formatMilkTypeText('cow,buffalo') !== 'Cow Milk & Buffalo Milk') {
        throw new \Exception("formatMilkTypeText('cow,buffalo') failed: " . formatMilkTypeText('cow,buffalo'));
    }

    // 2. Test buildCalendarMatrix for October (31 days)
    $sampleItemized = [
        ['date' => '2026-10-01', 'shift' => 'morning', 'status' => 'delivered', 'quantity' => 2.0, 'notes' => ''],
        ['date' => '2026-10-02', 'shift' => 'morning', 'status' => 'skipped', 'quantity' => 0.0, 'notes' => 'Door locked'],
        ['date' => '2026-10-03', 'shift' => 'morning', 'status' => 'leave', 'quantity' => 0.0, 'notes' => 'Out of town'],
        ['date' => '2026-10-04', 'shift' => 'morning', 'status' => 'delivered', 'quantity' => 1.5, 'notes' => ''],
        ['date' => '2026-10-04', 'shift' => 'evening', 'status' => 'delivered', 'quantity' => 1.0, 'notes' => ''],
    ];

    $matrixData = buildCalendarMatrix([
        'itemized_deliveries' => $sampleItemized,
        'days_in_month' => 31,
    ], 2026, 10);

    if ($matrixData['days_in_month'] !== 31) {
        throw new \Exception("Expected 31 days in October, got " . $matrixData['days_in_month']);
    }
    if (count($matrixData['matrix']) !== 31) {
        throw new \Exception("Expected 31 matrix cells, got " . count($matrixData['matrix']));
    }

    // Oct 1 was Thursday (day_of_week = 4)
    if ($matrixData['first_day_of_week'] !== 4) {
        throw new \Exception("Expected first day of week 4 (Thu), got " . $matrixData['first_day_of_week']);
    }

    // Day 1: Delivered 2.0L
    $day1 = $matrixData['matrix'][1];
    if ($day1['status'] !== 'delivered' || (float)$day1['quantity'] !== 2.0) {
        throw new \Exception("Day 1 mismatch: status={$day1['status']}, qty={$day1['quantity']}");
    }

    // Day 2: Skipped 0.0L
    $day2 = $matrixData['matrix'][2];
    if ($day2['status'] !== 'skipped' || (float)$day2['quantity'] !== 0.0) {
        throw new \Exception("Day 2 mismatch: status={$day2['status']}, qty={$day2['quantity']}");
    }

    // Day 3: Leave 0.0L
    $day3 = $matrixData['matrix'][3];
    if ($day3['status'] !== 'leave' || (float)$day3['quantity'] !== 0.0) {
        throw new \Exception("Day 3 mismatch: status={$day3['status']}, qty={$day3['quantity']}");
    }

    // Day 4: Morning 1.5L + Evening 1.0L = 2.5L delivered
    $day4 = $matrixData['matrix'][4];
    if ($day4['status'] !== 'delivered' || (float)$day4['quantity'] !== 2.5) {
        throw new \Exception("Day 4 multi-shift mismatch: status={$day4['status']}, qty={$day4['quantity']}");
    }

    // 3. Test invoice query joins customer milk_types
    $inv = $db->fetchOne(
        "SELECT inv.*, c.name AS customer_name, c.phone AS customer_phone, r.name AS route_name, c.milk_types
         FROM invoices inv
         JOIN customers c ON c.id = inv.customer_id
         JOIN routes r ON r.id = c.route_id
         WHERE inv.tenant_id = 1
         LIMIT 1"
    );
    if (!$inv) {
        throw new \Exception("No invoices found for tenant 1");
    }
    if (!isset($inv['milk_types'])) {
        throw new \Exception("Invoice query does not contain milk_types field!");
    }

    // 4. Test BillingService calculation contains calendar_matrix
    $bill = $billingService->calculateCustomerBill(1, 1, 2026, 10);
    if (empty($bill['calendar_matrix']) || count($bill['calendar_matrix']) !== 31) {
        throw new \Exception("BillingService bill missing 31-day calendar_matrix");
    }
}, $passed, $failed);

// TEST 9: Customer CSV Import, Export, and Sample Template
runTest("Customer CSV Import, Export, and Sample Template", function() use ($db) {
    $prodSvc = new \App\Services\ProductService();
    $prodSvc->ensureDefaultProducts(1);
    $cowProd = $db->fetchOne("SELECT id, default_rate FROM products WHERE tenant_id = 1 AND code = 'cow'");
    $bufProd = $db->fetchOne("SELECT id, default_rate FROM products WHERE tenant_id = 1 AND code = 'buffalo'");

    // 1. Verify Sample CSV generation (Option A: Product-specific columns)
    $sampleFile = tempnam(sys_get_temp_dir(), "sample_csv_");
    $fp = fopen($sampleFile, 'w');
    fputcsv($fp, ['Name', 'Phone', 'Address', 'Route', 'Cow Qty (L)', 'Cow Rate', 'Buffalo Qty (L)', 'Buffalo Rate', 'Status'], ',', '"', "\\");
    fputcsv($fp, ['Import Test 1', '9666611111', 'Flat 1', 'Route A', '1.5', '65.0', '', '', 'active'], ',', '"', "\\");
    fputcsv($fp, ['Import Test 2', '9666622222', 'Flat 2', 'Route B', '', '', '2.0', '75.0', 'active'], ',', '"', "\\");
    fputcsv($fp, ['Import Test 3', '9666633333', 'Flat 3', 'Route A', '1.0', '60.0', '2.5', '80.0', 'active'], ',', '"', "\\");
    fclose($fp);

    // 2. Test CSV parsing & Option A ingestion logic
    $handle = fopen($sampleFile, 'r');
    $header = fgetcsv($handle, null, ',', '"', "\\");
    if (!in_array('Name', $header) || !in_array('Phone', $header) || !in_array('Cow Qty (L)', $header) || !in_array('Buffalo Qty (L)', $header)) {
        throw new \Exception("Sample CSV missing Option A product headers");
    }

    $imported = 0;
    while (($row = fgetcsv($handle, null, ',', '"', "\\")) !== false) {
        $name = $row[0];
        $phone = $row[1];
        $addr = $row[2];
        $route = $row[3];
        $cowQty = $row[4] !== '' ? (float)$row[4] : 0.0;
        $cowRate = $row[5] !== '' ? (float)$row[5] : null;
        $bufQty = $row[6] !== '' ? (float)$row[6] : 0.0;
        $bufRate = $row[7] !== '' ? (float)$row[7] : null;
        $status = $row[8];

        $subs = [];
        if ($cowQty > 0) {
            $subs[] = ['product_id' => (int)$cowProd['id'], 'quantity' => $cowQty, 'custom_rate' => $cowRate, 'shift' => 'both', 'is_active' => true];
        }
        if ($bufQty > 0) {
            $subs[] = ['product_id' => (int)$bufProd['id'], 'quantity' => $bufQty, 'custom_rate' => $bufRate, 'shift' => 'both', 'is_active' => true];
        }

        $milkTypes = count($subs) === 2 ? 'cow,buffalo' : ($cowQty > 0 ? 'cow' : 'buffalo');

        $routeRecord = $db->fetchOne("SELECT id FROM routes WHERE tenant_id = 1 AND name = ?", [$route]);
        if (!$routeRecord) {
            $db->execute("INSERT INTO routes (tenant_id, name, is_active) VALUES (1, ?, 1)", [$route]);
            $rId = (int)$db->lastInsertId();
        } else {
            $rId = (int)$routeRecord['id'];
        }

        $db->execute(
            "INSERT INTO customers (tenant_id, route_id, name, phone, address, milk_types, status)
             VALUES (1, ?, ?, ?, ?, ?, ?)",
            [$rId, $name, $phone, $addr, $milkTypes, $status]
        );
        $newId = (int)$db->lastInsertId();
        $prodSvc->saveCustomerSubscriptions(1, $newId, $subs);
        $imported++;
    }
    fclose($handle);
    @unlink($sampleFile);

    if ($imported !== 3) {
        throw new \Exception("Expected 3 imported customers, got {$imported}");
    }

    // Verify imported data in DB
    $c1 = $db->fetchOne("SELECT * FROM customers WHERE phone = '9666611111'");
    $s1 = $db->fetchOne("SELECT default_quantity, custom_rate FROM customer_subscriptions WHERE customer_id = ? AND product_id = ?", [$c1['id'], $cowProd['id']]);
    if (!$c1 || $c1['milk_types'] !== 'cow' || (float)$s1['default_quantity'] !== 1.5 || (float)$s1['custom_rate'] !== 65.0) {
        throw new \Exception("Imported customer 1 mismatch");
    }

    $c2 = $db->fetchOne("SELECT * FROM customers WHERE phone = '9666622222'");
    $s2 = $db->fetchOne("SELECT default_quantity, custom_rate FROM customer_subscriptions WHERE customer_id = ? AND product_id = ?", [$c2['id'], $bufProd['id']]);
    if (!$c2 || $c2['milk_types'] !== 'buffalo' || (float)$s2['default_quantity'] !== 2.0 || (float)$s2['custom_rate'] !== 75.0) {
        throw new \Exception("Imported customer 2 mismatch");
    }

    $c3 = $db->fetchOne("SELECT * FROM customers WHERE phone = '9666633333'");
    $s3Cow = $db->fetchOne("SELECT default_quantity, custom_rate FROM customer_subscriptions WHERE customer_id = ? AND product_id = ?", [$c3['id'], $cowProd['id']]);
    $s3Buf = $db->fetchOne("SELECT default_quantity, custom_rate FROM customer_subscriptions WHERE customer_id = ? AND product_id = ?", [$c3['id'], $bufProd['id']]);
    if (!$c3 || $c3['milk_types'] !== 'cow,buffalo' || (float)$s3Cow['default_quantity'] !== 1.0 || (float)$s3Cow['custom_rate'] !== 60.0 || (float)$s3Buf['default_quantity'] !== 2.5 || (float)$s3Buf['custom_rate'] !== 80.0) {
        throw new \Exception("Imported customer 3 mismatch (both milks)");
    }

    // 3. Test Export query
    $exportedRows = $db->fetchAll("SELECT * FROM customers WHERE tenant_id = 1 AND phone LIKE '96666%'");
    if (count($exportedRows) !== 3) {
        throw new \Exception("Export rows count mismatch: expected 3, got " . count($exportedRows));
    }

    // Cleanup
    $db->execute("DELETE FROM customers WHERE phone LIKE '96666%'");
}, $passed, $failed);

// TEST 10: Multi-Product Catalog Management (Approach A)
runTest("Product Catalog: Dynamic Tenant Products with Units & Rates", function() use ($db) {
    $productService = new \App\Services\ProductService();
    $tenantId = 1;

    // 1. Ensure default products exist (Cow Milk & Buffalo Milk)
    $productService->ensureDefaultProducts($tenantId);
    $products = $productService->getTenantProducts($tenantId);
    if (count($products) < 2) {
        throw new \Exception("Default products missing");
    }

    // 2. Create custom product: Fresh Paneer in kg
    $pId = $productService->createProduct($tenantId, 'Fresh Paneer', 'paneer', 'kg', 360.00);
    if ($pId <= 0) {
        throw new \Exception("Failed to create product");
    }

    $paneer = $db->fetchOne("SELECT * FROM products WHERE id = ? AND tenant_id = ?", [$pId, $tenantId]);
    if (!$paneer || $paneer['unit'] !== 'kg' || (float)$paneer['default_rate'] !== 360.00) {
        throw new \Exception("Product data mismatch for Paneer");
    }
}, $passed, $failed);

// TEST 11: Customer Multi-Product Subscriptions (Both Milks & New Products)
runTest("Customer Subscriptions: Independent Quantities & Rates for Both Milks", function() use ($db) {
    $productService = new \App\Services\ProductService();
    $tenantId = 1;

    // Create a customer
    $r1 = (int)$db->fetchValue("SELECT id FROM routes WHERE tenant_id = ? LIMIT 1", [$tenantId]);
    $db->execute("INSERT INTO customers (tenant_id, route_id, name, phone, address, milk_types, status) 
                  VALUES (?, ?, 'Dual Milk Test Cust', '9888800001', 'Flat 100', 'cow,buffalo', 'active')",
                  [$tenantId, $r1]);
    $custId = (int)$db->lastInsertId();

    $cowProd = $db->fetchOne("SELECT id FROM products WHERE tenant_id = ? AND code = 'cow'", [$tenantId]);
    $bufProd = $db->fetchOne("SELECT id FROM products WHERE tenant_id = ? AND code = 'buffalo'", [$tenantId]);

    // Save independent subscriptions: Cow 1.5L @ 60, Buffalo 1.0L @ 75
    $subscriptions = [
        ['product_id' => (int)$cowProd['id'], 'quantity' => 1.5, 'custom_rate' => 60.0, 'shift' => 'both', 'is_active' => true],
        ['product_id' => (int)$bufProd['id'], 'quantity' => 1.0, 'custom_rate' => 75.0, 'shift' => 'both', 'is_active' => true],
    ];
    $productService->saveCustomerSubscriptions($tenantId, $custId, $subscriptions);

    // Verify stored subscriptions
    $stored = $productService->getCustomerSubscriptions($tenantId, $custId);
    if (count($stored) !== 2) {
        throw new \Exception("Expected 2 subscriptions, got " . count($stored));
    }

    $cowSub = null;
    $bufSub = null;
    foreach ($stored as $s) {
        if ($s['product_code'] === 'cow') $cowSub = $s;
        if ($s['product_code'] === 'buffalo') $bufSub = $s;
    }

    if (!$cowSub || (float)$cowSub['default_quantity'] !== 1.5 || (float)$cowSub['custom_rate'] !== 60.0) {
        throw new \Exception("Cow subscription mismatch");
    }
    if (!$bufSub || (float)$bufSub['default_quantity'] !== 1.0 || (float)$bufSub['custom_rate'] !== 75.0) {
        throw new \Exception("Buffalo subscription mismatch");
    }

    // Verify total default quantity across subscriptions is 2.5L
    $totalSubQty = array_sum(array_column($stored, 'default_quantity'));
    if ((float)$totalSubQty !== 2.5) {
        throw new \Exception("Total subscription quantity mismatch (expected 2.5, got {$totalSubQty})");
    }

    // Clean up
    $db->execute("DELETE FROM customers WHERE id = ?", [$custId]);
}, $passed, $failed);

// TEST 12: Daily Delivery Itemized Tracking & Zero-Charge Rule
runTest("Daily Delivery Run-Sheet: Itemized Product Tracking & Zero-Charge Rule", function() use ($db, $deliveryService) {
    $tenantId = 1;
    $productService = new \App\Services\ProductService();

    // Create a customer
    $r1 = (int)$db->fetchValue("SELECT id FROM routes WHERE tenant_id = ? LIMIT 1", [$tenantId]);
    $db->execute("INSERT INTO customers (tenant_id, route_id, name, phone, address, milk_types, status) 
                  VALUES (?, ?, 'RunSheet Item Test Cust', '9888800002', 'Flat 200', 'cow,buffalo', 'active')",
                  [$tenantId, $r1]);
    $custId = (int)$db->lastInsertId();

    $cowProd = $db->fetchOne("SELECT id FROM products WHERE tenant_id = ? AND code = 'cow'", [$tenantId]);
    $bufProd = $db->fetchOne("SELECT id FROM products WHERE tenant_id = ? AND code = 'buffalo'", [$tenantId]);

    $subscriptions = [
        ['product_id' => (int)$cowProd['id'], 'quantity' => 1.5, 'custom_rate' => 60.0, 'shift' => 'both', 'is_active' => true],
        ['product_id' => (int)$bufProd['id'], 'quantity' => 0.5, 'custom_rate' => 70.0, 'shift' => 'both', 'is_active' => true],
    ];
    $productService->saveCustomerSubscriptions($tenantId, $custId, $subscriptions);

    $date = date('Y-m-d');

    // 1. Deliver default: should log 1.5L cow and 0.5L buffalo
    $deliveryService->recordDelivery($tenantId, $custId, $date, 'morning', 'delivered');
    $del = $db->fetchOne("SELECT id, quantity FROM daily_deliveries WHERE tenant_id = ? AND customer_id = ? AND delivery_date = ? AND shift = 'morning'", [$tenantId, $custId, $date]);
    if (!$del || (float)$del['quantity'] !== 2.0) {
        throw new \Exception("Expected total delivery qty 2.0, got " . ($del['quantity'] ?? 'null'));
    }

    $items = $db->fetchAll("SELECT * FROM daily_delivery_items WHERE delivery_id = ? ORDER BY product_id ASC", [$del['id']]);
    if (count($items) !== 2) {
        throw new \Exception("Expected 2 daily_delivery_items, got " . count($items));
    }

    // 2. Custom adjustment: Customer asks for extra 0.5L Buffalo milk today (1.5L Cow, 1.0L Buffalo = 2.5L total)
    $deliveryService->recordDelivery($tenantId, $custId, $date, 'morning', 'delivered', null, 'Extra buffalo milk', [
        (int)$cowProd['id'] => 1.5,
        (int)$bufProd['id'] => 1.0,
    ]);

    $delUpdated = $db->fetchOne("SELECT id, quantity FROM daily_deliveries WHERE id = ?", [$del['id']]);
    if ((float)$delUpdated['quantity'] !== 2.5) {
        throw new \Exception("Adjusted delivery total mismatch: expected 2.5, got " . $delUpdated['quantity']);
    }

    // 3. Zero-Charge Rule on skip: both items must become 0.00
    $deliveryService->recordDelivery($tenantId, $custId, $date, 'morning', 'skipped');
    $skipItems = $db->fetchAll("SELECT * FROM daily_delivery_items WHERE delivery_id = ?", [$del['id']]);
    foreach ($skipItems as $si) {
        if ((float)$si['quantity'] !== 0.0) {
            throw new \Exception("Zero-charge rule failed for product " . $si['product_id'] . " on skip!");
        }
    }

    // Clean up
    $db->execute("DELETE FROM customers WHERE id = ?", [$custId]);
}, $passed, $failed);

// TEST 13: Multi-Product Itemized Billing
runTest("Multi-Product Invoicing: Itemized Product Subtotals", function() use ($db, $billingService, $deliveryService) {
    $tenantId = 1;
    $productService = new \App\Services\ProductService();

    // Create a customer
    $r1 = (int)$db->fetchValue("SELECT id FROM routes WHERE tenant_id = ? LIMIT 1", [$tenantId]);
    $db->execute("INSERT INTO customers (tenant_id, route_id, name, phone, address, milk_types, status) 
                  VALUES (?, ?, 'Billing Item Test Cust', '9888800003', 'Flat 300', 'cow,buffalo', 'active')",
                  [$tenantId, $r1]);
    $custId = (int)$db->lastInsertId();

    $cowProd = $db->fetchOne("SELECT id FROM products WHERE tenant_id = ? AND code = 'cow'", [$tenantId]);
    $bufProd = $db->fetchOne("SELECT id FROM products WHERE tenant_id = ? AND code = 'buffalo'", [$tenantId]);

    $subscriptions = [
        ['product_id' => (int)$cowProd['id'], 'quantity' => 2.0, 'custom_rate' => 60.0, 'shift' => 'both', 'is_active' => true],
        ['product_id' => (int)$bufProd['id'], 'quantity' => 1.0, 'custom_rate' => 80.0, 'shift' => 'both', 'is_active' => true],
    ];
    $productService->saveCustomerSubscriptions($tenantId, $custId, $subscriptions);

    // Record deliveries for test month
    $year = 2026;
    $month = 9;
    // Deliver for 10 days
    for ($d = 1; $d <= 10; $d++) {
        $dStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $deliveryService->recordDelivery($tenantId, $custId, $dStr, 'morning', 'delivered');
    }

    $bill = $billingService->calculateCustomerBill($tenantId, $custId, $year, $month);

    // 10 days * 2L Cow @ 60 = 20L * 60 = 1200
    // 10 days * 1L Buffalo @ 80 = 10L * 80 = 800
    // Milk subtotal should be 2000.00
    if ((float)$bill['milk_subtotal'] !== 2000.00) {
        throw new \Exception("Expected milk subtotal 2000.00, got " . $bill['milk_subtotal']);
    }

    if (empty($bill['products']) || count($bill['products']) !== 2) {
        throw new \Exception("Expected 2 itemized products in bill");
    }

    // Clean up
    $db->execute("DELETE FROM customers WHERE id = ?", [$custId]);
}, $passed, $failed);

// TEST 14: Route Entity Lifecycle, Tenant Scoping & Customer Assignment
runTest("Route Entity: Tenant Isolation, Default Route & Foreign Key Guard", function() use ($db) {
    $routeService = new \App\Services\RouteService();
    $tenantId = 1;
    
    // 1. Ensure default route exists
    $defaultRoute = $routeService->ensureDefaultRoute($tenantId);
    $defaultRouteId = (int)$defaultRoute['id'];
    if ($defaultRouteId <= 0) {
        throw new \Exception("Failed to ensure default route for tenant {$tenantId}");
    }
    
    $route = $routeService->getRouteById($defaultRouteId, $tenantId);
    if (!$route || $route['name'] !== 'Main Route') {
        throw new \Exception("Default route must be named 'Main Route'");
    }
    
    // 2. Prevent deleting default route
    $deleted = false;
    try {
        $routeService->deleteRoute($defaultRouteId, $tenantId);
        $deleted = true;
    } catch (\Exception $e) {
        // Expected
    }
    if ($deleted) {
        throw new \Exception("Security check failed: Default Main Route was deleted!");
    }
    
    // 3. Create a custom route
    $newRoute = $routeService->createRoute($tenantId, 'Express Route ' . uniqid(), 'Fast morning route', 10);
    $newRouteId = (int)$newRoute['id'];
    if ($newRouteId <= 0) {
        throw new \Exception("Failed to create custom route");
    }
    
    // 4. Verify tenant isolation: Tenant 2 cannot access or delete Tenant 1's route
    $tenant2Route = $routeService->getRouteById($newRouteId, 2);
    if ($tenant2Route !== null) {
        throw new \Exception("Tenant isolation failure: Tenant 2 accessed Tenant 1's route!");
    }
    
    // 5. Assign customer to new route
    $db->execute("INSERT INTO customers (tenant_id, route_id, name, phone, address, status) VALUES (?, ?, 'Route Cust', '9991112223', 'Sample', 'active')", [$tenantId, $newRouteId]);
    $custId = (int)$db->lastInsertId();
    
    // 6. Attempt to delete route with active customers - must throw exception
    $deletedWithCust = false;
    try {
        $routeService->deleteRoute($newRouteId, $tenantId);
        $deletedWithCust = true;
    } catch (\Exception $e) {
        // Expected
    }
    if ($deletedWithCust) {
        throw new \Exception("Foreign key safeguard failed: Deleted route with assigned customers!");
    }
    
    // Clean up
    $db->execute("DELETE FROM customers WHERE id = ?", [$custId]);
    $routeService->deleteRoute($newRouteId, $tenantId);
}, $passed, $failed);

// TEST 15: Runsheet Search, Route Scoping & Pagination
runTest("Daily Run-Sheet: Search Filter, Route Scoping & Pagination", function() use ($deliveryService) {
    $tenantId = 1;
    $date = date('Y-m-d');
    $shift = 'morning';

    // 1. Full runsheet (unpaginated)
    $sheet = $deliveryService->getRunSheet($tenantId, $date, $shift);
    $totalHouses = $sheet['stats']['total_houses'];
    if ($totalHouses === 0) {
        throw new \Exception("Expected active customers in Tenant 1 runsheet");
    }

    // 2. Search by customer name
    $sampleCustomer = $sheet['items'][0];
    $sampleName = $sampleCustomer['customer_name'];
    $searchResult = $deliveryService->getRunSheet($tenantId, $date, $shift, null, $sampleName);
    if (empty($searchResult['items'])) {
        throw new \Exception("Search by customer name '{$sampleName}' returned 0 results");
    }
    foreach ($searchResult['items'] as $it) {
        if (!str_contains(strtolower($it['customer_name']), strtolower($sampleName)) &&
            !str_contains(strtolower($it['phone']), strtolower($sampleName)) &&
            !str_contains(strtolower($it['address']), strtolower($sampleName))) {
            throw new \Exception("Search result item '{$it['customer_name']}' does not match query '{$sampleName}'");
        }
    }

    // 3. Search non-existent customer
    $emptySearch = $deliveryService->getRunSheet($tenantId, $date, $shift, null, 'NonExistentZXYZ9988');
    if (!empty($emptySearch['items'])) {
        throw new \Exception("Expected 0 results for non-existent search query");
    }

    // 4. Route Scoping: Filter by route
    $routeName = $sampleCustomer['route_name'];
    $routeSheet = $deliveryService->getRunSheet($tenantId, $date, $shift, $routeName);
    foreach ($routeSheet['items'] as $it) {
        if ($it['route_name'] !== $routeName) {
            throw new \Exception("Route scoping breached: expected route '{$routeName}', got '{$it['route_name']}'");
        }
    }

    // 5. Pagination
    $paged = $deliveryService->getRunSheet($tenantId, $date, $shift, null, null, 1, 2);
    if (!isset($paged['pagination'])) {
        throw new \Exception("Pagination metadata missing from getRunSheet response");
    }
    if ($paged['pagination']['current_page'] !== 1 || $paged['pagination']['per_page'] !== 2) {
        throw new \Exception("Pagination metadata mismatch");
    }
    if (count($paged['items']) > 2) {
        throw new \Exception("Items count exceeds per_page (2) limit: got " . count($paged['items']));
    }
    // Verify shift metrics remain accurate across all houses, not just the page slice
    if ($paged['stats']['total_houses'] !== $totalHouses) {
        throw new \Exception("Pagination broke shift metrics: expected total {$totalHouses}, got {$paged['stats']['total_houses']}");
    }
}, $passed, $failed);

// TEST 16: Staff Management & Delivery Rider RBAC Logic
runTest("Staff Management & Delivery Rider RBAC Logic", function() use ($db) {
    $staffService = new \App\Services\StaffService();
    $tenantId = 1;

    // 1. Create a new test staff member (delivery rider)
    $phone = '9876599999';
    $db->execute("DELETE FROM tenant_users WHERE phone = ? AND tenant_id = ?", [$phone, $tenantId]);

    $mainRoute = $db->fetchOne("SELECT id FROM routes WHERE tenant_id = ? LIMIT 1", [$tenantId]);
    $routeId = (int)$mainRoute['id'];

    $staff = $staffService->createStaff(
        $tenantId,
        'Test Rider Ramu',
        $phone,
        'riderpass123',
        $routeId,
        'delivery',
        'ramu@test.com'
    );

    if (empty($staff['id']) || $staff['role'] !== 'delivery' || $staff['assigned_route_id'] !== $routeId) {
        throw new \Exception("Failed to create staff member correctly");
    }

    // Verify password verification
    $userRow = $db->fetchOne("SELECT * FROM tenant_users WHERE id = ?", [$staff['id']]);
    if (!password_verify('riderpass123', (string)$userRow['password_hash'])) {
        throw new \Exception("Password hash verification failed for created staff");
    }

    // 2. Duplicate phone validation within tenant
    try {
        $staffService->createStaff($tenantId, 'Duplicate Ramu', $phone, 'pass456', null);
        throw new \Exception("Duplicate phone did not throw InvalidArgumentException");
    } catch (\InvalidArgumentException $e) {
        // Expected
    }

    // 3. Toggle active status
    $toggled = $staffService->toggleStaff((int)$staff['id'], $tenantId);
    if ($toggled['is_active'] !== 0) {
        throw new \Exception("Expected staff to be deactivated, got is_active=" . $toggled['is_active']);
    }

    // Toggle back
    $toggled2 = $staffService->toggleStaff((int)$staff['id'], $tenantId);
    if ($toggled2['is_active'] !== 1) {
        throw new \Exception("Expected staff to be reactivated, got is_active=" . $toggled2['is_active']);
    }

    // 4. Update staff
    $updated = $staffService->updateStaff(
        (int)$staff['id'],
        $tenantId,
        'Test Rider Ramu Updated',
        $phone,
        null,
        $routeId,
        'delivery',
        'ramu.updated@test.com'
    );
    if ($updated['name'] !== 'Test Rider Ramu Updated') {
        throw new \Exception("Staff name update failed");
    }

    // 5. Delete staff
    $staffService->deleteStaff((int)$staff['id'], $tenantId);
    $deletedCheck = $staffService->getStaffById((int)$staff['id'], $tenantId);
    if ($deletedCheck !== null) {
        throw new \Exception("Staff deletion failed");
    }
}, $passed, $failed);

// TEST 17: Full-Month Bulk Auto-Fill & Vacation/Zero-Charge Guard
runTest("Full-Month Bulk Auto-Fill: 1-Click Generation & Vacation Guard", function() use ($db) {
    $deliveryService = new \App\Services\DeliveryService();
    $tenantId = 1;
    $year = 2026;
    $month = 11; // November 2026 (30 days)

    // Create a dedicated test customer for full-month testing
    $route = $db->fetchOne("SELECT id, name FROM routes WHERE tenant_id = 1 LIMIT 1");
    $db->execute(
        "INSERT INTO customers (tenant_id, route_id, name, phone, address, status, milk_types)
         VALUES (1, ?, 'Month Test Customer', '9888800001', 'Flat 101, Star Apts', 'active', 'cow,buffalo')",
        [$route['id']]
    );
    $cId = (int)$db->fetchValue("SELECT LAST_INSERT_ID()");

    // Add Cow and Buffalo subscriptions
    $cowProd = $db->fetchOne("SELECT id FROM products WHERE tenant_id = 1 AND code = 'cow'");
    $bufProd = $db->fetchOne("SELECT id FROM products WHERE tenant_id = 1 AND code = 'buffalo'");

    $db->execute("INSERT INTO customer_subscriptions (tenant_id, customer_id, product_id, default_quantity, custom_rate, shift, is_active)
                  VALUES (1, ?, ?, 1.50, 65.00, 'both', 1)", [$cId, $cowProd['id']]);
    $db->execute("INSERT INTO customer_subscriptions (tenant_id, customer_id, product_id, default_quantity, custom_rate, shift, is_active)
                  VALUES (1, ?, ?, 1.00, 80.00, 'morning', 1)", [$cId, $bufProd['id']]);

    // Schedule vacation for days 10-12
    $db->execute(
        "INSERT INTO customer_leaves (tenant_id, customer_id, start_date, end_date, reason)
         VALUES (1, ?, '2026-11-10', '2026-11-12', 'Family Trip')",
        [$cId]
    );

    // 1. Run month fill with overwrite: true for this customer's route
    $res = $deliveryService->fillMonthDeliveries($tenantId, $year, $month, [
        'route_id' => (int)$route['id'],
        'shift' => 'both',
        'overwrite' => true,
    ]);

    if ($res['days_in_month'] !== 30 || $res['records_created'] < 30) {
        throw new \Exception("Month fill did not generate expected records: " . json_encode($res));
    }

    // 2. Verify normal day (Nov 5) morning shift
    $normDelivM = $db->fetchOne(
        "SELECT * FROM daily_deliveries WHERE tenant_id = 1 AND customer_id = ? AND delivery_date = '2026-11-05' AND shift = 'morning'",
        [$cId]
    );
    if (!$normDelivM || $normDelivM['status'] !== 'delivered' || (float)$normDelivM['quantity'] !== 2.50) {
        throw new \Exception("Normal morning delivery mismatch: expected 2.50L delivered, got " . json_encode($normDelivM));
    }

    // Verify itemized products for Nov 5 morning (1.5L cow + 1.0L buffalo)
    $itemsM = $db->fetchAll(
        "SELECT * FROM daily_delivery_items WHERE delivery_id = ? ORDER BY product_id ASC",
        [$normDelivM['id']]
    );
    if (count($itemsM) !== 2 || (float)$itemsM[0]['quantity'] !== 1.50 || (float)$itemsM[1]['quantity'] !== 1.00) {
        throw new \Exception("Itemized line items mismatch for Nov 5: " . json_encode($itemsM));
    }

    // 3. Verify vacation day (Nov 11) morning shift: MUST be leave and strictly 0.00L
    $leaveDeliv = $db->fetchOne(
        "SELECT * FROM daily_deliveries WHERE tenant_id = 1 AND customer_id = ? AND delivery_date = '2026-11-11' AND shift = 'morning'",
        [$cId]
    );
    if (!$leaveDeliv || $leaveDeliv['status'] !== 'leave' || (float)$leaveDeliv['quantity'] !== 0.00) {
        throw new \Exception("Vacation guard failed: expected status leave and 0.00L, got " . json_encode($leaveDeliv));
    }

    // 4. Test Preserve Existing: modify Nov 15 quantity to 5.0L
    $deliveryService->recordDelivery($tenantId, $cId, '2026-11-15', 'morning', 'delivered', 5.0, 'Custom party order');
    
    // Re-run month fill with overwrite: false
    $resPreserve = $deliveryService->fillMonthDeliveries($tenantId, $year, $month, [
        'route_id' => (int)$route['id'],
        'shift' => 'both',
        'overwrite' => false,
    ]);

    $checkNov15 = $db->fetchOne(
        "SELECT * FROM daily_deliveries WHERE tenant_id = 1 AND customer_id = ? AND delivery_date = '2026-11-15' AND shift = 'morning'",
        [$cId]
    );
    if ((float)$checkNov15['quantity'] !== 5.00) {
        throw new \Exception("Preserve existing failed: custom record was overwritten! Got " . $checkNov15['quantity']);
    }

    // 5. Test single-customer month fill (fill only this specific customer)
    $resSingle = $deliveryService->fillMonthDeliveries($tenantId, $year, $month, [
        'customer_id' => $cId,
        'shift' => 'both',
        'overwrite' => true,
    ]);
    if ($resSingle['customers_processed'] !== 1) {
        throw new \Exception("Expected exactly 1 customer processed, got " . $resSingle['customers_processed']);
    }

    // Cleanup
    $db->execute("DELETE FROM daily_deliveries WHERE customer_id = ?", [$cId]);
    $db->execute("DELETE FROM customer_leaves WHERE customer_id = ?", [$cId]);
    $db->execute("DELETE FROM customer_subscriptions WHERE customer_id = ?", [$cId]);
    $db->execute("DELETE FROM customers WHERE id = ?", [$cId]);
}, $passed, $failed);

// TEST 18: Customer Monthly Milk Card (1-31 Matrix)
runTest("Customer Monthly Milk Card: Interactive Matrix & Upsert", function() use ($db) {
    $deliveryService = new \App\Services\DeliveryService();
    $tenantId = 1;
    $year = 2026;
    $month = 11;

    // Use an existing seeded customer
    $cust = $db->fetchOne("SELECT id, name FROM customers WHERE tenant_id = 1 LIMIT 1");
    $cId = (int)$cust['id'];

    // 1. Fetch Month Sheet
    $sheet = $deliveryService->getCustomerMonthSheet($tenantId, $cId, $year, $month);
    if ($sheet['days_in_month'] !== 30 || count($sheet['days']) !== 30 || empty($sheet['summary'])) {
        throw new \Exception("Invalid customer month sheet returned: " . json_encode($sheet));
    }

    // 2. Save Milk Card changes for Days 1 and 2
    $updateData = [
        [
            'date' => '2026-11-01',
            'shift' => 'morning',
            'status' => 'delivered',
            'quantity' => 3.5,
            'notes' => 'Extra 1.5L requested'
        ],
        [
            'date' => '2026-11-02',
            'shift' => 'morning',
            'status' => 'skipped',
            'quantity' => 0.0,
            'notes' => 'Customer skipped'
        ]
    ];

    $updatedSheet = $deliveryService->saveCustomerMonthSheet($tenantId, $cId, $year, $month, $updateData);
    $day1 = $updatedSheet['days'][0];
    $day2 = $updatedSheet['days'][1];

    if ($day1['shifts']['morning']['status'] !== 'delivered' || (float)$day1['shifts']['morning']['quantity'] !== 3.5) {
        throw new \Exception("Day 1 update failed: " . json_encode($day1));
    }
    if ($day2['shifts']['morning']['status'] !== 'skipped' || (float)$day2['shifts']['morning']['quantity'] !== 0.0) {
        throw new \Exception("Day 2 zero-charge skip failed: " . json_encode($day2));
    }
}, $passed, $failed);

// TEST 19: Named Subscription Plans, Early Renewal, Mid-Cycle Upgrade, 7-Day Downgrade & Grace/Expiry
runTest("SaaS Plans: Named Packs, Early Renewal, Mid-Cycle Upgrade, Downgrade Window & Soft Downgrade", function() use ($quotaService, $db) {
    $origSub = $db->fetchOne("SELECT * FROM tenant_subscriptions WHERE tenant_id = 2");
    $starter = $quotaService->getPlanByCode('starter');
    $growth = $quotaService->getPlanByCode('growth');
    $pro = $quotaService->getPlanByCode('pro');
    if (!$starter || !$growth || !$pro) {
        throw new \Exception("Default subscription plans missing");
    }

    try {
        // 1. Subscribe Tenant 2 to Starter (Monthly) -> 100 max, expires +1 month
        $q1 = $quotaService->applyPlanSubscription(2, (int)$starter['id'], 'monthly');
        $exp1 = (new DateTimeImmutable('today'))->modify('+1 month')->format('Y-m-d');
        if ($q1['plan_code'] !== 'starter' || $q1['max_allowed'] !== 100 || $q1['valid_until'] !== $exp1) {
            throw new \Exception("Starter subscribe mismatch: " . json_encode($q1));
        }

        // 2. Early renewal of SAME pack (Starter Monthly): base 200.00 + 18% GST (36.00) = 236.00, new cycle starts after exp1
        $renewPreview = $quotaService->previewPlanSelection(2, (int)$starter['id'], 'monthly');
        $exp2 = (new DateTimeImmutable($exp1))->modify('+1 month')->format('Y-m-d');
        if (!$renewPreview['allowed'] || $renewPreview['action_type'] !== 'renew' || abs((float)$renewPreview['base_amount'] - 200.0) > 0.01 || abs((float)$renewPreview['gst_amount'] - 36.0) > 0.01 || abs((float)$renewPreview['amount'] - 236.0) > 0.01 || $renewPreview['new_valid_until'] !== $exp2) {
            throw new \Exception("Early same-pack renewal preview mismatch: " . json_encode($renewPreview));
        }
        $q2 = $quotaService->applyPlanSubscription(2, (int)$starter['id'], 'monthly');
        if ($q2['valid_until'] !== $exp2) {
            throw new \Exception("Early renewal valid_until mismatch: " . json_encode($q2));
        }

        // 3. Mid-cycle upgrade Starter -> Growth (same cycle, >7 days left): base diff 300.00 + 18% GST (54.00) = 354.00, keeps exp2
        $upgPreview = $quotaService->previewPlanSelection(2, (int)$growth['id'], 'monthly');
        if (!$upgPreview['allowed'] || $upgPreview['action_type'] !== 'upgrade' || abs((float)$upgPreview['base_amount'] - 300.0) > 0.01 || abs((float)$upgPreview['gst_amount'] - 54.0) > 0.01 || abs((float)$upgPreview['amount'] - 354.0) > 0.01 || $upgPreview['new_valid_until'] !== $exp2) {
            throw new \Exception("Mid-cycle upgrade preview mismatch: " . json_encode($upgPreview));
        }
        $q3 = $quotaService->applyPlanSubscription(2, (int)$growth['id'], 'monthly');
        if ($q3['plan_code'] !== 'growth' || $q3['max_allowed'] !== 300 || $q3['valid_until'] !== $exp2) {
            throw new \Exception("Growth upgrade failed: " . json_encode($q3));
        }

        // 4. Downgrade Growth -> Starter blocked outside 7-day renewal window
        $earlyDown = $quotaService->previewPlanSelection(2, (int)$starter['id'], 'monthly');
        if ($earlyDown['allowed'] !== false) {
            throw new \Exception("Downgrade should be blocked outside 7-day renewal window");
        }

        // 5. Within 7-day renewal window (valid_until 3 days from today): downgrade allowed, extends from valid_until
        $threeDaysLater = (new DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d');
        $db->execute("UPDATE tenant_subscriptions SET valid_until = ?, payment_status = 'active' WHERE tenant_id = 2", [$threeDaysLater]);
        $windowDown = $quotaService->previewPlanSelection(2, (int)$starter['id'], 'yearly');
        $expectedWindowEnd = (new DateTimeImmutable($threeDaysLater))->modify('+1 year')->format('Y-m-d');
        if (!$windowDown['allowed'] || $windowDown['action_type'] !== 'downgrade' || $windowDown['new_valid_until'] !== $expectedWindowEnd) {
            throw new \Exception("Downgrade within 7-day window failed: " . json_encode($windowDown));
        }
        $q4 = $quotaService->applyPlanSubscription(2, (int)$starter['id'], 'yearly');
        if ($q4['plan_code'] !== 'starter' || $q4['billing_cycle'] !== 'yearly' || $q4['max_allowed'] !== 100 || $q4['valid_until'] !== $expectedWindowEnd) {
            throw new \Exception("Applying downgrade within renewal window failed: " . json_encode($q4));
        }

        // 6. Grace period (valid_until 2 days ago) -> still active max_allowed, is_in_grace = true
        $twoDaysAgo = (new DateTimeImmutable('today'))->modify('-2 days')->format('Y-m-d');
        $db->execute("UPDATE tenant_subscriptions SET valid_until = ?, payment_status = 'active' WHERE tenant_id = 2", [$twoDaysAgo]);
        $graceQuota = $quotaService->getTenantQuota(2);
        if (!$graceQuota['is_in_grace'] || $graceQuota['is_expired'] || $graceQuota['max_allowed'] !== 100) {
            throw new \Exception("Grace period enforcement mismatch: " . json_encode($graceQuota));
        }

        // 7. Expired past grace (valid_until 15 days ago) -> soft downgrade to free_limit
        $fifteenDaysAgo = (new DateTimeImmutable('today'))->modify('-15 days')->format('Y-m-d');
        $db->execute("UPDATE tenant_subscriptions SET valid_until = ?, payment_status = 'active' WHERE tenant_id = 2", [$fifteenDaysAgo]);
        $expiredQuota = $quotaService->getTenantQuota(2);
        if (!$expiredQuota['is_expired'] || $expiredQuota['is_in_grace'] || $expiredQuota['max_allowed'] !== $expiredQuota['free_limit'] || $expiredQuota['payment_status'] !== 'expired') {
            throw new \Exception("Soft downgrade on expiry failed: " . json_encode($expiredQuota));
        }

        // 8. Yearly Pro activation (12000 + 18% GST = 14160) via Razorpay + Tax Invoice + Email/WA dispatch
        $q5 = $quotaService->applyPlanSubscription(2, (int)$pro['id'], 'yearly', [
            'payment_method' => 'razorpay',
            'utr_number' => 'pay_pro_' . time(),
        ]);
        if (($q5['plan_code'] ?? '') !== 'pro' || ($q5['max_allowed'] ?? 0) !== 1000) {
            throw new \Exception("Pro yearly activation mismatch: " . json_encode($q5));
        }
        $inv = $quotaService->getLastGeneratedInvoice();
        if (
            !$inv
            || ($inv['seller_name'] ?? '') !== 'Shri Ram Open Source Technologies'
            || ($inv['seller_gstin'] ?? '') !== '27AARPE1865G1ZJ'
            || ($inv['billing_cycle'] ?? '') !== 'yearly'
            || abs((float)$inv['base_amount'] - 12000.0) > 0.01
            || abs((float)$inv['gst_amount'] - 2160.0) > 0.01
            || abs((float)$inv['total_amount'] - 14160.0) > 0.01
            || ($inv['email_status'] ?? '') !== 'sent'
            || empty($inv['whatsapp_url'])
        ) {
            throw new \Exception("Subscription GST invoice or dispatch mismatch: " . json_encode($inv));
        }

        // Verify invoice HTML & PDF binary generation
        $invSvc = new \App\Services\SubscriptionInvoiceService($db);
        $html = $invSvc->renderInvoiceHtml($inv, true);
        if (strpos($html, '27AARPE1865G1ZJ') === false || strpos($html, 'Shri Ram Open Source Technologies') === false || strpos($html, 'All rights reserved') === false) {
            throw new \Exception("Subscription tax invoice HTML missing GSTIN/company/footer");
        }
        $pdfBytes = $invSvc->renderInvoicePdfBytes($inv);
        if (substr($pdfBytes, 0, 4) !== '%PDF') {
            throw new \Exception("Subscription tax invoice PDF generation failed");
        }
    } finally {
        $db->execute("DELETE FROM subscription_invoices WHERE tenant_id = 2");
        if ($origSub) {
            $db->execute(
                "UPDATE tenant_subscriptions SET plan_id = ?, purchased_blocks = ?, max_customers_allowed = ?, amount_paid = ?, billing_cycle = ?, started_at = ?, valid_until = ?, payment_status = ? WHERE tenant_id = 2",
                [
                    $origSub['plan_id'] ?? null,
                    $origSub['purchased_blocks'],
                    $origSub['max_customers_allowed'],
                    $origSub['amount_paid'],
                    $origSub['billing_cycle'] ?? null,
                    $origSub['started_at'] ?? null,
                    $origSub['valid_until'],
                    $origSub['payment_status'],
                ]
            );
        }
    }
}, $passed, $failed);

// TEST 20: Razorpay Order Creation, HMAC Signature Verification, Idempotency & Webhook
runTest("Razorpay Payment Gateway: Order, Signature Verify, Idempotent Fulfillment & Webhook", function() use ($quotaService, $db) {
    $origSub = $db->fetchOne("SELECT * FROM tenant_subscriptions WHERE tenant_id = 2");
    $origRzpSettings = $db->fetchAll("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'razorpay_%'");
    $starter = $quotaService->getPlanByCode('starter');
    $growth = $quotaService->getPlanByCode('growth');

    $testSecret = 'rzp_test_secret_unit_12345';
    $testWebhookSecret = 'rzp_wh_secret_unit_67890';

    try {
        // Configure Razorpay test keys
        $stmt = $db->getPdo()->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute(['razorpay_enabled', '1']);
        $stmt->execute(['razorpay_key_id', 'rzp_test_unit_key']);
        $stmt->execute(['razorpay_key_secret', $testSecret]);
        $stmt->execute(['razorpay_webhook_secret', $testWebhookSecret]);

        $rzp = new \App\Services\RazorpayService($db, $quotaService);
        if (!$rzp->isConfigured()) {
            throw new \Exception("Razorpay should report configured when enabled with test keys");
        }

        // 1. Create order for Starter Monthly (200 + 18% GST = 236 => 23600 paise)
        $order = $rzp->createSubscriptionOrder(2, (int)$starter['id'], 'monthly');
        if (empty($order['order_id']) || (int)$order['amount_paise'] !== 23600 || abs((float)$order['gst_amount'] - 36.0) > 0.01) {
            throw new \Exception("Razorpay order creation mismatch: " . json_encode($order));
        }

        // 2. Reject invalid signature
        $sigFailed = false;
        try {
            $rzp->verifyAndFulfillOrder(2, $order['order_id'], 'pay_bad_001', 'invalid_sig');
        } catch (\InvalidArgumentException $e) {
            $sigFailed = true;
        }
        if (!$sigFailed) {
            throw new \Exception("Invalid Razorpay signature was not rejected");
        }

        // Reset status back to created so valid signature test runs on same order or create fresh order
        $db->execute("UPDATE razorpay_orders SET status = 'created' WHERE razorpay_order_id = ?", [$order['order_id']]);

        // 3. Valid HMAC signature -> fulfills plan, creates GST invoice
        $paymentId = 'pay_test_' . time();
        $validSig = hash_hmac('sha256', $order['order_id'] . '|' . $paymentId, $testSecret);
        $fulfilled = $rzp->verifyAndFulfillOrder(2, $order['order_id'], $paymentId, $validSig);
        if (($fulfilled['quota']['plan_code'] ?? '') !== 'starter' || ($fulfilled['invoice']['payment_method'] ?? '') !== 'razorpay' || ($fulfilled['invoice']['utr_number'] ?? '') !== $paymentId) {
            throw new \Exception("Razorpay fulfillment mismatch: " . json_encode($fulfilled));
        }

        // 4. Second verification on same order must be idempotent
        $dup = $rzp->verifyAndFulfillOrder(2, $order['order_id'], $paymentId, $validSig);
        if (empty($dup['already_processed'])) {
            throw new \Exception("Duplicate Razorpay verification was not idempotent");
        }

        // 5. Webhook fulfillment on a Growth Monthly order
        $order2 = $rzp->createSubscriptionOrder(2, (int)$growth['id'], 'monthly');
        $pay2 = 'pay_wh_' . time();
        $whPayload = json_encode([
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => $pay2,
                        'order_id' => $order2['order_id'],
                    ],
                ],
            ],
        ]);
        $whSig = hash_hmac('sha256', $whPayload, $testWebhookSecret);
        $whRes = $rzp->handleWebhook($whPayload, $whSig);
        if (empty($whRes['handled']) || empty($whRes['invoice_id'])) {
            throw new \Exception("Razorpay webhook fulfillment failed: " . json_encode($whRes));
        }

        $qAfter = $quotaService->getTenantQuota(2);
        if ($qAfter['plan_code'] !== 'growth' || $qAfter['max_allowed'] !== 300) {
            throw new \Exception("Quota not upgraded to Growth after webhook: " . json_encode($qAfter));
        }
    } finally {
        $db->execute("DELETE FROM razorpay_orders WHERE tenant_id = 2");
        $db->execute("DELETE FROM subscription_invoices WHERE tenant_id = 2");
        if ($origSub) {
            $db->execute(
                "UPDATE tenant_subscriptions SET plan_id = ?, purchased_blocks = ?, max_customers_allowed = ?, amount_paid = ?, billing_cycle = ?, started_at = ?, valid_until = ?, payment_status = ? WHERE tenant_id = 2",
                [
                    $origSub['plan_id'] ?? null,
                    $origSub['purchased_blocks'],
                    $origSub['max_customers_allowed'],
                    $origSub['amount_paid'],
                    $origSub['billing_cycle'] ?? null,
                    $origSub['started_at'] ?? null,
                    $origSub['valid_until'],
                    $origSub['payment_status'],
                ]
            );
        }
        $db->execute("UPDATE system_settings SET setting_value = '0' WHERE setting_key = 'razorpay_enabled'");
        $db->execute("UPDATE system_settings SET setting_value = '' WHERE setting_key IN ('razorpay_key_id', 'razorpay_key_secret', 'razorpay_webhook_secret')");
        foreach ($origRzpSettings as $rs) {
            $db->execute("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?", [$rs['setting_value'], $rs['setting_key']]);
        }
    }
}, $passed, $failed);

echo "\n========================================================\n";
echo "   TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "========================================================\n";

exit($failed > 0 ? 1 : 0);

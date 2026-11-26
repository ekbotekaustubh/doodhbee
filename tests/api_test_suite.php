<?php
declare(strict_types=1);

define('DOODHBEE_TEST_RUNNER', true);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

echo "========================================================\n";
echo "     DOODHBEE REST API AUTOMATED TEST SUITE           \n";
echo "========================================================\n\n";

$db = Database::getInstance();
$passed = 0;
$failed = 0;

function runApiTest(string $name, callable $testFn, int &$passed, int &$failed): void
{
    echo "• Running: {$name}... ";
    try {
        $testFn();
        echo "\033[32m[PASS]\033[0m\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "\033[31m[FAIL]\033[0m\n";
        echo "  Error: " . $e->getMessage() . "\n";
        echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        $failed++;
    }
}

/**
 * Helper to simulate an API request through the Router and capture JSON response
 */
function callApi(string $method, string $path, array $data = [], ?string $bearerToken = null): array
{
    $_SERVER['REQUEST_METHOD'] = strtoupper($method);
    $_SERVER['REQUEST_URI'] = $path;
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';

    if ($bearerToken !== null) {
        $_SERVER['HTTP_AUTHORIZATION'] = "Bearer {$bearerToken}";
    } else {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
        $_POST = $data;
        $_GET = [];
    } else {
        $_GET = $data;
        $_POST = [];
    }

    Auth::resetApiState();

    $request = new Request();
    $router = new Router();
    $routes = require __DIR__ . '/../routes.php';
    $routes($router);

    ob_start();
    try {
        $router->dispatch($request);
    } catch (\App\Core\HttpResponseException $e) {
        // Handled HTTP response
    } catch (\Throwable $e) {
        ob_end_clean();
        throw $e;
    }
    $output = ob_get_clean();

    $json = json_decode($output, true);
    if (!is_array($json)) {
        throw new \Exception("Invalid JSON response (len " . strlen($output) . ", json_err: " . json_last_error_msg() . ") from {$method} {$path}: " . $output);
    }

    return [
        'status' => Response::getStatusCode(),
        'body' => $json,
    ];
}

// ==========================================
// TEST 1: Tenant & Admin Authentication
// ==========================================
$tenantToken = null;
$adminToken = null;

runApiTest("API Auth: Tenant & Admin Login with Bearer Token Generation", function() use (&$tenantToken, &$adminToken) {
    // 1. Invalid login
    $resFail = callApi('POST', '/api/auth/login', [
        'login' => 'ramesh@krishnadairy.com',
        'password' => 'wrongpassword'
    ]);
    if ($resFail['status'] !== 401 || $resFail['body']['success'] !== false) {
        throw new \Exception("Expected 401 for invalid login");
    }

    // 2. Valid tenant login
    $res = callApi('POST', '/api/auth/login', [
        'login' => 'ramesh@krishnadairy.com',
        'password' => 'password123',
        'device_name' => 'API Test Runner'
    ]);

    if ($res['status'] !== 200 || empty($res['body']['data']['token'])) {
        throw new \Exception("Tenant login failed: " . json_encode($res));
    }
    $tenantToken = $res['body']['data']['token'];

    // 3. Admin login
    $resAdmin = callApi('POST', '/api/admin/auth/login', [
        'email' => 'admin@doodhbee.com',
        'password' => 'admin123'
    ]);

    if ($resAdmin['status'] !== 200 || empty($resAdmin['body']['data']['token'])) {
        throw new \Exception("Admin login failed: " . json_encode($resAdmin));
    }
    $adminToken = $resAdmin['body']['data']['token'];
}, $passed, $failed);

// ==========================================
// TEST 2: Protected Endpoints & Bearer Auth
// ==========================================
runApiTest("API Security: Unauthenticated Rejection & Bearer Token Resolution", function() use (&$tenantToken) {
    // Call /api/auth/me without token
    $resNoAuth = callApi('GET', '/api/auth/me');
    if ($resNoAuth['status'] !== 401 || $resNoAuth['body']['success'] !== false) {
        throw new \Exception("Expected 401 for unauthenticated request, got " . $resNoAuth['status']);
    }

    // Call with valid Bearer token
    $resAuth = callApi('GET', '/api/auth/me', [], $tenantToken);
    if ($resAuth['status'] !== 200 || $resAuth['body']['data']['tenant']['id'] !== 1) {
        throw new \Exception("Failed to resolve tenant from Bearer token");
    }
}, $passed, $failed);

// ==========================================
// TEST 3: Dashboard Statistics API
// ==========================================
runApiTest("API Dashboard: Live Shift Counts and Monthly KPIs", function() use (&$tenantToken) {
    $res = callApi('GET', '/api/dashboard/stats', [], $tenantToken);
    if ($res['status'] !== 200 || !isset($res['body']['data']['shifts']['morning'])) {
        throw new \Exception("Dashboard stats API returned invalid payload");
    }
    $data = $res['body']['data'];
    if (!isset($data['quota']['remaining_slots']) || !isset($data['billing_month'])) {
        throw new \Exception("Missing quota or billing month in dashboard stats");
    }
}, $passed, $failed);

// ==========================================
// TEST 4: Product Catalog APIs
// ==========================================
$createdProductId = 0;
runApiTest("API Products: Catalog Listing, Creation & Toggle", function() use (&$tenantToken, &$createdProductId) {
    // 1. List products
    $resList = callApi('GET', '/api/products', [], $tenantToken);
    if ($resList['status'] !== 200 || count($resList['body']['data']) < 2) {
        throw new \Exception("Expected at least 2 default products (Cow & Buffalo)");
    }

    // 2. Create custom product
    $resCreate = callApi('POST', '/api/products', [
        'name' => 'API Organic Ghee',
        'code' => 'api_ghee_' . time(),
        'unit' => 'kg',
        'default_rate' => 650.00,
        'sort_order' => 5
    ], $tenantToken);

    if ($resCreate['status'] !== 201 || empty($resCreate['body']['data']['id'])) {
        throw new \Exception("Failed to create product via API: " . json_encode($resCreate));
    }
    $createdProductId = (int)$resCreate['body']['data']['id'];

    // 3. Toggle product status
    $resToggle = callApi('POST', "/api/products/{$createdProductId}/toggle", [], $tenantToken);
    if ($resToggle['status'] !== 200 || $resToggle['body']['data']['is_active'] !== 0) {
        throw new \Exception("Product toggle failed");
    }
}, $passed, $failed);

// ==========================================
// TEST 4b: Delivery Route Management (CRUD & Scoping)
// ==========================================
$createdRouteId = 0;
runApiTest("API Routes: Listing, Creation, Toggle & Main Route Guard", function() use (&$tenantToken, &$createdRouteId) {
    // 1. List routes - must have at least 'Main Route'
    $resList = callApi('GET', '/api/routes', [], $tenantToken);
    if ($resList['status'] !== 200 || !is_array($resList['body']['data'])) {
        throw new \Exception("Failed to list routes: " . json_encode($resList));
    }
    $names = array_column($resList['body']['data'], 'name');
    if (!in_array('Main Route', $names, true)) {
        throw new \Exception("Default 'Main Route' not found in routes list");
    }

    // 2. Create custom route
    $routeName = 'API Test Route ' . uniqid();
    $resCreate = callApi('POST', '/api/routes', [
        'name' => $routeName,
        'description' => 'Route created via API automated test',
        'sort_order' => 5
    ], $tenantToken);

    if ($resCreate['status'] !== 201 || empty($resCreate['body']['data']['id'])) {
        throw new \Exception("Failed to create route via API: " . json_encode($resCreate));
    }
    $createdRouteId = (int)$resCreate['body']['data']['id'];

    // 3. Toggle route
    $resToggle = callApi('POST', "/api/routes/{$createdRouteId}/toggle", [], $tenantToken);
    if ($resToggle['status'] !== 200 || $resToggle['body']['data']['is_active'] !== false) {
        throw new \Exception("Route toggle failed: " . json_encode($resToggle));
    }

    // Toggle back to active
    callApi('POST', "/api/routes/{$createdRouteId}/toggle", [], $tenantToken);
}, $passed, $failed);

// ==========================================
// TEST 5: Customer Management & Quota Guard
// ==========================================
$newCustomerId = 0;
runApiTest("API Customers: Creation with Subscriptions & Quota Enforcement", function() use (&$tenantToken, &$newCustomerId, $db) {
    // 1. List customers
    $resList = callApi('GET', '/api/customers', [], $tenantToken);
    if ($resList['status'] !== 200) {
        throw new \Exception("Failed to list customers");
    }

    // 2. Fetch routes
    $resRoutes = callApi('GET', '/api/customers/routes', [], $tenantToken);
    if ($resRoutes['status'] !== 200 || !is_array($resRoutes['body']['data'])) {
        throw new \Exception("Failed to fetch customer routes");
    }

    // 3. Create customer with Cow and Buffalo subscriptions
    $cowProd = $db->fetchOne("SELECT id FROM products WHERE tenant_id = 1 AND code = 'cow'");
    $bufProd = $db->fetchOne("SELECT id FROM products WHERE tenant_id = 1 AND code = 'buffalo'");

    $resCreate = callApi('POST', '/api/customers', [
        'name' => 'API Test Customer',
        'phone' => '9777711223',
        'address' => 'Flat 99, Cloud Residency',
        'route_name' => 'Tech Park Route',
        'status' => 'active',
        'subscriptions' => [
            ['product_id' => (int)$cowProd['id'], 'quantity' => 1.5, 'custom_rate' => 64.0, 'shift' => 'morning'],
            ['product_id' => (int)$bufProd['id'], 'quantity' => 2.0, 'custom_rate' => 74.0, 'shift' => 'both'],
        ]
    ], $tenantToken);

    if ($resCreate['status'] !== 201 || empty($resCreate['body']['data']['id'])) {
        throw new \Exception("Customer creation failed: " . json_encode($resCreate));
    }
    $newCustomerId = (int)$resCreate['body']['data']['id'];

    // 4. Verify customer detail
    $resShow = callApi('GET', "/api/customers/{$newCustomerId}", [], $tenantToken);
    if ($resShow['status'] !== 200 || count($resShow['body']['data']['subscriptions']) !== 2) {
        throw new \Exception("Customer subscriptions count mismatch in show API");
    }
}, $passed, $failed);

// ==========================================
// TEST 6: Customer Leave / Vacation Scheduling
// ==========================================
runApiTest("API Leaves: Vacation Scheduling & Deletion", function() use (&$tenantToken, &$newCustomerId) {
    $startDate = date('Y-m-d', strtotime('+5 days'));
    $endDate = date('Y-m-d', strtotime('+8 days'));

    // 1. Add vacation schedule
    $resAdd = callApi('POST', "/api/customers/{$newCustomerId}/leaves", [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'reason' => 'Annual Pilgrimage'
    ], $tenantToken);

    if ($resAdd['status'] !== 201 || empty($resAdd['body']['data']['id'])) {
        throw new \Exception("Failed to add customer leave: " . json_encode($resAdd));
    }
    $leaveId = (int)$resAdd['body']['data']['id'];

    // 2. List leaves
    $resList = callApi('GET', "/api/customers/{$newCustomerId}/leaves", [], $tenantToken);
    if ($resList['status'] !== 200 || count($resList['body']['data']) !== 1) {
        throw new \Exception("Leaves list count mismatch");
    }

    // 3. Delete leave
    $resDel = callApi('DELETE', "/api/customers/{$newCustomerId}/leaves/{$leaveId}", [], $tenantToken);
    if ($resDel['status'] !== 200) {
        throw new \Exception("Failed to delete leave");
    }
}, $passed, $failed);

// ==========================================
// TEST 7: Daily Delivery Run-Sheet & Zero-Charge Rule
// ==========================================
runApiTest("API Deliveries: Run-Sheet Fetching & Zero-Charge Toggle", function() use (&$tenantToken, &$newCustomerId) {
    $today = date('Y-m-d');

    // 1. Fetch run sheet
    $resSheet = callApi('GET', '/api/deliveries', ['date' => $today, 'shift' => 'morning'], $tenantToken);
    if ($resSheet['status'] !== 200 || !isset($resSheet['body']['data']['items'])) {
        throw new \Exception("Failed to fetch delivery run-sheet");
    }

    // 2. Toggle to Skipped -> Must enforce Zero-Charge Rule (quantity strictly 0.00)
    $resSkip = callApi('POST', '/api/deliveries/toggle', [
        'customer_id' => $newCustomerId,
        'date' => $today,
        'shift' => 'morning',
        'status' => 'skipped',
        'notes' => 'Gate locked'
    ], $tenantToken);

    if ($resSkip['status'] !== 200 || (float)$resSkip['body']['data']['delivery']['quantity'] !== 0.00) {
        throw new \Exception("Zero-charge rule failed on skipped delivery");
    }

    // 3. Toggle back to Delivered
    $resDeliver = callApi('POST', '/api/deliveries/toggle', [
        'customer_id' => $newCustomerId,
        'date' => $today,
        'shift' => 'morning',
        'status' => 'delivered'
    ], $tenantToken);

    if ($resDeliver['status'] !== 200 || (float)$resDeliver['body']['data']['delivery']['quantity'] <= 0.00) {
        throw new \Exception("Failed to mark delivery as delivered");
    }

    // 4. Test Runsheet Search Filter
    $resSearch = callApi('GET', '/api/deliveries', ['date' => $today, 'shift' => 'morning', 'q' => 'NonExistentXYZ999'], $tenantToken);
    if ($resSearch['status'] !== 200 || count($resSearch['body']['data']['items']) !== 0) {
        throw new \Exception("Runsheet search failed: Expected 0 items for non-existent query");
    }

    // 5. Test Runsheet Pagination & Route Scoping
    $resPaged = callApi('GET', '/api/deliveries', ['date' => $today, 'shift' => 'morning', 'page' => 1, 'per_page' => 2], $tenantToken);
    if ($resPaged['status'] !== 200 || !isset($resPaged['body']['data']['pagination'])) {
        throw new \Exception("Runsheet pagination failed: pagination metadata missing");
    }
    $pag = $resPaged['body']['data']['pagination'];
    if ($pag['current_page'] !== 1 || $pag['per_page'] !== 2 || count($resPaged['body']['data']['items']) > 2) {
        throw new \Exception("Runsheet pagination failed: items count exceeds per_page limit");
    }
}, $passed, $failed);

// ==========================================
// TEST 8: Billing, Invoices & WhatsApp Share
// ==========================================
$generatedInvoiceId = 0;
runApiTest("API Invoicing: Single & Batch Generation & WhatsApp Sharing", function() use (&$tenantToken, &$newCustomerId, &$generatedInvoiceId) {
    $month = (int)date('n');
    $year = (int)date('Y');

    // 1. Generate single invoice for customer
    $resGen = callApi('POST', '/api/invoices/generate-single', [
        'customer_id' => $newCustomerId,
        'month' => $month,
        'year' => $year
    ], $tenantToken);

    if ($resGen['status'] !== 200 || empty($resGen['body']['data']['id'])) {
        throw new \Exception("Single invoice generation failed: " . json_encode($resGen));
    }
    $generatedInvoiceId = (int)$resGen['body']['data']['id'];

    // 2. View invoice details
    $resShow = callApi('GET', "/api/invoices/{$generatedInvoiceId}", [], $tenantToken);
    if ($resShow['status'] !== 200 || !isset($resShow['body']['data']['summary']['final_amount'])) {
        throw new \Exception("Invoice detail API failed");
    }

    // 3. Get WhatsApp sharing link
    $resShare = callApi('GET', "/api/invoices/{$generatedInvoiceId}/share", [], $tenantToken);
    if ($resShare['status'] !== 200 || empty($resShare['body']['data']['whatsapp_url'])) {
        throw new \Exception("WhatsApp share URL missing");
    }
}, $passed, $failed);

// ==========================================
// TEST 9: Payments & Customer Ledger
// ==========================================
runApiTest("API Payments: Recording & Customer Chronological Ledger", function() use (&$tenantToken, &$newCustomerId, &$generatedInvoiceId) {
    // 1. Record payment against invoice
    $resPay = callApi('POST', '/api/payments', [
        'customer_id' => $newCustomerId,
        'invoice_id' => $generatedInvoiceId,
        'amount' => 500.00,
        'payment_date' => date('Y-m-d'),
        'payment_method' => 'upi',
        'reference_no' => 'UPI-TEST-123456',
        'notes' => 'Received via PhonePe'
    ], $tenantToken);

    if ($resPay['status'] !== 201 || empty($resPay['body']['data']['id'])) {
        throw new \Exception("Payment recording failed: " . json_encode($resPay));
    }

    // 2. Fetch customer ledger
    $resLedger = callApi('GET', "/api/customers/{$newCustomerId}/ledger", [], $tenantToken);
    if ($resLedger['status'] !== 200 || empty($resLedger['body']['data']['ledger'])) {
        throw new \Exception("Customer ledger failed");
    }

    $ledgerEntries = $resLedger['body']['data']['ledger'];
    $hasPayment = false;
    foreach ($ledgerEntries as $entry) {
        if ($entry['type'] === 'payment' && (float)$entry['credit'] === 500.00) {
            $hasPayment = true;
            break;
        }
    }
    if (!$hasPayment) {
        throw new \Exception("Payment credit entry not found in customer ledger");
    }
}, $passed, $failed);

// ==========================================
// TEST 10: Settings, Taxes & Custom Surcharges
// ==========================================
runApiTest("API Settings: Profile, Tax & Surcharge Management", function() use (&$tenantToken) {
    // 1. Update taxes
    $resTax = callApi('PUT', '/api/settings/taxes', [
        'tax_enabled' => 1,
        'tax_label' => 'GST',
        'tax_percentage' => 5.0,
        'tax_number' => '27AAAAA0000A1Z5'
    ], $tenantToken);

    if ($resTax['status'] !== 200 || $resTax['body']['data']['tax_enabled'] !== true) {
        throw new \Exception("Tax update failed: " . json_encode($resTax));
    }

    // 2. Add custom surcharge
    $resCharge = callApi('POST', '/api/settings/charges', [
        'charge_name' => 'Eco Glass Bottle Maintenance',
        'charge_type' => 'flat_monthly',
        'amount' => 40.00,
        'is_taxable' => 1
    ], $tenantToken);

    if ($resCharge['status'] !== 201 || empty($resCharge['body']['data']['id'])) {
        throw new \Exception("Surcharge creation failed: " . json_encode($resCharge));
    }
    $chargeId = (int)$resCharge['body']['data']['id'];

    // 3. Delete surcharge
    $resDelCharge = callApi('DELETE', "/api/settings/charges/{$chargeId}", [], $tenantToken);
    if ($resDelCharge['status'] !== 200) {
        throw new \Exception("Surcharge deletion failed");
    }
}, $passed, $failed);

// ==========================================
// TEST 11: Subscription & Named Plans Upgrade / Renewal
// ==========================================
runApiTest("API Subscription: Named Plan Activation & Renewal", function() use (&$tenantToken, $db) {
    $starterPlan = $db->fetchOne("SELECT id FROM subscription_plans WHERE code = 'starter' LIMIT 1");
    $db->execute(
        "UPDATE tenant_subscriptions SET plan_id = ?, purchased_blocks = 1, max_customers_allowed = 100, billing_cycle = 'yearly', valid_until = '2027-12-31', payment_status = 'active' WHERE tenant_id = 1",
        [$starterPlan ? (int)$starterPlan['id'] : 2]
    );

    // 1. Fetch current subscription & plans
    $resSub = callApi('GET', '/api/subscription', [], $tenantToken);
    if ($resSub['status'] !== 200 || empty($resSub['body']['data']['plans'])) {
        throw new \Exception("Failed to fetch subscription plans");
    }

    $plans = $resSub['body']['data']['plans'];
    $growthPlan = null;
    foreach ($plans as $p) {
        if (($p['code'] ?? '') === 'growth') {
            $growthPlan = $p;
            break;
        }
    }
    if (!$growthPlan) {
        throw new \Exception("Growth plan missing from /api/subscription");
    }

    // 2. Activate Growth plan on monthly cycle (500 + 18% GST = 590)
    $resUpgrade = callApi('POST', '/api/subscription/upgrade', [
        'plan_id' => (int)$growthPlan['id'],
        'billing_cycle' => 'monthly',
    ], $tenantToken);
    if ($resUpgrade['status'] !== 200) {
        throw new \Exception("Plan activation failed: " . json_encode($resUpgrade));
    }

    $newMax = (int)$resUpgrade['body']['data']['quota']['max_allowed'];
    if ($newMax !== (int)$growthPlan['customer_limit']) {
        throw new \Exception("Expected max capacity {$growthPlan['customer_limit']}, got {$newMax}");
    }
    $subInv = $resUpgrade['body']['data']['invoice'] ?? null;
    if (!$subInv || ($subInv['seller_gstin'] ?? '') !== '27AARPE1865G1ZJ' || abs((float)$subInv['total_amount'] - 590.0) > 0.01) {
        throw new \Exception("Subscription GST invoice missing or wrong total: " . json_encode($subInv));
    }

    // 3. Renew same Growth plan on yearly cycle + verify /api/subscription/invoices & resend
    $resRenew = callApi('POST', '/api/subscription/upgrade', [
        'plan_id' => (int)$growthPlan['id'],
        'billing_cycle' => 'yearly',
    ], $tenantToken);
    if ($resRenew['status'] !== 200 || ($resRenew['body']['data']['billing_cycle'] ?? '') !== 'yearly') {
        throw new \Exception("Yearly plan renewal failed: " . json_encode($resRenew));
    }

    $resInvs = callApi('GET', '/api/subscription/invoices', [], $tenantToken);
    if ($resInvs['status'] !== 200 || empty($resInvs['body']['data'])) {
        throw new \Exception("Failed to fetch subscription invoices API");
    }
    $invId = (int)$resInvs['body']['data'][0]['id'];
    $resResend = callApi('POST', "/api/subscription/invoices/{$invId}/resend", [], $tenantToken);
    if ($resResend['status'] !== 200 || empty($resResend['body']['data']['whatsapp_url'])) {
        throw new \Exception("Failed to resend subscription invoice notifications: " . json_encode($resResend));
    }
}, $passed, $failed);

// ==========================================
// TEST 11b: Approach 1 - Offline Payment & SuperAdmin Verification
// ==========================================
runApiTest("API Subscription: Offline Payment UTR Submission & SuperAdmin Approval Flow", function() use (&$tenantToken, &$adminToken, $db) {
    // 1. Tenant verifies receiving bank details & plans in subscription API
    $resSub = callApi('GET', '/api/subscription', [], $tenantToken);
    if ($resSub['status'] !== 200 || empty($resSub['body']['data']['bank_details']['upi_id'])) {
        throw new \Exception("Bank details missing from subscription response");
    }
    $proPlan = null;
    foreach ($resSub['body']['data']['plans'] as $p) {
        if (($p['code'] ?? '') === 'pro') {
            $proPlan = $p;
            break;
        }
    }
    if (!$proPlan) {
        throw new \Exception("Pro plan missing");
    }

    // 2. Validation: submitting without UTR should fail with 422
    $resFail = callApi('POST', '/api/subscription/request-upgrade', ['plan_id' => (int)$proPlan['id'], 'utr_number' => ''], $tenantToken);
    if ($resFail['status'] !== 422) {
        throw new \Exception("Expected 422 for missing UTR, got {$resFail['status']}");
    }

    // 3. Tenant submits offline payment request for Pro Monthly
    $testUtr = 'UTR' . time() . 'TEST';
    $resReq = callApi('POST', '/api/subscription/request-upgrade', [
        'plan_id' => (int)$proPlan['id'],
        'billing_cycle' => 'monthly',
        'utr_number' => $testUtr,
        'payment_method' => 'upi',
        'notes' => 'Test PhonePe payment'
    ], $tenantToken);

    if ($resReq['status'] !== 201 || empty($resReq['body']['data']['id'])) {
        throw new \Exception("Failed to submit payment request: " . json_encode($resReq));
    }
    $reqId = (int)$resReq['body']['data']['id'];
    if ($resReq['body']['data']['status'] !== 'pending') {
        throw new \Exception("Expected request status 'pending', got " . $resReq['body']['data']['status']);
    }

    // 4. Duplicate pending UTR submission should be rejected
    $resDup = callApi('POST', '/api/subscription/request-upgrade', [
        'plan_id' => (int)$proPlan['id'],
        'utr_number' => $testUtr
    ], $tenantToken);
    if ($resDup['status'] !== 422) {
        throw new \Exception("Duplicate UTR submission should be rejected with 422");
    }

    // 5. Super Admin lists pending requests and finds our request
    $resAdminList = callApi('GET', '/api/admin/subscription-requests', ['status' => 'pending'], $adminToken);
    if ($resAdminList['status'] !== 200) {
        throw new \Exception("SuperAdmin failed to list subscription requests");
    }
    $found = false;
    foreach ($resAdminList['body']['data'] as $r) {
        if ((int)$r['id'] === $reqId && $r['utr_number'] === $testUtr) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        throw new \Exception("Pending request not found in SuperAdmin request queue");
    }

    // 6. Test Rejection flow on a dummy request
    $rejectUtr = 'REJECT' . time();
    $resReq2 = callApi('POST', '/api/subscription/request-upgrade', [
        'plan_id' => (int)$proPlan['id'],
        'billing_cycle' => 'monthly',
        'utr_number' => $rejectUtr,
    ], $tenantToken);
    $rejectId = (int)$resReq2['body']['data']['id'];

    $resReject = callApi('POST', "/api/admin/subscription-requests/{$rejectId}/reject", [
        'reason' => 'Payment not found in bank statement'
    ], $adminToken);
    if ($resReject['status'] !== 200 || $resReject['body']['data']['request']['status'] !== 'rejected') {
        throw new \Exception("SuperAdmin rejection failed: " . json_encode($resReject));
    }

    // 7. Super Admin Approves the valid request
    $resApprove = callApi('POST', "/api/admin/subscription-requests/{$reqId}/approve", [
        'admin_notes' => 'UTR verified in HDFC Current Account'
    ], $adminToken);

    if ($resApprove['status'] !== 200) {
        throw new \Exception("SuperAdmin approval failed: " . json_encode($resApprove));
    }
    if ($resApprove['body']['data']['request']['status'] !== 'approved') {
        throw new \Exception("Request status not marked approved after SuperAdmin approval");
    }

    // 8. Verify tenant quota was updated to Pro customer_limit
    $resSubAfter = callApi('GET', '/api/subscription', [], $tenantToken);
    $finalMax = (int)$resSubAfter['body']['data']['quota']['max_allowed'];
    if ($finalMax !== (int)$proPlan['customer_limit']) {
        throw new \Exception("Capacity not updated to Pro! Expected {$proPlan['customer_limit']}, got {$finalMax}");
    }

    // Reset Tenant 1 back to Starter Demo state so repeated test runs stay idempotent
    $starterPlan = $db->fetchOne("SELECT id FROM subscription_plans WHERE code = 'starter' LIMIT 1");
    $db->execute(
        "UPDATE tenant_subscriptions SET plan_id = ?, purchased_blocks = 1, max_customers_allowed = 100, amount_paid = 2000.00, billing_cycle = 'yearly', valid_until = '2027-12-31', payment_status = 'active' WHERE tenant_id = 1",
        [$starterPlan ? (int)$starterPlan['id'] : 2]
    );
}, $passed, $failed);

// ==========================================
// TEST 12: Super Admin Platform Governance
// ==========================================
runApiTest("API Super Admin: Platform Stats, Settings & Tenant Governance", function() use (&$adminToken) {
    // 1. Fetch platform stats
    $resStats = callApi('GET', '/api/admin/stats', [], $adminToken);
    if ($resStats['status'] !== 200 || empty($resStats['body']['data']['tenants'])) {
        throw new \Exception("Admin stats API failed");
    }

    // 2. Fetch all tenants
    $resTenants = callApi('GET', '/api/admin/tenants', [], $adminToken);
    if ($resTenants['status'] !== 200 || empty($resTenants['body']['data'])) {
        throw new \Exception("Admin tenants listing failed");
    }

    // 3. Update platform global settings
    $resSet = callApi('PUT', '/api/admin/settings', [
        'free_customer_limit' => 5,
        'paid_tier_unit_size' => 100,
        'paid_tier_unit_price' => 200.0,
        'paid_tier_monthly_price' => 200.0,
        'paid_tier_yearly_price' => 2000.0,
        'subscription_grace_days' => 7,
        'currency_symbol' => '₹',
        'platform_name' => 'DoodhBee SaaS'
    ], $adminToken);

    if ($resSet['status'] !== 200 || $resSet['body']['data']['platform_name'] !== 'DoodhBee SaaS') {
        throw new \Exception("Admin settings update failed");
    }
}, $passed, $failed);

runApiTest("API Documentation: Swagger UI and OpenAPI 3.0 Specification Serving", function () {
    $docsPath = dirname(__DIR__) . '/docs';
    if (!file_exists($docsPath . '/openapi.json')) {
        throw new \Exception("docs/openapi.json missing");
    }
    if (!file_exists($docsPath . '/index.html')) {
        throw new \Exception("docs/index.html missing");
    }

    $jsonContent = file_get_contents($docsPath . '/openapi.json');
    $spec = json_decode($jsonContent, true);
    if (!is_array($spec) || empty($spec['paths']) || count($spec['paths']) < 35) {
        throw new \Exception("Invalid openapi.json structure or path count insufficient: " . count($spec['paths'] ?? []));
    }

    // Verify symlink doc -> docs
    $symlinkDoc = dirname(__DIR__) . '/doc';
    if (!file_exists($symlinkDoc . '/openapi.json')) {
        throw new \Exception("doc/openapi.json symlink access failed");
    }
}, $passed, $failed);

// ==========================================
// TEST 14: Comprehensive Cross-Tenant API Isolation & Security
// ==========================================
runApiTest("API Multi-Tenant Isolation: Cross-Tenant Data Leaks Strictly Blocked (404/403/401)", function() use (&$tenantToken, $db) {
    // 1. Authenticate as Tenant 2 (Radha Fresh Cow Dairy)
    $resT2Login = callApi('POST', '/api/auth/login', [
        'login' => '9876500000',
        'password' => 'password123',
        'device_name' => 'T2 Test Client'
    ]);
    if ($resT2Login['status'] !== 200 || empty($resT2Login['body']['data']['token'])) {
        throw new \Exception("Failed to login as Tenant 2 for cross-tenant isolation test");
    }
    $t2Token = $resT2Login['body']['data']['token'];

    // 2. Fetch Tenant 2's resources
    $t2CustRes = callApi('GET', '/api/customers', [], $t2Token);
    $t2Customers = $t2CustRes['body']['data'] ?? [];
    if (empty($t2Customers)) {
        throw new \Exception("Tenant 2 has no customers to test isolation");
    }
    $t2CustomerId = (int)$t2Customers[0]['id'];

    $t2ProdRes = callApi('GET', '/api/products', [], $t2Token);
    $t2Products = $t2ProdRes['body']['data'] ?? [];
    $t2ProductId = !empty($t2Products) ? (int)$t2Products[0]['id'] : 101;

    // Create an invoice for Tenant 2 customer to test invoice isolation
    $t2InvRes = callApi('POST', '/api/invoices/generate-single', [
        'customer_id' => $t2CustomerId,
        'month' => (int)date('n'),
        'year' => (int)date('Y')
    ], $t2Token);
    $t2InvoiceId = (int)($t2InvRes['body']['data']['id'] ?? $t2InvRes['body']['data']['invoice_id'] ?? 0);

    // ========================================================
    // NOW ATTEMPT TO ACCESS TENANT 2 DATA USING TENANT 1 TOKEN
    // ========================================================

    // A. Customer Isolation
    $crossCustGet = callApi('GET', "/api/customers/{$t2CustomerId}", [], $tenantToken);
    if ($crossCustGet['status'] !== 404) {
        throw new \Exception("Cross-tenant leak: Tenant 1 can read Tenant 2 customer details (Status: {$crossCustGet['status']})");
    }

    $crossCustPut = callApi('PUT', "/api/customers/{$t2CustomerId}", ['name' => 'Hacked Name', 'phone' => '9999999999'], $tenantToken);
    if ($crossCustPut['status'] !== 404) {
        throw new \Exception("Cross-tenant leak: Tenant 1 can update Tenant 2 customer (Status: {$crossCustPut['status']})");
    }

    $crossCustStatus = callApi('POST', "/api/customers/{$t2CustomerId}/status", [], $tenantToken);
    if ($crossCustStatus['status'] !== 404) {
        throw new \Exception("Cross-tenant leak: Tenant 1 can toggle Tenant 2 customer status (Status: {$crossCustStatus['status']})");
    }

    $crossCustLeaves = callApi('GET', "/api/customers/{$t2CustomerId}/leaves", [], $tenantToken);
    if ($crossCustLeaves['status'] !== 404) {
        throw new \Exception("Cross-tenant leak: Tenant 1 can read Tenant 2 customer leaves (Status: {$crossCustLeaves['status']})");
    }

    $crossCustAddLeave = callApi('POST', "/api/customers/{$t2CustomerId}/leaves", [
        'start_date' => date('Y-m-d'),
        'end_date' => date('Y-m-d', strtotime('+3 days')),
        'reason' => 'Unauthorized vacation'
    ], $tenantToken);
    if ($crossCustAddLeave['status'] !== 404) {
        throw new \Exception("Cross-tenant leak: Tenant 1 can add vacation for Tenant 2 customer (Status: {$crossCustAddLeave['status']})");
    }

    $crossCustLedger = callApi('GET', "/api/customers/{$t2CustomerId}/ledger", [], $tenantToken);
    if ($crossCustLedger['status'] !== 404) {
        throw new \Exception("Cross-tenant leak: Tenant 1 can view Tenant 2 customer ledger (Status: {$crossCustLedger['status']})");
    }

    // B. Delivery Tracking Isolation
    $crossDelivToggle = callApi('POST', '/api/deliveries/toggle', [
        'customer_id' => $t2CustomerId,
        'date' => date('Y-m-d'),
        'shift' => 'morning',
        'status' => 'delivered'
    ], $tenantToken);
    if ($crossDelivToggle['status'] !== 404) {
        throw new \Exception("Cross-tenant leak: Tenant 1 can toggle delivery for Tenant 2 customer (Status: {$crossDelivToggle['status']})");
    }

    $crossDelivEdit = callApi('POST', '/api/deliveries/edit-quantity', [
        'customer_id' => $t2CustomerId,
        'date' => date('Y-m-d'),
        'shift' => 'morning',
        'quantity' => 10.0
    ], $tenantToken);
    if ($crossDelivEdit['status'] !== 404) {
        throw new \Exception("Cross-tenant leak: Tenant 1 can edit delivery quantity for Tenant 2 customer (Status: {$crossDelivEdit['status']})");
    }

    // C. Invoice Isolation
    if ($t2InvoiceId > 0) {
        $crossInvShow = callApi('GET', "/api/invoices/{$t2InvoiceId}", [], $tenantToken);
        if ($crossInvShow['status'] !== 404) {
            throw new \Exception("Cross-tenant leak: Tenant 1 can view Tenant 2 invoice (Status: {$crossInvShow['status']})");
        }

        $crossInvShare = callApi('GET', "/api/invoices/{$t2InvoiceId}/share", [], $tenantToken);
        if ($crossInvShare['status'] !== 404) {
            throw new \Exception("Cross-tenant leak: Tenant 1 can share Tenant 2 invoice (Status: {$crossInvShare['status']})");
        }

        $crossInvPayList = callApi('GET', "/api/invoices/{$t2InvoiceId}/payments", [], $tenantToken);
        if ($crossInvPayList['status'] !== 404) {
            throw new \Exception("Cross-tenant leak: Tenant 1 can view payments of Tenant 2 invoice (Status: {$crossInvPayList['status']})");
        }
    }

    $crossInvGen = callApi('POST', '/api/invoices/generate-single', [
        'customer_id' => $t2CustomerId,
        'month' => (int)date('n'),
        'year' => (int)date('Y')
    ], $tenantToken);
    if ($crossInvGen['status'] !== 404) {
        throw new \Exception("Cross-tenant leak: Tenant 1 can generate invoice for Tenant 2 customer (Status: {$crossInvGen['status']})");
    }

    // D. Payment Recording Isolation
    $crossPayment = callApi('POST', '/api/payments', [
        'customer_id' => $t2CustomerId,
        'amount' => 500.0,
        'payment_date' => date('Y-m-d')
    ], $tenantToken);
    if ($crossPayment['status'] !== 404) {
        throw new \Exception("Cross-tenant leak: Tenant 1 can record payment for Tenant 2 customer (Status: {$crossPayment['status']})");
    }

    if ($t2InvoiceId > 0) {
        // Attempt linking Tenant 1 payment to Tenant 2's invoice
        $t1Customers = callApi('GET', '/api/customers', [], $tenantToken);
        $t1CustId = (int)$t1Customers['body']['data'][0]['id'];
        $crossInvPayment = callApi('POST', '/api/payments', [
            'customer_id' => $t1CustId,
            'invoice_id' => $t2InvoiceId,
            'amount' => 100.0,
            'payment_date' => date('Y-m-d')
        ], $tenantToken);
        if ($crossInvPayment['status'] !== 404) {
            throw new \Exception("Cross-tenant leak: Tenant 1 can record payment pointing to Tenant 2 invoice (Status: {$crossInvPayment['status']})");
        }
    }

    // E. Product Isolation
    $crossProdGet = callApi('GET', "/api/products/{$t2ProductId}", [], $tenantToken);
    if ($crossProdGet['status'] !== 404) {
        throw new \Exception("Cross-tenant leak: Tenant 1 can view Tenant 2 product details (Status: {$crossProdGet['status']})");
    }

    $crossProdToggle = callApi('POST', "/api/products/{$t2ProductId}/toggle", [], $tenantToken);
    if ($crossProdToggle['status'] !== 404) {
        throw new \Exception("Cross-tenant leak: Tenant 1 can toggle Tenant 2 product (Status: {$crossProdToggle['status']})");
    }

    $crossProdDel = callApi('DELETE', "/api/products/{$t2ProductId}", [], $tenantToken);
    if ($crossProdDel['status'] !== 404) {
        throw new \Exception("Cross-tenant leak: Tenant 1 can delete Tenant 2 product (Status: {$crossProdDel['status']})");
    }

    // F. Super Admin Privilege Escalation Guard
    $crossAdminSettings = callApi('GET', '/api/admin/settings', [], $tenantToken);
    if ($crossAdminSettings['status'] !== 401) {
        throw new \Exception("Privilege escalation breach: Tenant 1 can access Super Admin settings (Status: {$crossAdminSettings['status']})");
    }

    $crossAdminTenants = callApi('GET', '/api/admin/tenants', [], $tenantToken);
    if ($crossAdminTenants['status'] !== 401) {
        throw new \Exception("Privilege escalation breach: Tenant 1 can list all tenants (Status: {$crossAdminTenants['status']})");
    }
}, $passed, $failed);

// ==========================================
// TEST 15: Security Hardening - API CSRF Guard, Rate Limiting & Sensitive File Lockdown
// ==========================================
runApiTest('API Security Hardening: CSRF Guard, Auth Rate Limiting & Sensitive File Protection', function () use ($db, $createdProductId) {
    // 1. Rate Limiting verification
    $testLogin = '9999999999';
    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $throttleKey = "api_login:{$clientIp}:{$testLogin}";
    \App\Core\RateLimiter::reset($throttleKey);

    for ($i = 1; $i <= 5; $i++) {
        $res = callApi('POST', '/api/auth/login', [
            'login' => $testLogin,
            'password' => 'wrongpassword_' . $i,
        ]);
        if ($res['status'] !== 401) {
            throw new \Exception("Expected 401 for bad password attempt {$i}, got {$res['status']}");
        }
    }

    // 6th attempt must trigger 429 TOO_MANY_REQUESTS
    $throttledRes = callApi('POST', '/api/auth/login', [
        'login' => $testLogin,
        'password' => 'wrongpassword_6',
    ]);
    if ($throttledRes['status'] !== 429) {
        throw new \Exception("Rate limiter failed: 6th attempt returned status {$throttledRes['status']} instead of 429");
    }
    if (($throttledRes['body']['code'] ?? '') !== 'TOO_MANY_REQUESTS') {
        throw new \Exception("Rate limiter failed: Expected code TOO_MANY_REQUESTS, got " . ($throttledRes['body']['code'] ?? ''));
    }
    \App\Core\RateLimiter::reset($throttleKey);

    // 2. API CSRF Guard verification: External mutating call without Bearer and without CSRF token/header
    \App\Core\Auth::startSession();
    $_SESSION['tenant'] = [
        'id' => 1,
        'business_name' => 'Demo Tenant',
        'owner_name' => 'Demo',
        'phone' => '9822000001',
        'status' => 'active',
    ];
    unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_POST['csrf_token']);

    $csrfBlocked = callApi('POST', '/api/products/' . ($createdProductId ?: 1) . '/toggle', [], null);
    if ($csrfBlocked['status'] !== 419) {
        throw new \Exception("CSRF guard failed: State-changing API call using session without CSRF header should return 419, got {$csrfBlocked['status']}");
    }

    // 3. Sensitive file protection check
    if (file_exists(__DIR__ . '/../.env.php')) {
        $cmd = 'php8.4 -r "require \'' . __DIR__ . '/../.env.php\';" 2>&1';
        $output = (string)shell_exec($cmd);
        if (!str_contains($output, '403 Forbidden: Direct access to environment configuration is denied')) {
            throw new \Exception("Direct access to .env.php was not forbidden: {$output}");
        }
    }
}, $passed, $failed);

// TEST 18: Delivery Rider Login & Role-Based Access Control (RBAC)
runApiTest("Delivery Rider Login & RBAC Enforcement (/api/deliveries vs /api/invoices)", function() use ($db, $tenantToken) {
    // 1. Rider authentication with phone and password
    $riderPhone = '9876500001'; // Raju Rider
    $riderPass = 'rider123';

    $loginRes = callApi('POST', '/api/auth/login', [
        'login' => $riderPhone,
        'password' => $riderPass,
        'device_name' => 'Automated Rider Test Device',
    ]);

    if ($loginRes['status'] !== 200 || empty($loginRes['body']['data']['token'])) {
        throw new \Exception("Rider login failed: " . ($loginRes['body']['error'] ?? 'No token returned'));
    }

    $riderToken = $loginRes['body']['data']['token'];
    $riderUser = $loginRes['body']['data']['user'] ?? [];
    if (($riderUser['role'] ?? '') !== 'delivery') {
        throw new \Exception("Expected rider role 'delivery', got " . ($riderUser['role'] ?? 'none'));
    }
    if (!str_contains($riderUser['name'] ?? '', 'Raju Rider')) {
        throw new \Exception("Expected rider name containing 'Raju Rider', got " . ($riderUser['name'] ?? 'none'));
    }

    // 2. Rider can access delivery runsheet and gets auto-routed
    $sheetRes = callApi('GET', '/api/deliveries', ['date' => date('Y-m-d'), 'shift' => 'morning'], $riderToken);
    if ($sheetRes['status'] !== 200 || !isset($sheetRes['body']['data']['items'])) {
        throw new \Exception("Rider failed to fetch delivery runsheet: " . ($sheetRes['body']['error'] ?? ''));
    }
    // Verify auto-routing to Green Valley Route
    foreach ($sheetRes['body']['data']['items'] as $item) {
        if ($item['route_name'] !== 'Green Valley Route') {
            throw new \Exception("Expected auto-filtered route 'Green Valley Route', found '{$item['route_name']}'");
        }
    }

    // 3. RBAC Barrier: Rider CANNOT access sensitive admin/financial endpoints (Expect 403 FORBIDDEN_ROLE)
    $blockedEndpoints = [
        ['GET', '/api/invoices', []],
        ['GET', '/api/settings', []],
        ['GET', '/api/staff', []],
        ['POST', '/api/products', ['name' => 'Hack Milk', 'code' => 'hack', 'default_rate' => 10]],
        ['POST', '/api/customers', ['name' => 'Hack Customer', 'phone' => '9999999999']],
        ['POST', '/api/subscription/upgrade', ['blocks' => 1]],
        ['GET', '/api/dashboard/stats', []],
    ];

    foreach ($blockedEndpoints as [$method, $uri, $payload]) {
        $res = callApi($method, $uri, $payload, $riderToken);
        if ($res['status'] !== 403) {
            throw new \Exception("RBAC breach! Rider should be blocked with 403 on {$method} {$uri}, got {$res['status']}");
        }
        if (($res['body']['code'] ?? '') !== 'FORBIDDEN_ROLE') {
            throw new \Exception("Expected error code FORBIDDEN_ROLE on {$method} {$uri}, got " . ($res['body']['code'] ?? ''));
        }
    }

    // 4. Tenant Admin CAN manage staff via /api/staff
    $staffListRes = callApi('GET', '/api/staff', [], $tenantToken);
    if ($staffListRes['status'] !== 200 || !is_array($staffListRes['body']['data'])) {
        throw new \Exception("Admin failed to list staff members: " . ($staffListRes['body']['error'] ?? ''));
    }

    // Create staff via API
    $createStaffRes = callApi('POST', '/api/staff', [
        'name' => 'API Test Rider',
        'phone' => '9876588888',
        'password' => 'apirider123',
        'role' => 'delivery',
    ], $tenantToken);

    if ($createStaffRes['status'] !== 201) {
        throw new \Exception("Admin failed to create staff member: " . ($createStaffRes['body']['error'] ?? ''));
    }
    $createdStaffId = (int)$createStaffRes['body']['data']['id'];

    // Toggle staff via API
    $toggleRes = callApi('POST', "/api/staff/{$createdStaffId}/toggle", [], $tenantToken);
    if ($toggleRes['status'] !== 200 || $toggleRes['body']['data']['is_active'] !== 0) {
        throw new \Exception("Admin failed to toggle staff status: " . ($toggleRes['body']['error'] ?? ''));
    }

    // Delete staff via API
    $deleteRes = callApi('DELETE', "/api/staff/{$createdStaffId}", [], $tenantToken);
    if ($deleteRes['status'] !== 200) {
        throw new \Exception("Admin failed to delete staff member: " . ($deleteRes['body']['error'] ?? ''));
    }
}, $passed, $failed);

// ==========================================
// TEST 19: Full-Month Deliveries & Customer Milk Card API
// ==========================================
runApiTest("API Deliveries: Whole-Month Bulk Fill & Customer Milk Card API", function() use (&$tenantToken, &$newCustomerId) {
    // 1. Month Fill API
    $fillRes = callApi('POST', '/api/deliveries/month-fill', [
        'year' => 2026,
        'month' => 11,
        'shift' => 'both',
        'overwrite' => true,
    ], $tenantToken);

    if ($fillRes['status'] !== 200 || empty($fillRes['body']['success'])) {
        throw new \Exception("Month fill API failed: " . json_encode($fillRes));
    }
    $fillData = $fillRes['body']['data'];
    if ($fillData['days_in_month'] !== 30 || $fillData['records_created'] <= 0) {
        throw new \Exception("Month fill API unexpected response: " . json_encode($fillData));
    }

    // 1b. Single-Customer Month Fill API
    $fillCustRes = callApi('POST', '/api/deliveries/month-fill', [
        'customer_id' => $newCustomerId,
        'year' => 2026,
        'month' => 11,
        'shift' => 'both',
        'overwrite' => true,
    ], $tenantToken);
    if ($fillCustRes['status'] !== 200 || $fillCustRes['body']['data']['customers_processed'] !== 1) {
        throw new \Exception("Single customer month fill API failed: " . json_encode($fillCustRes));
    }

    // 2. Customer Month Deliveries Grid (GET)
    $gridRes = callApi('GET', "/api/customers/{$newCustomerId}/month-deliveries?year=2026&month=11", [], $tenantToken);
    if ($gridRes['status'] !== 200 || empty($gridRes['body']['success'])) {
        throw new \Exception("Customer month deliveries GET failed: " . json_encode($gridRes));
    }
    $gridData = $gridRes['body']['data'];
    if (count($gridData['days']) !== 30 || empty($gridData['summary'])) {
        throw new \Exception("Customer grid data format invalid: " . json_encode($gridData));
    }

    // 3. Customer Month Deliveries Save (POST)
    $saveRes = callApi('POST', "/api/customers/{$newCustomerId}/month-deliveries", [
        'year' => 2026,
        'month' => 11,
        'days' => [
            [
                'date' => '2026-11-05',
                'shift' => 'morning',
                'status' => 'delivered',
                'quantity' => 4.0,
                'notes' => 'API Card Update'
            ],
            [
                'date' => '2026-11-06',
                'shift' => 'morning',
                'status' => 'skipped',
                'quantity' => 0.0,
                'notes' => 'API Skipped'
            ]
        ]
    ], $tenantToken);

    if ($saveRes['status'] !== 200 || empty($saveRes['body']['success'])) {
        throw new \Exception("Customer month deliveries POST failed: " . json_encode($saveRes));
    }

    $savedDays = $saveRes['body']['data']['days'];
    $day5 = null;
    $day6 = null;
    foreach ($savedDays as $sd) {
        if ($sd['date'] === '2026-11-05') $day5 = $sd;
        if ($sd['date'] === '2026-11-06') $day6 = $sd;
    }
    if (!$day5 || (float)$day5['shifts']['morning']['quantity'] !== 4.0) {
        throw new \Exception("Day 5 quantity not saved properly via API: " . json_encode($day5));
    }
    if (!$day6 || (float)$day6['shifts']['morning']['quantity'] !== 0.0 || $day6['shifts']['morning']['status'] !== 'skipped') {
        throw new \Exception("Day 6 zero-charge skip not saved properly via API: " . json_encode($day6));
    }
}, $passed, $failed);

// Clean up test customer and test product
if ($newCustomerId > 0) {
    $db->execute("DELETE FROM customers WHERE id = ?", [$newCustomerId]);
}
if ($createdProductId > 0) {
    $db->execute("DELETE FROM products WHERE id = ?", [$createdProductId]);
}
if ($createdRouteId > 0) {
    $db->execute("DELETE FROM routes WHERE id = ?", [$createdRouteId]);
}

echo "\n========================================================\n";
echo "   API TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}

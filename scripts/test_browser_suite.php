<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * DoodhBee Comprehensive End-to-End Browser Test Runner
 *
 * Simulates a full web browser session across all user roles, pages,
 * forms, HTMX toggles, and PDF/CSV downloads.
 */

class BrowserClient
{
    private string $baseUrl;
    private string $cookieFile;
    private ?string $lastHtml = null;
    private int $lastStatusCode = 0;
    private array $lastHeaders = [];

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'doodhbee_cookie_');
    }

    public function __destruct()
    {
        if (file_exists($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    public function request(string $method, string $path, array $data = [], array $headers = []): array
    {
        $url = str_starts_with($path, 'http') ? $path : $this->baseUrl . (str_starts_with($path, '/') ? $path : '/' . $path);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_HEADER, true);

        $httpHeaders = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
        ];
        foreach ($headers as $k => $v) {
            $httpHeaders[] = is_numeric($k) ? $v : "{$k}: {$v}";
        }

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("cURL error on {$method} {$url}: {$err}");
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $this->lastStatusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        $headerStr = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        $this->lastHtml = $body;

        return [
            'statusCode' => $this->lastStatusCode,
            'effectiveUrl' => $effectiveUrl,
            'headers' => $headerStr,
            'body' => $body
        ];
    }

    public function get(string $path, array $headers = []): array
    {
        return $this->request('GET', $path, [], $headers);
    }

    public function post(string $path, array $data = [], array $headers = []): array
    {
        return $this->request('POST', $path, $data, $headers);
    }

    public function extractCsrfToken(): ?string
    {
        if ($this->lastHtml === null) {
            return null;
        }
        if (preg_match('/name=["\']csrf_token["\']\s+value=["\']([^"\']+)["\']/i', $this->lastHtml, $matches)) {
            return $matches[1];
        }
        if (preg_match('/content=["\']([^"\']+)["\']\s+name=["\']csrf-token["\']/i', $this->lastHtml, $matches)) {
            return $matches[1];
        }
        return null;
    }

    public function getLastHtml(): ?string
    {
        return $this->lastHtml;
    }

    public function getStatusCode(): int
    {
        return $this->lastStatusCode;
    }

    public function checkPhpErrors(?string $body = null): array
    {
        $content = $body ?? $this->lastHtml ?? '';
        $errors = [];
        $patterns = [
            'Fatal error' => '/Fatal error:\s*(.*?)(?:in\s+.*line\s+\d+|<\/)/is',
            'Uncaught Exception' => '/Uncaught\s+[A-Za-z0-9_\\\\]*Exception:\s*(.*?)(?:in\s+.*line\s+\d+|<\/)/is',
            'Parse error' => '/Parse error:\s*(.*?)(?:in\s+.*line\s+\d+|<\/)/is',
            'Warning' => '/Warning:\s*(.*?)(?:in\s+.*line\s+\d+|<\/)/is',
            'Notice' => '/Notice:\s*(.*?)(?:in\s+.*line\s+\d+|<\/)/is',
            'Deprecated' => '/Deprecated:\s*(.*?)(?:in\s+.*line\s+\d+|<\/)/is',
            'Undefined variable' => '/Undefined variable\s*(.*?)(?:in\s+.*line\s+\d+|<\/)/is',
            'Undefined array key' => '/Undefined array key\s*(.*?)(?:in\s+.*line\s+\d+|<\/)/is',
        ];

        foreach ($patterns as $type => $regex) {
            if (preg_match_all($regex, $content, $matches)) {
                foreach ($matches[0] as $match) {
                    $errors[] = strip_tags(trim($match));
                }
            }
        }

        return $errors;
    }
}

$baseUrl = 'http://localhost/doodhbee/index.php';
$client = new BrowserClient($baseUrl);

echo "======================================================================\n";
echo "      DOODHBEE AUTOMATED BROWSER & END-TO-END VERIFICATION           \n";
echo "======================================================================\n";
echo "• Base URL: {$baseUrl}\n\n";

$passed = 0;
$failed = 0;
$bugsFound = [];

function testStep(string $description, callable $fn, int &$passed, int &$failed, array &$bugsFound): void
{
    echo "• Testing: {$description}... ";
    try {
        $fn();
        echo "\033[32m[PASS]\033[0m\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "\033[31m[FAIL]\033[0m\n";
        echo "  Error: " . $e->getMessage() . "\n";
        $failed++;
        $bugsFound[] = [
            'test' => $description,
            'error' => $e->getMessage()
        ];
    }
}

// ---------------------------------------------------------
// SECTION 1: PUBLIC & GUEST ACCESS
// ---------------------------------------------------------
testStep("Health check endpoint (/health)", function() use ($client) {
    $res = $client->get('/health');
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Expected HTTP 200, got {$res['statusCode']}");
    }
    $json = json_decode($res['body'], true);
    if (($json['status'] ?? '') !== 'healthy') {
        throw new \Exception("Health check returned status: " . ($json['status'] ?? 'unknown'));
    }
}, $passed, $failed, $bugsFound);

testStep("Login view (/login)", function() use ($client) {
    $res = $client->get('/login');
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Expected HTTP 200, got {$res['statusCode']}");
    }
    $errors = $client->checkPhpErrors();
    if (!empty($errors)) {
        throw new \Exception("PHP errors on /login: " . implode('; ', $errors));
    }
    if (!str_contains($res['body'], 'DoodhBee') || !str_contains($res['body'], 'Sign In')) {
        throw new \Exception("Login page content missing expected brand / sign-in elements");
    }
}, $passed, $failed, $bugsFound);

testStep("Register view (/register)", function() use ($client) {
    $res = $client->get('/register');
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Expected HTTP 200, got {$res['statusCode']}");
    }
    $errors = $client->checkPhpErrors();
    if (!empty($errors)) {
        throw new \Exception("PHP errors on /register: " . implode('; ', $errors));
    }
    if (!str_contains($res['body'], 'Create Free Account') || !str_contains($res['body'], 'Dairy / Business Name')) {
        throw new \Exception("Register page content missing expected form fields");
    }
}, $passed, $failed, $bugsFound);

testStep("Admin login view (/admin/login)", function() use ($client) {
    $res = $client->get('/admin/login');
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Expected HTTP 200, got {$res['statusCode']}");
    }
    $errors = $client->checkPhpErrors();
    if (!empty($errors)) {
        throw new \Exception("PHP errors on /admin/login: " . implode('; ', $errors));
    }
    if (!str_contains($res['body'], 'Super Admin')) {
        throw new \Exception("Admin login page missing Super Admin branding");
    }
}, $passed, $failed, $bugsFound);

testStep("Public bill PDF security token guard (/bill/{id}/pdf)", function() use ($client) {
    $db = \App\Core\Database::getInstance();
    $inv = $db->fetchOne("SELECT id, tenant_id FROM invoices ORDER BY id ASC LIMIT 1");
    if (!$inv) {
        throw new \Exception("No invoices found to test public bill");
    }
    $invId = (int)$inv['id'];
    $tenantId = (int)$inv['tenant_id'];

    // 1. Without token, should be 403 Forbidden
    $res1 = $client->get("/bill/{$invId}/pdf");
    if ($res1['statusCode'] !== 403) {
        throw new \Exception("Expected HTTP 403 for unauthenticated public bill, got {$res1['statusCode']}");
    }

    // 2. With valid signed token, should stream PDF (HTTP 200)
    $validToken = \App\Services\WhatsAppService::generateInvoiceToken($invId, $tenantId);
    $res2 = $client->get("/bill/{$invId}/pdf?token={$validToken}");
    if ($res2['statusCode'] !== 200) {
        throw new \Exception("Expected HTTP 200 for tokenized public bill, got {$res2['statusCode']}");
    }
    if (!str_starts_with($res2['body'], '%PDF-')) {
        throw new \Exception("Expected valid PDF stream beginning with %PDF-");
    }
}, $passed, $failed, $bugsFound);

testStep("API Documentation UI (/api/docs)", function() use ($client) {
    $res = $client->get('/api/docs');
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Expected HTTP 200, got {$res['statusCode']}");
    }
    if (!str_contains($res['body'], 'SwaggerUIBundle') && !str_contains($res['body'], 'swagger-ui')) {
        throw new \Exception("Swagger UI assets not found on /api/docs");
    }
}, $passed, $failed, $bugsFound);

testStep("OpenAPI Specification (/api/docs/openapi.json)", function() use ($client) {
    $res = $client->get('/api/docs/openapi.json');
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Expected HTTP 200, got {$res['statusCode']}");
    }
    $json = json_decode($res['body'], true);
    if (!isset($json['openapi']) || !str_starts_with($json['openapi'], '3.')) {
        throw new \Exception("Invalid OpenAPI spec returned");
    }
}, $passed, $failed, $bugsFound);

// ---------------------------------------------------------
// SECTION 2: AUTHENTICATION & CSRF SECURITY
// ---------------------------------------------------------
testStep("CSRF token rejection on missing token", function() use ($client) {
    $client->get('/logout');
    $res = $client->post('/login', [
        'login' => '9822000001',
        'password' => 'password123'
    ]);
    if (!str_contains($res['body'], 'expired') && !str_contains($res['body'], 'CSRF') && $res['statusCode'] !== 403) {
        throw new \Exception("Expected 403 or CSRF rejection on missing token, got {$res['statusCode']}");
    }
}, $passed, $failed, $bugsFound);

testStep("Failed login with invalid credentials", function() use ($client) {
    $client->get('/logout');
    $client->get('/login');
    $token = $client->extractCsrfToken();
    $res = $client->post('/login', [
        'login' => '9999999999',
        'password' => 'wrongpassword',
        'csrf_token' => $token
    ]);
    if (!str_contains($res['body'], 'Invalid') && !str_contains($res['body'], 'rose-950') && !str_contains($res['body'], 'error')) {
        throw new \Exception("Expected error flash message on invalid login");
    }
}, $passed, $failed, $bugsFound);

testStep("Successful login with valid credentials", function() use ($client) {
    $client->get('/logout');
    $client->get('/login');
    $token = $client->extractCsrfToken();
    $res = $client->post('/login', [
        'login' => '9822000001',
        'password' => 'password123',
        'csrf_token' => $token
    ]);
    if ($res['statusCode'] !== 200 || !str_contains($res['body'], 'Ramesh Patel')) {
        throw new \Exception("Login failed! Did not land on dashboard as Ramesh Patel");
    }
}, $passed, $failed, $bugsFound);

// ---------------------------------------------------------
// SECTION 3: TENANT 1 DASHBOARD & DELIVERIES
// ---------------------------------------------------------
testStep("Tenant Dashboard view (/)", function() use ($client) {
    $res = $client->get('/');
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Expected HTTP 200, got {$res['statusCode']}");
    }
    $errors = $client->checkPhpErrors();
    if (!empty($errors)) {
        throw new \Exception("PHP errors on dashboard: " . implode('; ', $errors));
    }
    if (!str_contains($res['body'], 'Active Customers') || !str_contains($res['body'], 'Morning Shift')) {
        throw new \Exception("Dashboard missing essential KPI cards or shift info");
    }
}, $passed, $failed, $bugsFound);

testStep("Daily Deliveries tracker view (/deliveries)", function() use ($client) {
    $res = $client->get('/deliveries');
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Expected HTTP 200, got {$res['statusCode']}");
    }
    $errors = $client->checkPhpErrors();
    if (!empty($errors)) {
        throw new \Exception("PHP errors on /deliveries: " . implode('; ', $errors));
    }
    if (!str_contains($res['body'], 'Daily Delivery Run-Sheet') && !str_contains($res['body'], 'Delivery Run-Sheet')) {
        throw new \Exception("Deliveries page missing run-sheet title");
    }
}, $passed, $failed, $bugsFound);

testStep("Deliveries stats JSON endpoint (/deliveries/stats-json)", function() use ($client) {
    $res = $client->get('/deliveries/stats-json?date=' . date('Y-m-d') . '&shift=morning');
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Expected HTTP 200, got {$res['statusCode']}");
    }
    $json = json_decode($res['body'], true);
    if (!isset($json['stats']) && !isset($json['total_customers'])) {
        throw new \Exception("stats-json missing stats payload");
    }
}, $passed, $failed, $bugsFound);

testStep("Toggle delivery status (POST /deliveries/toggle)", function() use ($client) {
    $client->get('/deliveries');
    $token = $client->extractCsrfToken();
    $today = date('Y-m-d');
    
    // Toggle customer 1 to skipped
    $res = $client->post('/deliveries/toggle', [
        'customer_id' => 1,
        'date' => $today,
        'shift' => 'morning',
        'status' => 'skipped',
        'csrf_token' => $token
    ]);
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Toggle failed with HTTP {$res['statusCode']}");
    }

    // Toggle back to delivered with 2.0L
    $res = $client->post('/deliveries/toggle', [
        'customer_id' => 1,
        'date' => $today,
        'shift' => 'morning',
        'status' => 'delivered',
        'quantity' => 2.0,
        'csrf_token' => $token
    ]);
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Restore delivery failed with HTTP {$res['statusCode']}");
    }
}, $passed, $failed, $bugsFound);

testStep("Edit delivery quantity (POST /deliveries/edit-quantity)", function() use ($client) {
    $client->get('/deliveries');
    $token = $client->extractCsrfToken();
    $today = date('Y-m-d');

    $res = $client->post('/deliveries/edit-quantity', [
        'customer_id' => 1,
        'date' => $today,
        'shift' => 'morning',
        'quantity' => 2.5,
        'csrf_token' => $token
    ]);
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Edit quantity failed with HTTP {$res['statusCode']}");
    }
}, $passed, $failed, $bugsFound);

testStep("Bulk deliver all (POST /deliveries/bulk-deliver)", function() use ($client) {
    $client->get('/deliveries');
    $token = $client->extractCsrfToken();
    $today = date('Y-m-d');

    $res = $client->post('/deliveries/bulk-deliver', [
        'date' => $today,
        'shift' => 'morning',
        'csrf_token' => $token
    ]);
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Bulk deliver failed with HTTP {$res['statusCode']}");
    }
}, $passed, $failed, $bugsFound);

// ---------------------------------------------------------
// SECTION 4: CUSTOMERS & LEAVES
// ---------------------------------------------------------
testStep("Customers list view (/customers)", function() use ($client) {
    $res = $client->get('/customers');
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Expected HTTP 200, got {$res['statusCode']}");
    }
    $errors = $client->checkPhpErrors();
    if (!empty($errors)) {
        throw new \Exception("PHP errors on /customers: " . implode('; ', $errors));
    }
    if (!str_contains($res['body'], 'Customer Management') && !str_contains($res['body'], 'Customers')) {
        throw new \Exception("Customers page missing expected title");
    }
}, $passed, $failed, $bugsFound);

testStep("Customer create view (/customers/create)", function() use ($client) {
    $res = $client->get('/customers/create');
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Expected HTTP 200, got {$res['statusCode']}");
    }
    $errors = $client->checkPhpErrors();
    if (!empty($errors)) {
        throw new \Exception("PHP errors on /customers/create: " . implode('; ', $errors));
    }
    if (!str_contains($res['body'], 'Add New Customer')) {
        throw new \Exception("Customer create form missing header");
    }
}, $passed, $failed, $bugsFound);

testStep("Customer sample CSV download (/customers/sample-csv)", function() use ($client) {
    $res = $client->get('/customers/sample-csv');
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Expected HTTP 200, got {$res['statusCode']}");
    }
    if (!str_contains($res['headers'], 'text/csv') || (!str_contains($res['body'], 'Phone') && !str_contains($res['body'], 'phone'))) {
        throw new \Exception("Expected CSV file header and column headers");
    }
}, $passed, $failed, $bugsFound);

testStep("Customer CSV export (/customers/export)", function() use ($client) {
    $res = $client->get('/customers/export');
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Expected HTTP 200, got {$res['statusCode']}");
    }
    if (!str_contains($res['headers'], 'text/csv') || !str_contains($res['body'], 'Aarav Sharma')) {
        throw new \Exception("Customer export missing known customer records");
    }
}, $passed, $failed, $bugsFound);

$createdCustomerId = null;

testStep("Create new customer (POST /customers)", function() use ($client, &$createdCustomerId) {
    $client->get('/customers/create');
    $token = $client->extractCsrfToken();
    $randomPhone = '98' . rand(10000000, 99999999);

    $res = $client->post('/customers', [
        'name' => 'Browser Test Customer',
        'phone' => $randomPhone,
        'address' => 'Flat 502, Lotus Orchid, Pune',
        'route_name' => 'Model Colony Route',
        'milk_types' => ['cow', 'buffalo'],
        'subscriptions' => [
            1 => ['quantity' => '2.0', 'custom_rate' => '65.0', 'shift' => 'both', 'is_active' => '1'],
            2 => ['quantity' => '1.5', 'custom_rate' => '75.0', 'shift' => 'both', 'is_active' => '1']
        ],
        'csrf_token' => $token
    ]);

    if ($res['statusCode'] !== 200 && $res['statusCode'] !== 302) {
        throw new \Exception("Customer creation failed with status {$res['statusCode']}");
    }
    $errors = $client->checkPhpErrors();
    if (!empty($errors)) {
        throw new \Exception("PHP errors during customer create: " . implode('; ', $errors));
    }

    // Look up ID in database
    $db = \App\Core\Database::getInstance();
    $cust = $db->fetchOne("SELECT id FROM customers WHERE tenant_id = 1 AND phone = ?", [$randomPhone]);
    if (!$cust) {
        throw new \Exception("Newly created customer not found in database");
    }
    $createdCustomerId = (int)$cust['id'];
}, $passed, $failed, $bugsFound);

testStep("Customer edit view (/customers/{id}/edit)", function() use ($client, &$createdCustomerId) {
    if (!$createdCustomerId) {
        throw new \Exception("Skipping edit view: No created customer ID");
    }
    $res = $client->get("/customers/{$createdCustomerId}/edit");
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Expected HTTP 200, got {$res['statusCode']}");
    }
    $errors = $client->checkPhpErrors();
    if (!empty($errors)) {
        throw new \Exception("PHP errors on customer edit: " . implode('; ', $errors));
    }
    if (!str_contains($res['body'], 'Browser Test Customer')) {
        throw new \Exception("Customer edit form missing customer name");
    }
}, $passed, $failed, $bugsFound);

testStep("Update customer (POST /customers/{id})", function() use ($client, &$createdCustomerId) {
    if (!$createdCustomerId) {
        throw new \Exception("Skipping update: No created customer ID");
    }
    $client->get("/customers/{$createdCustomerId}/edit");
    $token = $client->extractCsrfToken();

    $res = $client->post("/customers/{$createdCustomerId}", [
        'name' => 'Browser Test Customer Updated',
        'address' => 'Flat 502, Lotus Orchid, Pune (Updated)',
        'route_name' => 'Model Colony Route',
        'milk_types' => ['cow'],
        'subscriptions' => [
            1 => ['quantity' => '3.0', 'custom_rate' => '65.0', 'shift' => 'morning', 'is_active' => '1']
        ],
        'csrf_token' => $token
    ]);
    if ($res['statusCode'] !== 200 && $res['statusCode'] !== 302) {
        throw new \Exception("Customer update failed with status {$res['statusCode']}");
    }
}, $passed, $failed, $bugsFound);

testStep("Schedule vacation leave and delete leave", function() use ($client, &$createdCustomerId) {
    if (!$createdCustomerId) {
        throw new \Exception("Skipping leave test: No created customer ID");
    }
    $client->get("/customers/{$createdCustomerId}/edit");
    $token = $client->extractCsrfToken();

    $startDate = date('Y-m-d', strtotime('+5 days'));
    $endDate = date('Y-m-d', strtotime('+10 days'));

    // Add Leave
    $res = $client->post("/customers/{$createdCustomerId}/leaves", [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'reason' => 'Annual Vacation',
        'csrf_token' => $token
    ]);
    if ($res['statusCode'] !== 200 && $res['statusCode'] !== 302) {
        throw new \Exception("Add leave failed with status {$res['statusCode']}");
    }

    $db = \App\Core\Database::getInstance();
    $leave = $db->fetchOne("SELECT id FROM customer_leaves WHERE customer_id = ? AND start_date = ?", [$createdCustomerId, $startDate]);
    if (!$leave) {
        throw new \Exception("Vacation leave not found in database");
    }
    $leaveId = (int)$leave['id'];

    // Delete Leave
    $res = $client->post("/customers/{$createdCustomerId}/leaves/{$leaveId}/delete", [
        'csrf_token' => $token
    ]);
    if ($res['statusCode'] !== 200 && $res['statusCode'] !== 302) {
        throw new \Exception("Delete leave failed with status {$res['statusCode']}");
    }
    $deleted = $db->fetchOne("SELECT id FROM customer_leaves WHERE id = ?", [$leaveId]);
    if ($deleted) {
        throw new \Exception("Vacation leave was not deleted from database");
    }
}, $passed, $failed, $bugsFound);

// Clean up created test customer
if ($createdCustomerId) {
    $db = \App\Core\Database::getInstance();
    $db->execute("DELETE FROM customers WHERE id = ?", [$createdCustomerId]);
}

// ---------------------------------------------------------
// SECTION 5: INVOICING & PAYMENTS
// ---------------------------------------------------------
testStep("Invoices list view (/invoices)", function() use ($client) {
    $res = $client->get('/invoices');
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Expected HTTP 200, got {$res['statusCode']}");
    }
    $errors = $client->checkPhpErrors();
    if (!empty($errors)) {
        throw new \Exception("PHP errors on /invoices: " . implode('; ', $errors));
    }
    if (!str_contains($res['body'], 'Billing & Invoicing') && !str_contains($res['body'], 'Invoices')) {
        throw new \Exception("Invoices page missing title");
    }
}, $passed, $failed, $bugsFound);

testStep("Generate single invoice (POST /invoices/generate-single)", function() use ($client) {
    $client->get('/invoices');
    $token = $client->extractCsrfToken();
    $now = new DateTime('now');
    $prev = (clone $now)->modify('-1 month');
    $py = (int)$prev->format('Y');
    $pm = (int)$prev->format('m');

    $res = $client->post('/invoices/generate-single', [
        'customer_id' => 1,
        'year' => $py,
        'month' => $pm,
        'csrf_token' => $token
    ]);
    if ($res['statusCode'] !== 200 && $res['statusCode'] !== 302) {
        throw new \Exception("Single invoice generation failed with status {$res['statusCode']}");
    }
}, $passed, $failed, $bugsFound);

testStep("Invoice detail view (/invoices/{id})", function() use ($client) {
    $db = \App\Core\Database::getInstance();
    $inv = $db->fetchOne("SELECT id FROM invoices WHERE tenant_id = 1 ORDER BY id DESC LIMIT 1");
    if (!$inv) {
        throw new \Exception("No invoices found in tenant 1 to view");
    }
    $invId = (int)$inv['id'];

    $res = $client->get("/invoices/{$invId}");
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Expected HTTP 200, got {$res['statusCode']}");
    }
    $errors = $client->checkPhpErrors();
    if (!empty($errors)) {
        throw new \Exception("PHP errors on /invoices/{$invId}: " . implode('; ', $errors));
    }
    if (!str_contains($res['body'], 'Invoice #') && !str_contains($res['body'], 'Bill To')) {
        throw new \Exception("Invoice detail page missing Invoice header or customer info");
    }
}, $passed, $failed, $bugsFound);

testStep("Print A4 invoice (/invoices/{id}/a4)", function() use ($client) {
    $db = \App\Core\Database::getInstance();
    $inv = $db->fetchOne("SELECT id FROM invoices WHERE tenant_id = 1 ORDER BY id DESC LIMIT 1");
    $invId = (int)$inv['id'];

    $res = $client->get("/invoices/{$invId}/a4");
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Expected HTTP 200, got {$res['statusCode']}");
    }
    $errors = $client->checkPhpErrors();
    if (!empty($errors)) {
        throw new \Exception("PHP errors on A4 invoice: " . implode('; ', $errors));
    }
}, $passed, $failed, $bugsFound);

testStep("Print Thermal invoice (/invoices/{id}/thermal)", function() use ($client) {
    $db = \App\Core\Database::getInstance();
    $inv = $db->fetchOne("SELECT id FROM invoices WHERE tenant_id = 1 ORDER BY id DESC LIMIT 1");
    $invId = (int)$inv['id'];

    $res = $client->get("/invoices/{$invId}/thermal");
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Expected HTTP 200, got {$res['statusCode']}");
    }
    $errors = $client->checkPhpErrors();
    if (!empty($errors)) {
        throw new \Exception("PHP errors on thermal invoice: " . implode('; ', $errors));
    }
}, $passed, $failed, $bugsFound);

testStep("Download Invoice PDF (/invoices/{id}/pdf)", function() use ($client) {
    $db = \App\Core\Database::getInstance();
    $inv = $db->fetchOne("SELECT id FROM invoices WHERE tenant_id = 1 ORDER BY id DESC LIMIT 1");
    $invId = (int)$inv['id'];

    $res = $client->get("/invoices/{$invId}/pdf");
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Expected HTTP 200, got {$res['statusCode']}");
    }
    if (!str_starts_with($res['body'], '%PDF-')) {
        throw new \Exception("Expected PDF stream beginning with %PDF-");
    }
}, $passed, $failed, $bugsFound);

testStep("Record Invoice Payment (POST /invoices/{id}/payment)", function() use ($client) {
    $db = \App\Core\Database::getInstance();
    $inv = $db->fetchOne("SELECT id FROM invoices WHERE tenant_id = 1 ORDER BY id DESC LIMIT 1");
    $invId = (int)$inv['id'];

    $client->get("/invoices/{$invId}");
    $token = $client->extractCsrfToken();

    $res = $client->post("/invoices/{$invId}/payment", [
        'amount' => 50.00,
        'payment_method' => 'cash',
        'reference_number' => 'TEST_CASH_01',
        'notes' => 'Browser test cash payment',
        'csrf_token' => $token
    ]);
    if ($res['statusCode'] !== 200 && $res['statusCode'] !== 302) {
        throw new \Exception("Payment record failed with status {$res['statusCode']}");
    }
}, $passed, $failed, $bugsFound);

testStep("WhatsApp single share (/invoices/{id}/whatsapp)", function() use ($client) {
    $db = \App\Core\Database::getInstance();
    $inv = $db->fetchOne("SELECT id FROM invoices WHERE tenant_id = 1 ORDER BY id DESC LIMIT 1");
    $invId = (int)$inv['id'];

    $res = $client->get("/invoices/{$invId}/whatsapp", ['Accept: application/json']);
    if ($res['statusCode'] !== 200 && $res['statusCode'] !== 302) {
        throw new \Exception("WhatsApp share failed with status {$res['statusCode']}");
    }
    if ($res['statusCode'] === 200) {
        $json = json_decode($res['body'], true);
        if (empty($json['whatsapp_url'])) {
            throw new \Exception("Expected whatsapp_url in JSON response");
        }
    }
}, $passed, $failed, $bugsFound);

testStep("WhatsApp bulk enqueue (POST /invoices/whatsapp/bulk-queue)", function() use ($client) {
    $db = \App\Core\Database::getInstance();
    $inv = $db->fetchOne("SELECT id FROM invoices WHERE tenant_id = 1 ORDER BY id DESC LIMIT 1");
    $invId = (int)$inv['id'];

    $client->get('/invoices');
    $token = $client->extractCsrfToken();

    $res = $client->post('/invoices/whatsapp/bulk-queue', [
        'invoice_ids' => [$invId],
        'year' => (int)date('Y'),
        'month' => (int)date('m'),
        'csrf_token' => $token
    ]);
    if ($res['statusCode'] !== 200 && $res['statusCode'] !== 302) {
        throw new \Exception("Bulk queue failed with status {$res['statusCode']}");
    }
}, $passed, $failed, $bugsFound);

// ---------------------------------------------------------
// SECTION 6: SETTINGS, PRODUCTS & SURCHARGES
// ---------------------------------------------------------
testStep("Settings view (/settings)", function() use ($client) {
    $res = $client->get('/settings');
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Expected HTTP 200, got {$res['statusCode']}");
    }
    $errors = $client->checkPhpErrors();
    if (!empty($errors)) {
        throw new \Exception("PHP errors on /settings: " . implode('; ', $errors));
    }
    if (!str_contains($res['body'], 'Business Profile') || !str_contains($res['body'], 'Tax & UPI')) {
        throw new \Exception("Settings page missing sections");
    }
}, $passed, $failed, $bugsFound);

testStep("Update Profile & Taxes (POST /settings/profile & /settings/taxes)", function() use ($client) {
    $client->get('/settings');
    $token = $client->extractCsrfToken();

    $res1 = $client->post('/settings/profile', [
        'business_name' => 'Krishna Pure Milk Dairy',
        'owner_name' => 'Ramesh Patel',
        'phone' => '9822000001',
        'email' => 'ramesh@krishnadairy.com',
        'address' => 'Gokul Dham, Sector 4, Pune',
        'csrf_token' => $token
    ]);
    if ($res1['statusCode'] !== 200 && $res1['statusCode'] !== 302) {
        throw new \Exception("Profile update failed with status {$res1['statusCode']}");
    }

    $res2 = $client->post('/settings/taxes', [
        'tax_enabled' => '1',
        'tax_label' => 'GST',
        'tax_percentage' => '5.00',
        'tax_number' => '27AAAAA0000A1Z5',
        'upi_id' => 'krushnadairy@okaxis',
        'invoice_footer_note' => 'Pure and fresh farm milk delivered daily at your doorstep.',
        'csrf_token' => $token
    ]);
    if ($res2['statusCode'] !== 200 && $res2['statusCode'] !== 302) {
        throw new \Exception("Taxes update failed with status {$res2['statusCode']}");
    }
}, $passed, $failed, $bugsFound);

testStep("Add, toggle, and delete custom surcharge (/settings/charges)", function() use ($client) {
    $client->get('/settings');
    $token = $client->extractCsrfToken();

    // 1. Add charge
    $res = $client->post('/settings/charges', [
        'charge_name' => 'Eco Glass Bottle Deposit',
        'charge_type' => 'flat_monthly',
        'amount' => '30.00',
        'is_taxable' => '1',
        'csrf_token' => $token
    ]);
    if ($res['statusCode'] !== 200 && $res['statusCode'] !== 302) {
        throw new \Exception("Add charge failed with status {$res['statusCode']}");
    }

    $db = \App\Core\Database::getInstance();
    $charge = $db->fetchOne("SELECT id, is_active FROM tenant_additional_charges WHERE tenant_id = 1 AND charge_name = 'Eco Glass Bottle Deposit'");
    if (!$charge) {
        throw new \Exception("Surcharge not found in database");
    }
    $chargeId = (int)$charge['id'];

    // 2. Toggle charge
    $resToggle = $client->post("/settings/charges/{$chargeId}/toggle", [
        'csrf_token' => $token
    ]);
    if ($resToggle['statusCode'] !== 200 && $resToggle['statusCode'] !== 302) {
        throw new \Exception("Toggle charge failed with status {$resToggle['statusCode']}");
    }

    // 3. Delete charge
    $resDel = $client->post("/settings/charges/{$chargeId}/delete", [
        'csrf_token' => $token
    ]);
    if ($resDel['statusCode'] !== 200 && $resDel['statusCode'] !== 302) {
        throw new \Exception("Delete charge failed with status {$resDel['statusCode']}");
    }
    $deleted = $db->fetchOne("SELECT id FROM tenant_additional_charges WHERE id = ?", [$chargeId]);
    if ($deleted) {
        throw new \Exception("Surcharge was not deleted from database");
    }
}, $passed, $failed, $bugsFound);

testStep("Add, toggle, and delete custom product (/settings/products)", function() use ($client) {
    $client->get('/settings');
    $token = $client->extractCsrfToken();

    // 1. Add product
    $res = $client->post('/settings/products', [
        'name' => 'Organic Paneer',
        'code' => 'paneer',
        'unit' => 'KG',
        'default_rate' => '400.00',
        'is_active' => '1',
        'csrf_token' => $token
    ]);
    if ($res['statusCode'] !== 200 && $res['statusCode'] !== 302) {
        throw new \Exception("Add product failed with status {$res['statusCode']}");
    }

    $db = \App\Core\Database::getInstance();
    $prod = $db->fetchOne("SELECT id, is_active FROM products WHERE tenant_id = 1 AND code = 'paneer'");
    if (!$prod) {
        throw new \Exception("Product not found in database");
    }
    $prodId = (int)$prod['id'];

    // 2. Toggle product
    $resToggle = $client->post("/settings/products/{$prodId}/toggle", [
        'csrf_token' => $token
    ]);
    if ($resToggle['statusCode'] !== 200 && $resToggle['statusCode'] !== 302) {
        throw new \Exception("Toggle product failed with status {$resToggle['statusCode']}");
    }

    // 3. Delete product
    $resDel = $client->post("/settings/products/{$prodId}/delete", [
        'csrf_token' => $token
    ]);
    if ($resDel['statusCode'] !== 200 && $resDel['statusCode'] !== 302) {
        throw new \Exception("Delete product failed with status {$resDel['statusCode']}");
    }
    $deleted = $db->fetchOne("SELECT id FROM products WHERE id = ?", [$prodId]);
    if ($deleted) {
        throw new \Exception("Product was not deleted from database");
    }
}, $passed, $failed, $bugsFound);

testStep("Subscription view & upgrade (/subscription)", function() use ($client) {
    $res = $client->get('/subscription');
    if ($res['statusCode'] !== 200) {
        throw new \Exception("Expected HTTP 200, got {$res['statusCode']}");
    }
    $errors = $client->checkPhpErrors();
    if (!empty($errors)) {
        throw new \Exception("PHP errors on /subscription: " . implode('; ', $errors));
    }
    if (!str_contains($res['body'], 'Active Customer Capacity') && !str_contains($res['body'], 'Subscription Plan')) {
        throw new \Exception("Subscription page missing plan details");
    }

    $token = $client->extractCsrfToken();
    $resUpgrade = $client->post('/subscription/upgrade', [
        'blocks' => 1,
        'csrf_token' => $token
    ]);
    if ($resUpgrade['statusCode'] !== 200 && $resUpgrade['statusCode'] !== 302) {
        throw new \Exception("Subscription upgrade failed with status {$resUpgrade['statusCode']}");
    }
}, $passed, $failed, $bugsFound);

// ---------------------------------------------------------
// SECTION 7: TENANT 2 (FREE TIER) & QUOTA GUARD
// ---------------------------------------------------------
testStep("Switch to Tenant 2 (Free Tier) & Quota Enforcement", function() use ($client) {
    $res = $client->get('/demo/tenant2');
    if ($res['statusCode'] !== 200 || !str_contains($res['body'], 'Suresh Sharma')) {
        throw new \Exception("Demo switch to Suresh Sharma (Tenant 2) failed");
    }

    // Check customer list
    $resCust = $client->get('/customers');
    if ($resCust['statusCode'] !== 200) {
        throw new \Exception("Expected HTTP 200 on /customers for Tenant 2");
    }
    
    // Attempt to add a 5th customer
    $token = $client->extractCsrfToken();
    $resAdd1 = $client->post('/customers', [
        'name' => 'Fifth Customer',
        'phone' => '9888800005',
        'address' => 'Station Road',
        'route_name' => 'Main Route',
        'milk_types' => ['cow'],
        'subscriptions' => [
            101 => ['quantity' => '1.0', 'custom_rate' => '60.0', 'shift' => 'morning', 'is_active' => '1']
        ],
        'csrf_token' => $token
    ]);

    // Now attempt to add a 6th customer (exceeding free limit of 5)
    $resAdd2 = $client->post('/customers', [
        'name' => 'Sixth Customer Over Quota',
        'phone' => '9888800006',
        'address' => 'Station Road',
        'route_name' => 'Main Route',
        'milk_types' => ['cow'],
        'subscriptions' => [
            101 => ['quantity' => '1.0', 'custom_rate' => '60.0', 'shift' => 'morning', 'is_active' => '1']
        ],
        'csrf_token' => $token
    ]);

    // Cleanup 5th customer
    $db = \App\Core\Database::getInstance();
    $db->execute("DELETE FROM customers WHERE tenant_id = 2 AND phone = '9888800005'");
    $db->execute("DELETE FROM customers WHERE tenant_id = 2 AND phone = '9888800006'");

    // Verify error flash or restriction on 6th customer
    if (!str_contains($resAdd2['body'], 'limit') && !str_contains($resAdd2['body'], 'quota') && !str_contains($resAdd2['body'], 'upgrade')) {
        throw new \Exception("Quota guard was not enforced when adding 6th customer on free tier");
    }
}, $passed, $failed, $bugsFound);

// ---------------------------------------------------------
// SECTION 8: SUPER ADMIN GOVERNANCE
// ---------------------------------------------------------
testStep("Switch to Super Admin & Platform Governance (/admin)", function() use ($client) {
    $res = $client->get('/demo/admin');
    if ($res['statusCode'] !== 200 || !str_contains($res['body'], 'Super Admin')) {
        throw new \Exception("Demo switch to Super Admin failed");
    }

    $errors = $client->checkPhpErrors();
    if (!empty($errors)) {
        throw new \Exception("PHP errors on /admin: " . implode('; ', $errors));
    }

    $token = $client->extractCsrfToken();

    // 1. Update platform settings
    $resSettings = $client->post('/admin/settings', [
        'free_customer_limit' => 5,
        'paid_tier_unit_size' => 100,
        'paid_tier_unit_price' => 200.00,
        'currency_symbol' => '₹',
        'support_email' => 'support@doodhbee.com',
        'support_phone' => '+91 98000 11223',
        'csrf_token' => $token
    ]);
    if ($resSettings['statusCode'] !== 200 && $resSettings['statusCode'] !== 302) {
        throw new \Exception("Admin settings update failed with status {$resSettings['statusCode']}");
    }

    // 2. Adjust tenant quota
    $resQuota = $client->post('/admin/tenants/2/quota', [
        'max_customers' => 10,
        'csrf_token' => $token
    ]);
    if ($resQuota['statusCode'] !== 200 && $resQuota['statusCode'] !== 302) {
        throw new \Exception("Admin quota adjust failed with status {$resQuota['statusCode']}");
    }
    // Reset back to 5
    $client->post('/admin/tenants/2/quota', ['max_customers' => 5, 'csrf_token' => $token]);

    // 3. Toggle tenant status (suspend and re-activate)
    $resToggle = $client->post('/admin/tenants/2/toggle-status', ['csrf_token' => $token]);
    if ($resToggle['statusCode'] !== 200 && $resToggle['statusCode'] !== 302) {
        throw new \Exception("Admin toggle status failed with status {$resToggle['statusCode']}");
    }
    // Toggle back to active
    $client->post('/admin/tenants/2/toggle-status', ['csrf_token' => $token]);
}, $passed, $failed, $bugsFound);

// ---------------------------------------------------------
// SECTION 9: LOGOUT
// ---------------------------------------------------------
testStep("Logout (/logout)", function() use ($client) {
    $res = $client->get('/logout');
    if ($res['statusCode'] !== 200 || !str_contains($res['body'], 'Sign In')) {
        throw new \Exception("Logout failed to redirect to login page");
    }
}, $passed, $failed, $bugsFound);

echo "\n======================================================================\n";
echo "   BROWSER TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "======================================================================\n";

if (!empty($bugsFound)) {
    echo "BUGS DETECTED:\n";
    foreach ($bugsFound as $i => $bug) {
        echo ($i + 1) . ". {$bug['test']}: {$bug['error']}\n";
    }
} else {
    echo "\033[32m✓ All browser workflows, pages, forms, and actions executed with 0 errors!\033[0m\n";
}
echo "======================================================================\n";

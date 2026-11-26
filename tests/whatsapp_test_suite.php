<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Services\WhatsAppService;

echo "========================================================\n";
echo "   DOODHBEE WHATSAPP INVOICE DISPATCH TEST SUITE        \n";
echo "========================================================\n\n";

$db = Database::getInstance();
$db->execute("UPDATE tenant_settings SET whatsapp_provider = 'browser', whatsapp_api_token = NULL, whatsapp_phone_number_id = NULL WHERE tenant_id = 1");
$service = new WhatsAppService($db);
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

// TEST 1: Phone Sanitization
runTest("WhatsApp Phone Number Sanitization", function() {
    $tests = [
        '9822012345' => '919822012345',
        '+91 98220 12345' => '919822012345',
        '09822012345' => '919822012345',
        '919822012345' => '919822012345',
        '+91-98220-12345' => '919822012345',
    ];

    foreach ($tests as $input => $expected) {
        $result = WhatsAppService::sanitizePhone($input);
        if ($result !== $expected) {
            throw new \Exception("Sanitize failed for '{$input}': expected '{$expected}', got '{$result}'");
        }
    }
}, $passed, $failed);

// TEST 2: Message Formatting & UPI Link Generation
runTest("Invoice Message & UPI Link Generator", function() use ($service) {
    $dummyInvoice = [
        'id' => 101,
        'billing_month' => 11,
        'billing_year' => 2026,
        'customer_name' => 'Rahul Sharma',
        'total_units' => 45.5,
        'final_amount' => 2730.00,
        'previous_balance' => 0.00,
        'payment_status' => 'unpaid',
    ];
    $dummyTenant = [
        'business_name' => 'Krushna Dairy Farm',
    ];
    $dummySettings = [
        'upi_id' => 'krushna@oksbi',
    ];

    $msg = $service->formatInvoiceMessage($dummyInvoice, $dummyTenant, $dummySettings);

    if (!str_contains($msg, 'Krushna Dairy Farm')) {
        throw new \Exception("Message missing business name");
    }
    if (!str_contains($msg, 'Rahul Sharma')) {
        throw new \Exception("Message missing customer name");
    }
    if (!str_contains($msg, 'November 2026')) {
        throw new \Exception("Message missing billing period");
    }
    if (!str_contains($msg, '45.5 L')) {
        throw new \Exception("Message missing liters delivered");
    }
    if (!str_contains($msg, '₹2,730.00')) {
        throw new \Exception("Message missing final amount");
    }
    if (!str_contains($msg, 'krushna@oksbi')) {
        throw new \Exception("Message missing UPI ID");
    }
    if (!str_contains($msg, 'upi://pay?pa=krushna@oksbi')) {
        throw new \Exception("Message missing valid UPI payment URI");
    }
}, $passed, $failed);

// TEST 3: Enqueue Invoices into Database Queue
$testInvoiceIds = [];
runTest("Enqueue Invoices into Database whatsapp_messages", function() use ($db, $service, &$testInvoiceIds) {
    $invoices = $db->fetchAll("SELECT id FROM invoices WHERE tenant_id = 1 LIMIT 3");
    if (empty($invoices)) {
        throw new \Exception("No invoices found for tenant 1 to test enqueueing.");
    }
    $testInvoiceIds = array_map(fn($i) => (int)$i['id'], $invoices);

    // Clean any prior messages for these invoices
    $placeholders = implode(',', array_fill(0, count($testInvoiceIds), '?'));
    $db->execute("DELETE FROM whatsapp_messages WHERE tenant_id = 1 AND invoice_id IN ({$placeholders})", $testInvoiceIds);

    // Enqueue
    $res = $service->enqueueInvoices(1, $testInvoiceIds);

    if ($res['enqueued_count'] !== count($testInvoiceIds)) {
        throw new \Exception("Expected {$res['enqueued_count']} to equal " . count($testInvoiceIds));
    }

    // Verify records exist in database
    $rows = $db->fetchAll("SELECT * FROM whatsapp_messages WHERE tenant_id = 1 AND invoice_id IN ({$placeholders})", $testInvoiceIds);
    if (count($rows) !== count($testInvoiceIds)) {
        throw new \Exception("Database row count mismatch in whatsapp_messages");
    }

    foreach ($rows as $row) {
        if ($row['status'] !== 'pending') {
            throw new \Exception("Initial message status should be 'pending', got: " . $row['status']);
        }
        if (empty($row['recipient_phone'])) {
            throw new \Exception("Recipient phone is empty in queued message");
        }
        if (!str_contains($row['message_body'], 'Namaste')) {
            throw new \Exception("Message body is malformed in queued message");
        }
    }

    // Duplicate prevention check: Re-enqueuing the same IDs should skip them
    $resDuplicate = $service->enqueueInvoices(1, $testInvoiceIds);
    if ($resDuplicate['enqueued_count'] !== 0 || $resDuplicate['skipped_count'] !== count($testInvoiceIds)) {
        throw new \Exception("Duplicate prevention failed: Expected 0 enqueued, got {$resDuplicate['enqueued_count']}");
    }
}, $passed, $failed);

// TEST 4: Queue Statistics
runTest("WhatsApp Queue Stats Retrieval", function() use ($service) {
    $stats = $service->getQueueStats(1);
    if (!isset($stats['pending']) || !isset($stats['sent']) || !isset($stats['failed']) || !isset($stats['total'])) {
        throw new \Exception("Stats array missing required keys");
    }
    if ($stats['pending'] <= 0) {
        throw new \Exception("Expected pending count > 0 after enqueueing");
    }
}, $passed, $failed);

// TEST 5: Background Cron Worker Execution
runTest("Cron CLI Worker (scripts/process_whatsapp_queue.php)", function() use ($db, &$testInvoiceIds) {
    // Run the CLI script via shell
    $cmd = 'php8.4 ' . escapeshellarg(dirname(__DIR__) . '/scripts/process_whatsapp_queue.php') . ' --tenant=1 --limit=10 2>&1';
    $output = (string)shell_exec($cmd);

    if (!str_contains($output, '[WhatsApp Queue] Finished')) {
        throw new \Exception("Cron worker failed to execute successfully: {$output}");
    }

    // Check database status
    $placeholders = implode(',', array_fill(0, count($testInvoiceIds), '?'));
    $rows = $db->fetchAll("SELECT * FROM whatsapp_messages WHERE tenant_id = 1 AND invoice_id IN ({$placeholders})", $testInvoiceIds);

    foreach ($rows as $row) {
        if ($row['status'] !== 'sent') {
            throw new \Exception("Message #{$row['id']} status should be 'sent' after cron processing, got: {$row['status']}");
        }
        if (empty($row['sent_at'])) {
            throw new \Exception("Message #{$row['id']} missing sent_at timestamp");
        }
    }
}, $passed, $failed);

// TEST 6: Retry Mechanism for Failed Messages
runTest("Message Retry & Re-queuing", function() use ($db, $service, &$testInvoiceIds) {
    $firstId = $testInvoiceIds[0];
    $msg = $db->fetchOne("SELECT id FROM whatsapp_messages WHERE tenant_id = 1 AND invoice_id = ? LIMIT 1", [$firstId]);
    if (!$msg) {
        throw new \Exception("Queued message not found for retry test");
    }
    $msgId = (int)$msg['id'];

    // Simulate failure
    $db->execute("UPDATE whatsapp_messages SET status = 'failed', error_message = 'Simulated timeout', attempts = 3 WHERE id = ?", [$msgId]);

    // Retry
    $retried = $service->retryMessage($msgId, 1);
    if (!$retried) {
        throw new \Exception("retryMessage returned false");
    }

    $updated = $db->fetchOne("SELECT status, attempts, error_message FROM whatsapp_messages WHERE id = ?", [$msgId]);
    if ($updated['status'] !== 'pending' || (int)$updated['attempts'] !== 0 || $updated['error_message'] !== null) {
        throw new \Exception("Failed to reset message fields on retry: " . json_encode($updated));
    }
}, $passed, $failed);

// TEST 7: Bill Cryptographic Token Generation & Verification
runTest("Public Bill Token Generator & Anti-Tamper Verification", function() use ($db) {
    $inv = $db->fetchOne("SELECT id, tenant_id FROM invoices WHERE tenant_id = 1 LIMIT 1");
    if (!$inv) {
        throw new \Exception("No invoice found for token verification test");
    }
    $invId = (int)$inv['id'];
    $tenantId = (int)$inv['tenant_id'];

    $token = WhatsAppService::generateInvoiceToken($invId, $tenantId);
    if (strlen($token) !== 32) {
        throw new \Exception("Generated token length should be 32 chars, got: " . strlen($token));
    }

    // Valid check
    if (!WhatsAppService::verifyInvoiceToken($invId, $tenantId, $token)) {
        throw new \Exception("Token verification failed for legitimate invoice and token");
    }

    // Tampered token check
    if (WhatsAppService::verifyInvoiceToken($invId, $tenantId, $token . 'tampered')) {
        throw new \Exception("Security check failed: Tampered token should be rejected");
    }

    // Tampered invoice ID check
    if (WhatsAppService::verifyInvoiceToken($invId + 999, $tenantId, $token)) {
        throw new \Exception("Security check failed: Token should not match a different invoice ID");
    }

    // Public URL format check
    $publicUrl = WhatsAppService::getPublicPdfUrl($invId, $tenantId);
    if (!str_contains($publicUrl, "/bill/{$invId}/pdf?token={$token}")) {
        throw new \Exception("Public PDF URL is malformed: {$publicUrl}");
    }
}, $passed, $failed);

// TEST 8: Public Bill REST API
runTest("Public Bill REST API (GET /api/public/invoices/{id})", function() use ($db) {
    $inv = $db->fetchOne("SELECT id, tenant_id FROM invoices WHERE tenant_id = 1 LIMIT 1");
    $invId = (int)$inv['id'];
    $tenantId = (int)$inv['tenant_id'];
    $validToken = WhatsAppService::generateInvoiceToken($invId, $tenantId);

    // Call API internally
    $resValid = \App\Core\ApiClient::get("/api/public/invoices/{$invId}", ['token' => $validToken]);
    if (!$resValid['success'] || empty($resValid['data']['invoice']['id'])) {
        throw new \Exception("Public bill API failed with valid token: " . json_encode($resValid));
    }

    // Call API with invalid token -> should fail with 403
    $resInvalid = \App\Core\ApiClient::get("/api/public/invoices/{$invId}", ['token' => 'invalid_random_token_12345678901']);
    if ($resInvalid['success']) {
        throw new \Exception("Public bill API should have rejected invalid token, but succeeded");
    }
}, $passed, $failed);

// TEST 9: Immediate Single Invoice Dispatch Reusing Cron Engine
runTest("Immediate Single Invoice Dispatch (sendSingleInvoiceNow)", function() use ($db, $service) {
    $inv = $db->fetchOne("SELECT id FROM invoices WHERE tenant_id = 1 ORDER BY id ASC LIMIT 1");
    if (!$inv) {
        throw new \Exception("No invoice found for tenant 1");
    }
    $invId = (int)$inv['id'];

    // Clean prior messages for this invoice
    $db->execute("DELETE FROM whatsapp_messages WHERE tenant_id = 1 AND invoice_id = ?", [$invId]);

    // Dispatch directly using single send method
    $result = $service->sendSingleInvoiceNow(1, $invId);

    if (!$result['success']) {
        throw new \Exception("sendSingleInvoiceNow failed: " . ($result['error'] ?? 'Unknown error'));
    }

    // Verify row status in database
    $status = $service->getInvoiceMessageStatus(1, $invId);
    if (!$status) {
        throw new \Exception("getInvoiceMessageStatus returned null");
    }
    if ($status['status'] !== 'sent') {
        throw new \Exception("Expected status 'sent', got: " . $status['status']);
    }
    if (empty($status['provider_message_id'])) {
        throw new \Exception("Missing provider_message_id in single send record");
    }
}, $passed, $failed);

// TEST 10: Single Invoice Dispatch with Meta Cloud API (Missing Credentials Guard)
runTest("Meta Cloud API Provider Credential Guard", function() use ($db, $service) {
    $inv = $db->fetchOne("SELECT id FROM invoices WHERE tenant_id = 1 ORDER BY id ASC LIMIT 1");
    $invId = (int)$inv['id'];

    // Clear tenant settings WhatsApp tokens to test validation
    $db->execute("UPDATE tenant_settings SET whatsapp_api_token = NULL, whatsapp_phone_number_id = NULL WHERE tenant_id = 1");

    // Clean prior message
    $db->execute("DELETE FROM whatsapp_messages WHERE tenant_id = 1 AND invoice_id = ?", [$invId]);

    // Attempt to force meta_cloud provider dispatch
    $result = $service->sendSingleInvoiceNow(1, $invId, 'meta_cloud');

    if ($result['success'] !== false) {
        throw new \Exception("Expected meta_cloud dispatch to fail with missing credentials, but succeeded");
    }
    if (!str_contains($result['error'] ?? '', 'Meta Cloud API credentials missing')) {
        throw new \Exception("Unexpected error message: " . ($result['error'] ?? 'none'));
    }

    // Verify database row logged error and status is pending (for retry) or failed
    $status = $service->getInvoiceMessageStatus(1, $invId);
    if (!in_array($status['status'], ['pending', 'failed'], true)) {
        throw new \Exception("Expected message status 'pending' or 'failed', got: " . $status['status']);
    }
    if (!str_contains($status['error_message'] ?? '', 'credentials missing')) {
        throw new \Exception("Error message not recorded in database: " . ($status['error_message'] ?? 'none'));
    }
}, $passed, $failed);

// TEST 11: Single Invoice Send REST API (POST /api/invoices/{id}/send-whatsapp)
runTest("Single Invoice Send REST API (POST /api/invoices/{id}/send-whatsapp)", function() use ($db) {
    $inv = $db->fetchOne("SELECT id FROM invoices WHERE tenant_id = 1 ORDER BY id ASC LIMIT 1");
    $invId = (int)$inv['id'];

    // Create a valid Bearer token for tenant 1
    $plainToken = 'test_token_' . bin2hex(random_bytes(16));
    $tokenHash = hash('sha256', $plainToken);
    $db->execute(
        "INSERT INTO api_tokens (tenant_id, token_hash, token_type, name, expires_at)
         VALUES (1, ?, 'tenant', 'PHPUnit Runner', DATE_ADD(NOW(), INTERVAL 1 DAY))",
        [$tokenHash]
    );

    // Also populate session context
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $_SESSION['tenant'] = [
        'id' => 1,
        'role' => 'admin',
        'business_name' => 'Krishna Pure Milk Dairy',
        'status' => 'active',
    ];

    // Dispatch via API with default provider using Bearer token
    $res = \App\Core\ApiClient::post("/api/invoices/{$invId}/send-whatsapp", ['provider' => 'browser'], $plainToken);

    if (!$res['success']) {
        throw new \Exception("API single send failed: " . json_encode($res));
    }
    if (empty($res['data']['message_id'])) {
        throw new \Exception("API response missing message_id");
    }
}, $passed, $failed);

// TEST 12: WhatsApp Gateway Settings API (POST /api/settings/whatsapp)
runTest("WhatsApp Gateway Settings API (POST /api/settings/whatsapp)", function() use ($db) {
    $plainToken = 'test_token_settings_' . bin2hex(random_bytes(16));
    $tokenHash = hash('sha256', $plainToken);
    $db->execute(
        "INSERT INTO api_tokens (tenant_id, token_hash, token_type, name, expires_at)
         VALUES (1, ?, 'tenant', 'PHPUnit Runner', DATE_ADD(NOW(), INTERVAL 1 DAY))",
        [$tokenHash]
    );

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $_SESSION['tenant'] = [
        'id' => 1,
        'role' => 'admin',
        'business_name' => 'Krishna Pure Milk Dairy',
        'status' => 'active',
    ];

    $payload = [
        'whatsapp_provider' => 'meta_cloud',
        'whatsapp_phone_number_id' => '109876543210987',
        'whatsapp_api_token' => 'EAAG_TEST_TOKEN_12345',
    ];

    $res = \App\Core\ApiClient::post("/api/settings/whatsapp", $payload, $plainToken);
    if (!$res['success']) {
        throw new \Exception("WhatsApp settings update failed: " . json_encode($res));
    }

    // Verify in database
    $row = $db->fetchOne("SELECT whatsapp_provider, whatsapp_phone_number_id FROM tenant_settings WHERE tenant_id = 1");
    if ($row['whatsapp_provider'] !== 'meta_cloud' || $row['whatsapp_phone_number_id'] !== '109876543210987') {
        throw new \Exception("Database row not updated properly: " . json_encode($row));
    }
}, $passed, $failed);

// Clean up test messages
$db->execute("UPDATE tenant_settings SET whatsapp_provider = 'browser', whatsapp_api_token = NULL, whatsapp_phone_number_id = NULL WHERE tenant_id = 1");
if (!empty($testInvoiceIds)) {
    $placeholders = implode(',', array_fill(0, count($testInvoiceIds), '?'));
    $db->execute("DELETE FROM whatsapp_messages WHERE tenant_id = 1 AND invoice_id IN ({$placeholders})", $testInvoiceIds);
}

echo "\n========================================================\n";
echo "   WHATSAPP TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}

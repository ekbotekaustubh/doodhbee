#!/usr/bin/env php8.4
<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Services\WhatsAppService;

// Prevent overlapping cron jobs using a lockfile
$lockDir = dirname(__DIR__) . '/storage/logs';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}
$lockFile = $lockDir . '/whatsapp_queue.lock';
$fp = fopen($lockFile, 'c+');

if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] Another WhatsApp queue processor is already running. Exiting.\n";
    exit(0);
}

// Parse command line arguments
$options = getopt('', ['limit::', 'tenant::', 'dry-run', 'help']);

if (isset($options['help'])) {
    echo "Usage: php process_whatsapp_queue.php [options]\n";
    echo "  --limit=N       Max messages to process (default: 50)\n";
    echo "  --tenant=ID     Process messages for a specific tenant ID\n";
    echo "  --dry-run       Simulate processing without sending or modifying data\n";
    echo "  --help          Display this help message\n";
    exit(0);
}

$limit = isset($options['limit']) ? (int)$options['limit'] : 50;
$tenantId = isset($options['tenant']) ? (int)$options['tenant'] : null;
$isDryRun = isset($options['dry-run']);

$startTime = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] [WhatsApp Queue] Starting worker" . ($isDryRun ? " (DRY-RUN)" : "") . " (Limit: {$limit}" . ($tenantId ? ", Tenant: {$tenantId}" : "") . ")...\n";

try {
    $service = new WhatsAppService();
    $result = $service->processQueue($limit, $tenantId, $isDryRun);

    $duration = round(microtime(true) - $startTime, 3);
    echo "[" . date('Y-m-d H:i:s') . "] [WhatsApp Queue] Finished in {$duration}s. Processed: {$result['processed']}, Sent: {$result['sent']}, Failed: {$result['failed']}.\n";

    if (!empty($result['details'])) {
        foreach ($result['details'] as $item) {
            $status = $item['status'] ?? $item['action'] ?? 'unknown';
            $err = !empty($item['error']) ? " (Error: {$item['error']})" : "";
            echo "   • Message #{$item['id']}: [{$status}]{$err}\n";
        }
    }
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] [WhatsApp Queue ERROR] " . $e->getMessage() . "\n";
} finally {
    flock($fp, LOCK_UN);
    fclose($fp);
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Env;

echo "========================================================\n";
echo "   DOODHBEE SAAS - AUTOMATED DATABASE BACKUP           \n";
echo "========================================================\n\n";

$dbHost = (string)Env::get('DB_HOST', '127.0.0.1');
$dbPort = (string)Env::get('DB_PORT', '3306');
$dbName = (string)Env::get('DB_DATABASE', 'dairyflow');
$dbUser = (string)Env::get('DB_USERNAME', 'dairyflow');
$dbPass = (string)Env::get('DB_PASSWORD', 'dairyflow123');

$backupDir = dirname(__DIR__) . '/storage/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

$timestamp = date('Ymd_His');
$targetFile = "{$backupDir}/backup_{$dbName}_{$timestamp}.sql.gz";

echo "• Target database: [{$dbName}]\n";
echo "• Destination: {$targetFile}\n";

// Execute mysqldump with gzip compression
$cmd = sprintf(
    'mysqldump --host=%s --port=%s --user=%s --password=%s --single-transaction --quick %s | gzip > %s',
    escapeshellarg($dbHost),
    escapeshellarg($dbPort),
    escapeshellarg($dbUser),
    escapeshellarg($dbPass),
    escapeshellarg($dbName),
    escapeshellarg($targetFile)
);

exec($cmd, $output, $returnCode);

if ($returnCode !== 0 || !file_exists($targetFile) || filesize($targetFile) === 0) {
    echo "[\033[31mERROR\033[0m] Database backup failed (Exit code: {$returnCode}). Check credentials and permissions.\n";
    if (file_exists($targetFile)) {
        unlink($targetFile);
    }
    exit(1);
}

$fileSizeKb = round(filesize($targetFile) / 1024, 2);
echo "• Backup archive generated ({$fileSizeKb} KB)\n";

// Retention rotation: clean backups older than 14 days
$retentionDays = 14;
$cutoff = time() - ($retentionDays * 86400);
$cleaned = 0;

foreach (glob("{$backupDir}/backup_*.sql.gz") as $oldFile) {
    if (filemtime($oldFile) < $cutoff) {
        unlink($oldFile);
        $cleaned++;
    }
}

if ($cleaned > 0) {
    echo "• Purged {$cleaned} backup(s) older than {$retentionDays} days.\n";
}

echo "\n[\033[32mSUCCESS\033[0m] Database backup completed successfully!\n";

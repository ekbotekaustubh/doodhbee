<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\Env;

echo "========================================================\n";
echo "   DOODHBEE SAAS - PRODUCTION READINESS AUDIT          \n";
echo "========================================================\n\n";

$issues = 0;
$warnings = 0;

function check(string $label, bool $passed, string $failMsg = '', bool $isWarning = false): void
{
    global $issues, $warnings;
    echo "• " . str_pad($label, 45, '.') . ' ';
    if ($passed) {
        echo "[\033[32mOK\033[0m]\n";
    } elseif ($isWarning) {
        echo "[\033[33mWARN\033[0m]\n";
        echo "  └─ \033[33mWarning\033[0m: {$failMsg}\n";
        $warnings++;
    } else {
        echo "[\033[31mFAIL\033[0m]\n";
        echo "  └─ \033[31mError\033[0m: {$failMsg}\n";
        $issues++;
    }
}

// 1. PHP Version
$phpOk = version_compare(PHP_VERSION, '8.2.0', '>=');
check("PHP Version >= 8.2 (Current: " . PHP_VERSION . ")", $phpOk, "PHP 8.2 or higher is required.");

// 2. Required PHP Extensions
$extensions = [
    'pdo' => 'PDO abstraction layer',
    'pdo_mysql' => 'MySQL PDO database driver',
    'mbstring' => 'Multibyte string support',
    'gd' => 'GD image library (for QR codes & avatars)',
    'intl' => 'Internationalization extension',
    'dom' => 'DOM XML extension (for Dompdf)',
    'curl' => 'cURL HTTP client',
    'zip' => 'Zip archive extension',
];

foreach ($extensions as $ext => $desc) {
    check("PHP Extension [{$ext}]", extension_loaded($ext), "Missing {$ext} extension ({$desc}).");
}

// 3. Environment & Configuration
$envFile = dirname(__DIR__) . '/.env';
$hasEnv = file_exists($envFile);
check(".env configuration file present", $hasEnv, ".env file not found. Copy .env.example to .env.", true);

$isProd = Env::isProduction();
$isDebug = Env::isDebug();
$isDemo = filter_var(Env::get('ALLOW_DEMO_MODE', false), FILTER_VALIDATE_BOOLEAN);

check("Environment is Production", $isProd, "APP_ENV is set to '" . Env::get('APP_ENV', 'development') . "' instead of 'production'.", true);
check("Debug mode disabled in production", !$isDebug, "APP_DEBUG is enabled! Set APP_DEBUG=false for production.", true);
check("Demo mode disabled in production", !$isDemo, "ALLOW_DEMO_MODE is true! Set ALLOW_DEMO_MODE=false in production to prevent demo bypasses.", true);

$dbPass = (string)Env::get('DB_PASSWORD', '');
$isDefaultPass = ($dbPass === 'dairyflow123');
check("Custom database password set", !$isDefaultPass, "Default demo password 'dairyflow123' is still being used!", true);

// 4. Database Connectivity
$dbOk = false;
$tableCount = 0;
try {
    $db = Database::getInstance();
    $rows = $db->fetchAll("SHOW TABLES");
    $tableCount = count($rows);
    $dbOk = ($tableCount >= 10);
} catch (\Throwable $e) {
    $dbFailMsg = $e->getMessage();
}

check("Database connectivity", $dbOk, $dbFailMsg ?? "Only found {$tableCount} tables. Run `php scripts/migrate.php`.");

// 5. Writable Directories
$dirs = [
    dirname(__DIR__) . '/storage/logs' => 'Application error logs',
    dirname(__DIR__) . '/storage/backups' => 'Database automated backups',
    dirname(__DIR__) . '/storage/cache' => 'Application cache',
    dirname(__DIR__) . '/public/uploads' => 'Tenant uploaded QR codes & assets',
];

foreach ($dirs as $d => $desc) {
    $rel = str_replace(dirname(__DIR__) . '/', '', $d);
    check("Directory writable: {$rel}", is_dir($d) && is_writable($d), "{$rel} is not writable by the web server ({$desc}). Run `chmod -R 775 {$rel}`.");
}

// 6. Security & Upload Protection
$uploadsHtaccess = dirname(__DIR__) . '/public/uploads/.htaccess';
check("Uploads folder script execution blocked", file_exists($uploadsHtaccess), "Missing public/uploads/.htaccess security file.");

$storageHtaccess = dirname(__DIR__) . '/storage/.htaccess';
check("Storage folder direct access blocked", file_exists($storageHtaccess), "Missing storage/.htaccess security file.");

echo "\n--------------------------------------------------------\n";
echo "AUDIT SUMMARY: {$issues} Error(s), {$warnings} Warning(s)\n";

if ($issues === 0 && $warnings === 0) {
    echo "[\033[32mPERFECT\033[0m] Application is 100% production ready!\n";
    exit(0);
} elseif ($issues === 0) {
    echo "[\033[33mREADY WITH WARNINGS\033[0m] Review the warnings above before launching.\n";
    exit(0);
} else {
    echo "[\033[31mACTION REQUIRED\033[0m] Resolve the errors above before deploying to production.\n";
    exit(1);
}

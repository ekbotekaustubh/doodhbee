<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\Env;

echo "========================================================\n";
echo "   DOODHBEE SAAS - DATABASE MIGRATION UTILITY          \n";
echo "========================================================\n\n";

try {
    $db = Database::getInstance();
    $pdo = $db->getPdo();
    $dbName = Env::get('DB_DATABASE', 'dairyflow');
    echo "• Connected to database: [{$dbName}]\n";

    $existingTables = $db->fetchAll("SHOW TABLES");
    $hasTables = count($existingTables) > 0;

    $isFresh = in_array('--fresh', $argv, true);

    if ($hasTables && !$isFresh) {
        echo "• Database already has " . count($existingTables) . " tables.\n";
        echo "• Running schema verification & seed check...\n";
    } else {
        if ($isFresh) {
            echo "• [--fresh] specified: Recreating all database tables...\n";
        } else {
            echo "• Fresh installation detected: Creating database tables...\n";
        }

        $schemaFile = dirname(__DIR__) . '/database/schema.sql';
        if (!file_exists($schemaFile)) {
            throw new \RuntimeException("Schema file not found at: {$schemaFile}");
        }

        $sql = file_get_contents($schemaFile);
        $pdo->exec($sql);
        echo "• Schema applied successfully!\n";
    }

    // Ensure default system settings exist
    $defaultSettings = [
        'free_customer_limit' => '5',
        'paid_tier_unit_size' => '100',
        'paid_tier_unit_price' => '200.00',
        'currency_symbol' => '₹',
        'platform_name' => 'DoodhBee SaaS',
        'support_email' => 'support@doodhbee.com',
        'support_phone' => '+91 98000 11223'
    ];

    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = setting_value");
    foreach ($defaultSettings as $key => $val) {
        $stmt->execute([$key, $val]);
    }
    echo "• System settings verified.\n";

    $tables = $db->fetchAll("SHOW TABLES");
    echo "\nTotal tables in database: " . count($tables) . "\n";
    foreach ($tables as $t) {
        $name = array_values($t)[0];
        echo "  - {$name}\n";
    }

    echo "\n[\033[32mSUCCESS\033[0m] Database migration completed successfully!\n";
} catch (\Throwable $e) {
    echo "\n[\033[31mERROR\033[0m] Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

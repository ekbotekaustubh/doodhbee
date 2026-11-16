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

    // 1. Ensure whatsapp_messages table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        invoice_id INT NOT NULL,
        customer_id INT NOT NULL,
        recipient_phone VARCHAR(20) NOT NULL,
        recipient_name VARCHAR(150) NOT NULL,
        message_body TEXT NOT NULL,
        status ENUM('pending', 'processing', 'sent', 'failed', 'cancelled') DEFAULT 'pending',
        attempts TINYINT DEFAULT 0,
        max_attempts TINYINT DEFAULT 3,
        error_message TEXT NULL,
        provider VARCHAR(50) DEFAULT 'browser',
        provider_message_id VARCHAR(100) NULL,
        sent_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
        INDEX idx_tenant_status (tenant_id, status),
        INDEX idx_pending_queue (status, attempts, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "• Table whatsapp_messages verified.\n";

    // 2. Ensure tenant_settings WhatsApp columns exist
    $columns = $db->fetchAll("SHOW COLUMNS FROM tenant_settings LIKE 'whatsapp_%'");
    $colNames = array_map(fn($c) => $c['Field'], $columns);
    if (!in_array('whatsapp_provider', $colNames, true)) {
        $pdo->exec("ALTER TABLE tenant_settings ADD COLUMN whatsapp_provider VARCHAR(50) DEFAULT 'browser'");
    }
    if (!in_array('whatsapp_api_token', $colNames, true)) {
        $pdo->exec("ALTER TABLE tenant_settings ADD COLUMN whatsapp_api_token TEXT NULL");
    }
    if (!in_array('whatsapp_phone_number_id', $colNames, true)) {
        $pdo->exec("ALTER TABLE tenant_settings ADD COLUMN whatsapp_phone_number_id VARCHAR(100) NULL");
    }
    echo "• Tenant settings WhatsApp columns verified.\n";

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

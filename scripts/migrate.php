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
    $dbName = Env::get('DB_DATABASE', 'doodhbee');
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

    // 3. Ensure subscription_plans table exists & default plans are seeded
    $pdo->exec("CREATE TABLE IF NOT EXISTS subscription_plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) UNIQUE NOT NULL,
        name VARCHAR(100) NOT NULL,
        description VARCHAR(255) NULL,
        customer_limit INT NOT NULL DEFAULT 5,
        monthly_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        yearly_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        is_free TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_plans_active (is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $defaultPlans = [
        ['free', 'Free', 'Up to 5 active customers free forever', 5, 0.00, 0.00, 1, 1, 1],
        ['starter', 'Starter', 'Ideal for growing milk routes up to 100 customers', 100, 200.00, 2000.00, 0, 1, 2],
        ['growth', 'Growth', 'For established dairies up to 300 active customers', 300, 500.00, 5000.00, 0, 1, 3],
        ['pro', 'Pro', 'High-volume dairy operations up to 1,000 customers', 1000, 1200.00, 12000.00, 0, 1, 4],
    ];
    $planStmt = $pdo->prepare(
        "INSERT INTO subscription_plans (code, name, description, customer_limit, monthly_price, yearly_price, is_free, is_active, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE code = code"
    );
    foreach ($defaultPlans as $p) {
        $planStmt->execute($p);
    }
    echo "• Table subscription_plans verified.\n";

    // 3b. Ensure monthly/yearly & plan columns exist on tenant_subscriptions
    $subCols = array_map(fn($c) => $c['Field'], $db->fetchAll("SHOW COLUMNS FROM tenant_subscriptions"));
    if (!in_array('plan_id', $subCols, true)) {
        $pdo->exec("ALTER TABLE tenant_subscriptions ADD COLUMN plan_id INT NULL AFTER tenant_id");
    }
    if (!in_array('billing_cycle', $subCols, true)) {
        $pdo->exec("ALTER TABLE tenant_subscriptions ADD COLUMN billing_cycle ENUM('monthly', 'yearly') NULL DEFAULT NULL AFTER amount_paid");
    }
    if (!in_array('started_at', $subCols, true)) {
        $pdo->exec("ALTER TABLE tenant_subscriptions ADD COLUMN started_at DATE NULL AFTER billing_cycle");
    }

    // 3d. Ensure subscription_invoices table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS subscription_invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_number VARCHAR(50) UNIQUE NOT NULL,
        tenant_id INT NOT NULL,
        upgrade_request_id INT NULL,
        plan_id INT NULL,
        plan_name VARCHAR(100) NOT NULL,
        billing_cycle ENUM('monthly', 'yearly') NOT NULL DEFAULT 'monthly',
        customer_limit INT NOT NULL DEFAULT 100,
        action_type VARCHAR(30) NOT NULL DEFAULT 'subscribe',
        period_start DATE NOT NULL,
        period_end DATE NOT NULL,
        base_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        gst_percentage DECIMAL(5, 2) NOT NULL DEFAULT 18.00,
        cgst_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        sgst_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        gst_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        total_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        payment_method VARCHAR(50) NOT NULL DEFAULT 'upi',
        utr_number VARCHAR(100) NULL,
        seller_name VARCHAR(150) NOT NULL DEFAULT 'Shri Ram Open Source Technologies',
        seller_gstin VARCHAR(30) NOT NULL DEFAULT '27AARPE1865G1ZJ',
        sac_code VARCHAR(20) NOT NULL DEFAULT '998314',
        buyer_business_name VARCHAR(150) NOT NULL,
        buyer_owner_name VARCHAR(100) NOT NULL,
        buyer_phone VARCHAR(20) NOT NULL,
        buyer_email VARCHAR(100) NULL,
        email_status ENUM('pending', 'sent', 'skipped', 'failed') NOT NULL DEFAULT 'pending',
        email_error TEXT NULL,
        emailed_at DATETIME NULL,
        whatsapp_status ENUM('pending', 'sent', 'queued_link', 'failed') NOT NULL DEFAULT 'pending',
        whatsapp_url TEXT NULL,
        whatsapp_sent_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        INDEX idx_sub_inv_tenant (tenant_id, created_at),
        INDEX idx_sub_inv_req (upgrade_request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "• Table subscription_invoices verified.\n";

    // 3e. Ensure razorpay_orders table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS razorpay_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        plan_id INT NOT NULL,
        billing_cycle ENUM('monthly', 'yearly') NOT NULL DEFAULT 'monthly',
        action_type VARCHAR(30) NOT NULL DEFAULT 'subscribe',
        razorpay_order_id VARCHAR(100) UNIQUE NOT NULL,
        razorpay_payment_id VARCHAR(100) NULL,
        razorpay_signature VARCHAR(255) NULL,
        base_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        gst_percentage DECIMAL(5, 2) NOT NULL DEFAULT 18.00,
        gst_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        total_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        amount_paise INT NOT NULL DEFAULT 0,
        currency VARCHAR(10) NOT NULL DEFAULT 'INR',
        status ENUM('created', 'paid', 'failed') NOT NULL DEFAULT 'created',
        subscription_invoice_id INT NULL,
        failure_reason TEXT NULL,
        paid_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        INDEX idx_rzp_tenant_status (tenant_id, status),
        INDEX idx_rzp_payment_id (razorpay_payment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "• Table razorpay_orders verified.\n";

    // Backfill plan_id for existing tenant_subscriptions if NULL
    $starterPlanId = (int)$db->fetchValue("SELECT id FROM subscription_plans WHERE code = 'starter' LIMIT 1");
    $freePlanId = (int)$db->fetchValue("SELECT id FROM subscription_plans WHERE code = 'free' LIMIT 1");
    if ($freePlanId > 0) {
        $pdo->prepare("UPDATE tenant_subscriptions SET plan_id = ? WHERE plan_id IS NULL AND purchased_blocks = 0")->execute([$freePlanId]);
    }
    if ($starterPlanId > 0) {
        $pdo->prepare("UPDATE tenant_subscriptions SET plan_id = ? WHERE plan_id IS NULL AND purchased_blocks > 0")->execute([$starterPlanId]);
    }
    echo "• Tenant subscriptions plan columns verified.\n";

    // Ensure default system settings exist
    $defaultSettings = [
        'free_customer_limit' => '5',
        'paid_tier_unit_size' => '100',
        'paid_tier_unit_price' => '200.00',
        'paid_tier_monthly_price' => '200.00',
        'paid_tier_yearly_price' => '2000.00',
        'subscription_grace_days' => '7',
        'subscription_gst_enabled' => '1',
        'subscription_gst_percentage' => '18.00',
        'company_legal_name' => 'Shri Ram Open Source Technologies',
        'company_gstin' => '27AARPE1865G1ZJ',
        'company_sac_code' => '998314',
        'company_address' => 'Maharashtra, India',
        'razorpay_enabled' => '0',
        'razorpay_key_id' => '',
        'razorpay_key_secret' => '',
        'razorpay_webhook_secret' => '',
        'currency_symbol' => '₹',
        'platform_name' => 'DoodhBee SaaS',
        'support_email' => 'hello@doodhbee.com',
        'support_phone' => '+91 9096279526'
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

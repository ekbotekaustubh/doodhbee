-- DoodhBee Multi-Tenant SaaS Database Schema
-- Strict Types & Composite Multi-Tenant Indexing

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS api_tokens;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS invoices;
DROP TABLE IF EXISTS daily_delivery_items;
DROP TABLE IF EXISTS daily_deliveries;
DROP TABLE IF EXISTS customer_subscriptions;
DROP TABLE IF EXISTS customer_leaves;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS razorpay_orders;
DROP TABLE IF EXISTS subscription_invoices;
DROP TABLE IF EXISTS tenant_subscriptions;
DROP TABLE IF EXISTS subscription_plans;
DROP TABLE IF EXISTS tenant_additional_charges;
DROP TABLE IF EXISTS tenant_settings;
DROP TABLE IF EXISTS tenants;
DROP TABLE IF EXISTS super_admins;
DROP TABLE IF EXISTS system_settings;

SET FOREIGN_KEY_CHECKS = 1;

-- 1. Global Platform Settings (Managed by Super-Admin)
CREATE TABLE system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(50) UNIQUE NOT NULL,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Super Administrators
CREATE TABLE super_admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


INSERT INTO `super_admins` (`id`, `name`, `email`, `password_hash`, `created_at`) VALUES
(1, 'DoodhBee Platform Admin', 'hello@doodhbee.com', '$2y$10$u.8ehnRvccsqDsJ3ggtxpu1dlibTop.K74gkGkFKSpLWfJvfZLT1C', '2026-11-14 20:07:51');

-- 3. Tenants (Milkmen / Dairies)
CREATE TABLE tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_name VARCHAR(150) NOT NULL,
    owner_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active', 'suspended') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Milkman Business Settings (Taxes, Branding, Invoice Info)
CREATE TABLE tenant_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    tax_enabled TINYINT(1) DEFAULT 0,
    tax_label VARCHAR(50) DEFAULT 'GST',
    tax_percentage DECIMAL(5, 2) DEFAULT 0.00,
    tax_number VARCHAR(100) NULL,
    upi_id VARCHAR(100) NULL,
    upi_qr_path VARCHAR(255) NULL,
    invoice_footer_note TEXT NULL,
    whatsapp_provider VARCHAR(50) DEFAULT 'browser',
    whatsapp_api_token TEXT NULL,
    whatsapp_phone_number_id VARCHAR(100) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uq_tenant_setting (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Milkman Custom Additional Charges
CREATE TABLE tenant_additional_charges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    charge_name VARCHAR(100) NOT NULL,
    charge_type ENUM('flat_monthly', 'per_liter', 'per_delivery_day') DEFAULT 'flat_monthly',
    amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    is_taxable TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_tenant_charges (tenant_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Subscription Plans (Managed by Super-Admin)
CREATE TABLE subscription_plans (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6a. Tenant Subscriptions (Quota limits)
CREATE TABLE tenant_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    plan_id INT NULL,
    purchased_blocks INT DEFAULT 0,
    max_customers_allowed INT DEFAULT 5,
    amount_paid DECIMAL(10, 2) DEFAULT 0.00,
    billing_cycle ENUM('monthly', 'yearly') NULL DEFAULT NULL,
    started_at DATE NULL,
    valid_until DATE NULL,
    payment_status ENUM('active', 'expired', 'free') DEFAULT 'free',
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES subscription_plans(id) ON DELETE SET NULL,
    UNIQUE KEY uq_tenant_subscription_tenant (tenant_id),
    INDEX idx_tenant_sub (tenant_id, payment_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6b. SaaS Subscription GST Tax Invoices
CREATE TABLE subscription_invoices (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6d. Razorpay Payment Orders (Idempotent Order & Signature Verification Ledger)
CREATE TABLE razorpay_orders (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6b. Tenant Product Catalog (Approach A: Normalized Multi-Product Architecture)
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50) NOT NULL,
    unit VARCHAR(20) NOT NULL DEFAULT 'L',
    default_rate DECIMAL(10, 2) NOT NULL DEFAULT 60.00,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uq_tenant_product_code (tenant_id, code),
    INDEX idx_tenant_product (tenant_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6c. Tenant Delivery Routes (Independent Route Entity)
CREATE TABLE routes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uq_tenant_route_name (tenant_id, name),
    INDEX idx_tenant_route_active (tenant_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Customers
CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    route_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    address TEXT,
    milk_types VARCHAR(100) DEFAULT 'cow',
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE RESTRICT,
    INDEX idx_tenant_customer (tenant_id, status),
    INDEX idx_tenant_route_id (tenant_id, route_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7b. Customer Product Subscriptions (Approach A: Independent Quantities & Rates per Product)
CREATE TABLE customer_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    customer_id INT NOT NULL,
    product_id INT NOT NULL,
    default_quantity DECIMAL(6, 2) NOT NULL DEFAULT 1.00,
    custom_rate DECIMAL(10, 2) NULL,
    shift ENUM('morning', 'evening', 'both') DEFAULT 'both',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uq_customer_product_shift (customer_id, product_id, shift),
    INDEX idx_tenant_cust_sub (tenant_id, customer_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Customer Leaves / Vacations
CREATE TABLE customer_leaves (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    customer_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_tenant_customer_leaves (tenant_id, customer_id, start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Daily Delivery Tracking (Core Anti-Error Engine)
CREATE TABLE daily_deliveries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    customer_id INT NOT NULL,
    delivery_date DATE NOT NULL,
    shift ENUM('morning', 'evening') DEFAULT 'morning',
    quantity DECIMAL(6, 2) NOT NULL DEFAULT 0.00,
    status ENUM('delivered', 'skipped', 'leave') NOT NULL,
    notes VARCHAR(255) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    UNIQUE KEY uq_delivery (tenant_id, customer_id, delivery_date, shift),
    INDEX idx_delivery_date (tenant_id, delivery_date),
    INDEX idx_delivery_shift (tenant_id, delivery_date, shift)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9b. Daily Delivery Line Items (Approach A: Product-wise delivery tracking)
CREATE TABLE daily_delivery_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    delivery_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(6, 2) NOT NULL DEFAULT 0.00,
    unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    status ENUM('delivered', 'skipped', 'leave') NOT NULL DEFAULT 'delivered',
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (delivery_id) REFERENCES daily_deliveries(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uq_delivery_product (delivery_id, product_id),
    INDEX idx_delivery_items (tenant_id, delivery_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Invoices
CREATE TABLE invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    customer_id INT NOT NULL,
    billing_month TINYINT NOT NULL,
    billing_year SMALLINT NOT NULL,
    total_units DECIMAL(10, 2) NOT NULL,
    milk_subtotal DECIMAL(10, 2) NOT NULL,
    additional_charges_total DECIMAL(10, 2) DEFAULT 0.00,
    tax_amount DECIMAL(10, 2) DEFAULT 0.00,
    previous_balance DECIMAL(10, 2) DEFAULT 0.00,
    final_amount DECIMAL(10, 2) NOT NULL,
    payment_status ENUM('unpaid', 'paid', 'partial') DEFAULT 'unpaid',
    breakdown_json JSON NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    UNIQUE KEY uq_customer_billing_period (tenant_id, customer_id, billing_year, billing_month),
    INDEX idx_tenant_invoice_period (tenant_id, billing_year, billing_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Payments Received (Customer Ledger)
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    customer_id INT NOT NULL,
    invoice_id INT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method ENUM('cash', 'upi', 'bank_transfer', 'cheque', 'other') DEFAULT 'upi',
    reference_no VARCHAR(100) NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    INDEX idx_tenant_customer_payments (tenant_id, customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11b. Tenant Users & Delivery Staff
CREATE TABLE tenant_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    role ENUM('admin', 'delivery') NOT NULL DEFAULT 'delivery',
    assigned_route_id INT NULL,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100) NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_route_id) REFERENCES routes(id) ON DELETE SET NULL,
    UNIQUE KEY uq_tenant_user_phone (tenant_id, phone),
    INDEX idx_tenant_user_role (tenant_id, role, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. API Access Tokens (Mobile Apps & Headless REST API)
CREATE TABLE api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NULL,
    user_id INT NULL,
    admin_id INT NULL,
    token_hash VARCHAR(64) UNIQUE NOT NULL,
    token_type ENUM('tenant', 'admin') NOT NULL DEFAULT 'tenant',
    name VARCHAR(100) DEFAULT 'default',
    last_used_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES tenant_users(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES super_admins(id) ON DELETE CASCADE,
    INDEX idx_api_tokens (token_hash, token_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. WhatsApp Outbox & Audit Queue
CREATE TABLE IF NOT EXISTS whatsapp_messages (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;

final class SubscriptionInvoiceService
{
    private Database $db;
    private MailService $mailService;
    private WhatsAppService $whatsAppService;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->mailService = new MailService();
        $this->whatsAppService = new WhatsAppService($this->db);
    }

    public static function generateToken(int $invoiceId, int $tenantId): string
    {
        $secret = (string)Env::get('APP_KEY', 'doodhbee_saas_secure_bill_token_key_2026');
        return substr(hash_hmac('sha256', "doodhbee_sub_inv_{$invoiceId}_{$tenantId}", $secret), 0, 32);
    }

    public static function verifyToken(int $invoiceId, int $tenantId, string $token): bool
    {
        if ($token === '' || strlen($token) !== 32) {
            return false;
        }
        return hash_equals(self::generateToken($invoiceId, $tenantId), $token);
    }

    public static function getPublicPdfUrl(int $invoiceId, int $tenantId): string
    {
        $token = self::generateToken($invoiceId, $tenantId);
        $baseUrl = rtrim((string)Env::get('APP_URL', 'http://localhost/doodhbee'), '/');

        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'] ?? 'http';
        $host = $parsed['host'] ?? 'localhost';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $origin = "{$scheme}://{$host}{$port}";

        $path = url("/subscription-invoice/{$invoiceId}/pdf");
        return "{$origin}{$path}?token={$token}";
    }

    public function generateInvoice(int $tenantId, array $data): array
    {
        $tenant = $this->db->fetchOne("SELECT * FROM tenants WHERE id = ?", [$tenantId]);
        if (!$tenant) {
            throw new \InvalidArgumentException("Tenant #{$tenantId} not found for subscription invoice.");
        }

        $baseAmount = round((float)($data['base_amount'] ?? 0.0), 2);
        $gstPct = round((float)($data['gst_percentage'] ?? 18.0), 2);
        $gstAmount = round((float)($data['gst_amount'] ?? ($baseAmount * ($gstPct / 100))), 2);
        $cgstAmount = round($gstAmount / 2, 2);
        $sgstAmount = round($gstAmount - $cgstAmount, 2);
        $totalAmount = round((float)($data['total_amount'] ?? ($baseAmount + $gstAmount)), 2);

        $sellerName = (string)($data['seller_name'] ?? 'Shri Ram Open Source Technologies');
        $sellerGstin = (string)($data['seller_gstin'] ?? '27AARPE1865G1ZJ');
        $sacCode = (string)($data['sac_code'] ?? '998314');

        $year = date('Y');
        $nextSeq = (int)$this->db->fetchValue("SELECT COALESCE(MAX(id), 0) + 1 FROM subscription_invoices");
        $invoiceNumber = sprintf('DB-SUB-%s-%04d', $year, $nextSeq);

        $this->db->execute(
            "INSERT INTO subscription_invoices (
                invoice_number, tenant_id, upgrade_request_id, plan_id, plan_name,
                billing_cycle, customer_limit, action_type, period_start, period_end,
                base_amount, gst_percentage, cgst_amount, sgst_amount, gst_amount, total_amount,
                payment_method, utr_number, seller_name, seller_gstin, sac_code,
                buyer_business_name, buyer_owner_name, buyer_phone, buyer_email
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $invoiceNumber,
                $tenantId,
                $data['upgrade_request_id'] ?? null,
                $data['plan_id'] ?? null,
                (string)($data['plan_name'] ?? 'Paid Plan'),
                (string)($data['billing_cycle'] ?? 'monthly'),
                (int)($data['customer_limit'] ?? 100),
                (string)($data['action_type'] ?? 'subscribe'),
                (string)($data['period_start'] ?? date('Y-m-d')),
                (string)($data['period_end'] ?? date('Y-m-d', strtotime('+1 month'))),
                $baseAmount,
                $gstPct,
                $cgstAmount,
                $sgstAmount,
                $gstAmount,
                $totalAmount,
                (string)($data['payment_method'] ?? 'upi'),
                $data['utr_number'] ?? null,
                $sellerName,
                $sellerGstin,
                $sacCode,
                (string)$tenant['business_name'],
                (string)$tenant['owner_name'],
                (string)$tenant['phone'],
                $tenant['email'] ?: null,
            ]
        );

        $invoiceId = (int)$this->db->getPdo()->lastInsertId();
        return $this->dispatchNotifications($invoiceId);
    }

    public function dispatchNotifications(int $invoiceId): array
    {
        $invoice = $this->getInvoiceById($invoiceId);
        if (!$invoice) {
            throw new \InvalidArgumentException("Subscription invoice #{$invoiceId} not found.");
        }

        $tenantId = (int)$invoice['tenant_id'];
        $pdfUrl = self::getPublicPdfUrl($invoiceId, $tenantId);

        // 1. WhatsApp notification / direct link
        $waRes = $this->whatsAppService->sendSubscriptionInvoiceNotification($invoice, $pdfUrl);
        $this->db->execute(
            "UPDATE subscription_invoices
             SET whatsapp_status = ?, whatsapp_url = ?, whatsapp_sent_at = ?
             WHERE id = ?",
            [
                $waRes['status'],
                $waRes['whatsapp_url'],
                $waRes['status'] === 'sent' ? date('Y-m-d H:i:s') : null,
                $invoiceId,
            ]
        );

        // 2. Email GST Tax Invoice PDF
        $buyerEmail = trim((string)($invoice['buyer_email'] ?? ''));
        if ($buyerEmail !== '') {
            $attachments = [];
            try {
                if (!(defined('DOODHBEE_TEST_RUNNER') && DOODHBEE_TEST_RUNNER === true)) {
                    $pdfBytes = $this->renderInvoicePdfBytes($invoice);
                    $attachments[] = [
                        'filename' => "{$invoice['invoice_number']}.pdf",
                        'content' => $pdfBytes,
                        'mime' => 'application/pdf',
                    ];
                }
            } catch (\Throwable $e) {
                // Proceed sending HTML email even if PDF render fails
            }

            $htmlBody = $this->buildEmailHtml($invoice, $pdfUrl);
            $subject = "Tax Invoice {$invoice['invoice_number']} - {$invoice['seller_name']}";
            $mailRes = $this->mailService->send(
                $buyerEmail,
                (string)$invoice['buyer_business_name'],
                $subject,
                $htmlBody,
                $attachments
            );

            $this->db->execute(
                "UPDATE subscription_invoices
                 SET email_status = ?, email_error = ?, emailed_at = ?
                 WHERE id = ?",
                [
                    $mailRes['status'],
                    $mailRes['error'] ?? null,
                    $mailRes['status'] === 'sent' ? date('Y-m-d H:i:s') : null,
                    $invoiceId,
                ]
            );
        } else {
            $this->db->execute(
                "UPDATE subscription_invoices SET email_status = 'skipped', email_error = 'No email address on tenant account.' WHERE id = ?",
                [$invoiceId]
            );
        }

        return $this->getInvoiceById($invoiceId) ?? $invoice;
    }

    public function getInvoiceById(int $invoiceId, ?int $tenantId = null): ?array
    {
        $sql = "SELECT * FROM subscription_invoices WHERE id = ?";
        $params = [$invoiceId];
        if ($tenantId !== null) {
            $sql .= " AND tenant_id = ?";
            $params[] = $tenantId;
        }
        return $this->db->fetchOne($sql, $params);
    }

    private function ensureSchema(): void
    {
        $this->db->getPdo()->exec(
            "CREATE TABLE IF NOT EXISTS subscription_invoices (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function getTenantInvoices(int $tenantId, int $limit = 20): array
    {
        $limit = max(1, min(500, $limit));
        $sql = "SELECT * FROM subscription_invoices WHERE tenant_id = ? ORDER BY id DESC LIMIT {$limit}";
        try {
            return $this->db->fetchAll($sql, [$tenantId]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '42S02') {
                $this->ensureSchema();
                return $this->db->fetchAll($sql, [$tenantId]);
            }
            throw $e;
        }
    }

    public function getAllInvoices(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        $sql = "SELECT * FROM subscription_invoices ORDER BY id DESC LIMIT {$limit}";
        try {
            return $this->db->fetchAll($sql);
        } catch (\PDOException $e) {
            if ($e->getCode() === '42S02') {
                $this->ensureSchema();
                return $this->db->fetchAll($sql);
            }
            throw $e;
        }
    }

    public function renderInvoiceHtml(array $invoice, bool $isPdf = false): string
    {
        $viewFile = dirname(__DIR__, 2) . '/views/subscription_invoices/tax_invoice.php';
        $sysRows = $this->db->fetchAll("SELECT setting_key, setting_value FROM system_settings");
        $sys = [];
        foreach ($sysRows as $r) {
            $sys[$r['setting_key']] = $r['setting_value'];
        }
        $companyAddress = (string)($sys['company_address'] ?? 'Maharashtra, India');
        $supportEmail = (string)($sys['support_email'] ?? 'hello@doodhbee.com');
        $supportPhone = (string)($sys['support_phone'] ?? '');

        ob_start();
        require $viewFile;
        return (string)ob_get_clean();
    }

    public function renderInvoicePdfBytes(array $invoice): string
    {
        $html = $this->renderInvoiceHtml($invoice, true);
        return PdfExportService::renderPdfBytes($html, 'A4', 'portrait');
    }

    private function buildEmailHtml(array $invoice, string $pdfUrl): string
    {
        $seller = htmlspecialchars((string)$invoice['seller_name'], ENT_QUOTES, 'UTF-8');
        $gstin = htmlspecialchars((string)$invoice['seller_gstin'], ENT_QUOTES, 'UTF-8');
        $buyer = htmlspecialchars((string)$invoice['buyer_business_name'], ENT_QUOTES, 'UTF-8');
        $invNo = htmlspecialchars((string)$invoice['invoice_number'], ENT_QUOTES, 'UTF-8');
        $plan = htmlspecialchars((string)$invoice['plan_name'], ENT_QUOTES, 'UTF-8');
        $cycle = htmlspecialchars(ucfirst((string)$invoice['billing_cycle']), ENT_QUOTES, 'UTF-8');
        $base = number_format((float)$invoice['base_amount'], 2);
        $gstPct = number_format((float)$invoice['gst_percentage'], 0);
        $gst = number_format((float)$invoice['gst_amount'], 2);
        $total = number_format((float)$invoice['total_amount'], 2);
        $start = htmlspecialchars((string)$invoice['period_start'], ENT_QUOTES, 'UTF-8');
        $end = htmlspecialchars((string)$invoice['period_end'], ENT_QUOTES, 'UTF-8');
        $safePdfUrl = htmlspecialchars($pdfUrl, ENT_QUOTES, 'UTF-8');
        $year = date('Y');

        return <<<HTML
<div style="font-family: Arial, sans-serif; color: #0f172a; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden;">
  <div style="background: #0f172a; color: #ffffff; padding: 18px 24px;">
    <h2 style="margin: 0; font-size: 18px;">{$seller}</h2>
    <p style="margin: 4px 0 0; font-size: 12px; color: #cbd5e1;">GSTIN: {$gstin} • Tax Invoice {$invNo}</p>
  </div>
  <div style="padding: 20px 24px; font-size: 13px; line-height: 1.5;">
    <p>Namaste <strong>{$buyer}</strong>,</p>
    <p>Thank you for subscribing to <strong>DoodhBee SaaS</strong>. Your GST Tax Invoice is attached as a PDF and summarized below:</p>
    <table style="width: 100%; border-collapse: collapse; margin: 14px 0; font-size: 12px;">
      <tr><td style="padding: 6px; border-bottom: 1px solid #e2e8f0;">Plan</td><td style="padding: 6px; border-bottom: 1px solid #e2e8f0; text-align: right;"><strong>{$plan} ({$cycle})</strong></td></tr>
      <tr><td style="padding: 6px; border-bottom: 1px solid #e2e8f0;">Validity Period</td><td style="padding: 6px; border-bottom: 1px solid #e2e8f0; text-align: right;">{$start} to {$end}</td></tr>
      <tr><td style="padding: 6px; border-bottom: 1px solid #e2e8f0;">Base Amount</td><td style="padding: 6px; border-bottom: 1px solid #e2e8f0; text-align: right;">₹{$base}</td></tr>
      <tr><td style="padding: 6px; border-bottom: 1px solid #e2e8f0;">GST ({$gstPct}% Exclusive)</td><td style="padding: 6px; border-bottom: 1px solid #e2e8f0; text-align: right;">₹{$gst}</td></tr>
      <tr style="background: #f8fafc;"><td style="padding: 8px 6px; font-weight: bold;">Total Paid</td><td style="padding: 8px 6px; text-align: right; font-weight: bold; font-size: 14px;">₹{$total}</td></tr>
    </table>
    <p><a href="{$safePdfUrl}" style="display: inline-block; background: #2563eb; color: #ffffff; text-decoration: none; padding: 10px 16px; border-radius: 6px; font-weight: bold;">Download Tax Invoice PDF</a></p>
  </div>
  <div style="background: #f8fafc; padding: 12px 24px; font-size: 11px; color: #64748b; border-top: 1px solid #e2e8f0; text-align: center;">
    © {$year} {$seller}. All rights reserved.
  </div>
</div>
HTML;
    }
}

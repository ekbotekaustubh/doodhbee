<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Services\BillingService;
use App\Services\WhatsAppService;

final class InvoiceApiController extends ApiBaseController
{
    private BillingService $billingService;
    private WhatsAppService $whatsAppService;

    public function __construct()
    {
        parent::__construct();
        $this->billingService = new BillingService();
        $this->whatsAppService = new WhatsAppService($this->db);
    }

    public function index(Request $request): void
    {
        Auth::requireApiTenantAdmin();
        $tenantId = $this->tenantId();
        $month = (int)$request->get('month', date('n'));
        $year = (int)$request->get('year', date('Y'));
        $status = trim((string)$request->get('status', ''));
        $customerId = (int)$request->get('customer_id', 0);

        $sql = "SELECT i.*, c.name as customer_name, c.phone as customer_phone, r.name as route_name
                FROM invoices i
                JOIN customers c ON c.id = i.customer_id
                LEFT JOIN routes r ON r.id = c.route_id
                WHERE i.tenant_id = ?";
        $params = [$tenantId];

        if ($month > 0) {
            $sql .= " AND i.billing_month = ?";
            $params[] = $month;
        }

        if ($year > 0) {
            $sql .= " AND i.billing_year = ?";
            $params[] = $year;
        }

        if (!empty($status)) {
            $sql .= " AND i.payment_status = ?";
            $params[] = $status;
        }

        if ($customerId > 0) {
            $sql .= " AND i.customer_id = ?";
            $params[] = $customerId;
        }

        $sql .= " ORDER BY i.billing_year DESC, i.billing_month DESC, r.name ASC, c.name ASC";
        $invoices = $this->db->fetchAll($sql, $params);

        foreach ($invoices as &$inv) {
            $inv['total_units'] = (float)$inv['total_units'];
            $inv['milk_subtotal'] = (float)$inv['milk_subtotal'];
            $inv['additional_charges_total'] = (float)$inv['additional_charges_total'];
            $inv['tax_amount'] = (float)$inv['tax_amount'];
            $inv['previous_balance'] = (float)$inv['previous_balance'];
            $inv['final_amount'] = (float)$inv['final_amount'];
            if (!empty($inv['breakdown_json'])) {
                $inv['breakdown'] = json_decode((string)$inv['breakdown_json'], true);
            }
            unset($inv['breakdown_json']);
        }

        $this->jsonSuccess($invoices, 'Invoices retrieved', 200, [
            'total_count' => count($invoices),
            'month' => $month,
            'year' => $year,
        ]);
    }

    public function show(Request $request, array $params): void
    {
        Auth::requireApiTenant();
        $tenantId = $this->tenantId();
        $id = (int)($params['id'] ?? 0);

        $invoice = $this->db->fetchOne(
            "SELECT i.*, c.name as customer_name, c.phone as customer_phone, c.address as customer_address, r.name as route_name
             FROM invoices i
             JOIN customers c ON c.id = i.customer_id
             LEFT JOIN routes r ON r.id = c.route_id
             WHERE i.id = ? AND i.tenant_id = ?",
            [$id, $tenantId]
        );

        if (!$invoice) {
            $this->jsonError('Invoice not found.', 404, 'NOT_FOUND');
        }

        $payments = $this->db->fetchAll(
            "SELECT * FROM payments WHERE invoice_id = ? AND tenant_id = ? ORDER BY payment_date ASC",
            [$id, $tenantId]
        );

        foreach ($payments as &$p) {
            $p['amount'] = (float)$p['amount'];
        }
        unset($p);

        $invoice['total_units'] = (float)$invoice['total_units'];
        $invoice['milk_subtotal'] = (float)$invoice['milk_subtotal'];
        $invoice['additional_charges_total'] = (float)$invoice['additional_charges_total'];
        $invoice['tax_amount'] = (float)$invoice['tax_amount'];
        $invoice['previous_balance'] = (float)$invoice['previous_balance'];
        $invoice['final_amount'] = (float)$invoice['final_amount'];

        $paidAmount = array_sum(array_column($payments, 'amount'));
        $balanceRemaining = max(0.0, round(((float)$invoice['final_amount']) - $paidAmount, 2));

        $breakdown = !empty($invoice['breakdown_json']) ? json_decode((string)$invoice['breakdown_json'], true) : null;
        unset($invoice['breakdown_json']);

        $settings = $this->db->fetchOne(
            "SELECT tax_enabled, tax_label, tax_percentage, tax_number, upi_id, invoice_footer_note FROM tenant_settings WHERE tenant_id = ?",
            [$tenantId]
        );

        $this->jsonSuccess([
            'invoice' => $invoice,
            'breakdown' => $breakdown,
            'payments' => $payments,
            'summary' => [
                'final_amount' => (float)$invoice['final_amount'],
                'paid_amount' => round($paidAmount, 2),
                'balance_remaining' => $balanceRemaining,
                'status' => $invoice['payment_status'],
            ],
            'settings' => $settings,
        ]);
    }

    public function generateBatch(Request $request): void
    {
        Auth::requireApiTenantAdmin();
        $tenantId = $this->tenantId();
        $month = (int)$request->input('month', date('n'));
        $year = (int)$request->input('year', date('Y'));

        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2050) {
            $this->jsonError('Invalid month or year.', 422, 'VALIDATION_ERROR');
        }

        $results = $this->billingService->generateBatchInvoices($tenantId, $year, $month);

        $this->jsonSuccess($results, "Batch generation completed: {$results['generated_count']} invoice(s) processed.");
    }

    public function generateSingle(Request $request): void
    {
        Auth::requireApiTenantAdmin();
        $tenantId = $this->tenantId();
        $customerId = (int)$request->input('customer_id', 0);
        $month = (int)$request->input('month', date('n'));
        $year = (int)$request->input('year', date('Y'));

        $errors = $this->validate([
            'customer_id' => $customerId,
            'month' => $month,
            'year' => $year,
        ], [
            'customer_id' => 'required|numeric',
            'month' => 'required|numeric',
            'year' => 'required|numeric',
        ]);

        if (!empty($errors)) {
            $this->jsonError('Validation failed', 422, 'VALIDATION_ERROR', $errors);
        }

        $customer = $this->db->fetchOne("SELECT id, name FROM customers WHERE id = ? AND tenant_id = ?", [$customerId, $tenantId]);
        if (!$customer) {
            $this->jsonError('Customer not found.', 404, 'NOT_FOUND');
        }

        $invoice = $this->billingService->generateOrUpdateInvoice($tenantId, $customerId, $year, $month);
        if (isset($invoice['invoice_id'])) {
            $invoice['id'] = (int)$invoice['invoice_id'];
        }

        $this->jsonSuccess($invoice, "Invoice generated successfully for {$customer['name']}.");
    }

    public function share(Request $request, array $params): void
    {
        Auth::requireApiTenant();
        $tenantId = $this->tenantId();
        $id = (int)($params['id'] ?? 0);

        $payload = $this->whatsAppService->getInvoiceSharePayload($tenantId, $id);
        if (!$payload) {
            $this->jsonError('Invoice not found.', 404, 'NOT_FOUND');
        }

        $this->jsonSuccess($payload);
    }

    public function queueWhatsApp(Request $request): void
    {
        Auth::requireApiTenantAdmin();
        $tenantId = $this->tenantId();
        $invoiceIds = $request->post('invoice_ids');

        if (empty($invoiceIds) || !is_array($invoiceIds)) {
            // Also check raw json
            $json = $request->json();
            $invoiceIds = $json['invoice_ids'] ?? [];
        }

        if (empty($invoiceIds) || !is_array($invoiceIds)) {
            $this->jsonError('No invoices selected.', 422, 'VALIDATION_FAILED');
        }

        $cleanedIds = array_map('intval', $invoiceIds);
        $result = $this->whatsAppService->enqueueInvoices($tenantId, $cleanedIds);

        $this->jsonSuccess($result, "Successfully enqueued {$result['enqueued_count']} invoices for WhatsApp delivery.");
    }

    public function queueStats(Request $request): void
    {
        Auth::requireApiTenantAdmin();
        $tenantId = $this->tenantId();
        $stats = $this->whatsAppService->getQueueStats($tenantId);
        $this->jsonSuccess($stats);
    }

    public function retryWhatsApp(Request $request, array $params): void
    {
        Auth::requireApiTenantAdmin();
        $tenantId = $this->tenantId();
        $id = (int)($params['id'] ?? 0);

        $success = $this->whatsAppService->retryMessage($id, $tenantId);
        if ($success) {
            $this->jsonSuccess(null, 'Message re-queued for delivery.');
        } else {
            $this->jsonError('Message not found or already processed.', 404, 'NOT_FOUND');
        }
    }

    public function publicShow(Request $request, array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $token = (string)$request->get('token', '');

        $invoice = $this->db->fetchOne(
            "SELECT i.*, c.name as customer_name, c.phone as customer_phone, c.address as customer_address, r.name as route_name
             FROM invoices i
             JOIN customers c ON c.id = i.customer_id
             JOIN routes r ON r.id = c.route_id
             WHERE i.id = ?",
            [$id]
        );

        if (!$invoice) {
            $this->jsonError('Invoice not found.', 404, 'NOT_FOUND');
        }

        if (!WhatsAppService::verifyInvoiceToken((int)$invoice['id'], (int)$invoice['tenant_id'], $token)) {
            $this->jsonError('Invalid or expired bill token.', 403, 'FORBIDDEN');
        }

        $tenantId = (int)$invoice['tenant_id'];
        $tenant = $this->db->fetchOne("SELECT * FROM tenants WHERE id = ?", [$tenantId]) ?? [];
        $settings = $this->db->fetchOne("SELECT * FROM tenant_settings WHERE tenant_id = ?", [$tenantId]) ?? [];
        $breakdown = !empty($invoice['breakdown_json']) ? json_decode((string)$invoice['breakdown_json'], true) : [];

        $this->jsonSuccess([
            'invoice' => $invoice,
            'tenant' => $tenant,
            'settings' => $settings,
            'breakdown' => $breakdown,
        ]);
    }
}

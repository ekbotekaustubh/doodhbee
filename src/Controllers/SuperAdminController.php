<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiClient;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

final class SuperAdminController
{
    public function index(Request $request): void
    {
        // 1. Fetch tenants roster via REST API
        $tenantsRes = ApiClient::get('/api/admin/tenants');
        $tenants = $tenantsRes['data'] ?? [];

        // 2. Fetch global system settings via REST API
        $settingsRes = ApiClient::get('/api/admin/settings');
        $systemSettings = $settingsRes['data'] ?? [];

        // 3. Fetch aggregated stats via REST API
        $statsRes = ApiClient::get('/api/admin/stats');
        $stats = $statsRes['data'] ?? [];

        // 4. Fetch subscription upgrade requests via REST API
        $subReqRes = ApiClient::get('/api/admin/subscription-requests');
        $upgradeRequests = $subReqRes['data'] ?? [];

        // 5. Fetch subscription plans via REST API
        $plansRes = ApiClient::get('/api/admin/plans');
        $plans = $plansRes['data'] ?? [];

        $subInvService = new \App\Services\SubscriptionInvoiceService();
        $subscriptionInvoices = $subInvService->getAllInvoices(30);

        $totalTenants = $stats['tenants']['total'] ?? $stats['total_tenants'] ?? count($tenants);
        $totalActiveCustomers = $stats['customers']['active'] ?? $stats['total_customers'] ?? 0;
        $platformRevenue = (float)($stats['total_platform_revenue'] ?? 0.0);

        View::render('superadmin/index', [
            'admin' => Auth::admin(),
            'tenants' => $tenants,
            'systemSettings' => $systemSettings,
            'upgradeRequests' => $upgradeRequests,
            'plans' => $plans,
            'subscriptionInvoices' => $subscriptionInvoices,
            'totalTenants' => $totalTenants,
            'totalActiveCustomers' => $totalActiveCustomers,
            'platformRevenue' => $platformRevenue,
            'success' => Auth::flash('success'),
            'error' => Auth::flash('error'),
        ], 'admin');
    }

    public function updateSettings(Request $request): void
    {
        $monthlyPrice = (float)$request->post('paid_tier_monthly_price', $request->post('paid_tier_unit_price', 200.0));
        $payload = [
            'free_customer_limit' => (int)$request->post('free_customer_limit', 5),
            'paid_tier_unit_size' => (int)$request->post('paid_tier_unit_size', 100),
            'paid_tier_unit_price' => $monthlyPrice,
            'paid_tier_monthly_price' => $monthlyPrice,
            'paid_tier_yearly_price' => (float)$request->post('paid_tier_yearly_price', 2000.0),
            'subscription_grace_days' => max(0, (int)$request->post('subscription_grace_days', 7)),
            'subscription_gst_enabled' => $request->post('subscription_gst_enabled') ? 1 : 0,
            'subscription_gst_percentage' => max(0.0, (float)$request->post('subscription_gst_percentage', 18.0)),
            'company_legal_name' => trim((string)$request->post('company_legal_name', 'Shri Ram Open Source Technologies')),
            'company_gstin' => trim((string)$request->post('company_gstin', '27AARPE1865G1ZJ')),
            'company_sac_code' => trim((string)$request->post('company_sac_code', '998314')),
            'company_address' => trim((string)$request->post('company_address', 'Maharashtra, India')),
            'currency_symbol' => trim((string)$request->post('currency_symbol', '₹')),
            'platform_name' => trim((string)$request->post('platform_name', 'DoodhBee SaaS')),
            'payment_upi_id' => trim((string)$request->post('payment_upi_id', '')),
            'payment_account_name' => trim((string)$request->post('payment_account_name', '')),
            'payment_bank_name' => trim((string)$request->post('payment_bank_name', '')),
            'payment_account_number' => trim((string)$request->post('payment_account_number', '')),
            'payment_ifsc_code' => trim((string)$request->post('payment_ifsc_code', '')),
            'razorpay_enabled' => $request->post('razorpay_enabled') ? 1 : 0,
            'razorpay_key_id' => trim((string)$request->post('razorpay_key_id', '')),
        ];

        $rzpSecret = trim((string)$request->post('razorpay_key_secret', ''));
        if ($rzpSecret !== '') {
            $payload['razorpay_key_secret'] = $rzpSecret;
        }
        $whSecret = trim((string)$request->post('razorpay_webhook_secret', ''));
        if ($whSecret !== '') {
            $payload['razorpay_webhook_secret'] = $whSecret;
        }

        // Update settings via REST API
        $res = ApiClient::put('/api/admin/settings', $payload);

        if ($res['success']) {
            Auth::flash('success', 'Global SaaS tier parameters updated successfully.');
        } else {
            Auth::flash('error', $res['error'] ?? 'Failed to update system settings.');
        }

        Response::redirect(url('/admin'));
    }

    public function toggleTenantStatus(Request $request, array $params): void
    {
        $tenantId = (int)($params['id'] ?? 0);

        // Toggle tenant status via REST API
        $res = ApiClient::post("/api/admin/tenants/{$tenantId}/toggle-status");

        if ($res['success']) {
            $newStatus = $res['data']['status'] ?? 'updated';
            Auth::flash('success', "Tenant status updated to {$newStatus}.");
        } else {
            Auth::flash('error', $res['error'] ?? 'Failed to update tenant status.');
        }

        Response::redirect(url('/admin'));
    }

    public function adjustQuota(Request $request, array $params): void
    {
        $tenantId = (int)($params['id'] ?? 0);
        $planId = (int)$request->post('plan_id', 0);
        $blocks = (int)$request->post('purchased_blocks', 0);
        $billingCycle = trim((string)$request->post('billing_cycle', 'monthly')) ?: 'monthly';

        $payload = $planId > 0
            ? ['plan_id' => $planId, 'billing_cycle' => $billingCycle]
            : ['purchased_blocks' => $blocks, 'billing_cycle' => $billingCycle];

        $res = ApiClient::post("/api/admin/tenants/{$tenantId}/quota", $payload);

        if ($res['success']) {
            Auth::flash('success', $res['message'] ?? "Subscription plan updated for tenant #{$tenantId}.");
        } else {
            Auth::flash('error', $res['error'] ?? 'Failed to adjust customer quota.');
        }

        Response::redirect(url('/admin'));
    }

    public function savePlan(Request $request, array $params = []): void
    {
        $planId = isset($params['id']) ? (int)$params['id'] : (int)$request->post('id', 0);
        $payload = [
            'code' => trim((string)$request->post('code', '')),
            'name' => trim((string)$request->post('name', '')),
            'description' => trim((string)$request->post('description', '')),
            'customer_limit' => (int)$request->post('customer_limit', 100),
            'monthly_price' => (float)$request->post('monthly_price', 0.0),
            'yearly_price' => (float)$request->post('yearly_price', 0.0),
            'sort_order' => (int)$request->post('sort_order', 10),
        ];

        $endpoint = $planId > 0 ? "/api/admin/plans/{$planId}" : '/api/admin/plans';
        $res = ApiClient::post($endpoint, $payload);

        if ($res['success']) {
            Auth::flash('success', $res['message'] ?? 'Subscription plan saved.');
        } else {
            Auth::flash('error', $res['error'] ?? 'Failed to save subscription plan.');
        }

        Response::redirect(url('/admin#plans-section'));
    }

    public function togglePlan(Request $request, array $params): void
    {
        $planId = (int)($params['id'] ?? 0);
        $res = ApiClient::post("/api/admin/plans/{$planId}/toggle");
        if ($res['success']) {
            Auth::flash('success', $res['message'] ?? 'Plan status updated.');
        } else {
            Auth::flash('error', $res['error'] ?? 'Failed to toggle plan.');
        }
        Response::redirect(url('/admin#plans-section'));
    }

    public function approveUpgradeRequest(Request $request, array $params): void
    {
        $requestId = (int)($params['id'] ?? 0);
        $adminNotes = trim((string)$request->post('admin_notes', 'Verified payment in bank/UPI account'));

        $res = ApiClient::post("/api/admin/subscription-requests/{$requestId}/approve", [
            'admin_notes' => $adminNotes,
        ]);

        if ($res['success']) {
            $invNo = $res['data']['invoice']['invoice_number'] ?? null;
            $msg = $res['message'] ?? 'Subscription upgrade approved and quota added successfully.';
            if ($invNo) {
                $msg .= " GST Tax Invoice {$invNo} generated and dispatched.";
            }
            Auth::flash('success', $msg);
        } else {
            Auth::flash('error', $res['error'] ?? 'Failed to approve upgrade request.');
        }

        Response::redirect(url('/admin#subscription-requests'));
    }

    public function rejectUpgradeRequest(Request $request, array $params): void
    {
        $requestId = (int)($params['id'] ?? 0);
        $reason = trim((string)$request->post('reason', 'Payment could not be verified in bank/UPI account'));

        $res = ApiClient::post("/api/admin/subscription-requests/{$requestId}/reject", [
            'reason' => $reason,
        ]);

        if ($res['success']) {
            Auth::flash('success', 'Subscription upgrade request rejected.');
        } else {
            Auth::flash('error', $res['error'] ?? 'Failed to reject upgrade request.');
        }

        Response::redirect(url('/admin#subscription-requests'));
    }

    public function subscriptionInvoicePdf(Request $request, array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $invService = new \App\Services\SubscriptionInvoiceService();
        $invoice = $invService->getInvoiceById($id);
        if (!$invoice) {
            Auth::flash('error', 'Subscription tax invoice not found.');
            Response::redirect(url('/admin'));
            return;
        }
        $html = $invService->renderInvoiceHtml($invoice, true);
        \App\Services\PdfExportService::streamPdf($html, "{$invoice['invoice_number']}.pdf", 'A4', 'portrait');
    }
}

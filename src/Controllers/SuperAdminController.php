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

        $totalTenants = $stats['total_tenants'] ?? count($tenants);
        $totalActiveCustomers = $stats['total_customers'] ?? 0;
        $platformRevenue = (float)($stats['total_platform_revenue'] ?? 0.0);

        View::render('superadmin/index', [
            'admin' => Auth::admin(),
            'tenants' => $tenants,
            'systemSettings' => $systemSettings,
            'upgradeRequests' => $upgradeRequests,
            'totalTenants' => $totalTenants,
            'totalActiveCustomers' => $totalActiveCustomers,
            'platformRevenue' => $platformRevenue,
            'success' => Auth::flash('success'),
            'error' => Auth::flash('error'),
        ], 'admin');
    }

    public function updateSettings(Request $request): void
    {
        $payload = [
            'free_customer_limit' => (int)$request->post('free_customer_limit', 5),
            'paid_tier_unit_size' => (int)$request->post('paid_tier_unit_size', 100),
            'paid_tier_unit_price' => (float)$request->post('paid_tier_unit_price', 200.0),
            'currency_symbol' => trim((string)$request->post('currency_symbol', '₹')),
            'platform_name' => trim((string)$request->post('platform_name', 'DoodhBee SaaS')),
            'payment_upi_id' => trim((string)$request->post('payment_upi_id', '')),
            'payment_account_name' => trim((string)$request->post('payment_account_name', '')),
            'payment_bank_name' => trim((string)$request->post('payment_bank_name', '')),
            'payment_account_number' => trim((string)$request->post('payment_account_number', '')),
            'payment_ifsc_code' => trim((string)$request->post('payment_ifsc_code', '')),
        ];

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
        $blocks = (int)$request->post('purchased_blocks', 0);

        // Adjust quota via REST API
        $res = ApiClient::post("/api/admin/tenants/{$tenantId}/quota", [
            'purchased_blocks' => $blocks,
        ]);

        if ($res['success']) {
            Auth::flash('success', "Customer capacity quota override applied for tenant #{$tenantId}.");
        } else {
            Auth::flash('error', $res['error'] ?? 'Failed to adjust customer quota.');
        }

        Response::redirect(url('/admin'));
    }

    public function approveUpgradeRequest(Request $request, array $params): void
    {
        $requestId = (int)($params['id'] ?? 0);
        $adminNotes = trim((string)$request->post('admin_notes', 'Verified payment in bank/UPI account'));

        $res = ApiClient::post("/api/admin/subscription-requests/{$requestId}/approve", [
            'admin_notes' => $adminNotes,
        ]);

        if ($res['success']) {
            Auth::flash('success', $res['message'] ?? 'Subscription upgrade approved and quota added successfully.');
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
}

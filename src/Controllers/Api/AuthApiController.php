<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Services\ProductService;
use App\Services\QuotaService;
use App\Services\RouteService;

final class AuthApiController extends ApiBaseController
{
    private QuotaService $quotaService;
    private ProductService $productService;
    private RouteService $routeService;

    public function __construct()
    {
        parent::__construct();
        $this->quotaService = new QuotaService();
        $this->productService = new ProductService();
        $this->routeService = new RouteService();
    }

    public function login(Request $request): void
    {
        $login = trim((string)$request->input('login', ''));
        $password = (string)$request->input('password', '');
        $deviceName = trim((string)$request->input('device_name', 'Mobile App'));

        $errors = $this->validate(['login' => $login, 'password' => $password], [
            'login' => 'required',
            'password' => 'required'
        ]);

        if (!empty($errors)) {
            $this->jsonError('Validation failed', 422, 'VALIDATION_ERROR', $errors);
        }

        // Rate Limiting Guard: Max 5 failed attempts per 15 minutes
        $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $throttleKey = "api_login:{$clientIp}:" . strtolower($login);
        $rateStatus = RateLimiter::check($throttleKey, 5, 900);
        if ($rateStatus['limited']) {
            if (!headers_sent()) {
                header("Retry-After: {$rateStatus['retry_after']}");
            }
            $this->jsonError("Too many failed login attempts. Please try again in {$rateStatus['retry_after']} seconds.", 429, 'TOO_MANY_REQUESTS', [
                'retry_after' => $rateStatus['retry_after']
            ]);
        }

        $tenant = $this->db->fetchOne(
            "SELECT * FROM tenants WHERE phone = ? OR email = ?",
            [$login, $login]
        );

        $staffUser = null;
        $isOwnerLogin = false;

        if ($tenant && password_verify($password, $tenant['password_hash'])) {
            $isOwnerLogin = true;
        } else {
            // Check if it's a staff/delivery user
            $cleanPhone = preg_replace('/[^0-9]/', '', $login);
            $staffUser = $this->db->fetchOne(
                "SELECT u.*, t.business_name, t.owner_name, t.status AS tenant_status, r.name AS assigned_route_name
                 FROM tenant_users u
                 JOIN tenants t ON t.id = u.tenant_id
                 LEFT JOIN routes r ON r.id = u.assigned_route_id
                 WHERE (u.phone = ? OR u.phone = ? OR u.email = ?) AND u.is_active = 1",
                [$login, $cleanPhone, $login]
            );

            if ($staffUser && password_verify($password, $staffUser['password_hash'])) {
                $tenant = $this->db->fetchOne("SELECT * FROM tenants WHERE id = ?", [$staffUser['tenant_id']]);
            } else {
                $staffUser = null;
            }
        }

        if (!$tenant || (!$isOwnerLogin && !$staffUser)) {
            RateLimiter::hit($throttleKey, 900);
            $this->jsonError('Invalid phone/email or password.', 401, 'INVALID_CREDENTIALS');
        }

        // Reset throttle on successful credentials
        RateLimiter::reset($throttleKey);

        if ($tenant['status'] === 'suspended') {
            $this->jsonError('Account is suspended. Please contact platform administration.', 403, 'ACCOUNT_SUSPENDED');
        }

        // Generate Bearer token
        $userId = $staffUser ? (int)$staffUser['id'] : null;
        $token = Auth::createApiToken((int)$tenant['id'], 'tenant', $deviceName, 90, $userId);
        $quota = $this->quotaService->getTenantQuota((int)$tenant['id']);

        $userPayload = [
            'id' => (int)$tenant['id'],
            'user_id' => $userId,
            'name' => $staffUser ? (string)$staffUser['name'] : (string)$tenant['owner_name'],
            'owner_name' => (string)$tenant['owner_name'],
            'business_name' => (string)$tenant['business_name'],
            'phone' => $staffUser ? (string)$staffUser['phone'] : (string)$tenant['phone'],
            'email' => $staffUser ? (string)($staffUser['email'] ?? '') : (string)($tenant['email'] ?? ''),
            'role' => $staffUser ? (string)$staffUser['role'] : 'admin',
            'assigned_route_id' => ($staffUser && $staffUser['assigned_route_id']) ? (int)$staffUser['assigned_route_id'] : null,
            'assigned_route_name' => $staffUser ? ($staffUser['assigned_route_name'] ?? null) : null,
            'is_owner' => $isOwnerLogin,
            'status' => $tenant['status'],
            'created_at' => $staffUser ? $staffUser['created_at'] : $tenant['created_at'],
        ];

        $this->jsonSuccess([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in_days' => 90,
            'tenant' => $userPayload,
            'user' => $userPayload,
            'quota' => $quota,
        ], 'Login successful');
    }

    public function register(Request $request): void
    {
        $businessName = trim((string)$request->input('business_name', ''));
        $ownerName = trim((string)$request->input('owner_name', ''));
        $phone = trim((string)$request->input('phone', ''));
        $email = trim((string)$request->input('email', ''));
        $password = (string)$request->input('password', '');
        $deviceName = trim((string)$request->input('device_name', 'Mobile App'));

        $errors = $this->validate([
            'business_name' => $businessName,
            'owner_name' => $ownerName,
            'phone' => $phone,
            'password' => $password,
        ], [
            'business_name' => 'required',
            'owner_name' => 'required',
            'phone' => 'required',
            'password' => 'required',
        ]);

        if (strlen($password) < 6) {
            $errors['password'] = 'Password must be at least 6 characters.';
        }

        if (!empty($errors)) {
            $this->jsonError('Validation failed', 422, 'VALIDATION_ERROR', $errors);
        }

        $existing = $this->db->fetchOne("SELECT id FROM tenants WHERE phone = ?", [$phone]);
        if ($existing) {
            $this->jsonError('Phone number already registered. Please login.', 422, 'PHONE_TAKEN');
        }

        if (!empty($email)) {
            $existingEmail = $this->db->fetchOne("SELECT id FROM tenants WHERE email = ?", [$email]);
            if ($existingEmail) {
                $this->jsonError('Email already registered.', 422, 'EMAIL_TAKEN');
            }
        }

        $tenantId = 0;
        $this->db->transaction(function () use ($businessName, $ownerName, $phone, $email, $password, &$tenantId) {
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $this->db->execute(
                "INSERT INTO tenants (business_name, owner_name, phone, email, password_hash, status)
                 VALUES (?, ?, ?, ?, ?, 'active')",
                [$businessName, $ownerName, $phone, empty($email) ? null : $email, $passwordHash]
            );
            $tenantId = (int)$this->db->lastInsertId();

            $this->db->execute(
                "INSERT INTO tenant_settings (tenant_id, tax_enabled, tax_label, tax_percentage)
                 VALUES (?, 0, 'GST', 5.00)",
                [$tenantId]
            );

            $freeLimit = $this->quotaService->getFreeCustomerLimit();
            $this->db->execute(
                "INSERT INTO tenant_subscriptions (tenant_id, purchased_blocks, max_customers_allowed, amount_paid, payment_status)
                 VALUES (?, 0, ?, 0.00, 'free')",
                [$tenantId, $freeLimit]
            );

            // Default products
            $this->productService->ensureDefaultProducts($tenantId);

            // Default route (Main Route)
            $this->routeService->ensureDefaultRoute($tenantId);
        });

        $tenant = $this->db->fetchOne("SELECT * FROM tenants WHERE id = ?", [$tenantId]);
        $token = Auth::createApiToken($tenantId, 'tenant', $deviceName, 90);
        $quota = $this->quotaService->getTenantQuota($tenantId);

        $this->jsonSuccess([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in_days' => 90,
            'tenant' => [
                'id' => (int)$tenant['id'],
                'business_name' => $tenant['business_name'],
                'owner_name' => $tenant['owner_name'],
                'phone' => $tenant['phone'],
                'email' => $tenant['email'],
                'status' => $tenant['status'],
                'created_at' => $tenant['created_at'],
            ],
            'quota' => $quota,
        ], 'Registration successful. Free plan active.', 201);
    }

    public function me(Request $request): void
    {
        $tenant = $this->tenant();
        if (!$tenant) {
            $this->jsonError('Unauthenticated', 401, 'UNAUTHENTICATED');
        }

        $tenantId = (int)$tenant['id'];
        $freshTenant = $this->db->fetchOne("SELECT id, business_name, owner_name, phone, email, status, created_at FROM tenants WHERE id = ?", [$tenantId]);
        $quota = $this->quotaService->getTenantQuota($tenantId);
        $settings = $this->db->fetchOne("SELECT tax_enabled, tax_label, tax_percentage, tax_number, upi_id, invoice_footer_note FROM tenant_settings WHERE tenant_id = ?", [$tenantId]);

        $this->jsonSuccess([
            'tenant' => $freshTenant,
            'user' => [
                'id' => $tenantId,
                'user_id' => $tenant['user_id'] ?? null,
                'name' => $tenant['user_name'] ?? $freshTenant['owner_name'],
                'role' => $tenant['role'] ?? 'admin',
                'assigned_route_id' => $tenant['assigned_route_id'] ?? null,
                'assigned_route_name' => $tenant['assigned_route_name'] ?? null,
                'is_owner' => $tenant['is_owner'] ?? true,
            ],
            'quota' => $quota,
            'settings' => $settings,
        ]);
    }

    public function logout(Request $request): void
    {
        Auth::revokeCurrentApiToken();
        Auth::logout();
        $this->jsonSuccess(null, 'Logged out successfully');
    }

    public function adminLogin(Request $request): void
    {
        $email = trim((string)$request->input('email', ''));
        $password = (string)$request->input('password', '');
        $deviceName = trim((string)$request->input('device_name', 'Admin Console'));

        $errors = $this->validate(['email' => $email, 'password' => $password], [
            'email' => 'required|email',
            'password' => 'required'
        ]);

        if (!empty($errors)) {
            $this->jsonError('Validation failed', 422, 'VALIDATION_ERROR', $errors);
        }

        // Rate Limiting Guard: Max 5 failed attempts per 15 minutes
        $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $throttleKey = "api_admin_login:{$clientIp}:" . strtolower($email);
        $rateStatus = RateLimiter::check($throttleKey, 5, 900);
        if ($rateStatus['limited']) {
            if (!headers_sent()) {
                header("Retry-After: {$rateStatus['retry_after']}");
            }
            $this->jsonError("Too many failed admin login attempts. Please try again in {$rateStatus['retry_after']} seconds.", 429, 'TOO_MANY_REQUESTS', [
                'retry_after' => $rateStatus['retry_after']
            ]);
        }

        $admin = $this->db->fetchOne("SELECT * FROM super_admins WHERE email = ?", [$email]);
        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            RateLimiter::hit($throttleKey, 900);
            $this->jsonError('Invalid admin email or password.', 401, 'INVALID_CREDENTIALS');
        }

        RateLimiter::reset($throttleKey);

        $token = Auth::createApiToken((int)$admin['id'], 'admin', $deviceName, 30);

        $this->jsonSuccess([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in_days' => 30,
            'admin' => [
                'id' => (int)$admin['id'],
                'name' => $admin['name'],
                'email' => $admin['email'],
            ]
        ], 'Admin authentication successful');
    }
}

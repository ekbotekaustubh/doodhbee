<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private static ?array $apiTenant = null;
    private static ?array $apiAdmin = null;
    private static ?string $currentApiToken = null;

    public static function resetApiState(): void
    {
        self::$apiTenant = null;
        self::$apiAdmin = null;
        self::$currentApiToken = null;
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['tenant'], $_SESSION['admin']);
        }
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            if (!headers_sent()) {
                @session_start();
            }
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    public static function csrfToken(): string
    {
        self::startSession();
        return $_SESSION['csrf_token'] ?? '';
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::csrfToken(), ENT_QUOTES) . '">';
    }

    public static function loginTenant(array $tenant, ?array $user = null): void
    {
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['tenant'] = [
            'id' => (int)$tenant['id'],
            'business_name' => (string)$tenant['business_name'],
            'owner_name' => (string)$tenant['owner_name'],
            'phone' => (string)$tenant['phone'],
            'email' => (string)($tenant['email'] ?? ''),
            'status' => (string)$tenant['status'],
            'user_id' => $user ? (int)$user['id'] : null,
            'user_name' => $user ? (string)$user['name'] : (string)$tenant['owner_name'],
            'role' => $user ? (string)($user['role'] ?? 'delivery') : 'admin',
            'assigned_route_id' => ($user && !empty($user['assigned_route_id'])) ? (int)$user['assigned_route_id'] : null,
            'assigned_route_name' => $user ? ($user['assigned_route_name'] ?? null) : null,
            'is_owner' => empty($user),
        ];
        unset($_SESSION['admin']);
    }

    public static function loginAdmin(array $admin): void
    {
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['admin'] = [
            'id' => (int)$admin['id'],
            'name' => (string)$admin['name'],
            'email' => (string)$admin['email'],
        ];
        unset($_SESSION['tenant']);
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
    }

    public static function resolveApiUser(): void
    {
        if (self::$apiTenant !== null || self::$apiAdmin !== null) {
            return;
        }

        // 1. Check Bearer token in headers
        $authHeader = '';
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = trim((string)$_SERVER['HTTP_AUTHORIZATION']);
        } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        } elseif (!empty($_SERVER['HTTP_X_API_KEY'])) {
            $authHeader = 'Bearer ' . trim((string)$_SERVER['HTTP_X_API_KEY']);
        }

        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = trim(substr($authHeader, 7));
            if (!empty($token)) {
                $hash = hash('sha256', $token);
                $db = Database::getInstance();
                $tokenRow = $db->fetchOne(
                    "SELECT * FROM api_tokens 
                     WHERE token_hash = ? AND (expires_at IS NULL OR expires_at > NOW())",
                    [$hash]
                );

                if ($tokenRow) {
                    self::$currentApiToken = $token;
                    $db->execute("UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?", [$tokenRow['id']]);

                    if ($tokenRow['token_type'] === 'tenant' && !empty($tokenRow['tenant_id'])) {
                        $tenant = $db->fetchOne("SELECT * FROM tenants WHERE id = ? AND status = 'active'", [$tokenRow['tenant_id']]);
                        if ($tenant) {
                            $userRow = null;
                            if (!empty($tokenRow['user_id'])) {
                                $userRow = $db->fetchOne(
                                    "SELECT u.*, r.name AS assigned_route_name 
                                     FROM tenant_users u
                                     LEFT JOIN routes r ON r.id = u.assigned_route_id
                                     WHERE u.id = ? AND u.tenant_id = ? AND u.is_active = 1",
                                    [$tokenRow['user_id'], $tokenRow['tenant_id']]
                                );
                            }

                            self::$apiTenant = [
                                'id' => (int)$tenant['id'],
                                'business_name' => (string)$tenant['business_name'],
                                'owner_name' => (string)$tenant['owner_name'],
                                'phone' => (string)$tenant['phone'],
                                'email' => (string)($tenant['email'] ?? ''),
                                'status' => (string)$tenant['status'],
                                'user_id' => $userRow ? (int)$userRow['id'] : null,
                                'user_name' => $userRow ? (string)$userRow['name'] : (string)$tenant['owner_name'],
                                'role' => $userRow ? (string)($userRow['role'] ?? 'delivery') : 'admin',
                                'assigned_route_id' => ($userRow && $userRow['assigned_route_id']) ? (int)$userRow['assigned_route_id'] : null,
                                'assigned_route_name' => $userRow ? ($userRow['assigned_route_name'] ?? null) : null,
                                'is_owner' => empty($userRow),
                            ];
                        }
                    } elseif ($tokenRow['token_type'] === 'admin' && !empty($tokenRow['admin_id'])) {
                        $admin = $db->fetchOne("SELECT * FROM super_admins WHERE id = ?", [$tokenRow['admin_id']]);
                        if ($admin) {
                            self::$apiAdmin = [
                                'id' => (int)$admin['id'],
                                'name' => (string)$admin['name'],
                                'email' => (string)$admin['email'],
                            ];
                        }
                    }
                    return;
                }
            }
        }

        // 2. Fall back to Session
        self::startSession();
        if (!empty($_SESSION['tenant'])) {
            self::$apiTenant = $_SESSION['tenant'];
        }
        if (!empty($_SESSION['admin'])) {
            self::$apiAdmin = $_SESSION['admin'];
        }
    }

    public static function createApiToken(
        int $entityId,
        string $type = 'tenant',
        string $name = 'default',
        ?int $expiresInDays = 90,
        ?int $userId = null
    ): string {
        $plainToken = bin2hex(random_bytes(32));
        $hash = hash('sha256', $plainToken);
        $expiresAt = $expiresInDays ? date('Y-m-d H:i:s', time() + ($expiresInDays * 86400)) : null;

        $db = Database::getInstance();
        $db->execute(
            "INSERT INTO api_tokens (tenant_id, user_id, admin_id, token_hash, token_type, name, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $type === 'tenant' ? $entityId : null,
                $userId,
                $type === 'admin' ? $entityId : null,
                $hash,
                $type,
                $name,
                $expiresAt
            ]
        );

        return $plainToken;
    }

    public static function revokeCurrentApiToken(): bool
    {
        self::resolveApiUser();
        if (empty(self::$currentApiToken)) {
            return false;
        }
        $hash = hash('sha256', self::$currentApiToken);
        $db = Database::getInstance();
        $db->execute("DELETE FROM api_tokens WHERE token_hash = ?", [$hash]);
        self::$currentApiToken = null;
        self::$apiTenant = null;
        self::$apiAdmin = null;
        return true;
    }

    public static function tenant(): ?array
    {
        self::resolveApiUser();
        if (self::$apiTenant !== null) {
            return self::$apiTenant;
        }
        self::startSession();
        return $_SESSION['tenant'] ?? null;
    }

    public static function tenantId(): int
    {
        $tenant = self::tenant();
        if ($tenant === null) {
            throw new \RuntimeException('Unauthenticated tenant access.');
        }
        return (int)$tenant['id'];
    }

    public static function isTenant(): bool
    {
        return self::tenant() !== null;
    }

    public static function role(): string
    {
        $tenant = self::tenant();
        return (string)($tenant['role'] ?? 'admin');
    }

    public static function isDeliveryBoy(): bool
    {
        return self::isTenant() && self::role() === 'delivery';
    }

    public static function isTenantAdmin(): bool
    {
        return self::isTenant() && self::role() === 'admin';
    }

    public static function assignedRouteId(): ?int
    {
        $tenant = self::tenant();
        return (isset($tenant['assigned_route_id']) && $tenant['assigned_route_id']) ? (int)$tenant['assigned_route_id'] : null;
    }

    public static function assignedRouteName(): ?string
    {
        $tenant = self::tenant();
        return $tenant['assigned_route_name'] ?? null;
    }

    public static function requireTenantAdmin(): void
    {
        self::requireTenant();
        if (self::isDeliveryBoy()) {
            self::flash('error', 'Access restricted: Delivery staff accounts cannot access administrative or billing pages.');
            Response::redirect(url('/deliveries'));
        }
    }

    public static function requireApiTenantAdmin(): void
    {
        self::requireApiTenant();
        if (self::isDeliveryBoy()) {
            Response::json([
                'success' => false,
                'error' => 'Forbidden: Delivery staff are not permitted to access this resource.',
                'code' => 'FORBIDDEN_ROLE'
            ], 403);
        }
    }

    public static function admin(): ?array
    {
        self::resolveApiUser();
        if (self::$apiAdmin !== null) {
            return self::$apiAdmin;
        }
        self::startSession();
        return $_SESSION['admin'] ?? null;
    }

    public static function isAdmin(): bool
    {
        return self::admin() !== null;
    }

    public static function requireTenant(): void
    {
        if (!self::isTenant()) {
            Response::redirect(url('/login?msg=tenant_required'));
        }
    }

    public static function requireAdmin(): void
    {
        if (!self::isAdmin()) {
            Response::redirect(url('/admin/login?msg=admin_required'));
        }
    }

    public static function requireApiTenant(): void
    {
        self::resolveApiUser();
        if (!self::isTenant()) {
            Response::json([
                'success' => false,
                'error' => 'Unauthenticated: Valid tenant Bearer token or active session is required.',
                'code' => 'UNAUTHENTICATED'
            ], 401);
        }
        if ((self::tenant()['status'] ?? 'active') === 'suspended') {
            Response::json([
                'success' => false,
                'error' => 'Account suspended. Please contact platform administration.',
                'code' => 'ACCOUNT_SUSPENDED'
            ], 403);
        }
    }

    public static function requireApiAdmin(): void
    {
        self::resolveApiUser();
        if (!self::isAdmin()) {
            Response::json([
                'success' => false,
                'error' => 'Unauthenticated: Valid super admin Bearer token or active session is required.',
                'code' => 'UNAUTHENTICATED'
            ], 401);
        }
    }

    public static function flash(string $key, ?string $message = null): ?string
    {
        self::startSession();
        if ($message !== null) {
            $_SESSION['flash'][$key] = $message;
            return null;
        }
        $val = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);
        return $val;
    }
}

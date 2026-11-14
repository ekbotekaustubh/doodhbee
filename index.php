<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Auth;
use App\Core\Bootstrap;
use App\Core\Database;
use App\Core\Request;
use App\Core\Router;

Bootstrap::init();
Auth::startSession();

$request = new Request();
$path = $request->path();

$authPaths = ['/login', '/register', '/admin/login', '/logout'];
$isAuthPath = in_array($path, $authPaths, true) || str_starts_with($path, '/demo') || str_starts_with($path, '/api');

// In demo/development environments, optionally auto-login to Tenant 1 if explicitly enabled via ALLOW_DEMO_MODE=true
if (isDemoMode() && !Auth::isTenant() && !Auth::isAdmin() && empty($_GET['msg']) && !$isAuthPath && ($path === '/' || $path === '')) {
    try {
        $db = Database::getInstance();
        $defaultTenant = $db->fetchOne("SELECT * FROM tenants WHERE id = 1 AND status = 'active'");
        if ($defaultTenant) {
            Auth::loginTenant($defaultTenant);
        }
    } catch (\Throwable $e) {
        // Fallback gracefully if db error
    }
}

$router = new Router();

$routes = require __DIR__ . '/routes.php';
$routes($router);

$router->dispatch($request);

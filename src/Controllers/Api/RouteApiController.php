<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Services\RouteService;

final class RouteApiController extends ApiBaseController
{
    private RouteService $routeService;

    public function __construct()
    {
        parent::__construct();
        $this->routeService = new RouteService();
    }

    public function index(Request $request): void
    {
        $tenantId = $this->tenantId();
        $activeOnly = (bool)$request->get('active_only', false);
        $routes = $this->routeService->getTenantRoutes($tenantId, $activeOnly);

        $this->jsonSuccess($routes);
    }

    public function show(Request $request, array $params): void
    {
        $tenantId = $this->tenantId();
        $id = (int)($params['id'] ?? 0);

        $route = $this->routeService->getRouteById($id, $tenantId);
        if (!$route) {
            $this->jsonError('Route not found.', 404, 'NOT_FOUND');
        }

        $this->jsonSuccess($route);
    }

    public function store(Request $request): void
    {
        Auth::requireApiTenantAdmin();
        $tenantId = $this->tenantId();
        $name = trim((string)$request->input('name', ''));
        $description = trim((string)$request->input('description', ''));
        $sortOrder = (int)$request->input('sort_order', 0);

        $errors = $this->validate([
            'name' => $name,
        ], [
            'name' => 'required',
        ]);

        if (!empty($errors)) {
            $this->jsonError('Validation failed', 422, 'VALIDATION_ERROR', $errors);
        }

        try {
            $route = $this->routeService->createRoute(
                $tenantId,
                $name,
                empty($description) ? null : $description,
                $sortOrder
            );
        } catch (\InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422, 'VALIDATION_ERROR');
        } catch (\Throwable $e) {
            $this->jsonError('Failed to create route: ' . $e->getMessage(), 500);
        }

        $this->jsonSuccess($route, 'Route created successfully', 201);
    }

    public function update(Request $request, array $params): void
    {
        Auth::requireApiTenantAdmin();
        $tenantId = $this->tenantId();
        $id = (int)($params['id'] ?? 0);
        $name = trim((string)$request->input('name', ''));
        $description = trim((string)$request->input('description', ''));
        $sortOrder = $request->input('sort_order') !== null ? (int)$request->input('sort_order') : null;

        $errors = $this->validate([
            'name' => $name,
        ], [
            'name' => 'required',
        ]);

        if (!empty($errors)) {
            $this->jsonError('Validation failed', 422, 'VALIDATION_ERROR', $errors);
        }

        try {
            $route = $this->routeService->updateRoute(
                $id,
                $tenantId,
                $name,
                empty($description) ? null : $description,
                $sortOrder
            );
        } catch (\InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422, 'VALIDATION_ERROR');
        } catch (\RuntimeException $e) {
            $this->jsonError($e->getMessage(), 404, 'NOT_FOUND');
        } catch (\Throwable $e) {
            $this->jsonError('Failed to update route: ' . $e->getMessage(), 500);
        }

        $this->jsonSuccess($route, 'Route updated successfully');
    }

    public function toggle(Request $request, array $params): void
    {
        Auth::requireApiTenantAdmin();
        $tenantId = $this->tenantId();
        $id = (int)($params['id'] ?? 0);

        try {
            $route = $this->routeService->toggleRoute($id, $tenantId);
        } catch (\RuntimeException $e) {
            $this->jsonError($e->getMessage(), 404, 'NOT_FOUND');
        } catch (\Throwable $e) {
            $this->jsonError('Failed to toggle route: ' . $e->getMessage(), 500);
        }

        $this->jsonSuccess($route, 'Route status updated successfully');
    }

    public function destroy(Request $request, array $params): void
    {
        Auth::requireApiTenantAdmin();
        $tenantId = $this->tenantId();
        $id = (int)($params['id'] ?? 0);

        try {
            $this->routeService->deleteRoute($id, $tenantId);
        } catch (\InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422, 'CANNOT_DELETE');
        } catch (\RuntimeException $e) {
            $this->jsonError($e->getMessage(), 404, 'NOT_FOUND');
        } catch (\Throwable $e) {
            $this->jsonError('Failed to delete route: ' . $e->getMessage(), 500);
        }

        $this->jsonSuccess(null, 'Route deleted successfully');
    }
}

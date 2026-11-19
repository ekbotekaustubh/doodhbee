<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Services\StaffService;

final class StaffApiController extends ApiBaseController
{
    private StaffService $staffService;

    public function __construct()
    {
        parent::__construct();
        $this->staffService = new StaffService();
    }

    public function index(Request $request): void
    {
        Auth::requireApiTenantAdmin();
        $tenantId = $this->tenantId();
        $staff = $this->staffService->getStaffMembers($tenantId);

        $this->jsonSuccess($staff, 'Staff roster retrieved');
    }

    public function show(Request $request, array $params): void
    {
        Auth::requireApiTenantAdmin();
        $tenantId = $this->tenantId();
        $id = (int)($params['id'] ?? 0);

        $staff = $this->staffService->getStaffById($id, $tenantId);
        if (!$staff) {
            $this->jsonError('Staff member not found.', 404, 'NOT_FOUND');
        }

        $this->jsonSuccess($staff);
    }

    public function store(Request $request): void
    {
        Auth::requireApiTenantAdmin();
        $tenantId = $this->tenantId();

        $name = trim((string)$request->input('name', ''));
        $phone = trim((string)$request->input('phone', ''));
        $password = (string)$request->input('password', '');
        $role = trim((string)$request->input('role', 'delivery'));
        $assignedRouteId = $request->input('assigned_route_id') !== null ? (int)$request->input('assigned_route_id') : null;
        $email = trim((string)$request->input('email', ''));

        $errors = $this->validate([
            'name' => $name,
            'phone' => $phone,
            'password' => $password,
        ], [
            'name' => 'required',
            'phone' => 'required',
            'password' => 'required',
        ]);

        if (!empty($errors)) {
            $this->jsonError('Validation failed', 422, 'VALIDATION_ERROR', $errors);
        }

        try {
            $created = $this->staffService->createStaff(
                $tenantId,
                $name,
                $phone,
                $password,
                $assignedRouteId,
                $role,
                empty($email) ? null : $email
            );
        } catch (\InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422, 'VALIDATION_ERROR');
        } catch (\Throwable $e) {
            $this->jsonError('Failed to create staff member: ' . $e->getMessage(), 500);
        }

        $this->jsonSuccess($created, 'Staff member created successfully', 201);
    }

    public function update(Request $request, array $params): void
    {
        Auth::requireApiTenantAdmin();
        $tenantId = $this->tenantId();
        $id = (int)($params['id'] ?? 0);

        $name = trim((string)$request->input('name', ''));
        $phone = trim((string)$request->input('phone', ''));
        $password = $request->input('password') !== null ? (string)$request->input('password') : null;
        $role = trim((string)$request->input('role', 'delivery'));
        $assignedRouteId = $request->input('assigned_route_id') !== null ? (int)$request->input('assigned_route_id') : null;
        $email = trim((string)$request->input('email', ''));

        $errors = $this->validate([
            'name' => $name,
            'phone' => $phone,
        ], [
            'name' => 'required',
            'phone' => 'required',
        ]);

        if (!empty($errors)) {
            $this->jsonError('Validation failed', 422, 'VALIDATION_ERROR', $errors);
        }

        try {
            $updated = $this->staffService->updateStaff(
                $id,
                $tenantId,
                $name,
                $phone,
                empty($password) ? null : $password,
                $assignedRouteId,
                $role,
                empty($email) ? null : $email
            );
        } catch (\InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422, 'VALIDATION_ERROR');
        } catch (\RuntimeException $e) {
            $this->jsonError($e->getMessage(), 404, 'NOT_FOUND');
        } catch (\Throwable $e) {
            $this->jsonError('Failed to update staff member: ' . $e->getMessage(), 500);
        }

        $this->jsonSuccess($updated, 'Staff member updated successfully');
    }

    public function toggle(Request $request, array $params): void
    {
        Auth::requireApiTenantAdmin();
        $tenantId = $this->tenantId();
        $id = (int)($params['id'] ?? 0);

        try {
            $updated = $this->staffService->toggleStaff($id, $tenantId);
        } catch (\RuntimeException $e) {
            $this->jsonError($e->getMessage(), 404, 'NOT_FOUND');
        } catch (\Throwable $e) {
            $this->jsonError('Failed to update staff status: ' . $e->getMessage(), 500);
        }

        $this->jsonSuccess($updated, 'Staff member status updated successfully');
    }

    public function destroy(Request $request, array $params): void
    {
        Auth::requireApiTenantAdmin();
        $tenantId = $this->tenantId();
        $id = (int)($params['id'] ?? 0);

        try {
            $this->staffService->deleteStaff($id, $tenantId);
        } catch (\RuntimeException $e) {
            $this->jsonError($e->getMessage(), 404, 'NOT_FOUND');
        } catch (\Throwable $e) {
            $this->jsonError('Failed to delete staff member: ' . $e->getMessage(), 500);
        }

        $this->jsonSuccess(null, 'Staff member deleted successfully');
    }
}

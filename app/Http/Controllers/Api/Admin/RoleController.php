<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignRoleRequest;
use App\Http\Requests\Admin\SyncRolePermissionsRequest;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    public function __construct(
        private UserService $userService
    ) {}

    public function index(): JsonResponse
    {
        $roles = Role::with('permissions')
            ->get()
            ->map(fn ($role) => $this->formatRoleResponse($role));

        return $this->successResponse($roles, 'Roles retrieved');
    }

    public function permissions(): JsonResponse
    {
        $permissions = Permission::orderBy('name')->get()->map(fn ($permission) => [
            'id' => $permission->id,
            'name' => $permission->name,
            'guard_name' => $permission->guard_name,
            'created_at' => $permission->created_at,
            'updated_at' => $permission->updated_at,
        ]);

        return $this->successResponse($permissions, 'Permissions retrieved');
    }

    public function syncPermissions(SyncRolePermissionsRequest $request, Role $role): JsonResponse
    {
        if ($role->name === 'super_admin') {
            return $this->errorResponse('Cannot modify permissions for super_admin role', null, 403);
        }

        $role->syncPermissions($request->validated()['permissions']);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $role->load('permissions');

        return $this->successResponse($this->formatRoleResponse($role), 'Role permissions updated');
    }

    public function assignRoles(AssignRoleRequest $request, User $user): JsonResponse
    {
        $updatedUser = $this->userService->assignRoles($user, $request->validated()['roles']);

        return $this->successResponse($updatedUser, 'Roles assigned');
    }

    public function removeRole(User $user, Role $role): JsonResponse
    {
        $updatedUser = $this->userService->removeRole($user, $role->name);

        return $this->successResponse($updatedUser, 'Role removed');
    }

    private function formatRoleResponse(Role $role): array
    {
        $roleUsers = $role->users()->select(['users.id', 'name', 'email', 'is_active'])->get();

        return [
            'id' => $role->id,
            'name' => $role->name,
            'guard_name' => $role->guard_name,
            'permissions_count' => $role->permissions->count(),
            'users_count' => $roleUsers->count(),
            'permissions' => $role->permissions->map(fn ($permission) => [
                'id' => $permission->id,
                'name' => $permission->name,
                'guard_name' => $permission->guard_name,
            ]),
            'users' => $roleUsers->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
            ]),
            'created_at' => $role->created_at,
            'updated_at' => $role->updated_at,
        ];
    }
}

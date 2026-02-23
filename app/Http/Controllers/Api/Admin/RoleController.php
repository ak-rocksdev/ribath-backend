<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignRoleRequest;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function __construct(
        private UserService $userService
    ) {}

    public function index(): JsonResponse
    {
        $roles = Role::withCount('permissions')
            ->get()
            ->map(function ($role) {
                $role->users_count = $role->users()->count();

                return $role;
            });

        return $this->successResponse($roles, 'Roles retrieved');
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
}

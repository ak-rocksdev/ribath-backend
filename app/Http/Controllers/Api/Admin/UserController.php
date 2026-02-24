<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResetPasswordRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        private UserService $userService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $users = $this->userService->listUsers($request->all());

        return $this->paginatedResponse($users, 'Users retrieved');
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userService->createUser($request->validated());

        return $this->successResponse($user, 'User created', 201);
    }

    public function show(User $user): JsonResponse
    {
        $user->load('roles');

        return $this->successResponse($user, 'User retrieved');
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $updatedUser = $this->userService->updateUser($user, $request->validated());

        return $this->successResponse($updatedUser, 'User updated');
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            return $this->errorResponse('Cannot delete your own account', code: 403);
        }

        $cascadeTeacher = filter_var($request->query('cascade_teacher', false), FILTER_VALIDATE_BOOLEAN);

        $this->userService->deleteWithCascade($user, $cascadeTeacher);

        return $this->successResponse(null, 'User deleted');
    }

    public function relationships(User $user): JsonResponse
    {
        $relationships = $this->userService->getRelationships($user);

        return $this->successResponse($relationships, 'User relationships retrieved');
    }

    public function checkEmail(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $softDeletedUser = $this->userService->checkEmail($request->input('email'));

        return $this->successResponse($softDeletedUser, 'Email check completed');
    }

    public function toggleStatus(Request $request, User $user): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            return $this->errorResponse('Cannot toggle your own status', code: 403);
        }

        $updatedUser = $this->userService->toggleActiveStatus($user);

        $statusLabel = $updatedUser->is_active ? 'activated' : 'deactivated';

        return $this->successResponse($updatedUser, "User {$statusLabel}");
    }

    public function resetPassword(ResetPasswordRequest $request, User $user): JsonResponse
    {
        $this->userService->resetPassword($user, $request->validated()['new_password']);

        return $this->successResponse(null, 'Password reset successfully');
    }
}

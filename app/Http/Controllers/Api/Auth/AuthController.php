<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService
    ) {}

    public function login(LoginRequest $loginRequest): JsonResponse
    {
        $result = $this->authService->attemptLogin(
            $loginRequest->validated('email'),
            $loginRequest->validated('password'),
        );

        if (! $result) {
            return $this->errorResponse('Invalid credentials', null, 401);
        }

        return $this->successResponse($result, 'Login successful');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return $this->successResponse(null, 'Logged out successfully');
    }

    public function me(Request $request): JsonResponse
    {
        $userData = $this->authService->getAuthenticatedUser($request->user());

        return $this->successResponse($userData);
    }

    public function changePassword(ChangePasswordRequest $changePasswordRequest): JsonResponse
    {
        $this->authService->changePassword(
            $changePasswordRequest->user(),
            $changePasswordRequest->validated('new_password'),
        );

        return $this->successResponse(null, 'Password changed successfully');
    }
}

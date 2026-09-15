<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function attemptLogin(string $email, string $password): ?array
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return null;
        }

        if (! $user->is_active) {
            return null;
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'is_active' => $user->is_active,
                'must_change_password' => $user->must_change_password,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
                'teacher' => $this->linkedTeacherSummary($user),
            ],
            'token' => $token,
            'session_timeout_minutes' => config('auth.frontend_session_timeout'),
            'token_expires_in_minutes' => config('sanctum.expiration'),
        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }

    public function getAuthenticatedUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'is_active' => $user->is_active,
            'must_change_password' => $user->must_change_password,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'teacher' => $this->linkedTeacherSummary($user),
            'session_timeout_minutes' => config('auth.frontend_session_timeout'),
            'token_expires_in_minutes' => config('sanctum.expiration'),
        ];
    }

    /**
     * The Ustadz linked to an Akun Ustadz — the Ustadz penyimak the Log
     * Setoran form locks to — or null for an account without one.
     *
     * @return array{id: string, full_name: string}|null
     */
    private function linkedTeacherSummary(User $user): ?array
    {
        $linkedTeacher = $user->teacher;

        return $linkedTeacher === null ? null : [
            'id' => $linkedTeacher->id,
            'full_name' => $linkedTeacher->full_name,
        ];
    }

    /** The user's own password change; it also completes a required one (wajib ganti password). */
    public function changePassword(User $user, string $newPassword): void
    {
        $user->update([
            'password' => Hash::make($newPassword),
            'must_change_password' => false,
        ]);
    }
}

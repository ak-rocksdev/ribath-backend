<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

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

    /**
     * The user's own password change; it also completes a required one (wajib
     * ganti password). Every other session of the account is signed out — a
     * token obtained with the old (e.g. temporary) password must not outlive
     * it — while the session that made the change keeps working. Without a
     * token session (null) every token is revoked.
     */
    public function changePassword(User $user, string $newPassword, ?PersonalAccessToken $currentAccessToken): void
    {
        DB::transaction(function () use ($user, $newPassword, $currentAccessToken) {
            $user->update([
                'password' => Hash::make($newPassword),
                'must_change_password' => false,
            ]);

            $user->tokens()
                ->when($currentAccessToken !== null, fn ($tokensQuery) => $tokensQuery->whereKeyNot($currentAccessToken->getKey()))
                ->delete();
        });
    }
}

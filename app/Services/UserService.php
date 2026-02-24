<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function listUsers(array $filters): LengthAwarePaginator
    {
        $query = User::with('roles');

        if (! empty($filters['search'])) {
            $searchTerm = mb_strtolower($filters['search']);
            $query->where(function ($q) use ($searchTerm) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$searchTerm}%"])
                    ->orWhereRaw('LOWER(email) LIKE ?', ["%{$searchTerm}%"])
                    ->orWhereRaw('LOWER(COALESCE(phone, \'\')) LIKE ?', ["%{$searchTerm}%"]);
            });
        }

        if (! empty($filters['role'])) {
            $query->whereHas('roles', function ($q) use ($filters) {
                $q->where('name', $filters['role']);
            });
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function createUser(array $data): User
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'phone' => $data['phone'] ?? null,
        ]);

        if (! empty($data['role'])) {
            $user->assignRole($data['role']);
        }

        return $user->load('roles');
    }

    public function updateUser(User $user, array $data): User
    {
        $user->update($data);

        return $user->fresh()->load('roles');
    }

    public function toggleActiveStatus(User $user): User
    {
        $user->update(['is_active' => ! $user->is_active]);

        if (! $user->is_active) {
            $user->tokens()->delete();
        }

        return $user->fresh()->load('roles');
    }

    public function resetPassword(User $user, string $newPassword): void
    {
        $user->update([
            'password' => Hash::make($newPassword),
        ]);

        $user->tokens()->delete();
    }

    public function assignRoles(User $user, array $roleNames): User
    {
        $user->syncRoles($roleNames);

        return $user->fresh()->load('roles');
    }

    public function removeRole(User $user, string $roleName): User
    {
        $user->removeRole($roleName);

        return $user->fresh()->load('roles');
    }

    public function getRelationships(User $user): array
    {
        $user->load(['teacher', 'guardianStudents']);

        return [
            'teacher' => $user->teacher ? [
                'id' => $user->teacher->id,
                'full_name' => $user->teacher->full_name,
                'code' => $user->teacher->code,
                'status' => $user->teacher->status,
            ] : null,
            'guardian_students' => $user->guardianStudents->map(fn ($student) => [
                'id' => $student->id,
                'full_name' => $student->full_name,
                'status' => $student->status,
            ])->toArray(),
        ];
    }

    public function checkEmail(string $email): ?array
    {
        $softDeletedUser = User::onlyTrashed()
            ->where('email', $email)
            ->first();

        if (! $softDeletedUser) {
            return null;
        }

        return [
            'id' => $softDeletedUser->id,
            'name' => $softDeletedUser->name,
            'email' => $softDeletedUser->email,
            'deleted_at' => $softDeletedUser->deleted_at->toISOString(),
            'roles' => $softDeletedUser->roles->pluck('name')->toArray(),
        ];
    }

    public function deleteWithCascade(User $user, bool $cascadeTeacher = false): void
    {
        DB::transaction(function () use ($user, $cascadeTeacher) {
            if ($cascadeTeacher && $user->teacher) {
                $user->teacher->delete();
            }

            $user->tokens()->delete();
            $user->delete();
        });
    }
}

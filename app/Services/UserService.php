<?php

namespace App\Services;

use App\Models\School;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserService
{
    /**
     * Login accounts of the active school. The role filter matches any of an
     * account's roles, so a multi-role account shows under each of them.
     */
    public function listUsers(array $filters): LengthAwarePaginator
    {
        $query = User::with('roles')->where('school_id', School::activeOrFail()->id);

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

    /**
     * Account counts of the active school for the Akun Pengguna statistics:
     * every role is listed, a multi-role account counts under each of its roles,
     * while `administrators` counts each account holding an administrator role once.
     *
     * @return array{total: int, active: int, inactive: int, administrators: int, by_role: array<string, int>}
     */
    public function summarizeUsers(): array
    {
        $activeSchoolId = School::activeOrFail()->id;

        $totalAccounts = User::where('school_id', $activeSchoolId)->count();
        $activeAccounts = User::where('school_id', $activeSchoolId)->where('is_active', true)->count();

        $accountCountsByRoleId = User::query()
            ->where('users.school_id', $activeSchoolId)
            ->join(config('permission.table_names.model_has_roles').' as account_roles', function ($join) {
                $join->on('account_roles.model_id', '=', 'users.id')
                    ->where('account_roles.model_type', (new User)->getMorphClass());
            })
            ->groupBy('account_roles.role_id')
            ->selectRaw('account_roles.role_id, COUNT(*) as account_count')
            ->pluck('account_count', 'role_id');

        $roleNamesById = Role::query()->orderBy('id')->pluck('name', 'id');

        $accountCountsByRole = $roleNamesById
            ->mapWithKeys(fn (string $roleName, int $roleId) => [
                $roleName => (int) ($accountCountsByRoleId[$roleId] ?? 0),
            ])
            ->all();

        // Matched against explicit names rather than a LIKE pattern, where "_" is a wildcard.
        $administratorRoleNames = $roleNamesById
            ->filter(fn (string $roleName) => $this->isAdministratorRole($roleName))
            ->values()
            ->all();

        $administratorAccounts = User::where('school_id', $activeSchoolId)
            ->whereHas('roles', fn ($rolesQuery) => $rolesQuery->whereIn('name', $administratorRoleNames))
            ->count();

        return [
            'total' => $totalAccounts,
            'active' => $activeAccounts,
            'inactive' => $totalAccounts - $activeAccounts,
            'administrators' => $administratorAccounts,
            'by_role' => $accountCountsByRole,
        ];
    }

    /** Administrator roles: super_admin and every pengurus_* role. */
    private function isAdministratorRole(string $roleName): bool
    {
        return $roleName === 'super_admin' || str_starts_with($roleName, 'pengurus_');
    }

    public function createUser(array $data): User
    {
        $user = User::create([
            'school_id' => School::activeOrFail()->id,
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

    /**
     * An admin sets a new password for the account: its tokens are revoked and
     * the user must change the password at the next login (wajib ganti
     * password), so the password the admin handed over does not stay.
     */
    public function resetPassword(User $user, string $newPassword): void
    {
        $user->update([
            'password' => Hash::make($newPassword),
            'must_change_password' => true,
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

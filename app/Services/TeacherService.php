<?php

namespace App\Services;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TeacherService
{
    public function listTeachers(array $filters): LengthAwarePaginator
    {
        $query = Teacher::with(['school', 'user']);

        if (! empty($filters['search'])) {
            $searchTerm = mb_strtolower($filters['search']);
            $query->where(function ($q) use ($searchTerm) {
                $q->whereRaw('LOWER(full_name) LIKE ?', ["%{$searchTerm}%"])
                    ->orWhereRaw('LOWER(code) LIKE ?', ["%{$searchTerm}%"])
                    ->orWhereRaw('LOWER(email) LIKE ?', ["%{$searchTerm}%"]);
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['school_id'])) {
            $query->where('school_id', $filters['school_id']);
        }

        return $query->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function createTeacher(array $data): Teacher
    {
        $teacher = Teacher::create($data);

        return $teacher->load(['school', 'user']);
    }

    public function updateTeacher(Teacher $teacher, array $data): Teacher
    {
        return DB::transaction(function () use ($teacher, $data) {
            $teacher->update($data);

            $this->deactivateLinkedAccountWhenInactive($teacher);

            return $teacher->fresh()->load(['school', 'user']);
        });
    }

    public function updateTeacherStatus(Teacher $teacher, string $status): Teacher
    {
        return DB::transaction(function () use ($teacher, $status) {
            $teacher->update(['status' => $status]);

            $this->deactivateLinkedAccountWhenInactive($teacher);

            return $teacher->fresh()->load(['school', 'user']);
        });
    }

    /**
     * Status nonaktif on a teacher's data deactivates its linked Akun Ustadz
     * and revokes every one of its sessions, in the same transaction as the
     * status change, so someone who has left no longer holds access to
     * santri grades. Status cuti and reactivating the teacher (aktif) leave
     * the account untouched — reactivating the account stays a deliberate,
     * manual step in Kelola Pengguna (Akun Pengguna).
     */
    private function deactivateLinkedAccountWhenInactive(Teacher $teacher): void
    {
        if ($teacher->status !== Teacher::STATUS_INACTIVE || $teacher->user === null) {
            return;
        }

        $teacher->user->update(['is_active' => false]);
        $teacher->user->tokens()->delete();
    }

    public function grantAccess(Teacher $teacher, string $email, string $password): array
    {
        return DB::transaction(function () use ($teacher, $email, $password) {
            $user = User::create([
                'name' => $teacher->full_name,
                'email' => $email,
                'password' => Hash::make($password),
                'school_id' => $teacher->school_id,
                // The temporary password is handed over outside the app; the Ustadz
                // must change it at his first login (wajib ganti password).
                'must_change_password' => true,
            ]);

            // Seeded by RolePermissionSeeder with the "milik sendiri" permissions; a
            // missing role fails the whole grant instead of creating an empty one.
            $user->assignRole('ustadz');

            $teacher->update(['user_id' => $user->id]);

            return [
                'teacher' => $teacher->fresh()->load(['school', 'user']),
                'credentials' => [
                    'email' => $email,
                    'password' => $password,
                ],
            ];
        });
    }

    public function getRelationships(Teacher $teacher): array
    {
        $teacher->load('user.roles');

        return [
            'user' => $teacher->user ? [
                'id' => $teacher->user->id,
                'name' => $teacher->user->name,
                'email' => $teacher->user->email,
                'is_active' => $teacher->user->is_active,
                'roles' => $teacher->user->roles->pluck('name')->toArray(),
            ] : null,
        ];
    }

    public function deleteWithCascade(Teacher $teacher, bool $cascadeUser = false): void
    {
        DB::transaction(function () use ($teacher, $cascadeUser) {
            if ($cascadeUser && $teacher->user) {
                $teacher->user->tokens()->delete();
                $teacher->user->delete();
            }

            $teacher->delete();
        });
    }
}

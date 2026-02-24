<?php

namespace App\Services;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

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
        $teacher->update($data);

        return $teacher->fresh()->load(['school', 'user']);
    }

    public function updateTeacherStatus(Teacher $teacher, string $status): Teacher
    {
        $teacher->update(['status' => $status]);

        return $teacher->fresh()->load(['school', 'user']);
    }

    public function grantAccess(Teacher $teacher, string $email, string $password): array
    {
        return DB::transaction(function () use ($teacher, $email, $password) {
            $user = User::create([
                'name' => $teacher->full_name,
                'email' => $email,
                'password' => Hash::make($password),
                'school_id' => $teacher->school_id,
            ]);

            $ustadzRole = Role::firstOrCreate(
                ['name' => 'ustadz', 'guard_name' => 'web']
            );
            $user->assignRole($ustadzRole);

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
}

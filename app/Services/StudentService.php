<?php

namespace App\Services;

use App\Models\Student;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class StudentService
{
    public function listStudents(array $filters): LengthAwarePaginator
    {
        $query = Student::with(['guardian', 'registration']);

        if (! empty($filters['search'])) {
            $searchTerm = mb_strtolower($filters['search']);
            $query->whereRaw('LOWER(full_name) LIKE ?', ["%{$searchTerm}%"]);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['class_level'])) {
            $query->where('class_level', $filters['class_level']);
        }

        if (! empty($filters['program'])) {
            $query->where('program', $filters['program']);
        }

        return $query->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function createStudent(array $data): Student
    {
        $student = Student::create($data);
        $this->syncProfileCompletionTimestamp($student);

        return $student->load(['guardian', 'registration']);
    }

    public function updateStudent(Student $student, array $data): Student
    {
        $student->update($data);
        $student = $student->fresh();
        $this->syncProfileCompletionTimestamp($student);

        return $student->load(['guardian', 'registration']);
    }

    public function updateStudentStatus(Student $student, string $status): Student
    {
        $student->update(['status' => $status]);

        return $student->fresh()->load(['guardian', 'registration']);
    }

    private function syncProfileCompletionTimestamp(Student $student): void
    {
        if ($student->isProfileComplete() && $student->profile_completed_at === null) {
            $student->updateQuietly(['profile_completed_at' => now()]);
        } elseif (! $student->isProfileComplete() && $student->profile_completed_at !== null) {
            $student->updateQuietly(['profile_completed_at' => null]);
        }
    }
}

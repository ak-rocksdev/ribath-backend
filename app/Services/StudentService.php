<?php

namespace App\Services;

use App\Models\Student;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class StudentService
{
    private const RELATION_KEYS = ['parents', 'health', 'education_history', 'religious_profile', 'additional_info'];

    private const ALL_RELATIONS = ['guardian', 'registration', 'parents', 'health', 'educationHistory', 'religiousProfile', 'additionalInfo', 'documents'];

    public function listStudents(array $filters): LengthAwarePaginator
    {
        $query = Student::with([
            'guardian',
            'registration',
            'documents' => fn ($q) => $q->where('document_type', 'foto'),
        ]);

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
        // Separate core student fields from nested relation data
        $relationData = [];
        foreach (self::RELATION_KEYS as $key) {
            if (isset($data[$key])) {
                $relationData[$key] = $data[$key];
                unset($data[$key]);
            }
        }

        return DB::transaction(function () use ($student, $data, $relationData) {
            // Update core student fields
            if (! empty($data)) {
                $student->update($data);
            }

            // Upsert parents
            if (isset($relationData['parents'])) {
                foreach ($relationData['parents'] as $relation => $parentData) {
                    $student->parents()->updateOrCreate(
                        ['relation' => $relation],
                        $parentData,
                    );
                }
            }

            // Upsert HasOne relationships
            if (isset($relationData['health'])) {
                $student->health()->updateOrCreate([], $relationData['health']);
            }

            if (isset($relationData['education_history'])) {
                $student->educationHistory()->updateOrCreate([], $relationData['education_history']);
            }

            if (isset($relationData['religious_profile'])) {
                $student->religiousProfile()->updateOrCreate([], $relationData['religious_profile']);
            }

            if (isset($relationData['additional_info'])) {
                $student->additionalInfo()->updateOrCreate([], $relationData['additional_info']);
            }

            // Refresh from DB to get latest state for profile completion check
            $student = $student->fresh();
            $this->syncProfileCompletionTimestamp($student);

            return $student->load(self::ALL_RELATIONS);
        });
    }

    public function updateStudentStatus(Student $student, string $status): Student
    {
        $student->update(['status' => $status]);

        return $student->fresh()->load(['guardian', 'registration']);
    }

    private function syncProfileCompletionTimestamp(Student $student): void
    {
        $needsUpdate = false;
        $updates = [];

        if ($student->isProfileComplete() && $student->profile_completed_at === null) {
            $updates['profile_completed_at'] = now();
            $needsUpdate = true;
        } elseif (! $student->isProfileComplete() && $student->profile_completed_at !== null) {
            $updates['profile_completed_at'] = null;
            $needsUpdate = true;
        }

        // Auto-set profile_completion_status to 'completed' when admin fills all fields
        // (only if it's still 'incomplete' — don't override 'draft' or 'completed' from student completion flow)
        if ($student->isProfileComplete() && $student->profile_completion_status === 'incomplete') {
            $updates['profile_completion_status'] = 'completed';
            $needsUpdate = true;
        }

        if ($needsUpdate) {
            $student->updateQuietly($updates);
        }
    }
}

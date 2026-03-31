<?php

namespace App\Services;

use App\Models\School;
use App\Models\TeachingSchedule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeachingScheduleService
{
    public function listSchedules(array $filters): Collection
    {
        $school = School::activeOrFail();

        $query = TeachingSchedule::where('school_id', $school->id)
            ->with(TeachingSchedule::EAGER_LOAD_RELATIONS);

        if (! empty($filters['academic_year_id'])) {
            $query->where('academic_year_id', $filters['academic_year_id']);
        }

        if (! empty($filters['semester'])) {
            $query->where('semester', (int) $filters['semester']);
        }

        if (! empty($filters['class_level_id'])) {
            $query->where('class_level_id', $filters['class_level_id']);
        }

        if (! empty($filters['day_of_week'])) {
            $query->where('day_of_week', $filters['day_of_week']);
        }

        if (! empty($filters['teacher_id'])) {
            $query->where('teacher_id', $filters['teacher_id']);
        }

        return $query->orderBy('day_of_week')
            ->orderBy('created_at')
            ->get();
    }

    public function createSchedule(array $data): TeachingSchedule
    {
        $school = School::activeOrFail();

        $data['school_id'] = $school->id;

        $this->validateNoClassSlotConflict($data);

        $this->validateNoTeacherConflict(
            teacherId: $data['teacher_id'],
            dayOfWeek: $data['day_of_week'],
            timeSlotId: $data['time_slot_id'],
            academicYearId: $data['academic_year_id'],
            semester: (int) $data['semester'],
        );

        $schedule = TeachingSchedule::create($data);

        return $schedule->load(TeachingSchedule::EAGER_LOAD_RELATIONS);
    }

    public function updateSchedule(TeachingSchedule $teachingSchedule, array $data): TeachingSchedule
    {
        $mergedData = array_merge($teachingSchedule->only([
            'school_id', 'teacher_id', 'day_of_week', 'time_slot_id',
            'academic_year_id', 'semester', 'class_level_id',
        ]), $data);

        $this->validateNoClassSlotConflict($mergedData, $teachingSchedule->id);

        $this->validateNoTeacherConflict(
            teacherId: $mergedData['teacher_id'],
            dayOfWeek: $mergedData['day_of_week'],
            timeSlotId: $mergedData['time_slot_id'],
            academicYearId: $mergedData['academic_year_id'],
            semester: (int) $mergedData['semester'],
            excludeScheduleId: $teachingSchedule->id,
        );

        $teachingSchedule->update($data);

        return $teachingSchedule->fresh()->load(TeachingSchedule::EAGER_LOAD_RELATIONS);
    }

    public function deleteSchedule(TeachingSchedule $teachingSchedule): void
    {
        $teachingSchedule->update(['is_active' => false]);
    }

    /**
     * Clone all active schedules from one semester to another.
     * Skips entries that would create conflicts in the target semester.
     *
     * @return array{created: int, skipped: int, skipped_details: array}
     */
    public function cloneSemesterSchedules(array $data): array
    {
        $school = School::activeOrFail();

        // Prevent cloning to the same period
        if ($data['source_academic_year_id'] === $data['target_academic_year_id']
            && (int) $data['source_semester'] === (int) $data['target_semester']) {
            throw ValidationException::withMessages([
                'target_semester' => 'Target semester must be different from source semester.',
            ]);
        }

        $excludeIds = $data['exclude_schedule_ids'] ?? [];

        $sourceSchedules = TeachingSchedule::where('school_id', $school->id)
            ->where('academic_year_id', $data['source_academic_year_id'])
            ->where('semester', (int) $data['source_semester'])
            ->where('is_active', true)
            ->when(count($excludeIds) > 0, fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->get();

        if ($sourceSchedules->isEmpty()) {
            throw ValidationException::withMessages([
                'source_semester' => 'No active schedules found in the source semester.',
            ]);
        }

        $created = 0;
        $skipped = 0;
        $skippedDetails = [];

        DB::transaction(function () use ($sourceSchedules, $data, $school, &$created, &$skipped, &$skippedDetails) {
            foreach ($sourceSchedules as $source) {
                // Check for class-slot conflict in target
                $classConflict = TeachingSchedule::where('school_id', $school->id)
                    ->where('academic_year_id', $data['target_academic_year_id'])
                    ->where('semester', (int) $data['target_semester'])
                    ->where('day_of_week', $source->day_of_week)
                    ->where('time_slot_id', $source->time_slot_id)
                    ->where('class_level_id', $source->class_level_id)
                    ->where('is_active', true)
                    ->exists();

                // Check for teacher conflict in target
                $teacherConflict = TeachingSchedule::where('teacher_id', $source->teacher_id)
                    ->where('day_of_week', $source->day_of_week)
                    ->where('time_slot_id', $source->time_slot_id)
                    ->where('academic_year_id', $data['target_academic_year_id'])
                    ->where('semester', (int) $data['target_semester'])
                    ->where('is_active', true)
                    ->exists();

                if ($classConflict || $teacherConflict) {
                    $skipped++;
                    $skippedDetails[] = [
                        'source_id' => $source->id,
                        'reason' => $classConflict ? 'class_slot_conflict' : 'teacher_conflict',
                    ];

                    continue;
                }

                TeachingSchedule::create([
                    'school_id' => $school->id,
                    'academic_year_id' => $data['target_academic_year_id'],
                    'semester' => (int) $data['target_semester'],
                    'day_of_week' => $source->day_of_week,
                    'time_slot_id' => $source->time_slot_id,
                    'class_level_id' => $source->class_level_id,
                    'subject_book_id' => $source->subject_book_id,
                    'teacher_id' => $source->teacher_id,
                    'is_active' => true,
                ]);

                $created++;
            }
        });

        return [
            'created' => $created,
            'skipped' => $skipped,
            'skipped_details' => $skippedDetails,
            'source_total' => $sourceSchedules->count(),
        ];
    }

    /**
     * Replace one teacher with another across schedules.
     * Validates that the replacement teacher has no conflicts.
     *
     * @return array{updated: int, conflicts: array}
     */
    public function replaceTeacher(array $data): array
    {
        $school = School::activeOrFail();

        $query = TeachingSchedule::where('school_id', $school->id)
            ->where('teacher_id', $data['source_teacher_id'])
            ->where('is_active', true);

        if (! empty($data['academic_year_id'])) {
            $query->where('academic_year_id', $data['academic_year_id']);
        }

        if (! empty($data['semester'])) {
            $query->where('semester', (int) $data['semester']);
        }

        $schedules = $query->get();

        if ($schedules->isEmpty()) {
            throw ValidationException::withMessages([
                'source_teacher_id' => 'No active schedules found for this teacher.',
            ]);
        }

        $updated = 0;
        $conflicts = [];

        DB::transaction(function () use ($schedules, $data, &$updated, &$conflicts) {
            foreach ($schedules as $schedule) {
                $conflict = $this->findTeacherConflict(
                    teacherId: $data['target_teacher_id'],
                    dayOfWeek: $schedule->day_of_week,
                    timeSlotId: $schedule->time_slot_id,
                    academicYearId: $schedule->academic_year_id,
                    semester: $schedule->semester,
                );

                if ($conflict) {
                    $conflicts[] = [
                        'schedule_id' => $schedule->id,
                        'day_of_week' => $schedule->day_of_week,
                        'time_slot_id' => $schedule->time_slot_id,
                        'conflicting_class' => $conflict->classLevel->label ?? null,
                    ];

                    continue;
                }

                $schedule->update(['teacher_id' => $data['target_teacher_id']]);
                $updated++;
            }
        });

        return [
            'updated' => $updated,
            'conflicts' => $conflicts,
            'total' => $schedules->count(),
        ];
    }

    public function findTeacherConflict(
        string $teacherId,
        string $dayOfWeek,
        string $timeSlotId,
        string $academicYearId,
        int $semester,
        ?string $excludeScheduleId = null,
    ): ?TeachingSchedule {
        $query = TeachingSchedule::where('teacher_id', $teacherId)
            ->where('day_of_week', $dayOfWeek)
            ->where('time_slot_id', $timeSlotId)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->where('is_active', true);

        if ($excludeScheduleId) {
            $query->where('id', '!=', $excludeScheduleId);
        }

        return $query->with('classLevel:id,label')->first();
    }

    private function validateNoClassSlotConflict(array $data, ?string $excludeScheduleId = null): void
    {
        $query = TeachingSchedule::where('school_id', $data['school_id'])
            ->where('academic_year_id', $data['academic_year_id'])
            ->where('semester', $data['semester'])
            ->where('day_of_week', $data['day_of_week'])
            ->where('time_slot_id', $data['time_slot_id'])
            ->where('class_level_id', $data['class_level_id'])
            ->where('is_active', true);

        if ($excludeScheduleId) {
            $query->where('id', '!=', $excludeScheduleId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'class_level_id' => 'This class already has a schedule at the same time slot.',
            ]);
        }
    }

    private function validateNoTeacherConflict(
        string $teacherId,
        string $dayOfWeek,
        string $timeSlotId,
        string $academicYearId,
        int $semester,
        ?string $excludeScheduleId = null,
    ): void {
        $conflict = $this->findTeacherConflict(
            $teacherId,
            $dayOfWeek,
            $timeSlotId,
            $academicYearId,
            $semester,
            $excludeScheduleId,
        );

        if ($conflict) {
            throw ValidationException::withMessages([
                'teacher_id' => "This teacher is already assigned to {$conflict->classLevel->label} at the same time slot.",
            ]);
        }
    }
}

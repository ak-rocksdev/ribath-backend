<?php

namespace App\Services;

use App\Models\School;
use App\Models\TeachingSchedule;
use Illuminate\Database\Eloquent\Collection;
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

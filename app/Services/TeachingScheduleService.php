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
            ->with([
                'subjectBook:id,title,subject_category_id,sessions_per_week',
                'subjectBook.subjectCategory:id,name,color',
                'teacher:id,full_name,code',
                'timeSlot:id,code,label,type,start_time,end_time,sort_order',
                'classLevel:id,slug,label,category',
                'academicYear:id,name',
            ]);

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

        $this->validateNoTeacherConflict(
            teacherId: $data['teacher_id'],
            dayOfWeek: $data['day_of_week'],
            timeSlotId: $data['time_slot_id'],
            academicYearId: $data['academic_year_id'],
            semester: (int) $data['semester'],
        );

        $schedule = TeachingSchedule::create($data);

        return $schedule->load([
            'subjectBook:id,title,subject_category_id,sessions_per_week',
            'subjectBook.subjectCategory:id,name,color',
            'teacher:id,full_name,code',
            'timeSlot:id,code,label,type,start_time,end_time,sort_order',
            'classLevel:id,slug,label,category',
            'academicYear:id,name',
        ]);
    }

    public function updateSchedule(TeachingSchedule $teachingSchedule, array $data): TeachingSchedule
    {
        $mergedData = array_merge($teachingSchedule->only([
            'teacher_id', 'day_of_week', 'time_slot_id', 'academic_year_id', 'semester',
        ]), $data);

        $this->validateNoTeacherConflict(
            teacherId: $mergedData['teacher_id'],
            dayOfWeek: $mergedData['day_of_week'],
            timeSlotId: $mergedData['time_slot_id'],
            academicYearId: $mergedData['academic_year_id'],
            semester: (int) $mergedData['semester'],
            excludeScheduleId: $teachingSchedule->id,
        );

        $teachingSchedule->update($data);

        return $teachingSchedule->fresh()->load([
            'subjectBook:id,title,subject_category_id,sessions_per_week',
            'subjectBook.subjectCategory:id,name,color',
            'teacher:id,full_name,code',
            'timeSlot:id,code,label,type,start_time,end_time,sort_order',
            'classLevel:id,slug,label,category',
            'academicYear:id,name',
        ]);
    }

    public function deleteSchedule(TeachingSchedule $teachingSchedule): void
    {
        $teachingSchedule->delete();
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

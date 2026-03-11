<?php

namespace App\Services;

use App\Exceptions\HasDependentsException;
use App\Models\School;
use App\Models\TimeSlot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class TimeSlotService
{
    public function listActive(): Collection
    {
        $school = School::activeOrFail();

        return TimeSlot::where('school_id', $school->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    public function listForAdmin(): Collection
    {
        $school = School::activeOrFail();

        $query = TimeSlot::where('school_id', $school->id)
            ->orderBy('sort_order');

        if (class_exists(\App\Models\TeachingSchedule::class)) {
            $query->withCount('teachingSchedules');
        }

        return $query->get();
    }

    public function createTimeSlot(array $data): TimeSlot
    {
        $school = School::activeOrFail();

        $data['school_id'] = $school->id;

        return TimeSlot::create($data);
    }

    public function updateTimeSlot(TimeSlot $timeSlot, array $data): TimeSlot
    {
        $timeSlot->update($data);

        return $timeSlot->fresh();
    }

    public function deleteTimeSlot(TimeSlot $timeSlot): void
    {
        if (class_exists(\App\Models\TeachingSchedule::class)) {
            if ($timeSlot->teachingSchedules()->exists()) {
                throw new HasDependentsException(
                    'Cannot delete time slot with existing teaching schedules'
                );
            }
        }

        $timeSlot->delete();
    }

    public function reorder(array $orderedIds): void
    {
        $school = School::activeOrFail();

        DB::transaction(function () use ($orderedIds, $school) {
            foreach ($orderedIds as $index => $id) {
                TimeSlot::where('id', $id)
                    ->where('school_id', $school->id)
                    ->update(['sort_order' => $index + 1]);
            }
        });
    }
}

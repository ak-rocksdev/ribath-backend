<?php

namespace App\Services;

use App\Exceptions\HasDependentsException;
use App\Models\AcademicYear;
use App\Models\School;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AcademicYearService
{
    public function listAll(): Collection
    {
        $school = School::activeOrFail();

        $query = AcademicYear::where('school_id', $school->id)
            ->orderByDesc('name');

        if (class_exists(\App\Models\TeachingSchedule::class)) {
            $query->withCount('teachingSchedules');
        }

        return $query->get();
    }

    public function getActive(): ?AcademicYear
    {
        $defaultSchool = School::where('is_active', true)->first();

        if (! $defaultSchool) {
            return null;
        }

        return AcademicYear::where('school_id', $defaultSchool->id)
            ->where('is_active', true)
            ->first();
    }

    public function createAcademicYear(array $data): AcademicYear
    {
        $school = School::activeOrFail();

        $data['school_id'] = $school->id;

        return AcademicYear::create($data);
    }

    public function updateAcademicYear(AcademicYear $academicYear, array $data): AcademicYear
    {
        $academicYear->update($data);

        return $academicYear->fresh();
    }

    public function deleteAcademicYear(AcademicYear $academicYear): void
    {
        // Check for teaching schedule dependents if the model/table exists
        if (class_exists(\App\Models\TeachingSchedule::class)) {
            if ($academicYear->teachingSchedules()->exists()) {
                throw new HasDependentsException(
                    'Cannot delete academic year with existing teaching schedules'
                );
            }
        }

        $academicYear->delete();
    }

    public function activate(AcademicYear $academicYear): AcademicYear
    {
        return DB::transaction(function () use ($academicYear) {
            // Deactivate all other academic years for the same school
            AcademicYear::where('school_id', $academicYear->school_id)
                ->where('id', '!=', $academicYear->id)
                ->update(['is_active' => false]);

            $academicYear->update(['is_active' => true]);

            return $academicYear->fresh();
        });
    }

    public function switchSemester(AcademicYear $academicYear, int $semester): AcademicYear
    {
        $academicYear->update(['active_semester' => $semester]);

        return $academicYear->fresh();
    }
}

<?php

namespace App\Services;

use App\Exceptions\HasDependentsException;
use App\Models\AcademicYear;
use App\Models\GradingTemplateFactor;
use App\Models\School;
use App\Models\TeachingSchedule;
use App\Services\Akademik\AcademicSemesterService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AcademicYearService
{
    /**
     * Tables holding grading data (Penilaian, Absensi Pertemuan, Hafalan,
     * Rapor) scoped to an academic year. Queried without Eloquent scopes so
     * soft-deleted rows count too: they still hold the restrict FK.
     */
    private const GRADING_DATA_TABLES = [
        'student_grades',
        'class_tasks',
        'class_sessions',
        'memorization_targets',
        'memorization_logs',
        'report_cards',
    ];

    public function __construct(
        private AcademicSemesterService $academicSemesterService,
    ) {}

    public function listAll(): Collection
    {
        $school = School::activeOrFail();

        $query = AcademicYear::where('school_id', $school->id)
            ->with('semesters')
            ->orderByDesc('name');

        if (class_exists(TeachingSchedule::class)) {
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
            ->with('semesters')
            ->first();
    }

    public function createAcademicYear(array $data): AcademicYear
    {
        $school = School::activeOrFail();

        $data['school_id'] = $school->id;

        return DB::transaction(function () use ($data) {
            $academicYear = AcademicYear::create($data);

            $this->academicSemesterService->createSemestersForAcademicYear($academicYear);

            return $academicYear;
        });
    }

    public function updateAcademicYear(AcademicYear $academicYear, array $data): AcademicYear
    {
        $academicYear->update($data);

        return $academicYear->fresh();
    }

    public function deleteAcademicYear(AcademicYear $academicYear): void
    {
        // Check for teaching schedule dependents if the model/table exists
        if (class_exists(TeachingSchedule::class)) {
            if ($academicYear->teachingSchedules()->exists()) {
                throw new HasDependentsException(
                    'Cannot delete academic year with existing teaching schedules'
                );
            }
        }

        DB::transaction(function () use ($academicYear) {
            if ($this->academicYearHasGradingData($academicYear)) {
                throw new HasDependentsException(
                    'Tahun ajaran tidak bisa dihapus karena sudah memiliki data penilaian.'
                );
            }

            // Semester weights are configuration only; academic_semesters
            // cascade on their own.
            GradingTemplateFactor::where('academic_year_id', $academicYear->id)->delete();

            $academicYear->delete();
        });
    }

    private function academicYearHasGradingData(AcademicYear $academicYear): bool
    {
        foreach (self::GRADING_DATA_TABLES as $gradingDataTable) {
            if (DB::table($gradingDataTable)->where('academic_year_id', $academicYear->id)->exists()) {
                return true;
            }
        }

        return false;
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

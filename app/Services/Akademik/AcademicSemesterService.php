<?php

namespace App\Services\Akademik;

use App\Models\AcademicSemester;
use App\Models\AcademicYear;
use App\Models\GradingTemplate;
use Illuminate\Database\Eloquent\Collection;

class AcademicSemesterService
{
    public function __construct(
        private GradingDefaultsInstaller $gradingDefaultsInstaller,
    ) {}

    /**
     * Creates semesters 1 and 2 for a newly created academic year.
     * Idempotent (firstOrCreate) so it is safe to call more than once for
     * the same academic year.
     *
     * If the school already has grading templates installed (Task 3's
     * Penilaian settings), each new semester also gets its
     * grading_template_factors weights populated — copied from the most
     * recent earlier semester, or defaults if none exists yet.
     */
    public function createSemestersForAcademicYear(AcademicYear $academicYear): Collection
    {
        $semesters = new Collection;

        $schoolHasGradingTemplates = GradingTemplate::where('school_id', $academicYear->school_id)->exists();

        foreach ([1, 2] as $semesterNumber) {
            $semester = AcademicSemester::firstOrCreate(
                [
                    'academic_year_id' => $academicYear->id,
                    'semester' => $semesterNumber,
                ],
                [
                    'school_id' => $academicYear->school_id,
                ]
            );

            $semesters->push($semester);

            if ($schoolHasGradingTemplates) {
                $this->gradingDefaultsInstaller->ensureWeightsForSemester($semester);
            }
        }

        return $semesters;
    }

    public function listForAcademicYear(AcademicYear $academicYear): Collection
    {
        return $academicYear->semesters()->get();
    }

    public function updateSemester(AcademicYear $academicYear, int $semester, array $data): AcademicSemester
    {
        $academicSemester = $this->findByPair($academicYear->id, $semester);

        abort_unless($academicSemester !== null, 404);

        $academicSemester->update($data);

        return $academicSemester->fresh();
    }

    public function findByPair(string $academicYearId, int $semester): ?AcademicSemester
    {
        return AcademicSemester::findByPair($academicYearId, $semester);
    }
}

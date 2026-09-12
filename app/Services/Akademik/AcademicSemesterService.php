<?php

namespace App\Services\Akademik;

use App\Models\AcademicSemester;
use App\Models\AcademicYear;
use Illuminate\Database\Eloquent\Collection;

class AcademicSemesterService
{
    /**
     * Creates semesters 1 and 2 for a newly created academic year.
     * Idempotent (firstOrCreate) so it is safe to call more than once for
     * the same academic year.
     */
    public function createSemestersForAcademicYear(AcademicYear $academicYear): Collection
    {
        $semesters = new Collection;

        foreach ([1, 2] as $semesterNumber) {
            $semesters->push(AcademicSemester::firstOrCreate(
                [
                    'academic_year_id' => $academicYear->id,
                    'semester' => $semesterNumber,
                ],
                [
                    'school_id' => $academicYear->school_id,
                ]
            ));
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

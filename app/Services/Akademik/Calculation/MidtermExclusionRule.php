<?php

namespace App\Services\Akademik\Calculation;

use App\Models\AcademicSemester;
use App\Models\GradingFactor;
use App\Models\Student;

/**
 * Which midterm (is_midterm_exam) factors are disabled — and therefore left
 * out of GradeWeightNormalizer — for a santri in a semester akademik:
 *
 *  - every midterm factor when the semester has uts_enabled = false;
 *  - otherwise, when midterm_exam_date is set and the santri's entry_date is
 *    after it (santri pindahan); an empty midterm date disables nothing.
 *
 * A template without a midterm factor (Tahfizh) is never touched. UAS never
 * replaces UTS: the disabled weight is redistributed by normalization.
 *
 * Pure: it only reads the attributes of the models it is given, never queries.
 */
final class MidtermExclusionRule
{
    /**
     * @param  iterable<GradingFactor>  $factors
     * @return array<int, string> codes of the disabled midterm factors, in input order
     */
    public function disabledFactorCodesFor(AcademicSemester $academicSemester, Student $student, iterable $factors): array
    {
        if (! $academicSemester->uts_enabled || $this->enteredAfterMidtermExam($academicSemester, $student)) {
            return $this->midtermFactorCodes($factors);
        }

        return [];
    }

    /**
     * The semester-wide part of the rule (uts_enabled only), for headers
     * that describe the class as a whole rather than one santri.
     *
     * @param  iterable<GradingFactor>  $factors
     * @return array<int, string>
     */
    public function disabledFactorCodesForSemester(AcademicSemester $academicSemester, iterable $factors): array
    {
        return $academicSemester->uts_enabled ? [] : $this->midtermFactorCodes($factors);
    }

    private function enteredAfterMidtermExam(AcademicSemester $academicSemester, Student $student): bool
    {
        $midtermExamDate = $academicSemester->midterm_exam_date;
        $entryDate = $student->entry_date;

        if ($midtermExamDate === null || $entryDate === null) {
            return false;
        }

        return $entryDate->toDateString() > $midtermExamDate->toDateString();
    }

    /**
     * @param  iterable<GradingFactor>  $factors
     * @return array<int, string>
     */
    private function midtermFactorCodes(iterable $factors): array
    {
        $midtermFactorCodes = [];

        foreach ($factors as $factor) {
            if ($factor->is_midterm_exam) {
                $midtermFactorCodes[] = $factor->code;
            }
        }

        return $midtermFactorCodes;
    }
}

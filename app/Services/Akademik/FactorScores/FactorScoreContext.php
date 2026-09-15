<?php

namespace App\Services\Akademik\FactorScores;

use App\Models\AcademicSemester;
use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * What a FactorScoreProvider needs to score one factor for a set of santri:
 * the semester akademik (pair + its row), the kitab, the class (NULL for a
 * per-santri recap spanning classes) and the santri themselves.
 */
final readonly class FactorScoreContext
{
    /**
     * @param  Collection<int, Student>  $students  with at least id and entry_date loaded
     */
    public function __construct(
        public AcademicSemester $academicSemester,
        public string $academicYearId,
        public int $semester,
        public ?string $classLevelId,
        public string $subjectBookId,
        public Collection $students,
    ) {}
}

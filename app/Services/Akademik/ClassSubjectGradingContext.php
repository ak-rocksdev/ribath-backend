<?php

namespace App\Services\Akademik;

use App\Models\AcademicSemester;
use App\Models\GradingTemplateFactor;
use App\Models\SubjectBook;
use Illuminate\Support\Collection;

/**
 * A validated Kelas × Kitab selection in one semester akademik, as resolved
 * by StudentGradeService::resolveClassSubjectContext(): the kitab (with its
 * grading template), the academic_semesters row, and the template's weight
 * rows for that semester (with gradingFactor, ordered by sort_order).
 */
final readonly class ClassSubjectGradingContext
{
    /**
     * @param  Collection<int, GradingTemplateFactor>  $templateFactors
     */
    public function __construct(
        public SubjectBook $subjectBook,
        public AcademicSemester $academicSemester,
        public Collection $templateFactors,
        public string $classLevelId,
    ) {}
}

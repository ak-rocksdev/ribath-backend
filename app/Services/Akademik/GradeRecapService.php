<?php

namespace App\Services\Akademik;

use App\Models\AcademicSemester;
use App\Models\ClassLevel;
use App\Models\GradingFactor;
use App\Models\GradingTemplateFactor;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Services\Akademik\Calculation\FinalGradeCalculator;
use App\Services\Akademik\Calculation\FinalGradeResult;
use App\Services\Akademik\Calculation\GradeWeightNormalizer;
use App\Services\Akademik\Calculation\MidtermExclusionRule;
use App\Services\Akademik\FactorScores\FactorScore;
use App\Services\Akademik\FactorScores\FactorScoreContext;
use App\Services\Akademik\FactorScores\FactorScoreProviderRegistry;
use App\Services\Akademik\FactorScores\FactorScoreSource;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Live grade recap (Rekap Nilai), computed from the semester's own weights:
 * per santri, every factor of the kitab's template with its score, source,
 * original and normalized weight, whether it counts and whether it is
 * missing; plus the final score (NULL while any counted factor is empty).
 *
 * Scores of manual factors (manual_once, end_of_semester_bulk) come from
 * student_grades; every other factor (Tugas, Absensi, Tahfizh) comes from
 * the FactorScoreProviderRegistry. The arithmetic lives in the pure
 * calculators (MidtermExclusionRule → GradeWeightNormalizer →
 * FinalGradeCalculator) so a finalized rapor can snapshot it (ADR 0001).
 */
class GradeRecapService
{
    /** Normalized weights are kept unrounded internally and rounded only here, in the payload. */
    public const NORMALIZED_WEIGHT_DECIMALS = 2;

    public const MESSAGE_STUDENT_WITHOUT_CLASS = 'Santri belum memiliki kelas.';

    public function __construct(
        private StudentGradeService $studentGradeService,
        private GradableSubjectService $gradableSubjectService,
        private FactorScoreProviderRegistry $factorScoreProviderRegistry,
        private MidtermExclusionRule $midtermExclusionRule,
        private GradeWeightNormalizer $gradeWeightNormalizer,
        private FinalGradeCalculator $finalGradeCalculator,
    ) {}

    /**
     * Recap of one Kelas × Kitab in one semester akademik.
     *
     * @return array<string, mixed> see the "recap response shape" in the Task 6 report
     *
     * @throws ValidationException same rejections and messages as the grade grid
     */
    public function recapForClassSubject(string $academicYearId, int $semester, string $classLevelId, string $subjectBookId): array
    {
        $gradingContext = $this->studentGradeService->resolveClassSubjectContext($academicYearId, $semester, $classLevelId, $subjectBookId);
        $academicSemester = $gradingContext->academicSemester;
        $templateFactors = $gradingContext->templateFactors;
        $gradingFactors = $templateFactors->map(fn (GradingTemplateFactor $templateFactor) => $templateFactor->gradingFactor);

        $students = $this->studentGradeService->listClassStudents($classLevelId);

        $factorScoreContext = new FactorScoreContext(
            academicSemester: $academicSemester,
            academicYearId: $academicYearId,
            semester: $semester,
            classLevelId: $classLevelId,
            subjectBookId: $subjectBookId,
            students: $students,
        );
        $rows = $this->buildFactorRows($gradingContext, $factorScoreContext);

        $semesterNormalizedWeights = $this->gradeWeightNormalizer->normalize(
            $this->weightInputs($templateFactors),
            $this->midtermExclusionRule->disabledFactorCodesForSemester($academicSemester, $gradingFactors),
        );

        $classLevel = ClassLevel::where('school_id', School::activeOrFail()->id)->findOrFail($classLevelId);
        $subjectBook = $gradingContext->subjectBook;
        $gradingTemplate = $subjectBook->gradingTemplate;

        return [
            'academic_year_id' => $academicYearId,
            'semester' => $semester,
            'class_level' => [
                'id' => $classLevel->id,
                'slug' => $classLevel->slug,
                'label' => $classLevel->label,
            ],
            'subject_book' => [
                'id' => $subjectBook->id,
                'title' => $subjectBook->title,
            ],
            'grading_template' => [
                'id' => $gradingTemplate->id,
                'code' => $gradingTemplate->code,
                'name' => $gradingTemplate->name,
            ],
            'uts_enabled' => $academicSemester->uts_enabled,
            'midterm_exam_date' => $academicSemester->midterm_exam_date?->toDateString(),
            'factors' => $templateFactors
                ->map(fn (GradingTemplateFactor $templateFactor) => $this->presentHeaderFactor($templateFactor, $semesterNormalizedWeights))
                ->all(),
            'rows' => $rows,
            'summary' => [
                'student_count' => count($rows),
                'complete_count' => collect($rows)->where('is_complete', true)->count(),
            ],
        ];
    }

    /**
     * Recap of every gradable kitab of one santri's class, in one semester
     * akademik: for each Kelas × Kitab pair the class is scheduled for
     * (GradableSubjectService), the same per-factor breakdown as the class
     * recap restricted to this one santri, plus an overall `is_complete`
     * (every gradable subject complete, and at least one exists).
     *
     * A kitab without a grading template is still listed (`is_gradable:
     * false`, no factors) rather than failing the whole recap.
     *
     * @return array<string, mixed> see the "recapForStudent" shape in the Task 9 report
     *
     * @throws ValidationException MESSAGE_STUDENT_WITHOUT_CLASS, or the same rejections as recapForClassSubject
     */
    public function recapForStudent(Student $student, string $academicYearId, int $semester): array
    {
        if ($student->class_level_id === null) {
            throw ValidationException::withMessages(['class_level_id' => self::MESSAGE_STUDENT_WITHOUT_CLASS]);
        }

        $pairs = $this->gradableSubjectService->listForSemester($academicYearId, $semester, $student->class_level_id);

        $subjects = collect($pairs)
            ->map(fn (array $pair) => $this->buildStudentSubjectRow($student, $academicYearId, $semester, $pair))
            ->values()
            ->all();

        $gradableSubjects = collect($subjects)->where('is_gradable', true);

        return [
            'student' => $this->presentRecapStudent($student),
            'academic_year_id' => $academicYearId,
            'semester' => $semester,
            'uts_enabled' => (bool) (AcademicSemester::findByPair($academicYearId, $semester)?->uts_enabled),
            'subjects' => $subjects,
            'is_complete' => $gradableSubjects->isNotEmpty() && $gradableSubjects->every(fn (array $subject) => $subject['is_complete']),
        ];
    }

    /**
     * One row of recapForStudent's `subjects`: a gradable pair reuses the
     * class recap's own factor-row builder restricted to this one santri;
     * an ungradable pair (kitab without a template) is presented empty
     * rather than failing the whole recap.
     *
     * @param  array<string, mixed>  $pair  one GradableSubjectService::listForSemester() entry
     * @return array<string, mixed>
     */
    private function buildStudentSubjectRow(Student $student, string $academicYearId, int $semester, array $pair): array
    {
        if (! $pair['is_gradable']) {
            return [
                'subject_book' => $pair['subject_book'],
                'grading_template' => null,
                'is_gradable' => false,
                'factors' => [],
                'final_score' => null,
                'missing_factor_codes' => [],
                'is_complete' => false,
                'midterm_excluded' => false,
            ];
        }

        $gradingContext = $this->studentGradeService->resolveClassSubjectContext(
            $academicYearId,
            $semester,
            $pair['class_level_id'],
            $pair['subject_book_id'],
        );

        $factorScoreContext = new FactorScoreContext(
            academicSemester: $gradingContext->academicSemester,
            academicYearId: $academicYearId,
            semester: $semester,
            classLevelId: $pair['class_level_id'],
            subjectBookId: $pair['subject_book_id'],
            students: collect([$student]),
        );

        $row = $this->buildFactorRows($gradingContext, $factorScoreContext)[0];

        return [
            'subject_book' => $pair['subject_book'],
            'grading_template' => $pair['grading_template'],
            'is_gradable' => true,
            'factors' => $row['factors'],
            'final_score' => $row['final_score'],
            'missing_factor_codes' => $row['missing_factor_codes'],
            'is_complete' => $row['is_complete'],
            'midterm_excluded' => $row['midterm_excluded'],
        ];
    }

    /**
     * Every student row of one Kelas × Kitab context — the class recap for
     * its whole class, a per-santri recap for a one-student context.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildFactorRows(ClassSubjectGradingContext $gradingContext, FactorScoreContext $factorScoreContext): array
    {
        $factorScoresByCode = $this->collectFactorScores($gradingContext->templateFactors, $factorScoreContext);

        return $factorScoreContext->students
            ->map(fn (Student $student) => $this->buildStudentRow($student, $gradingContext, $factorScoresByCode))
            ->values()
            ->all();
    }

    /**
     * Scores of every template factor for every santri of the context:
     * manual factors from student_grades (one query), the others from the
     * provider registry. A santri without a value gets FactorScore(null).
     *
     * @param  Collection<int, GradingTemplateFactor>  $templateFactors
     * @return array<string, array<string, FactorScore>> factor code => student id => score
     */
    private function collectFactorScores(Collection $templateFactors, FactorScoreContext $factorScoreContext): array
    {
        [$manualTemplateFactors, $providedTemplateFactors] = $templateFactors->partition(
            fn (GradingTemplateFactor $templateFactor) => in_array($templateFactor->gradingFactor->input_type, StudentGradeService::MANUAL_INPUT_TYPES, true),
        );

        $manualScoresByStudentAndFactorId = StudentGrade::query()
            ->where('school_id', School::activeOrFail()->id)
            ->where('subject_book_id', $factorScoreContext->subjectBookId)
            ->where('academic_year_id', $factorScoreContext->academicYearId)
            ->where('semester', $factorScoreContext->semester)
            ->whereIn('student_id', $factorScoreContext->students->pluck('id'))
            ->whereIn('grading_factor_id', $manualTemplateFactors->pluck('grading_factor_id'))
            ->get(['student_id', 'grading_factor_id', 'score'])
            ->mapWithKeys(fn (StudentGrade $grade) => [
                $grade->student_id.'|'.$grade->grading_factor_id => $grade->score === null ? null : (float) $grade->score,
            ]);

        $factorScoresByCode = [];

        foreach ($manualTemplateFactors as $templateFactor) {
            foreach ($factorScoreContext->students as $student) {
                $factorScoresByCode[$templateFactor->gradingFactor->code][$student->id] = new FactorScore(
                    $manualScoresByStudentAndFactorId->get($student->id.'|'.$templateFactor->grading_factor_id),
                );
            }
        }

        foreach ($providedTemplateFactors as $templateFactor) {
            $factorScoresByCode[$templateFactor->gradingFactor->code] = $this->factorScoreProviderRegistry
                ->scoresFor($templateFactor->gradingFactor, $factorScoreContext);
        }

        return $factorScoresByCode;
    }

    /**
     * @param  array<string, array<string, FactorScore>>  $factorScoresByCode
     * @return array<string, mixed>
     */
    private function buildStudentRow(Student $student, ClassSubjectGradingContext $gradingContext, array $factorScoresByCode): array
    {
        $templateFactors = $gradingContext->templateFactors;

        $disabledFactorCodes = $this->midtermExclusionRule->disabledFactorCodesFor(
            $gradingContext->academicSemester,
            $student,
            $templateFactors->map(fn (GradingTemplateFactor $templateFactor) => $templateFactor->gradingFactor),
        );
        $normalizedWeights = $this->gradeWeightNormalizer->normalize($this->weightInputs($templateFactors), $disabledFactorCodes);

        $studentFactorScores = [];
        foreach ($templateFactors as $templateFactor) {
            $code = $templateFactor->gradingFactor->code;
            $studentFactorScores[$code] = $factorScoresByCode[$code][$student->id] ?? new FactorScore(null);
        }

        $finalGrade = $this->finalGradeCalculator->calculate(
            array_map(fn (FactorScore $factorScore) => $factorScore->score, $studentFactorScores),
            $normalizedWeights,
        );

        // Only a midterm factor that would otherwise have counted is worth explaining.
        $activeFactorCodes = $templateFactors
            ->filter(fn (GradingTemplateFactor $templateFactor) => $templateFactor->is_active)
            ->map(fn (GradingTemplateFactor $templateFactor) => $templateFactor->gradingFactor->code)
            ->all();

        return [
            'student' => $this->studentGradeService->presentClassStudent($student),
            'midterm_excluded' => array_intersect($disabledFactorCodes, $activeFactorCodes) !== [],
            'factors' => $templateFactors
                ->map(fn (GradingTemplateFactor $templateFactor) => $this->presentStudentFactor(
                    $templateFactor,
                    $studentFactorScores[$templateFactor->gradingFactor->code],
                    $finalGrade,
                ))
                ->all(),
            'final_score' => $finalGrade->finalScore,
            'missing_factor_codes' => $finalGrade->missingFactorCodes,
            'is_complete' => $finalGrade->isComplete(),
        ];
    }

    /**
     * @param  Collection<int, GradingTemplateFactor>  $templateFactors
     * @return array<int, array{code: string, weight: float, is_active: bool}>
     */
    private function weightInputs(Collection $templateFactors): array
    {
        return $templateFactors
            ->map(fn (GradingTemplateFactor $templateFactor) => [
                'code' => $templateFactor->gradingFactor->code,
                'weight' => (float) $templateFactor->weight,
                'is_active' => (bool) $templateFactor->is_active,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentStudentFactor(GradingTemplateFactor $templateFactor, FactorScore $factorScore, FinalGradeResult $finalGrade): array
    {
        $factor = $templateFactor->gradingFactor;
        $normalizedWeight = $finalGrade->normalizedWeights[$factor->code] ?? null;

        return [
            'code' => $factor->code,
            'name' => $factor->name,
            'score' => $factorScore->score,
            'source' => FactorScoreSource::forInputType($factor->input_type)->value,
            'weight' => (float) $templateFactor->weight,
            'normalized_weight' => $this->roundNormalizedWeight($normalizedWeight),
            'is_active' => $normalizedWeight !== null,
            'is_missing' => in_array($factor->code, $finalGrade->missingFactorCodes, true),
            'missing_reason' => $factorScore->missingReason,
        ];
    }

    /**
     * @param  array<string, float>  $semesterNormalizedWeights
     * @return array<string, mixed>
     */
    private function presentHeaderFactor(GradingTemplateFactor $templateFactor, array $semesterNormalizedWeights): array
    {
        /** @var GradingFactor $factor */
        $factor = $templateFactor->gradingFactor;

        return [
            'grading_factor_id' => $factor->id,
            'code' => $factor->code,
            'name' => $factor->name,
            'input_type' => $factor->input_type,
            'score_scale' => $factor->score_scale,
            'source' => FactorScoreSource::forInputType($factor->input_type)->value,
            'is_midterm_exam' => $factor->is_midterm_exam,
            'sort_order' => $factor->sort_order,
            'weight' => (float) $templateFactor->weight,
            'is_active' => (bool) $templateFactor->is_active,
            'normalized_weight' => $this->roundNormalizedWeight($semesterNormalizedWeights[$factor->code] ?? null),
        ];
    }

    private function roundNormalizedWeight(?float $normalizedWeight): ?float
    {
        return $normalizedWeight === null ? null : round($normalizedWeight, self::NORMALIZED_WEIGHT_DECIMALS);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRecapStudent(Student $student): array
    {
        $classLevel = $student->class_level_id === null
            ? null
            : ClassLevel::where('school_id', School::activeOrFail()->id)->find($student->class_level_id);

        return [
            'id' => $student->id,
            'full_name' => $student->full_name,
            'class_level' => $classLevel === null ? null : [
                'id' => $classLevel->id,
                'label' => $classLevel->label,
            ],
            'entry_date' => $student->entry_date?->toDateString(),
            'is_active_student' => $student->status === Student::STATUS_ACTIVE,
        ];
    }
}

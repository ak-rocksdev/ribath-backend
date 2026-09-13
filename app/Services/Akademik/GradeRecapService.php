<?php

namespace App\Services\Akademik;

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

    public function __construct(
        private StudentGradeService $studentGradeService,
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
        $factorScoresByCode = $this->collectFactorScores($templateFactors, $factorScoreContext);

        $rows = $students
            ->map(fn (Student $student) => $this->buildStudentRow($student, $gradingContext, $factorScoresByCode))
            ->values()
            ->all();

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
}

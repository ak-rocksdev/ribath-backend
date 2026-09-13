<?php

namespace App\Services\Akademik;

use App\Models\AcademicSemester;
use App\Models\ClassLevel;
use App\Models\GradingFactor;
use App\Models\GradingTemplate;
use App\Models\GradingTemplateFactor;
use App\Models\MemorizationTarget;
use App\Models\ReportCard;
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
        private ReportCardSnapshot $reportCardSnapshot,
    ) {}

    /**
     * Recap of one Kelas × Kitab in one semester akademik. A santri whose
     * Rapor is final gets the frozen row instead of the live one
     * (substituteFinalizedRows); every row carries `is_finalized`,
     * `is_snapshot` and `finalized_at`.
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

        $students = $this->studentGradeService->listGradedStudents($gradingContext);

        $factorScoreContext = new FactorScoreContext(
            academicSemester: $academicSemester,
            academicYearId: $academicYearId,
            semester: $semester,
            classLevelId: $classLevelId,
            subjectBookId: $subjectBookId,
            students: $students,
        );
        $rows = $this->substituteFinalizedRows(
            $this->buildFactorRows($academicSemester, $templateFactors, $factorScoreContext),
            $academicYearId,
            $semester,
            $subjectBookId,
        );

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
     * Recap of every gradable kitab of one santri in one semester akademik.
     *
     * A santri whose Rapor is final for the semester gets the snapshot
     * (ADR 0001, spec US90): same shape, `subjects` built from the frozen
     * report_card_entries, `is_finalized: true` with `finalized_at` and
     * `finalized_by_name`. Everyone else gets the live recap
     * (liveRecapForStudent) with `is_finalized: false`; `report_card_id` is
     * the rapor's id whenever one exists (final or draft), else null.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException see liveRecapForStudent()
     */
    public function recapForStudent(Student $student, string $academicYearId, int $semester): array
    {
        $reportCard = ReportCard::query()
            ->where('school_id', School::activeOrFail()->id)
            ->where('student_id', $student->id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->first();

        if ($reportCard?->isFinal()) {
            $reportCard->load(['entries', 'finalizer:id,name']);

            return [
                'student' => $this->presentRecapStudent($student),
                'academic_year_id' => $academicYearId,
                'semester' => $semester,
                'uts_enabled' => (bool) $this->reportCardSnapshot->utsEnabled($reportCard),
                'subjects' => $this->reportCardSnapshot->subjects($reportCard),
                'is_complete' => true,
                'is_finalized' => true,
                'report_card_id' => $reportCard->id,
                'finalized_at' => $reportCard->finalized_at?->toJSON(),
                'finalized_by_name' => $reportCard->finalizer?->name,
            ];
        }

        return array_merge($this->liveRecapForStudent($student, $academicYearId, $semester), [
            'is_finalized' => false,
            'report_card_id' => $reportCard?->id,
            'finalized_at' => null,
            'finalized_by_name' => null,
        ]);
    }

    /**
     * The LIVE recap of every gradable kitab of one santri's class, in one
     * semester akademik, whatever the santri's Rapor status: for each
     * Kelas × Kitab pair the class is gradable for (GradableSubjectService),
     * the same per-factor breakdown as the class recap restricted to this
     * one santri, plus an overall `is_complete` (every gradable subject
     * complete, and at least one exists). This is what finalization freezes.
     *
     * A kitab without a grading template is still listed (`is_gradable:
     * false`, no factors) rather than failing the whole recap.
     *
     * @return array{student: array<string, mixed>, academic_year_id: string, semester: int, uts_enabled: bool, subjects: array<int, array<string, mixed>>, is_complete: bool}
     *
     * @throws ValidationException MESSAGE_STUDENT_WITHOUT_CLASS, or the same rejections as recapForClassSubject
     */
    public function liveRecapForStudent(Student $student, string $academicYearId, int $semester): array
    {
        if ($student->class_level_id === null) {
            throw ValidationException::withMessages(['class_level_id' => self::MESSAGE_STUDENT_WITHOUT_CLASS]);
        }

        $classRecap = $this->liveSubjectsForClassStudents(collect([$student]), $student->class_level_id, $academicYearId, $semester);

        return [
            'student' => $this->presentRecapStudent($student),
            'academic_year_id' => $academicYearId,
            'semester' => $semester,
            'uts_enabled' => $classRecap['uts_enabled'],
            'subjects' => $classRecap['subjects_by_student_id'][$student->id],
            'is_complete' => $classRecap['is_complete_by_student_id'][$student->id],
        ];
    }

    /**
     * liveRecapForStudent()'s `subjects` and `is_complete` for several santri
     * of ONE class at once, batched per kitab: every factor score is fetched
     * once per kitab for all of them (one FactorScoreContext per kitab), so
     * the query count grows with the number of kitab, not of santri.
     *
     * The Tahfizh pair (ADR 0003) only belongs to santri with a
     * non-deleted Target Hafalan for the semester; the others don't get it.
     *
     * @param  Collection<int, Student>  $students  santri of $classLevelId
     * @return array{uts_enabled: bool, subjects_by_student_id: array<string, array<int, array<string, mixed>>>, is_complete_by_student_id: array<string, bool>}
     *
     * @throws ValidationException MESSAGE_SEMESTER_NOT_CONFIGURED
     */
    public function liveSubjectsForClassStudents(Collection $students, string $classLevelId, string $academicYearId, int $semester): array
    {
        $pairs = $this->gradableSubjectService->listForSemester($academicYearId, $semester, $classLevelId);
        $rostersByPairIndex = $this->gradedRostersByPairIndex($pairs, $students, $academicYearId, $semester);

        // Resolved once for the whole class, then handed to every kitab
        // below — resolveClassSubjectContext() would otherwise re-fetch the
        // same academic_semesters row and re-run a redundant isGradablePair()
        // check (pairs already came from the same active-schedules source)
        // once per kitab.
        $academicSemester = null;
        $templateFactorsByTemplateId = [];

        if ($rostersByPairIndex !== []) {
            $academicSemester = AcademicSemester::findByPair($academicYearId, $semester);

            if ($academicSemester === null) {
                throw ValidationException::withMessages(['semester' => StudentGradeService::MESSAGE_SEMESTER_NOT_CONFIGURED]);
            }

            // One query for every distinct template among this class's
            // kitab (almost always 1-2: teori_kitab, tahfizh) instead of
            // one query per kitab.
            $templateFactorsByTemplateId = $this->loadTemplateFactorsByTemplateId(
                $academicYearId,
                $semester,
                collect($rostersByPairIndex)->keys()->map(fn (int $pairIndex) => $pairs[$pairIndex]['grading_template']['id'])->unique()->values()->all(),
            );
        }

        $rowsByPairIndex = [];
        foreach ($rostersByPairIndex as $pairIndex => $roster) {
            $pair = $pairs[$pairIndex];

            /** @var Collection<int, GradingTemplateFactor> $templateFactors */
            $templateFactors = $templateFactorsByTemplateId[$pair['grading_template']['id']] ?? collect();

            if ($templateFactors->isEmpty()) {
                throw ValidationException::withMessages(['semester' => StudentGradeService::MESSAGE_SEMESTER_NOT_CONFIGURED]);
            }

            $factorScoreContext = new FactorScoreContext(
                academicSemester: $academicSemester,
                academicYearId: $academicYearId,
                semester: $semester,
                classLevelId: $pair['class_level_id'],
                subjectBookId: $pair['subject_book_id'],
                students: $roster,
            );

            $rowsByPairIndex[$pairIndex] = collect($this->buildFactorRows($academicSemester, $templateFactors, $factorScoreContext))
                ->keyBy(fn (array $row) => $row['student']['id']);
        }

        $subjectsByStudentId = [];
        $isCompleteByStudentId = [];

        foreach ($students as $student) {
            $subjects = [];

            foreach ($pairs as $pairIndex => $pair) {
                if (! $pair['is_gradable']) {
                    $subjects[] = $this->presentUngradableSubject($pair);

                    continue;
                }

                $row = isset($rowsByPairIndex[$pairIndex]) ? $rowsByPairIndex[$pairIndex]->get($student->id) : null;

                if ($row !== null) {
                    $subjects[] = $this->presentGradableSubject($pair, $row);
                }
            }

            $gradableSubjects = collect($subjects)->where('is_gradable', true);
            $subjectsByStudentId[$student->id] = $subjects;
            $isCompleteByStudentId[$student->id] = $gradableSubjects->isNotEmpty()
                && $gradableSubjects->every(fn (array $subject) => $subject['is_complete']);
        }

        return [
            'uts_enabled' => (bool) $academicSemester?->uts_enabled,
            'subjects_by_student_id' => $subjectsByStudentId,
            'is_complete_by_student_id' => $isCompleteByStudentId,
        ];
    }

    /**
     * Who is graded for each gradable pair among $students: every one of
     * them for a regular kitab; for the Tahfizh kitab only those with a
     * non-deleted Target Hafalan for the semester (ADR 0003 — the class may
     * be gradable via a classmate's target). Pairs nobody is graded for are
     * left out. A class with no Tahfizh pair skips the target query.
     *
     * @param  array<int, array<string, mixed>>  $pairs
     * @param  Collection<int, Student>  $students
     * @return array<int, Collection<int, Student>> pair index => roster
     */
    private function gradedRostersByPairIndex(array $pairs, Collection $students, string $academicYearId, int $semester): array
    {
        $isTahfizhPair = fn (array $pair) => ($pair['grading_template']['code'] ?? null) === GradingTemplate::CODE_TAHFIZH;
        $studentIdsWithTarget = collect();

        if ($students->isNotEmpty() && collect($pairs)->contains($isTahfizhPair)) {
            $studentIdsWithTarget = MemorizationTarget::query()
                ->where('school_id', School::activeOrFail()->id)
                ->whereIn('student_id', $students->pluck('id'))
                ->where('academic_year_id', $academicYearId)
                ->where('semester', $semester)
                ->pluck('student_id')
                ->flip();
        }

        $rostersByPairIndex = [];
        foreach ($pairs as $pairIndex => $pair) {
            if (! $pair['is_gradable']) {
                continue;
            }

            $roster = $isTahfizhPair($pair)
                ? $students->filter(fn (Student $student) => $studentIdsWithTarget->has($student->id))->values()
                : $students->values();

            if ($roster->isNotEmpty()) {
                $rostersByPairIndex[$pairIndex] = $roster;
            }
        }

        return $rostersByPairIndex;
    }

    /**
     * @param  array<string, mixed>  $pair  one GradableSubjectService::listForSemester() entry
     * @return array<string, mixed>
     */
    private function presentUngradableSubject(array $pair): array
    {
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

    /**
     * @param  array<string, mixed>  $pair  one GradableSubjectService::listForSemester() entry
     * @param  array<string, mixed>  $row  one buildFactorRows() row
     * @return array<string, mixed>
     */
    private function presentGradableSubject(array $pair, array $row): array
    {
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
     * Class recap rows with each finalized santri's row read from its Rapor
     * snapshot (ADR 0001, spec US90). Every row gets `is_finalized` (the
     * santri's rapor is final), `is_snapshot` (the row comes from the
     * snapshot) and `finalized_at`. A finalized santri whose snapshot has no
     * entry for this kitab (e.g. the kitab was scheduled after
     * finalization) keeps the live row, flagged `is_finalized: true,
     * is_snapshot: false`. One query for all santri.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function substituteFinalizedRows(array $rows, string $academicYearId, int $semester, string $subjectBookId): array
    {
        $finalReportCardsByStudentId = ReportCard::query()
            ->where('school_id', School::activeOrFail()->id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->where('status', ReportCard::STATUS_FINAL)
            ->whereIn('student_id', collect($rows)->pluck('student.id'))
            ->with(['entries' => fn ($query) => $query->where('subject_book_id', $subjectBookId)])
            ->get()
            ->keyBy('student_id');

        return collect($rows)
            ->map(function (array $row) use ($finalReportCardsByStudentId) {
                /** @var ReportCard|null $reportCard */
                $reportCard = $finalReportCardsByStudentId->get($row['student']['id']);
                $entry = $reportCard?->entries->first();

                if ($reportCard !== null && $entry !== null) {
                    return $this->reportCardSnapshot->classRowFromEntry($row['student'], $entry, $reportCard);
                }

                return array_merge($row, [
                    'is_finalized' => $reportCard !== null,
                    'is_snapshot' => false,
                    'finalized_at' => $reportCard?->finalized_at?->toJSON(),
                ]);
            })
            ->values()
            ->all();
    }

    /**
     * GradingTemplateFactor (+ gradingFactor) rows for several templates in
     * one (academic_year_id, semester), grouped by grading_template_id and
     * sorted by sort_order — one query for every distinct template a
     * per-santri recap's kitab use, instead of one per kitab.
     *
     * @param  array<int, string>  $gradingTemplateIds
     * @return array<string, Collection<int, GradingTemplateFactor>>
     */
    private function loadTemplateFactorsByTemplateId(string $academicYearId, int $semester, array $gradingTemplateIds): array
    {
        if ($gradingTemplateIds === []) {
            return [];
        }

        return GradingTemplateFactor::query()
            ->where('school_id', School::activeOrFail()->id)
            ->whereIn('grading_template_id', $gradingTemplateIds)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->with('gradingFactor')
            ->get()
            ->groupBy('grading_template_id')
            ->map(fn (Collection $templateFactors) => $templateFactors
                ->sortBy(fn (GradingTemplateFactor $templateFactor) => $templateFactor->gradingFactor->sort_order)
                ->values())
            ->all();
    }

    /**
     * Every student row of one Kelas × Kitab context — the class recap for
     * its whole class, a per-santri recap for a one-student context.
     *
     * @param  Collection<int, GradingTemplateFactor>  $templateFactors
     * @return array<int, array<string, mixed>>
     */
    private function buildFactorRows(AcademicSemester $academicSemester, Collection $templateFactors, FactorScoreContext $factorScoreContext): array
    {
        $factorScoresByCode = $this->collectFactorScores($templateFactors, $factorScoreContext);

        return $factorScoreContext->students
            ->map(fn (Student $student) => $this->buildStudentRow($student, $academicSemester, $templateFactors, $factorScoresByCode))
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
     * @param  Collection<int, GradingTemplateFactor>  $templateFactors
     * @param  array<string, array<string, FactorScore>>  $factorScoresByCode
     * @return array<string, mixed>
     */
    private function buildStudentRow(Student $student, AcademicSemester $academicSemester, Collection $templateFactors, array $factorScoresByCode): array
    {
        $disabledFactorCodes = $this->midtermExclusionRule->disabledFactorCodesFor(
            $academicSemester,
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
            'is_midterm_exam' => $factor->is_midterm_exam,
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

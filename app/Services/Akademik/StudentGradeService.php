<?php

namespace App\Services\Akademik;

use App\Exceptions\FinalizedReportCardException;
use App\Models\AcademicSemester;
use App\Models\ClassLevel;
use App\Models\GradingFactor;
use App\Models\GradingTemplate;
use App\Models\GradingTemplateFactor;
use App\Models\MemorizationTarget;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\SubjectBook;
use App\Services\Akademik\Validation\PercentScoreValidator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grade grid (Input Nilai) for one Kelas × Kitab in one semester akademik:
 * reads the class's students, the kitab template's manual factors and the
 * stored student_grades, and bulk-upserts manually entered scores.
 *
 * Bulk writes are all-or-nothing: every cell is validated first and errors
 * are keyed "<student_id>" (student-level) or "<student_id>.<factor_code>"
 * (cell-level); only then does one transaction write the rows.
 */
class StudentGradeService
{
    /** Factor input types whose scores are stored in student_grades. */
    public const MANUAL_INPUT_TYPES = [
        GradingFactor::INPUT_TYPE_MANUAL_ONCE,
        GradingFactor::INPUT_TYPE_END_OF_SEMESTER_BULK,
    ];

    public const MESSAGE_SEMESTER_NOT_CONFIGURED = 'Semester akademik belum dikonfigurasi.';

    public const MESSAGE_BOOK_WITHOUT_TEMPLATE = 'Kitab ini belum memiliki template penilaian.';

    public const MESSAGE_PAIR_NOT_SCHEDULED = 'Kitab ini tidak dijadwalkan untuk kelas tersebut pada semester ini.';

    public const MESSAGE_INVALID_LEVEL = 'Level harus bilangan bulat 1–4.';

    public const MESSAGE_STUDENT_WITHOUT_TARGET = 'Santri ini belum punya Target Hafalan semester ini.';

    public function __construct(
        private GradableSubjectService $gradableSubjectService,
        private FinalizedReportCardGuard $finalizedReportCardGuard,
    ) {}

    /**
     * @return array{
     *     academic_year_id: string,
     *     semester: int,
     *     class_level: array{id: string, slug: string, label: string},
     *     subject_book: array{id: string, title: string},
     *     grading_template: array{id: string, code: string, name: string},
     *     factors: array<int, array<string, mixed>>,
     *     students: array<int, array<string, mixed>>,
     *     grades: array<string, array<string, array<string, mixed>>>|\stdClass
     * }
     */
    public function getGrid(string $academicYearId, int $semester, string $classLevelId, string $subjectBookId): array
    {
        $gridContext = $this->resolveClassSubjectContext($academicYearId, $semester, $classLevelId, $subjectBookId);

        $subjectBook = $gridContext->subjectBook;
        $manualTemplateFactors = $gridContext->templateFactors
            ->filter(fn (GradingTemplateFactor $templateFactor) => in_array($templateFactor->gradingFactor->input_type, self::MANUAL_INPUT_TYPES, true))
            ->values();

        $students = $this->listGradedStudents($gridContext);

        $grades = StudentGrade::query()
            ->where('school_id', School::activeOrFail()->id)
            ->where('subject_book_id', $subjectBookId)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->whereIn('student_id', $students->pluck('id'))
            ->whereIn('grading_factor_id', $manualTemplateFactors->pluck('grading_factor_id'))
            ->with(['gradingFactor:id,code', 'updater:id,name'])
            ->get();

        $gradesByStudentAndFactorCode = [];
        foreach ($grades as $grade) {
            $gradesByStudentAndFactorCode[$grade->student_id][$grade->gradingFactor->code] = $this->presentGrade($grade);
        }

        $classLevel = ClassLevel::findOrFail($classLevelId);
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
            'factors' => $manualTemplateFactors
                ->map(fn (GradingTemplateFactor $templateFactor) => $this->presentGridFactor($templateFactor))
                ->all(),
            'students' => $students
                ->map(fn (Student $student) => $this->presentClassStudent($student))
                ->values()
                ->all(),
            // Object (not list) even when empty so clients can always index by student id.
            'grades' => $gradesByStudentAndFactorCode === [] ? new \stdClass : $gradesByStudentAndFactorCode,
        ];
    }

    /**
     * Upserts the submitted scores of one Kelas × Kitab grid.
     *
     * A factor code present with null clears the score (row kept, score
     * NULL, updated_by set); a code absent from `scores` leaves that grade
     * untouched; a null for a cell with no stored row creates nothing.
     *
     * For a `level_1_4` factor (Adab, Keaktifan) the submitted value is the
     * integer level (1-4), not a score: it is converted through the
     * factor's current `scale_levels[level].score` and both `scale_level`
     * and the converted `score` are stored. Editing a factor's scale_levels
     * only changes the conversion used by later saves — rows saved earlier
     * keep whatever score they were converted to at the time.
     *
     * @param  array<int, array{student_id: string, scores: array<string, int|float|string|null>}>  $rows
     * @return array<int, array<string, mixed>> the stored rows of every submitted cell
     *
     * @throws ValidationException
     * @throws FinalizedReportCardException a submitted santri's Rapor is final for the semester (keyed by student id)
     */
    public function upsertGrid(string $academicYearId, int $semester, string $classLevelId, string $subjectBookId, array $rows): array
    {
        $gridContext = $this->resolveClassSubjectContext($academicYearId, $semester, $classLevelId, $subjectBookId);

        $templateFactorsByCode = $gridContext->templateFactors->keyBy(fn (GradingTemplateFactor $templateFactor) => $templateFactor->gradingFactor->code);
        $gradedStudentIds = $this->listGradedStudents($gridContext)->pluck('id')->flip();
        $isTahfizhTemplate = $gridContext->subjectBook->gradingTemplate?->code === GradingTemplate::CODE_TAHFIZH;

        $this->assertGridRowsAreValid($rows, $templateFactorsByCode, $gradedStudentIds, $isTahfizhTemplate);
        // A finalized santri's rows are rejected (ADR 0001); nothing is written.
        $this->finalizedReportCardGuard->assertEditableForStudents(collect($rows)->pluck('student_id'), $academicYearId, $semester);

        $schoolId = School::activeOrFail()->id;
        $userId = auth()->id();

        $savedGradeIds = DB::transaction(function () use ($rows, $templateFactorsByCode, $schoolId, $userId, $academicYearId, $semester, $classLevelId, $subjectBookId) {
            $existingGradesByCell = StudentGrade::query()
                ->where('subject_book_id', $subjectBookId)
                ->where('academic_year_id', $academicYearId)
                ->where('semester', $semester)
                ->whereIn('student_id', collect($rows)->pluck('student_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (StudentGrade $grade) => $grade->student_id.'|'.$grade->grading_factor_id);

            $savedGradeIds = [];

            foreach ($rows as $row) {
                foreach ($row['scores'] as $factorCode => $rawValue) {
                    $templateFactor = $templateFactorsByCode->get($factorCode);
                    $gradingFactorId = $templateFactor->grading_factor_id;
                    $resolved = $this->resolveScoreAndScaleLevel($templateFactor->gradingFactor, $rawValue);
                    $existingGrade = $existingGradesByCell->get($row['student_id'].'|'.$gradingFactorId);

                    if ($existingGrade === null && $resolved['score'] === null && $resolved['scale_level'] === null) {
                        continue;
                    }

                    if ($existingGrade === null) {
                        $savedGradeIds[] = StudentGrade::create([
                            'school_id' => $schoolId,
                            'student_id' => $row['student_id'],
                            'subject_book_id' => $subjectBookId,
                            'grading_factor_id' => $gradingFactorId,
                            'academic_year_id' => $academicYearId,
                            'semester' => $semester,
                            'class_level_id' => $classLevelId,
                            'score' => $resolved['score'],
                            'scale_level' => $resolved['scale_level'],
                            'created_by' => $userId,
                            'updated_by' => $userId,
                        ])->id;

                        continue;
                    }

                    $existingGrade->fill([
                        'score' => $resolved['score'],
                        'scale_level' => $resolved['scale_level'],
                        'class_level_id' => $classLevelId,
                    ]);

                    if ($existingGrade->isDirty()) {
                        $existingGrade->updated_by = $userId;
                        $existingGrade->save();
                    }

                    $savedGradeIds[] = $existingGrade->id;
                }
            }

            return $savedGradeIds;
        });

        $savedGradesById = StudentGrade::query()
            ->whereIn('id', $savedGradeIds)
            ->with(['gradingFactor:id,code', 'updater:id,name'])
            ->get()
            ->keyBy('id');

        return collect($savedGradeIds)
            ->map(fn (string $gradeId) => $this->presentGrade($savedGradesById->get($gradeId)))
            ->values()
            ->all();
    }

    /**
     * Students of a class in the active school (soft-deleted excluded):
     * status active first, then the rest, each group ordered by name.
     *
     * @return Collection<int, Student>
     */
    public function listClassStudents(string $classLevelId): Collection
    {
        return Student::query()
            ->where('school_id', School::activeOrFail()->id)
            ->where('class_level_id', $classLevelId)
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [Student::STATUS_ACTIVE])
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'status', 'entry_date', 'class_level_id']);
    }

    /**
     * The roster graded for one Kelas × Kitab context: for a Tahfizh-
     * template kitab (ADR 0003) — the class's santri who have a
     * non-deleted Target Hafalan for that semester; for every other kitab
     * — every santri of the class (listClassStudents()). The single place
     * the grade grid, the class recap and the bulk-upsert validation get
     * "who is graded for this kitab" from, so all three can never disagree.
     *
     * @return Collection<int, Student>
     */
    public function listGradedStudents(ClassSubjectGradingContext $context): Collection
    {
        if ($context->subjectBook->gradingTemplate?->code === GradingTemplate::CODE_TAHFIZH) {
            return $this->listStudentsWithMemorizationTarget(
                $context->classLevelId,
                $context->academicSemester->academic_year_id,
                $context->academicSemester->semester,
            );
        }

        return $this->listClassStudents($context->classLevelId);
    }

    /**
     * Santri of a class (active school, not soft-deleted) who have a
     * non-deleted memorization_targets row for the (academic_year_id,
     * semester) pair — same ordering as listClassStudents().
     *
     * @return Collection<int, Student>
     */
    private function listStudentsWithMemorizationTarget(string $classLevelId, string $academicYearId, int $semester): Collection
    {
        $studentIdsWithTarget = MemorizationTarget::query()
            ->where('school_id', School::activeOrFail()->id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->pluck('student_id');

        return Student::query()
            ->where('school_id', School::activeOrFail()->id)
            ->where('class_level_id', $classLevelId)
            ->whereIn('id', $studentIdsWithTarget)
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [Student::STATUS_ACTIVE])
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'status', 'entry_date', 'class_level_id']);
    }

    /**
     * One stored grade as returned by the grid and bulk endpoints.
     * Expects gradingFactor and updater to be eager-loaded.
     *
     * @return array<string, mixed>
     */
    public function presentGrade(StudentGrade $grade): array
    {
        return [
            'id' => $grade->id,
            'student_id' => $grade->student_id,
            'subject_book_id' => $grade->subject_book_id,
            'grading_factor_id' => $grade->grading_factor_id,
            'code' => $grade->gradingFactor?->code,
            'academic_year_id' => $grade->academic_year_id,
            'semester' => $grade->semester,
            'class_level_id' => $grade->class_level_id,
            'score' => $grade->score === null ? null : (float) $grade->score,
            'scale_level' => $grade->scale_level,
            'notes' => $grade->notes,
            'created_by' => $grade->created_by,
            'updated_by' => $grade->updated_by,
            'updated_by_name' => $grade->updater?->name,
            'created_at' => $grade->created_at?->toJSON(),
            'updated_at' => $grade->updated_at?->toJSON(),
        ];
    }

    /**
     * Validates a Kelas × Kitab selection for a semester akademik — the kitab
     * has a template, the pair is scheduled (GradableSubjectService) and the
     * semester is configured, checked in that order — and returns the kitab
     * (with its template), the academic_semesters row and the template's
     * weight rows for the semester (with gradingFactor, by sort_order).
     *
     * Shared by the grade grid and the grade recap so both reject the same
     * selections with the same messages.
     *
     * @throws ValidationException
     */
    public function resolveClassSubjectContext(string $academicYearId, int $semester, string $classLevelId, string $subjectBookId): ClassSubjectGradingContext
    {
        $school = School::activeOrFail();

        $subjectBook = SubjectBook::where('school_id', $school->id)
            ->with('gradingTemplate:id,code,name')
            ->findOrFail($subjectBookId);

        if ($subjectBook->gradingTemplate === null) {
            throw ValidationException::withMessages(['subject_book_id' => self::MESSAGE_BOOK_WITHOUT_TEMPLATE]);
        }

        $academicSemester = $this->assertScheduledPairAndConfiguredSemester($academicYearId, $semester, $classLevelId, $subjectBookId);

        $templateFactors = GradingTemplateFactor::query()
            ->where('school_id', $school->id)
            ->where('grading_template_id', $subjectBook->grading_template_id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->with('gradingFactor')
            ->get()
            ->sortBy(fn (GradingTemplateFactor $templateFactor) => $templateFactor->gradingFactor->sort_order)
            ->values();

        if ($templateFactors->isEmpty()) {
            throw ValidationException::withMessages(['semester' => self::MESSAGE_SEMESTER_NOT_CONFIGURED]);
        }

        return new ClassSubjectGradingContext($subjectBook, $academicSemester, $templateFactors, $classLevelId);
    }

    /**
     * The two selection checks every Kelas × Kitab screen shares, in this
     * order: the pair is scheduled (GradableSubjectService, keyed
     * "subject_book_id") and the semester akademik is configured (keyed
     * "semester"). resolveClassSubjectContext() adds the book-has-template
     * check on top; the attendance recap uses these two alone.
     *
     * @return AcademicSemester the configured academic_semesters row
     *
     * @throws ValidationException MESSAGE_PAIR_NOT_SCHEDULED | MESSAGE_SEMESTER_NOT_CONFIGURED
     */
    public function assertScheduledPairAndConfiguredSemester(string $academicYearId, int $semester, string $classLevelId, string $subjectBookId): AcademicSemester
    {
        if (! $this->gradableSubjectService->isGradablePair($academicYearId, $semester, $classLevelId, $subjectBookId)) {
            throw ValidationException::withMessages(['subject_book_id' => self::MESSAGE_PAIR_NOT_SCHEDULED]);
        }

        $academicSemester = AcademicSemester::findByPair($academicYearId, $semester);

        if ($academicSemester === null) {
            throw ValidationException::withMessages(['semester' => self::MESSAGE_SEMESTER_NOT_CONFIGURED]);
        }

        return $academicSemester;
    }

    /**
     * @param  array<int, array{student_id: string, scores: array<string, mixed>}>  $rows
     * @param  Collection<string, GradingTemplateFactor>  $templateFactorsByCode
     * @param  Collection<string, int>  $gradedStudentIds  student ids graded for this kitab (as keys) — listGradedStudents()
     *
     * @throws ValidationException
     */
    private function assertGridRowsAreValid(array $rows, Collection $templateFactorsByCode, Collection $gradedStudentIds, bool $isTahfizhTemplate = false): void
    {
        $errors = [];
        $seenStudentIds = [];
        $notInRosterMessage = $isTahfizhTemplate ? self::MESSAGE_STUDENT_WITHOUT_TARGET : 'Santri tidak terdaftar di kelas ini.';

        foreach ($rows as $row) {
            $studentId = $row['student_id'];

            if (isset($seenStudentIds[$studentId])) {
                $errors[$studentId] = 'Santri tercantum lebih dari sekali.';

                continue;
            }
            $seenStudentIds[$studentId] = true;

            if (! $gradedStudentIds->has($studentId)) {
                $errors[$studentId] = $notInRosterMessage;

                continue;
            }

            foreach ($row['scores'] as $factorCode => $score) {
                $cellError = $this->validateCell($templateFactorsByCode->get($factorCode), $score);

                if ($cellError !== null) {
                    $errors[$studentId.'.'.$factorCode] = $cellError;
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function validateCell(?GradingTemplateFactor $templateFactor, mixed $score): ?string
    {
        if ($templateFactor === null) {
            return 'Faktor tidak termasuk template penilaian kitab ini.';
        }

        $factor = $templateFactor->gradingFactor;

        if (! in_array($factor->input_type, self::MANUAL_INPUT_TYPES, true)) {
            return 'Faktor ini tidak diinput manual.';
        }

        // level_1_4 factors (Adab, Keaktifan): the submitted value is the
        // level (1-4), converted to a score via resolveScoreAndScaleLevel().
        if ($factor->score_scale === GradingFactor::SCORE_SCALE_LEVEL_1_4) {
            return $this->validateLevelCell($score);
        }

        return (new PercentScoreValidator)->validate($score);
    }

    private function validateLevelCell(mixed $level): ?string
    {
        if ($level === null) {
            return null;
        }

        if (! is_int($level) || $level < 1 || $level > 4) {
            return self::MESSAGE_INVALID_LEVEL;
        }

        return null;
    }

    /**
     * Resolves the (score, scale_level) pair to store for one cell.
     *
     * For a level_1_4 factor, $rawValue is the level (already validated as
     * an int 1-4, or null); it is converted through the factor's current
     * scale_levels. For every other factor $rawValue is stored as-is and
     * scale_level is always null.
     *
     * @return array{score: float|int|string|null, scale_level: int|null}
     */
    private function resolveScoreAndScaleLevel(GradingFactor $factor, mixed $rawValue): array
    {
        if ($factor->score_scale !== GradingFactor::SCORE_SCALE_LEVEL_1_4) {
            return ['score' => $rawValue, 'scale_level' => null];
        }

        if ($rawValue === null) {
            return ['score' => null, 'scale_level' => null];
        }

        $level = (int) $rawValue;

        return ['score' => $this->convertLevelToScore($factor, $level), 'scale_level' => $level];
    }

    /**
     * Looks up a level_1_4 factor's converted score for one level in its
     * current scale_levels JSON. Returns null if the level is not defined
     * (should not happen for a validated 1-4 level with the seeded 4-level
     * default, but kept defensive against a corrupted configuration).
     */
    private function convertLevelToScore(GradingFactor $factor, int $level): ?float
    {
        foreach ((array) $factor->scale_levels as $scaleLevel) {
            if ((int) ($scaleLevel['level'] ?? null) === $level) {
                return isset($scaleLevel['score']) ? (float) $scaleLevel['score'] : null;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentGridFactor(GradingTemplateFactor $templateFactor): array
    {
        $factor = $templateFactor->gradingFactor;

        return [
            'grading_factor_id' => $factor->id,
            'code' => $factor->code,
            'name' => $factor->name,
            'input_type' => $factor->input_type,
            'score_scale' => $factor->score_scale,
            'scale_levels' => $factor->scale_levels,
            'is_midterm_exam' => $factor->is_midterm_exam,
            'sort_order' => $factor->sort_order,
            'weight' => (float) $templateFactor->weight,
            'is_active' => $templateFactor->is_active,
        ];
    }

    /**
     * One class student as listed by the grid and the recap.
     *
     * @return array{id: string, full_name: string, status: string, is_active_student: bool, entry_date: string|null}
     */
    public function presentClassStudent(Student $student): array
    {
        return [
            'id' => $student->id,
            'full_name' => $student->full_name,
            'status' => $student->status,
            'is_active_student' => $student->status === Student::STATUS_ACTIVE,
            'entry_date' => $student->entry_date?->toDateString(),
        ];
    }
}

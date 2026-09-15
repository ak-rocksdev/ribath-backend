<?php

namespace App\Services\Akademik;

use App\Exceptions\FinalizedReportCardException;
use App\Exceptions\OutsideTeachingScopeException;
use App\Models\AcademicSemester;
use App\Models\ClassTask;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentTaskScore;
use App\Services\Akademik\Calculation\EnrollmentDateRule;
use App\Services\Akademik\Validation\PercentScoreValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tugas (Penilaian): CRUD of one Kelas × Kitab semester's assignments, and
 * the all-or-nothing bulk save of their student scores.
 *
 * Bulk score writes follow the same shape as StudentGradeService::upsertGrid:
 * every row is validated first (errors keyed "<student_id>"), then one
 * transaction writes the rows; a cleared score keeps its row (NULL,
 * updated_by set) instead of being deleted.
 *
 * A task is not "expected" of a student who joined the class after it was
 * assigned (EnrollmentDateRule: task_date before the student's
 * entry_date) — such a student is excluded from that task's
 * scored_count/student_count and their score cell is rejected by
 * upsertScores(); the frontend grid shows it as "Belum masuk" instead of
 * an editable cell.
 *
 * Cakupan Mengajar (ADR 0004): a user holding only the "milik sendiri"
 * grade permissions works on the Tugas of his own Kelas × Kitab pairs. A
 * pair chosen in the request outside the scope is refused with 403
 * (resolveClassSubjectContext); a Tugas bound to the route outside it is
 * not found (404), like tenancy — see ensureWithinTeachingScope(). For the
 * Kitab Tahfizh a Pembimbing Tahfizh limited to his Cakupan Mengajar sees
 * and scores only his santri bimbingan of the class
 * (TeachingScope::rosterWithinScope()); a score for another santri of the
 * class is refused per santri.
 */
class ClassTaskService
{
    public const MESSAGE_TASK_DATE_OUT_OF_RANGE = 'Tanggal tugas di luar rentang semester.';

    public const MESSAGE_STUDENT_NOT_IN_CLASS = 'Santri tidak terdaftar di kelas ini.';

    public const MESSAGE_STUDENT_NOT_YET_ENROLLED = 'Santri belum masuk kelas pada tanggal tugas ini.';

    public const MESSAGE_DUPLICATE_STUDENT = 'Santri tercantum lebih dari sekali.';

    public function __construct(
        private StudentGradeService $studentGradeService,
        private FinalizedReportCardGuard $finalizedReportCardGuard,
        private TeachingScopeResolver $teachingScopeResolver,
    ) {}

    /**
     * Tasks of one Kelas × Kitab semester, most recent task_date first,
     * each with scored_count/student_count.
     *
     * @throws ValidationException
     */
    public function list(string $academicYearId, int $semester, string $classLevelId, string $subjectBookId): array
    {
        $teachingScope = $this->teachingScopeResolver->forCurrentUser('view-grades', $academicYearId, $semester);
        $this->studentGradeService->resolveClassSubjectContext($teachingScope, $academicYearId, $semester, $classLevelId, $subjectBookId);

        $students = $this->listRosterWithinScope($teachingScope, $classLevelId, $subjectBookId);

        $tasks = ClassTask::query()
            ->where('school_id', School::activeOrFail()->id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->where('class_level_id', $classLevelId)
            ->where('subject_book_id', $subjectBookId)
            ->orderByDesc('task_date')
            ->orderByDesc('created_at')
            ->get();

        // One query for every task's scored student ids (not just a count),
        // so present() can exclude students who joined after each task's
        // own task_date — a per-task filter a single withCount() can't express.
        $scoredStudentIdsByTaskId = StudentTaskScore::query()
            ->whereIn('class_task_id', $tasks->pluck('id'))
            ->whereNotNull('score')
            ->get(['class_task_id', 'student_id'])
            ->groupBy('class_task_id')
            ->map(fn (Collection $scores) => $scores->pluck('student_id'));

        return $tasks
            ->map(fn (ClassTask $task) => $this->present($task, $students, $scoredStudentIdsByTaskId->get($task->id) ?? collect()))
            ->all();
    }

    /**
     * @param  array{academic_year_id: string, semester: int, class_level_id: string, subject_book_id: string, title: string, task_date: string, description: string|null}  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): array
    {
        $teachingScope = $this->teachingScopeResolver->forCurrentUser('manage-grades', $data['academic_year_id'], (int) $data['semester']);
        $context = $this->studentGradeService->resolveClassSubjectContext(
            $teachingScope,
            $data['academic_year_id'],
            (int) $data['semester'],
            $data['class_level_id'],
            $data['subject_book_id'],
        );

        $this->assertTaskDateWithinSemester($context->academicSemester, $data['task_date']);

        $task = ClassTask::create([
            'school_id' => School::activeOrFail()->id,
            'class_level_id' => $data['class_level_id'],
            'subject_book_id' => $data['subject_book_id'],
            'academic_year_id' => $data['academic_year_id'],
            'semester' => $data['semester'],
            'title' => $data['title'],
            'task_date' => $data['task_date'],
            'description' => $data['description'] ?? null,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $students = $this->listRosterWithinScope($teachingScope, $data['class_level_id'], $data['subject_book_id']);

        return $this->present($task, $students, collect());
    }

    public function show(ClassTask $task): array
    {
        $teachingScope = $this->ensureWithinTeachingScope($task, 'view-grades');

        return $this->present($task, $this->listTaskRosterWithinScope($task, $teachingScope));
    }

    /**
     * @param  array{title?: string, task_date?: string, description?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function update(ClassTask $task, array $data): array
    {
        $teachingScope = $this->ensureWithinTeachingScope($task, 'manage-grades');

        if (array_key_exists('task_date', $data)) {
            $academicSemester = AcademicSemester::findByPair($task->academic_year_id, $task->semester);
            $this->assertTaskDateWithinSemester($academicSemester, $data['task_date']);
        }

        $task->fill([
            'title' => $data['title'] ?? $task->title,
            'task_date' => $data['task_date'] ?? $task->task_date,
            'description' => array_key_exists('description', $data) ? $data['description'] : $task->description,
        ]);

        if ($task->isDirty()) {
            $task->updated_by = auth()->id();
            $task->save();
        }

        return $this->present($task, $this->listTaskRosterWithinScope($task, $teachingScope));
    }

    public function delete(ClassTask $task): void
    {
        $this->ensureWithinTeachingScope($task, 'manage-grades');

        $task->updated_by = auth()->id();
        $task->save();
        $task->delete();
    }

    /**
     * The class's students and this task's stored scores, keyed by student
     * id — enough for a scoring grid (santri × one score column).
     *
     * `not_yet_enrolled_student_ids` lists students who joined the class
     * after this task's task_date (EnrollmentDateRule) — the frontend
     * shows their cell as "Belum masuk" instead of an editable score, and
     * they are excluded from `class_task.scored_count`/`student_count`.
     */
    public function getScores(ClassTask $task): array
    {
        $teachingScope = $this->ensureWithinTeachingScope($task, 'view-grades');

        $students = $this->listTaskRosterWithinScope($task, $teachingScope);

        $scores = StudentTaskScore::query()
            ->where('class_task_id', $task->id)
            ->whereIn('student_id', $students->pluck('id'))
            ->with('updater:id,name')
            ->get()
            ->keyBy('student_id');

        $scoredStudentIds = $scores
            ->filter(fn (StudentTaskScore $score) => $score->score !== null)
            ->keys();

        $enrollmentDateRule = new EnrollmentDateRule;
        $notYetEnrolledStudentIds = $students
            ->reject(fn (Student $student) => $enrollmentDateRule->isExpectedOn($student, $task->task_date))
            ->pluck('id')
            ->values();

        return [
            'class_task' => $this->present($task, $students, $scoredStudentIds),
            'students' => $students->map(fn ($student) => $this->studentGradeService->presentClassStudent($student))->values()->all(),
            'scores' => $students->mapWithKeys(
                fn ($student) => [$student->id => $scores->has($student->id) ? $this->presentScore($scores->get($student->id)) : null]
            )->all(),
            'not_yet_enrolled_student_ids' => $notYetEnrolledStudentIds->all(),
        ];
    }

    /**
     * All-or-nothing upsert of this task's student scores.
     *
     * @param  array<int, array{student_id: string, score: int|float|string|null}>  $rows
     * @return array<int, array<string, mixed>> the stored rows of every submitted score
     *
     * @throws ValidationException
     * @throws FinalizedReportCardException a submitted santri's Rapor is final for the semester (keyed by student id)
     */
    public function upsertScores(ClassTask $task, array $rows): array
    {
        $teachingScope = $this->ensureWithinTeachingScope($task, 'manage-grades');

        $classStudents = $this->studentGradeService->listClassStudents($task->class_level_id);
        $rosterStudents = $teachingScope->rosterWithinScope($task->subject_book_id, $classStudents);

        $enrollmentDateRule = new EnrollmentDateRule;
        $expectedStudentIds = $rosterStudents
            ->filter(fn (Student $student) => $enrollmentDateRule->isExpectedOn($student, $task->task_date))
            ->pluck('id')
            ->flip();

        $this->assertScoreRowsAreValid($rows, $classStudents->pluck('id')->flip(), $rosterStudents->pluck('id')->flip(), $expectedStudentIds);
        // A finalized santri's rows are rejected (ADR 0001); Tugas CRUD itself stays allowed.
        $this->finalizedReportCardGuard->assertEditableForStudents(collect($rows)->pluck('student_id'), $task->academic_year_id, $task->semester);

        $schoolId = School::activeOrFail()->id;
        $userId = auth()->id();

        $savedScoreIds = DB::transaction(function () use ($rows, $task, $schoolId, $userId) {
            $existingScoresByStudentId = StudentTaskScore::query()
                ->where('class_task_id', $task->id)
                ->whereIn('student_id', collect($rows)->pluck('student_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('student_id');

            $savedScoreIds = [];

            foreach ($rows as $row) {
                $studentId = $row['student_id'];
                $score = $row['score'];
                $existingScore = $existingScoresByStudentId->get($studentId);

                if ($existingScore === null && $score === null) {
                    continue;
                }

                if ($existingScore === null) {
                    $savedScoreIds[] = StudentTaskScore::create([
                        'school_id' => $schoolId,
                        'class_task_id' => $task->id,
                        'student_id' => $studentId,
                        'score' => $score,
                        'created_by' => $userId,
                        'updated_by' => $userId,
                    ])->id;

                    continue;
                }

                $existingScore->score = $score;

                if ($existingScore->isDirty()) {
                    $existingScore->updated_by = $userId;
                    $existingScore->save();
                }

                $savedScoreIds[] = $existingScore->id;
            }

            return $savedScoreIds;
        });

        $savedScoresById = StudentTaskScore::query()
            ->whereIn('id', $savedScoreIds)
            ->with('updater:id,name')
            ->get()
            ->keyBy('id');

        return collect($savedScoreIds)
            ->map(fn (string $scoreId) => $this->presentScore($savedScoresById->get($scoreId)))
            ->values()
            ->all();
    }

    /**
     * A Tugas outside the user's Cakupan Mengajar is not found (404), the
     * same answer as a Tugas of another school. The scope is resolved for
     * the Tugas's own Semester Akademik, so the riwayat pengajar of that
     * semester counts (ADR 0005).
     *
     * @param  string  $allDataPermission  `view-grades` to read the Tugas, `manage-grades` to change or score it
     * @return TeachingScope the Cakupan Mengajar the Tugas was found in
     */
    public function ensureWithinTeachingScope(ClassTask $task, string $allDataPermission): TeachingScope
    {
        $teachingScope = $this->teachingScopeResolver->forCurrentUser($allDataPermission, $task->academic_year_id, $task->semester);

        abort_unless($teachingScope->includesClassSubjectPair($task->class_level_id, $task->subject_book_id), 404);

        return $teachingScope;
    }

    /**
     * The santri a Tugas of the pair is scored for: every santri of the
     * class, or — for the Kitab Tahfizh of a Pembimbing Tahfizh limited to
     * his Cakupan Mengajar — his santri bimbingan among them.
     *
     * @return Collection<int, Student>
     */
    private function listRosterWithinScope(TeachingScope $teachingScope, string $classLevelId, string $subjectBookId): Collection
    {
        return $teachingScope->rosterWithinScope($subjectBookId, $this->studentGradeService->listClassStudents($classLevelId));
    }

    /**
     * @return Collection<int, Student>
     */
    private function listTaskRosterWithinScope(ClassTask $task, TeachingScope $teachingScope): Collection
    {
        return $this->listRosterWithinScope($teachingScope, $task->class_level_id, $task->subject_book_id);
    }

    private function assertTaskDateWithinSemester(?AcademicSemester $academicSemester, string $taskDate): void
    {
        if ($academicSemester === null || $academicSemester->start_date === null || $academicSemester->end_date === null) {
            return;
        }

        $taskDateAsCarbon = Carbon::parse($taskDate)->startOfDay();

        if ($taskDateAsCarbon->lt($academicSemester->start_date) || $taskDateAsCarbon->gt($academicSemester->end_date)) {
            throw ValidationException::withMessages(['task_date' => self::MESSAGE_TASK_DATE_OUT_OF_RANGE]);
        }
    }

    /**
     * @param  array<int, array{student_id: string, score: mixed}>  $rows
     * @param  Collection<string, int>  $classStudentIds  every student of the class
     * @param  Collection<string, int>  $rosterStudentIds  the students of the class the current user may score (listRosterWithinScope())
     * @param  Collection<string, int>  $expectedStudentIds  students of that roster this task is expected of (EnrollmentDateRule)
     *
     * @throws ValidationException
     */
    private function assertScoreRowsAreValid(array $rows, Collection $classStudentIds, Collection $rosterStudentIds, Collection $expectedStudentIds): void
    {
        $errors = [];
        $seenStudentIds = [];
        $percentScoreValidator = new PercentScoreValidator;

        foreach ($rows as $row) {
            $studentId = $row['student_id'];

            if (isset($seenStudentIds[$studentId])) {
                $errors[$studentId] = self::MESSAGE_DUPLICATE_STUDENT;

                continue;
            }
            $seenStudentIds[$studentId] = true;

            if (! $classStudentIds->has($studentId)) {
                $errors[$studentId] = self::MESSAGE_STUDENT_NOT_IN_CLASS;

                continue;
            }

            if (! $rosterStudentIds->has($studentId)) {
                $errors[$studentId] = OutsideTeachingScopeException::MESSAGE_STUDENT;

                continue;
            }

            if (! $expectedStudentIds->has($studentId)) {
                $errors[$studentId] = self::MESSAGE_STUDENT_NOT_YET_ENROLLED;

                continue;
            }

            $scoreError = $percentScoreValidator->validate($row['score']);

            if ($scoreError !== null) {
                $errors[$studentId] = $scoreError;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  Collection<int, Student>  $students  the roster the Tugas is scored for (listRosterWithinScope())
     * @return array<string, mixed>
     */
    private function present(ClassTask $task, Collection $students, ?Collection $scoredStudentIds = null): array
    {
        $enrollmentDateRule = new EnrollmentDateRule;
        $expectedStudentIds = $students
            ->filter(fn (Student $student) => $enrollmentDateRule->isExpectedOn($student, $task->task_date))
            ->pluck('id');

        $scoredStudentIds ??= StudentTaskScore::query()
            ->where('class_task_id', $task->id)
            ->whereNotNull('score')
            ->pluck('student_id');

        $scoredCount = $scoredStudentIds->intersect($expectedStudentIds)->count();

        return [
            'id' => $task->id,
            'class_level_id' => $task->class_level_id,
            'subject_book_id' => $task->subject_book_id,
            'academic_year_id' => $task->academic_year_id,
            'semester' => $task->semester,
            'title' => $task->title,
            'task_date' => $task->task_date?->toDateString(),
            'description' => $task->description,
            'scored_count' => $scoredCount,
            'student_count' => $expectedStudentIds->count(),
            'created_by' => $task->created_by,
            'updated_by' => $task->updated_by,
            'updated_by_name' => $task->updater?->name,
            'created_at' => $task->created_at?->toJSON(),
            'updated_at' => $task->updated_at?->toJSON(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentScore(StudentTaskScore $score): array
    {
        return [
            'id' => $score->id,
            'student_id' => $score->student_id,
            'class_task_id' => $score->class_task_id,
            'score' => $score->score === null ? null : (float) $score->score,
            'created_by' => $score->created_by,
            'updated_by' => $score->updated_by,
            'updated_by_name' => $score->updater?->name,
            'created_at' => $score->created_at?->toJSON(),
            'updated_at' => $score->updated_at?->toJSON(),
        ];
    }
}

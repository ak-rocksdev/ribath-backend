<?php

namespace App\Services\Akademik;

use App\Models\AcademicSemester;
use App\Models\ClassTask;
use App\Models\School;
use App\Models\StudentTaskScore;
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
 */
class ClassTaskService
{
    public const MESSAGE_TASK_DATE_OUT_OF_RANGE = 'Tanggal tugas di luar rentang semester.';

    public const MESSAGE_STUDENT_NOT_IN_CLASS = 'Santri tidak terdaftar di kelas ini.';

    public const MESSAGE_DUPLICATE_STUDENT = 'Santri tercantum lebih dari sekali.';

    public const MESSAGE_SCORE_NOT_NUMERIC = 'Nilai harus berupa angka.';

    public const MESSAGE_SCORE_OUT_OF_RANGE = 'Nilai harus antara 0 dan 100.';

    public const MESSAGE_SCORE_TOO_MANY_DECIMALS = 'Nilai maksimal 2 angka desimal.';

    public function __construct(
        private StudentGradeService $studentGradeService,
    ) {}

    /**
     * Tasks of one Kelas × Kitab semester, most recent task_date first,
     * each with scored_count/student_count.
     *
     * @throws ValidationException
     */
    public function list(string $academicYearId, int $semester, string $classLevelId, string $subjectBookId): array
    {
        $this->studentGradeService->resolveClassSubjectContext($academicYearId, $semester, $classLevelId, $subjectBookId);

        $studentCount = $this->studentGradeService->listClassStudents($classLevelId)->count();

        $tasks = ClassTask::query()
            ->where('school_id', School::activeOrFail()->id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->where('class_level_id', $classLevelId)
            ->where('subject_book_id', $subjectBookId)
            ->withCount(['scores as scored_count' => fn ($query) => $query->whereNotNull('score')])
            ->orderByDesc('task_date')
            ->orderByDesc('created_at')
            ->get();

        return $tasks
            ->map(fn (ClassTask $task) => $this->present($task, $studentCount, $task->scored_count))
            ->all();
    }

    /**
     * @param  array{academic_year_id: string, semester: int, class_level_id: string, subject_book_id: string, title: string, task_date: string, description: string|null}  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): array
    {
        $context = $this->studentGradeService->resolveClassSubjectContext(
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

        $studentCount = $this->studentGradeService->listClassStudents($data['class_level_id'])->count();

        return $this->present($task, $studentCount, 0);
    }

    /**
     * @param  array{title?: string, task_date?: string, description?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function update(ClassTask $task, array $data): array
    {
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

        $studentCount = $this->studentGradeService->listClassStudents($task->class_level_id)->count();

        return $this->present($task, $studentCount);
    }

    public function delete(ClassTask $task): void
    {
        $task->updated_by = auth()->id();
        $task->save();
        $task->delete();
    }

    /**
     * The class's students and this task's stored scores, keyed by student
     * id — enough for a scoring grid (santri × one score column).
     */
    public function getScores(ClassTask $task): array
    {
        $students = $this->studentGradeService->listClassStudents($task->class_level_id);

        $scores = StudentTaskScore::query()
            ->where('class_task_id', $task->id)
            ->whereIn('student_id', $students->pluck('id'))
            ->with('updater:id,name')
            ->get()
            ->keyBy('student_id');

        return [
            'class_task' => $this->present($task, $students->count(), $scores->filter(fn (StudentTaskScore $score) => $score->score !== null)->count()),
            'students' => $students->map(fn ($student) => $this->studentGradeService->presentClassStudent($student))->values()->all(),
            'scores' => $students->mapWithKeys(
                fn ($student) => [$student->id => $scores->has($student->id) ? $this->presentScore($scores->get($student->id)) : null]
            )->all(),
        ];
    }

    /**
     * All-or-nothing upsert of this task's student scores.
     *
     * @param  array<int, array{student_id: string, score: int|float|string|null}>  $rows
     * @return array<int, array<string, mixed>> the stored rows of every submitted score
     *
     * @throws ValidationException
     */
    public function upsertScores(ClassTask $task, array $rows): array
    {
        $classStudentIds = $this->studentGradeService->listClassStudents($task->class_level_id)->pluck('id')->flip();

        $this->assertScoreRowsAreValid($rows, $classStudentIds);

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
     * @param  Collection<string, int>  $classStudentIds
     *
     * @throws ValidationException
     */
    private function assertScoreRowsAreValid(array $rows, Collection $classStudentIds): void
    {
        $errors = [];
        $seenStudentIds = [];

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

            $scoreError = $this->validateScore($row['score']);

            if ($scoreError !== null) {
                $errors[$studentId] = $scoreError;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function validateScore(mixed $score): ?string
    {
        if ($score === null) {
            return null;
        }

        if (is_bool($score) || ! is_numeric($score)) {
            return self::MESSAGE_SCORE_NOT_NUMERIC;
        }

        $numericScore = (float) $score;

        if ($numericScore < 0 || $numericScore > 100) {
            return self::MESSAGE_SCORE_OUT_OF_RANGE;
        }

        if (abs(round($numericScore, 2) - $numericScore) > 1e-9) {
            return self::MESSAGE_SCORE_TOO_MANY_DECIMALS;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ClassTask $task, ?int $studentCount = null, ?int $scoredCount = null): array
    {
        return [
            'id' => $task->id,
            'class_level_id' => $task->class_level_id,
            'subject_book_id' => $task->subject_book_id,
            'academic_year_id' => $task->academic_year_id,
            'semester' => $task->semester,
            'title' => $task->title,
            'task_date' => $task->task_date?->toDateString(),
            'description' => $task->description,
            'scored_count' => $scoredCount ?? $task->scores()->whereNotNull('score')->count(),
            'student_count' => $studentCount ?? $this->studentGradeService->listClassStudents($task->class_level_id)->count(),
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

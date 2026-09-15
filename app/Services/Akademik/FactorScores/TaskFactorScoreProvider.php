<?php

namespace App\Services\Akademik\FactorScores;

use App\Models\ClassTask;
use App\Models\GradingFactor;
use App\Models\Student;
use App\Models\StudentTaskScore;
use App\Services\Akademik\Calculation\EnrollmentDateRule;

/**
 * Scores the "tugas" (manual_periodic) factor: per student, the average of
 * student_task_scores.score across the non-soft-deleted class_tasks of the
 * context's class × kitab × semester that are *expected* of that student —
 * a task dated before the student's entry_date is not expected of them
 * (EnrollmentDateRule) and is excluded from both the average and the
 * "any unscored task" check, exactly as if it did not exist for them.
 *
 * NULL (never 0), with a reason, when: no task is expected for the student
 * yet — either none exists at all for the pair, or every task predates
 * their entry_date ("Belum ada tugas") — or the student has fewer scored
 * rows than there are expected tasks, or any of those rows has a NULL
 * score ("Ada tugas yang belum dinilai") — a missing student_task_scores
 * row counts the same as a NULL score.
 */
class TaskFactorScoreProvider implements FactorScoreProvider
{
    public const MESSAGE_NO_TASKS = 'Belum ada tugas';

    public const MESSAGE_INCOMPLETE = 'Ada tugas yang belum dinilai';

    public function supports(GradingFactor $factor): bool
    {
        return $factor->input_type === GradingFactor::INPUT_TYPE_MANUAL_PERIODIC;
    }

    /**
     * @return array<string, FactorScore>
     */
    public function scoresFor(GradingFactor $factor, FactorScoreContext $context): array
    {
        $tasks = ClassTask::query()
            ->where('subject_book_id', $context->subjectBookId)
            ->where('academic_year_id', $context->academicYearId)
            ->where('semester', $context->semester)
            ->when($context->classLevelId, fn ($query) => $query->where('class_level_id', $context->classLevelId))
            ->get(['id', 'task_date']);

        if ($tasks->isEmpty()) {
            return $context->students->mapWithKeys(
                fn ($student) => [$student->id => new FactorScore(null, self::MESSAGE_NO_TASKS)]
            )->all();
        }

        $enrollmentDateRule = new EnrollmentDateRule;

        $scoresByStudentId = StudentTaskScore::query()
            ->whereIn('class_task_id', $tasks->pluck('id'))
            ->whereIn('student_id', $context->students->pluck('id'))
            ->get(['student_id', 'class_task_id', 'score'])
            ->groupBy('student_id');

        return $context->students->mapWithKeys(function (Student $student) use ($tasks, $scoresByStudentId, $enrollmentDateRule) {
            $expectedTasks = $tasks->filter(fn (ClassTask $task) => $enrollmentDateRule->isExpectedOn($student, $task->task_date));

            if ($expectedTasks->isEmpty()) {
                return [$student->id => new FactorScore(null, self::MESSAGE_NO_TASKS)];
            }

            $expectedTaskIds = $expectedTasks->pluck('id');
            $expectedStudentScores = ($scoresByStudentId->get($student->id) ?? collect())
                ->whereIn('class_task_id', $expectedTaskIds);

            $hasMissingScore = $expectedStudentScores->count() < $expectedTaskIds->count()
                || $expectedStudentScores->contains(fn (StudentTaskScore $score) => $score->score === null);

            if ($hasMissingScore) {
                return [$student->id => new FactorScore(null, self::MESSAGE_INCOMPLETE)];
            }

            $average = $expectedStudentScores->avg(fn (StudentTaskScore $score) => (float) $score->score);

            return [$student->id => new FactorScore(round($average, 2))];
        })->all();
    }
}

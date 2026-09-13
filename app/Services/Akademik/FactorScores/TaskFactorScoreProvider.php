<?php

namespace App\Services\Akademik\FactorScores;

use App\Models\ClassTask;
use App\Models\GradingFactor;
use App\Models\StudentTaskScore;

/**
 * Scores the "tugas" (manual_periodic) factor: per student, the average of
 * student_task_scores.score across the non-soft-deleted class_tasks of the
 * context's class × kitab × semester.
 *
 * NULL (never 0), with a reason, when: no task exists yet for the pair
 * ("Belum ada tugas"), or the student has fewer scored rows than there are
 * tasks, or any of the student's rows has a NULL score ("Ada tugas yang
 * belum dinilai") — a missing student_task_scores row counts the same as
 * a NULL score.
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
        $taskIds = ClassTask::query()
            ->where('subject_book_id', $context->subjectBookId)
            ->where('academic_year_id', $context->academicYearId)
            ->where('semester', $context->semester)
            ->when($context->classLevelId, fn ($query) => $query->where('class_level_id', $context->classLevelId))
            ->pluck('id');

        $taskCount = $taskIds->count();

        if ($taskCount === 0) {
            return $context->students->mapWithKeys(
                fn ($student) => [$student->id => new FactorScore(null, self::MESSAGE_NO_TASKS)]
            )->all();
        }

        $scoresByStudentId = StudentTaskScore::query()
            ->whereIn('class_task_id', $taskIds)
            ->whereIn('student_id', $context->students->pluck('id'))
            ->get(['student_id', 'score'])
            ->groupBy('student_id');

        return $context->students->mapWithKeys(function ($student) use ($scoresByStudentId, $taskCount) {
            $studentScores = $scoresByStudentId->get($student->id) ?? collect();

            $hasMissingScore = $studentScores->count() < $taskCount
                || $studentScores->contains(fn (StudentTaskScore $score) => $score->score === null);

            if ($hasMissingScore) {
                return [$student->id => new FactorScore(null, self::MESSAGE_INCOMPLETE)];
            }

            $average = $studentScores->avg(fn (StudentTaskScore $score) => (float) $score->score);

            return [$student->id => new FactorScore(round($average, 2))];
        })->all();
    }
}

<?php

namespace App\Services\Akademik\Calculation;

use App\Models\ClassTask;
use App\Models\Student;

/**
 * Whether a Tugas (class_task) is expected of a santri: false when the
 * task's task_date is before the santri's entry_date — a santri who joins
 * the class after a task was assigned is not expected to have done it
 * (spec rule G16: nothing before a santri's entry_date is expected of
 * them). Strict: a santri who enters on the task's own date is expected,
 * consistent with MidtermExclusionRule's UTS-date comparison. A santri
 * with no entry_date is always expected (nothing to compare against).
 *
 * Shared by TaskFactorScoreProvider (the "tugas" factor's recap average —
 * an unexpected task is excluded from both the average and the "any
 * unscored task" check) and ClassTaskService (the scoring grid marks an
 * unexpected cell "Belum masuk" and excludes it from scored_count /
 * student_count; upsertScores() rejects a submitted score for one).
 *
 * Pure: it only reads the attributes of the models it is given, never queries.
 */
final class TaskExpectationRule
{
    public function isExpectedFor(ClassTask $task, Student $student): bool
    {
        if ($student->entry_date === null) {
            return true;
        }

        return $task->task_date->toDateString() >= $student->entry_date->toDateString();
    }
}

<?php

namespace App\Observers;

use App\Models\FeeActivityLog;
use App\Models\StudentFeeAssignment;

// Same audit pattern as FeeScheduleObserver; subject_type = `assignment`.
// Skips when auth() is null — the PSB hook path (system actor) writes its
// own log explicitly via service using `withoutEvents` to control actor_kind.
class StudentFeeAssignmentObserver
{
    public function created(StudentFeeAssignment $assignment): void
    {
        $this->writeLog($assignment, FeeActivityLog::ACTION_CREATED, null);
    }

    public function deleted(StudentFeeAssignment $assignment): void
    {
        $this->writeLog($assignment, FeeActivityLog::ACTION_DELETED, null);
    }

    private function writeLog(StudentFeeAssignment $assignment, string $action, ?array $changes): void
    {
        $actorId = auth()->id();

        if ($actorId === null) {
            return;
        }

        FeeActivityLog::create([
            'school_id' => $assignment->school_id,
            'subject_type' => FeeActivityLog::SUBJECT_ASSIGNMENT,
            'subject_id' => $assignment->id,
            'action' => $action,
            'actor_kind' => FeeActivityLog::ACTOR_USER,
            'actor_id' => $actorId,
            'changes' => $changes,
        ]);
    }
}

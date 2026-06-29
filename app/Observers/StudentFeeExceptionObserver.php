<?php

namespace App\Observers;

use App\Models\FeeActivityLog;
use App\Models\StudentFeeException;

// Same audit pattern as FeeScheduleObserver; subject_type = `exception`.
// Exceptions belong to an assignment which carries school_id — resolved via
// the loaded assignment relation.
class StudentFeeExceptionObserver
{
    public function created(StudentFeeException $exception): void
    {
        $this->writeLog($exception, FeeActivityLog::ACTION_CREATED, null);
    }

    public function updated(StudentFeeException $exception): void
    {
        $changes = $this->computeDiff($exception);
        if ($changes === null) {
            return;
        }
        $this->writeLog($exception, FeeActivityLog::ACTION_UPDATED, $changes);
    }

    public function deleted(StudentFeeException $exception): void
    {
        $this->writeLog($exception, FeeActivityLog::ACTION_DELETED, null);
    }

    private function computeDiff(StudentFeeException $exception): ?array
    {
        $ignored = ['updated_at'];
        $before = [];
        $after = [];

        foreach ($exception->getChanges() as $field => $newValue) {
            if (in_array($field, $ignored, true)) {
                continue;
            }
            $before[$field] = $exception->getOriginal($field);
            $after[$field] = $newValue;
        }

        if (empty($after)) {
            return null;
        }

        return ['before' => $before, 'after' => $after];
    }

    private function writeLog(StudentFeeException $exception, string $action, ?array $changes): void
    {
        $actorId = auth()->id();
        if ($actorId === null) {
            return;
        }

        $schoolId = $exception->assignment?->school_id;
        if ($schoolId === null) {
            return;
        }

        FeeActivityLog::create([
            'school_id' => $schoolId,
            'subject_type' => FeeActivityLog::SUBJECT_EXCEPTION,
            'subject_id' => $exception->id,
            'action' => $action,
            'actor_kind' => FeeActivityLog::ACTOR_USER,
            'actor_id' => $actorId,
            'changes' => $changes,
        ]);
    }
}

<?php

namespace App\Observers;

use App\Models\FeeActivityLog;
use App\Models\FeeSchedule;

/**
 * Same audit pattern as FeeTypeObserver; subject_type = `fee_schedule`.
 */
class FeeScheduleObserver
{
    public function created(FeeSchedule $schedule): void
    {
        $this->writeLog($schedule, FeeActivityLog::ACTION_CREATED, null);
    }

    public function updated(FeeSchedule $schedule): void
    {
        $diff = $this->computeDiff($schedule);

        if ($diff === null) {
            return;
        }

        $this->writeLog($schedule, FeeActivityLog::ACTION_UPDATED, $diff);
    }

    public function deleted(FeeSchedule $schedule): void
    {
        $this->writeLog($schedule, FeeActivityLog::ACTION_DELETED, null);
    }

    public function restored(FeeSchedule $schedule): void
    {
        $this->writeLog($schedule, FeeActivityLog::ACTION_RESTORED, null);
    }

    private function computeDiff(FeeSchedule $schedule): ?array
    {
        $ignored = ['updated_at'];
        $before = [];
        $after = [];

        foreach ($schedule->getChanges() as $field => $newValue) {
            if (in_array($field, $ignored, true)) {
                continue;
            }

            $before[$field] = $schedule->getOriginal($field);
            $after[$field] = $newValue;
        }

        if (empty($after)) {
            return null;
        }

        return ['before' => $before, 'after' => $after];
    }

    private function writeLog(FeeSchedule $schedule, string $action, ?array $changes): void
    {
        $actorId = auth()->id();

        if ($actorId === null) {
            return;
        }

        FeeActivityLog::create([
            'school_id' => $schedule->school_id,
            'subject_type' => FeeActivityLog::SUBJECT_FEE_SCHEDULE,
            'subject_id' => $schedule->id,
            'action' => $action,
            'actor_kind' => FeeActivityLog::ACTOR_USER,
            'actor_id' => $actorId,
            'changes' => $changes,
        ]);
    }
}

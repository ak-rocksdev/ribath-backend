<?php

namespace App\Observers;

use App\Models\FeeActivityLog;
use App\Models\FeeType;

/**
 * Writes audit entries for human-triggered fee_type mutations to
 * fee_activity_logs. Skips silently when there's no auth context (jobs,
 * console seeders) — pattern mirrors CashBookEntryObserver. System actor
 * paths (BillGenerator, PSB hook) bypass this observer entirely and write
 * their own entries with actor_kind != 'user'.
 */
class FeeTypeObserver
{
    public function created(FeeType $feeType): void
    {
        $this->writeLog($feeType, FeeActivityLog::ACTION_CREATED, null);
    }

    public function updated(FeeType $feeType): void
    {
        $diff = $this->computeDiff($feeType);

        if ($diff === null) {
            return;
        }

        $this->writeLog($feeType, FeeActivityLog::ACTION_UPDATED, $diff);
    }

    public function deleted(FeeType $feeType): void
    {
        $this->writeLog($feeType, FeeActivityLog::ACTION_DELETED, null);
    }

    public function restored(FeeType $feeType): void
    {
        $this->writeLog($feeType, FeeActivityLog::ACTION_RESTORED, null);
    }

    private function computeDiff(FeeType $feeType): ?array
    {
        $ignored = ['updated_at'];
        $before = [];
        $after = [];

        foreach ($feeType->getChanges() as $field => $newValue) {
            if (in_array($field, $ignored, true)) {
                continue;
            }

            $before[$field] = $feeType->getOriginal($field);
            $after[$field] = $newValue;
        }

        if (empty($after)) {
            return null;
        }

        return ['before' => $before, 'after' => $after];
    }

    private function writeLog(FeeType $feeType, string $action, ?array $changes): void
    {
        $actorId = auth()->id();

        if ($actorId === null) {
            return;
        }

        FeeActivityLog::create([
            'school_id' => $feeType->school_id,
            'subject_type' => FeeActivityLog::SUBJECT_FEE_TYPE,
            'subject_id' => $feeType->id,
            'action' => $action,
            'actor_kind' => FeeActivityLog::ACTOR_USER,
            'actor_id' => $actorId,
            'changes' => $changes,
        ]);
    }
}

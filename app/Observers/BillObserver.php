<?php

namespace App\Observers;

use App\Models\Bill;
use App\Models\FeeActivityLog;

// Audit pattern identik dengan FeeScheduleObserver; subject_type = `bill`.
// Skips when auth() is null — generator batch path writes a single summary
// log row explicitly via service (not per-bill).
class BillObserver
{
    public function created(Bill $bill): void
    {
        $this->writeLog($bill, FeeActivityLog::ACTION_CREATED, null);
    }

    public function updated(Bill $bill): void
    {
        $changes = $this->computeDiff($bill);
        if ($changes === null) {
            return;
        }
        $this->writeLog($bill, FeeActivityLog::ACTION_UPDATED, $changes);
    }

    public function deleted(Bill $bill): void
    {
        $this->writeLog($bill, FeeActivityLog::ACTION_DELETED, null);
    }

    public function restored(Bill $bill): void
    {
        $this->writeLog($bill, FeeActivityLog::ACTION_RESTORED, null);
    }

    private function computeDiff(Bill $bill): ?array
    {
        $ignored = ['updated_at'];
        $before = [];
        $after = [];

        foreach ($bill->getChanges() as $field => $newValue) {
            if (in_array($field, $ignored, true)) {
                continue;
            }
            $before[$field] = $bill->getOriginal($field);
            $after[$field] = $newValue;
        }

        if (empty($after)) {
            return null;
        }

        return ['before' => $before, 'after' => $after];
    }

    private function writeLog(Bill $bill, string $action, ?array $changes): void
    {
        $actorId = auth()->id();
        if ($actorId === null) {
            return;
        }

        FeeActivityLog::create([
            'school_id' => $bill->school_id,
            'subject_type' => FeeActivityLog::SUBJECT_BILL,
            'subject_id' => $bill->id,
            'action' => $action,
            'actor_kind' => FeeActivityLog::ACTOR_USER,
            'actor_id' => $actorId,
            'changes' => $changes,
        ]);
    }
}

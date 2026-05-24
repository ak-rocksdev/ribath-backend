<?php

namespace App\Observers;

use App\Models\CashBookActivityLog;
use App\Models\CashBookEntry;

class CashBookEntryObserver
{
    public function created(CashBookEntry $entry): void
    {
        $this->writeLog($entry, CashBookActivityLog::ACTION_CREATED, null);
    }

    public function updated(CashBookEntry $entry): void
    {
        $diff = $this->computeDiff($entry);

        if ($diff === null) {
            return;
        }

        $this->writeLog($entry, CashBookActivityLog::ACTION_UPDATED, $diff);
    }

    public function deleted(CashBookEntry $entry): void
    {
        $this->writeLog($entry, CashBookActivityLog::ACTION_DELETED, null);
    }

    public function restored(CashBookEntry $entry): void
    {
        $this->writeLog($entry, CashBookActivityLog::ACTION_RESTORED, null);
    }

    /**
     * Compute before/after diff from Eloquent's getChanges()/getOriginal().
     * Returns null when nothing meaningful changed (skips audit for no-op updates).
     */
    private function computeDiff(CashBookEntry $entry): ?array
    {
        $ignored = ['updated_at', 'updated_by'];
        $before = [];
        $after = [];

        foreach ($entry->getChanges() as $field => $newValue) {
            if (in_array($field, $ignored, true)) {
                continue;
            }

            $before[$field] = $entry->getOriginal($field);
            $after[$field] = $newValue;
        }

        if (empty($after)) {
            return null;
        }

        return ['before' => $before, 'after' => $after];
    }

    private function writeLog(CashBookEntry $entry, string $action, ?array $changes): void
    {
        $actorId = auth()->id();

        if ($actorId === null) {
            return;
        }

        CashBookActivityLog::create([
            'school_id' => $entry->school_id,
            'subject_type' => CashBookActivityLog::SUBJECT_ENTRY,
            'subject_id' => $entry->id,
            'action' => $action,
            'actor_id' => $actorId,
            'changes' => $changes,
        ]);
    }
}

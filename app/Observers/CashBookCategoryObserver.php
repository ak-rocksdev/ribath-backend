<?php

namespace App\Observers;

use App\Models\CashBookActivityLog;
use App\Models\CashBookCategory;

class CashBookCategoryObserver
{
    public function created(CashBookCategory $category): void
    {
        $this->writeLog($category, CashBookActivityLog::ACTION_CREATED, null);
    }

    public function updated(CashBookCategory $category): void
    {
        $diff = $this->computeDiff($category);

        if ($diff === null) {
            return;
        }

        $this->writeLog($category, CashBookActivityLog::ACTION_UPDATED, $diff);
    }

    public function deleted(CashBookCategory $category): void
    {
        $this->writeLog($category, CashBookActivityLog::ACTION_DELETED, null);
    }

    /**
     * Compute before/after diff from Eloquent's getChanges()/getOriginal().
     * Returns null when nothing meaningful changed (skips audit for no-op updates).
     */
    private function computeDiff(CashBookCategory $category): ?array
    {
        $ignored = ['updated_at'];
        $before = [];
        $after = [];

        foreach ($category->getChanges() as $field => $newValue) {
            if (in_array($field, $ignored, true)) {
                continue;
            }

            $before[$field] = $category->getOriginal($field);
            $after[$field] = $newValue;
        }

        if (empty($after)) {
            return null;
        }

        return ['before' => $before, 'after' => $after];
    }

    private function writeLog(CashBookCategory $category, string $action, ?array $changes): void
    {
        $actorId = auth()->id();

        if ($actorId === null) {
            return;
        }

        CashBookActivityLog::create([
            'school_id' => $category->school_id,
            'subject_type' => CashBookActivityLog::SUBJECT_CATEGORY,
            'subject_id' => $category->id,
            'action' => $action,
            'actor_id' => $actorId,
            'changes' => $changes,
        ]);
    }
}

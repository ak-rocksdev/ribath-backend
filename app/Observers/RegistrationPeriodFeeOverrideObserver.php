<?php

namespace App\Observers;

use App\Models\FeeActivityLog;
use App\Models\RegistrationPeriodFeeOverride;

/**
 * Audit trail untuk override biaya per gelombang. Pola sama dengan
 * observer fee_type / fee_schedule — diff disimpan di changes JSON
 * supaya admin bisa lihat "kapan komite ubah dari Rp 500k ke Rp 300k".
 */
class RegistrationPeriodFeeOverrideObserver
{
    public function created(RegistrationPeriodFeeOverride $override): void
    {
        $this->writeLog($override, FeeActivityLog::ACTION_CREATED, null);
    }

    public function updated(RegistrationPeriodFeeOverride $override): void
    {
        $diff = $this->computeDiff($override);

        if ($diff === null) {
            return;
        }

        $this->writeLog($override, FeeActivityLog::ACTION_UPDATED, $diff);
    }

    public function deleted(RegistrationPeriodFeeOverride $override): void
    {
        $this->writeLog($override, FeeActivityLog::ACTION_DELETED, null);
    }

    private function computeDiff(RegistrationPeriodFeeOverride $override): ?array
    {
        $ignored = ['updated_at', 'updated_by'];
        $before = [];
        $after = [];

        foreach ($override->getChanges() as $field => $newValue) {
            if (in_array($field, $ignored, true)) {
                continue;
            }

            $before[$field] = $override->getOriginal($field);
            $after[$field] = $newValue;
        }

        if (empty($after)) {
            return null;
        }

        return ['before' => $before, 'after' => $after];
    }

    private function writeLog(RegistrationPeriodFeeOverride $override, string $action, ?array $changes): void
    {
        $actorId = auth()->id();

        if ($actorId === null) {
            return;
        }

        FeeActivityLog::create([
            'school_id' => $override->school_id,
            'subject_type' => FeeActivityLog::SUBJECT_PERIOD_OVERRIDE,
            'subject_id' => $override->id,
            'action' => $action,
            'actor_kind' => FeeActivityLog::ACTOR_USER,
            'actor_id' => $actorId,
            'changes' => $changes,
        ]);
    }
}

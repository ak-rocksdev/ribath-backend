<?php

namespace App\Observers;

use App\Models\FeeActivityLog;
use App\Models\StudentPayment;

// Audit pattern identik dengan BillObserver; subject_type = `payment`.
// Reversal path uses `StudentPayment::withoutEvents()` to suppress `deleted`
// firing here — the service writes its own `reversed` row instead (spec
// Clarifications ronde 2 Q2: 1 entry per business reversal, not 2).
class StudentPaymentObserver
{
    public function created(StudentPayment $payment): void
    {
        $this->writeLog($payment, FeeActivityLog::ACTION_CREATED, null);
    }

    public function updated(StudentPayment $payment): void
    {
        $changes = $this->computeDiff($payment);
        if ($changes === null) {
            return;
        }
        $this->writeLog($payment, FeeActivityLog::ACTION_UPDATED, $changes);
    }

    public function deleted(StudentPayment $payment): void
    {
        $this->writeLog($payment, FeeActivityLog::ACTION_DELETED, null);
    }

    private function computeDiff(StudentPayment $payment): ?array
    {
        // proof_file_path/mime are post-commit storage details — exposing the
        // disk path in the audit log diff leaks internals to admins and adds
        // noise when the only "change" was attaching a receipt file.
        $ignored = ['updated_at', 'proof_file_path', 'proof_file_mime'];
        $before = [];
        $after = [];

        foreach ($payment->getChanges() as $field => $newValue) {
            if (in_array($field, $ignored, true)) {
                continue;
            }
            $before[$field] = $payment->getOriginal($field);
            $after[$field] = $newValue;
        }

        if (empty($after)) {
            return null;
        }

        return ['before' => $before, 'after' => $after];
    }

    private function writeLog(StudentPayment $payment, string $action, ?array $changes): void
    {
        $actorId = auth()->id();
        if ($actorId === null) {
            return;
        }

        FeeActivityLog::create([
            'school_id' => $payment->school_id,
            'subject_type' => FeeActivityLog::SUBJECT_PAYMENT,
            'subject_id' => $payment->id,
            'action' => $action,
            'actor_kind' => FeeActivityLog::ACTOR_USER,
            'actor_id' => $actorId,
            'changes' => $changes,
        ]);
    }
}

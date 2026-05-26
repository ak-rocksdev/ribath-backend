<?php

namespace App\Services;

use App\Models\FeeSchedule;
use App\Models\RegistrationPeriod;

// Helper terpisah dari controller supaya bisa di-reuse oleh US2 snapshot santri.
class PsbPeriodBiayaResolver
{
    public function resolveForPeriod(RegistrationPeriod $period): array
    {
        return FeeSchedule::with('feeType')
            ->where('academic_year_id', $period->academic_year_id)
            ->get()
            ->filter(fn (FeeSchedule $s) => $s->feeType !== null)
            ->map(fn (FeeSchedule $schedule) => [
                'fee_type_id' => $schedule->fee_type_id,
                'fee_type_label' => $schedule->feeType->label,
                'fee_type_code' => $schedule->feeType->code,
                'cadence' => $schedule->feeType->default_cadence,
                'amount' => (int) $schedule->amount,
            ])
            ->values()
            ->all();
    }
}

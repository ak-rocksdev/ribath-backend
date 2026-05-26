<?php

namespace App\Services;

use App\Models\FeeSchedule;
use App\Models\RegistrationPeriod;
use App\Models\RegistrationPeriodFeeOverride;

/**
 * Resolve "effective biaya" untuk satu periode PSB: ambil semua
 * fee_schedules dari AY periode tsb, lalu apply override dari
 * registration_period_fee_overrides untuk yang cadence
 * `once_at_enrollment`. Output flat list siap-render untuk UI:
 *
 *   [
 *     [
 *       'fee_type_id' => '...',
 *       'fee_type_label' => 'SPP Bulanan',
 *       'fee_type_code' => 'spp',
 *       'cadence' => 'monthly',
 *       'amount' => 500000,
 *       'is_overridden' => false,
 *       'override_reason' => null,
 *     ],
 *     [
 *       'fee_type_id' => '...',
 *       'fee_type_label' => 'Pendaftaran',
 *       'fee_type_code' => 'pendaftaran',
 *       'cadence' => 'once_at_enrollment',
 *       'amount' => 200000,                 // ← from override, not schedule
 *       'is_overridden' => true,
 *       'override_reason' => 'Komite ...',
 *     ],
 *   ]
 *
 * Helper terpisah dari controller supaya bisa di-reuse oleh US2
 * snapshot santri (lookup logic sama: override-first, schedule-fallback).
 */
class PsbPeriodBiayaResolver
{
    public function resolveForPeriod(RegistrationPeriod $period): array
    {
        $schedules = FeeSchedule::with('feeType')
            ->where('academic_year_id', $period->academic_year_id)
            ->get();

        // Index override rows by fee_type_id for O(1) lookup.
        $overrides = RegistrationPeriodFeeOverride::query()
            ->where('registration_period_id', $period->id)
            ->get()
            ->keyBy('fee_type_id');

        return $schedules
            ->filter(fn (FeeSchedule $s) => $s->feeType !== null)
            ->map(function (FeeSchedule $schedule) use ($overrides) {
                $override = $overrides->get($schedule->fee_type_id);

                return [
                    'fee_type_id' => $schedule->fee_type_id,
                    'fee_type_label' => $schedule->feeType->label,
                    'fee_type_code' => $schedule->feeType->code,
                    'cadence' => $schedule->feeType->default_cadence,
                    'amount' => $override ? (int) $override->amount : (int) $schedule->amount,
                    'is_overridden' => (bool) $override,
                    'override_reason' => $override?->reason,
                ];
            })
            ->values()
            ->all();
    }
}

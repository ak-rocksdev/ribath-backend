<?php

namespace App\Services\Keuangan;

use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeException;
use Carbon\Carbon;

class EffectiveAmountCalculator
{
    // Computes the effective billable amount for an assignment at a given date,
    // applying active exceptions (US4):
    //   - any active full_waiver → 0
    //   - else locked_amount - sum(active partial_nominal discounts), capped at 0
    //
    // "Active at $atDate" = effective_from <= atDate AND
    //   (effective_until IS NULL OR effective_until >= atDate).
    //
    // Reuses an already-loaded `exceptions` relation when present (avoids an
    // extra query inside the bill generator loop); otherwise queries directly.
    public function compute(StudentFeeAssignment $assignment, Carbon $atDate): int
    {
        $locked = (int) $assignment->locked_amount;

        $activeExceptions = $this->resolveActiveExceptions($assignment, $atDate);

        foreach ($activeExceptions as $exception) {
            if ($exception->kind === StudentFeeException::KIND_FULL_WAIVER) {
                return 0;
            }
        }

        $totalDiscount = $activeExceptions
            ->where('kind', StudentFeeException::KIND_PARTIAL_NOMINAL)
            ->sum('discount_amount');

        return max(0, $locked - (int) $totalDiscount);
    }

    private function resolveActiveExceptions(StudentFeeAssignment $assignment, Carbon $atDate)
    {
        if ($assignment->relationLoaded('exceptions')) {
            $date = $atDate->toDateString();

            return $assignment->exceptions->filter(function (StudentFeeException $e) use ($date) {
                $from = $e->effective_from?->toDateString();
                $until = $e->effective_until?->toDateString();

                return $from !== null
                    && $from <= $date
                    && ($until === null || $until >= $date);
            });
        }

        return $assignment->exceptions()->activeAt($atDate)->get();
    }
}

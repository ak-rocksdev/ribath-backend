<?php

namespace App\Services\Keuangan;

use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeException;
use Carbon\Carbon;

class EffectiveAmountCalculator
{
    // Active full_waiver → 0; else locked - sum(active partial discounts),
    // capped at 0. Reuses an eager-loaded `exceptions` relation when present
    // (avoids a query per assignment inside the bill generator loop).
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

            return $assignment->exceptions->filter(
                fn (StudentFeeException $e) => StudentFeeException::isActiveAt($e, $date)
            );
        }

        return $assignment->exceptions()->activeAt($atDate)->get();
    }
}

<?php

namespace App\Services\Keuangan;

use App\Models\StudentFeeAssignment;
use Carbon\Carbon;

// US3 stub: returns locked_amount as-is. US4 will extend this to subtract
// active exceptions (full_waiver → 0, partial_nominal → locked - sum(discounts),
// capped at 0) effective at $atDate.
class EffectiveAmountCalculator
{
    public function compute(StudentFeeAssignment $assignment, Carbon $atDate): int
    {
        return (int) $assignment->locked_amount;
    }
}

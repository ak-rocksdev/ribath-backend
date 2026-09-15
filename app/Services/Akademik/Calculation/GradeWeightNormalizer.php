<?php

namespace App\Services\Akademik\Calculation;

/**
 * The ONE weight normalization of the grading feature (spec §4.2).
 *
 * A factor counts when its semester weight row is active and it is not
 * disabled for the student (e.g. UTS switched off for the semester, or a
 * student who entered after the midterm exam date — see
 * MidtermExclusionRule). Both cases go through this same function.
 *
 * Normalized weight = weight ÷ Σ(weights of counted factors) × 100, so the
 * counted weights always add up to 100 even when the stored sum does not.
 * Values are kept unrounded; round only when presenting them.
 *
 * Pure: no queries, no state.
 */
final class GradeWeightNormalizer
{
    /**
     * @param  iterable<array{code: string, weight: float|int|string, is_active: bool}>  $factors
     * @param  array<int, string>  $disabledFactorCodes  codes excluded even when their weight row is active
     * @return array<string, float> code => normalized weight in percent, for counted factors only, in input order;
     *                              empty when nothing counts (or every counted weight is 0)
     */
    public function normalize(iterable $factors, array $disabledFactorCodes = []): array
    {
        $countedWeights = [];

        foreach ($factors as $factor) {
            if (! $factor['is_active'] || in_array($factor['code'], $disabledFactorCodes, true)) {
                continue;
            }

            $countedWeights[$factor['code']] = (float) $factor['weight'];
        }

        $countedWeightSum = array_sum($countedWeights);

        if ($countedWeightSum <= 0) {
            return [];
        }

        return array_map(
            fn (float $weight) => $weight / $countedWeightSum * 100,
            $countedWeights,
        );
    }
}

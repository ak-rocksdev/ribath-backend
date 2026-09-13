<?php

namespace App\Services\Akademik\Calculation;

/**
 * Nilai akhir of one santri for one kitab: Σ(score × normalizedWeight / 100),
 * rounded to 2 decimals (half up).
 *
 * NULL ≠ 0: a counted factor whose score is NULL (belum diinput) is never
 * coalesced to 0 — it is listed as missing and the final score is NULL.
 * A score of 0 is a real score.
 *
 * Pure: no queries, no state.
 */
final class FinalGradeCalculator
{
    public const FINAL_SCORE_DECIMALS = 2;

    /**
     * @param  array<string, float|int|null>  $factorScores  code => score; a code of $normalizedWeights that is absent counts as missing,
     *                                                       codes without a normalized weight are ignored
     * @param  array<string, float>  $normalizedWeights  code => normalized weight (from GradeWeightNormalizer)
     */
    public function calculate(array $factorScores, array $normalizedWeights): FinalGradeResult
    {
        $countedScores = [];
        $missingFactorCodes = [];
        $weightedScoreSum = 0.0;

        foreach ($normalizedWeights as $code => $normalizedWeight) {
            $score = $factorScores[$code] ?? null;
            $countedScores[$code] = $score === null ? null : (float) $score;

            if ($score === null) {
                $missingFactorCodes[] = $code;

                continue;
            }

            $weightedScoreSum += (float) $score * $normalizedWeight;
        }

        $finalScore = ($normalizedWeights === [] || $missingFactorCodes !== [])
            ? null
            : round($weightedScoreSum / 100, self::FINAL_SCORE_DECIMALS);

        return new FinalGradeResult(
            finalScore: $finalScore,
            missingFactorCodes: $missingFactorCodes,
            normalizedWeights: $normalizedWeights,
            factorScores: $countedScores,
        );
    }
}

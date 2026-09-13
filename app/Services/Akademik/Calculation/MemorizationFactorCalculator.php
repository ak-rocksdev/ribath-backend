<?php

namespace App\Services\Akademik\Calculation;

/**
 * The three auto_from_log Tahfizh grading factors (§4.3, provisional),
 * isolated in one function per story 74: Pencapaian Target (`target_hafalan`),
 * Kualitas Setoran (`kualitas_setoran`) and Murajaah (`murajaah`).
 *
 * calculateTargetAchievement: newPages ÷ targetPages × 100, capped at 100
 * (over-achieving a target never scores above 100), rounded to 2 decimals;
 * NULL — never 0 — when there is no target or the target is ≤ 0, since a
 * target-less santri simply isn't being measured against one yet
 * ("Target belum diset"), consistent with NULL ≠ 0 across this feature.
 *
 * calculate(): the same target achievement, plus the average `quality_score`
 * of the santri's Setoran (`submissionQuality`) and Murajaah
 * (`reviewQuality`) logs of the semester — each NULL, never 0, when there
 * is no log of that type yet. Used by MemorizationFactorScoreProvider (the
 * three grading factors) and MemorizationLogService::progressForStudent()
 * (the Progres Hafalan view) — one source of the averaging logic.
 *
 * Pure: no queries, no state.
 */
final class MemorizationFactorCalculator
{
    public function calculateTargetAchievement(?float $targetPages, float $newPages): ?float
    {
        if ($targetPages === null || $targetPages <= 0) {
            return null;
        }

        return round(min($newPages / $targetPages * 100, 100), 2);
    }

    /**
     * @param  array<int, float|int>  $newQualityScores  quality_score of the semester's Setoran (`new`) logs
     * @param  array<int, float|int>  $reviewQualityScores  quality_score of the semester's Murajaah (`review`) logs
     */
    public function calculate(?float $targetPages, float $newPages, array $newQualityScores, array $reviewQualityScores): MemorizationFactorResult
    {
        return new MemorizationFactorResult(
            targetAchievement: $this->calculateTargetAchievement($targetPages, $newPages),
            submissionQuality: $this->averageOf($newQualityScores),
            reviewQuality: $this->averageOf($reviewQualityScores),
        );
    }

    /**
     * @param  array<int, float|int>  $scores
     */
    private function averageOf(array $scores): ?float
    {
        if ($scores === []) {
            return null;
        }

        return round(array_sum($scores) / count($scores), 2);
    }
}

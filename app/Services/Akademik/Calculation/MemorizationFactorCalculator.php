<?php

namespace App\Services\Akademik\Calculation;

/**
 * Tahfizh auto_from_log grading factors (Target Hafalan's `target_hafalan`
 * factor first — Task 14; `kualitas_setoran`/`murajaah` land in Task 15,
 * which extends this same class rather than creating a sibling one).
 *
 * calculateTargetAchievement: newPages ÷ targetPages × 100, capped at 100
 * (over-achieving a target never scores above 100), rounded to 2 decimals;
 * NULL — never 0 — when there is no target or the target is ≤ 0, since a
 * target-less santri simply isn't being measured against one yet
 * ("Target belum diset"), consistent with NULL ≠ 0 across this feature.
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
}

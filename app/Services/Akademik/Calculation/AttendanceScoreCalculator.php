<?php

namespace App\Services\Akademik\Calculation;

/**
 * Nilai Absensi = hadir ÷ (hadir + alpa) × 100, dibulatkan 2 desimal; NULL
 * (never 0) when the denominator is 0 — no held Pertemuan has decided the
 * santri's presence yet. Sakit and izin are neutral: they never enter the
 * numerator or the denominator.
 *
 * @provisional Spec §3.4b: the denominator is "Pertemuan yang tercatat
 * untuk santri itu" (present + absent only), not the total scheduled
 * Pertemuan of the semester — so a santri is never penalized for a
 * Pertemuan Bolong (a teacher's missed recording). Flagged provisional by
 * the spec (§11 "penyebut absensi saat Pertemuan bolong") and must be
 * reviewed after a trial period; this rule lives in this one isolated
 * function so it can be replaced without touching the recap or provider.
 *
 * Pure: no queries, no state.
 */
final class AttendanceScoreCalculator
{
    public function calculate(int $presentCount, int $absentCount): ?float
    {
        $denominator = $presentCount + $absentCount;

        if ($denominator === 0) {
            return null;
        }

        return round($presentCount / $denominator * 100, 2);
    }
}

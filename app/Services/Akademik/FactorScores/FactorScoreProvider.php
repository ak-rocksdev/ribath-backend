<?php

namespace App\Services\Akademik\FactorScores;

use App\Models\GradingFactor;

/**
 * Supplies scores of a non-manual factor (Tugas, Absensi, Tahfizh) for the
 * santri of a FactorScoreContext. Implementations must be pure with respect
 * to the recap: read the source rows, return scores; NULL (never 0) when a
 * santri's score cannot be computed yet.
 *
 * Register an implementation in GradingServiceProvider::FACTOR_SCORE_PROVIDERS.
 */
interface FactorScoreProvider
{
    public function supports(GradingFactor $factor): bool;

    /**
     * @return array<string, FactorScore> student id => score; santri left out are treated as FactorScore(null, null)
     */
    public function scoresFor(GradingFactor $factor, FactorScoreContext $context): array;
}

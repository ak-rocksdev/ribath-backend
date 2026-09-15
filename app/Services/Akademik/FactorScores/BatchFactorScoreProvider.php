<?php

namespace App\Services\Akademik\FactorScores;

use App\Models\GradingFactor;

/**
 * Optional capability of a FactorScoreProvider that scores several of its
 * factors from one shared read of the context — e.g. the three Tahfizh
 * factors from one pair of memorization queries instead of one pair per
 * factor. FactorScoreProviderRegistry::scoresForFactors() hands such a
 * provider all of its factors of one context in a single call.
 *
 * Stateless like every provider: whatever is read or computed lives only
 * for that one call, never across calls or contexts.
 */
interface BatchFactorScoreProvider extends FactorScoreProvider
{
    /**
     * @param  array<int, GradingFactor>  $factors  factors this provider supports()
     * @return array<string, array<string, FactorScore>> factor code => student id => score; santri left out are treated as FactorScore(null, null)
     */
    public function scoresForFactors(array $factors, FactorScoreContext $context): array;
}

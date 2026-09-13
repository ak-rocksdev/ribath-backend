<?php

namespace App\Services\Akademik\FactorScores;

use App\Models\GradingFactor;
use App\Models\Student;

/**
 * Routes a factor to the first registered FactorScoreProvider that supports
 * it. A factor no provider supports — or a santri a provider leaves out —
 * yields FactorScore(null, null): belum lengkap, never 0.
 *
 * Built by GradingServiceProvider from its FACTOR_SCORE_PROVIDERS list.
 */
final class FactorScoreProviderRegistry
{
    /** @var array<int, FactorScoreProvider> */
    private array $providers;

    /**
     * @param  iterable<FactorScoreProvider>  $providers
     */
    public function __construct(iterable $providers = [])
    {
        $this->providers = is_array($providers) ? array_values($providers) : iterator_to_array($providers, false);
    }

    public function supports(GradingFactor $factor): bool
    {
        return $this->providerFor($factor) !== null;
    }

    /**
     * @return array<string, FactorScore> one entry per santri of the context, keyed by student id, in context order
     */
    public function scoresFor(GradingFactor $factor, FactorScoreContext $context): array
    {
        $providedScores = $this->providerFor($factor)?->scoresFor($factor, $context) ?? [];

        $scoresByStudentId = [];
        foreach ($context->students as $student) {
            /** @var Student $student */
            $scoresByStudentId[$student->id] = $providedScores[$student->id] ?? new FactorScore(null, null);
        }

        return $scoresByStudentId;
    }

    private function providerFor(GradingFactor $factor): ?FactorScoreProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($factor)) {
                return $provider;
            }
        }

        return null;
    }
}

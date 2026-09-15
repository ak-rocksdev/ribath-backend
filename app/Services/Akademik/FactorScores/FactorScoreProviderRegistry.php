<?php

namespace App\Services\Akademik\FactorScores;

use App\Models\GradingFactor;
use App\Models\Student;

/**
 * Routes a factor to the first registered FactorScoreProvider that supports
 * it. A factor no provider supports — or a santri a provider leaves out —
 * yields FactorScore(null, null): belum lengkap, never 0.
 *
 * scoresForFactors() scores several factors of one context at once, so a
 * BatchFactorScoreProvider reads its source rows once for all its factors.
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
        return $this->fillEveryStudent($this->providerFor($factor)?->scoresFor($factor, $context) ?? [], $context);
    }

    /**
     * Scores of every given factor for the one context — the same result as
     * calling scoresFor() per factor, but a BatchFactorScoreProvider is
     * called once with all of its factors; any other provider per factor.
     *
     * @param  iterable<GradingFactor>  $factors
     * @return array<string, array<string, FactorScore>> factor code => (student id => score, in context order), in the given factor order
     */
    public function scoresForFactors(iterable $factors, FactorScoreContext $context): array
    {
        $factors = is_array($factors) ? array_values($factors) : iterator_to_array($factors, false);

        $factorsByProviderIndex = [];
        foreach ($factors as $factor) {
            $providerIndex = $this->providerIndexFor($factor);

            if ($providerIndex !== null) {
                $factorsByProviderIndex[$providerIndex][] = $factor;
            }
        }

        $providedScoresByCode = [];
        foreach ($factorsByProviderIndex as $providerIndex => $providerFactors) {
            $provider = $this->providers[$providerIndex];

            if ($provider instanceof BatchFactorScoreProvider) {
                foreach ($provider->scoresForFactors($providerFactors, $context) as $code => $providedScores) {
                    $providedScoresByCode[$code] = $providedScores;
                }

                continue;
            }

            foreach ($providerFactors as $factor) {
                $providedScoresByCode[$factor->code] = $provider->scoresFor($factor, $context);
            }
        }

        $scoresByCode = [];
        foreach ($factors as $factor) {
            $scoresByCode[$factor->code] = $this->fillEveryStudent($providedScoresByCode[$factor->code] ?? [], $context);
        }

        return $scoresByCode;
    }

    /**
     * @param  array<string, FactorScore>  $providedScores
     * @return array<string, FactorScore> one entry per santri of the context, keyed by student id, in context order
     */
    private function fillEveryStudent(array $providedScores, FactorScoreContext $context): array
    {
        $scoresByStudentId = [];
        foreach ($context->students as $student) {
            /** @var Student $student */
            $scoresByStudentId[$student->id] = $providedScores[$student->id] ?? new FactorScore(null, null);
        }

        return $scoresByStudentId;
    }

    private function providerFor(GradingFactor $factor): ?FactorScoreProvider
    {
        $providerIndex = $this->providerIndexFor($factor);

        return $providerIndex === null ? null : $this->providers[$providerIndex];
    }

    private function providerIndexFor(GradingFactor $factor): ?int
    {
        foreach ($this->providers as $providerIndex => $provider) {
            if ($provider->supports($factor)) {
                return $providerIndex;
            }
        }

        return null;
    }
}

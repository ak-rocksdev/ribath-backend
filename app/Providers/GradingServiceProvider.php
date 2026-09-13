<?php

namespace App\Providers;

use App\Services\Akademik\FactorScores\FactorScoreProvider;
use App\Services\Akademik\FactorScores\FactorScoreProviderRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring of the Penilaian (grading) feature.
 */
class GradingServiceProvider extends ServiceProvider
{
    /**
     * Scores of the non-manual grading factors (Tugas, Absensi, Tahfizh) are
     * supplied by these providers, asked in this order; the first whose
     * supports() accepts a factor wins. Register a provider by adding its
     * class here — it is resolved through the container, so it may use
     * constructor injection.
     *
     * @var array<int, class-string<FactorScoreProvider>>
     */
    public const FACTOR_SCORE_PROVIDERS = [
        // e.g. \App\Services\Akademik\FactorScores\TaskFactorScoreProvider::class,
    ];

    public function register(): void
    {
        $this->app->bind(FactorScoreProviderRegistry::class, fn (Application $app) => new FactorScoreProviderRegistry(
            array_map(fn (string $providerClass) => $app->make($providerClass), self::FACTOR_SCORE_PROVIDERS),
        ));
    }
}

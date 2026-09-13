<?php

namespace App\Services\Akademik\FactorScores;

/**
 * One santri's score for one factor, as supplied by a FactorScoreProvider.
 * A NULL score means "belum lengkap" (never 0); $missingReason optionally
 * tells the user why (e.g. "Target hafalan belum ditetapkan.").
 */
final readonly class FactorScore
{
    public function __construct(
        public ?float $score,
        public ?string $missingReason = null,
    ) {}

    public function isMissing(): bool
    {
        return $this->score === null;
    }
}

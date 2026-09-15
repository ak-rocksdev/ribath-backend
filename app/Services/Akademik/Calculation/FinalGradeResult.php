<?php

namespace App\Services\Akademik\Calculation;

/**
 * Outcome of FinalGradeCalculator with the full breakdown it was computed
 * from, so a finalized rapor can snapshot it (ADR 0001).
 */
final readonly class FinalGradeResult
{
    /**
     * @param  float|null  $finalScore  rounded to 2 decimals; NULL while any counted factor is missing
     * @param  array<int, string>  $missingFactorCodes  counted factors whose score is NULL, in weight order
     * @param  array<string, float>  $normalizedWeights  code => normalized weight (unrounded) of the counted factors
     * @param  array<string, float|null>  $factorScores  code => score of the counted factors (NULL = belum diinput)
     */
    public function __construct(
        public ?float $finalScore,
        public array $missingFactorCodes,
        public array $normalizedWeights,
        public array $factorScores,
    ) {}

    public function isComplete(): bool
    {
        return $this->finalScore !== null;
    }

    /**
     * @return array{final_score: float|null, is_complete: bool, missing_factor_codes: array<int, string>, normalized_weights: array<string, float>, factor_scores: array<string, float|null>}
     */
    public function toArray(): array
    {
        return [
            'final_score' => $this->finalScore,
            'is_complete' => $this->isComplete(),
            'missing_factor_codes' => $this->missingFactorCodes,
            'normalized_weights' => $this->normalizedWeights,
            'factor_scores' => $this->factorScores,
        ];
    }
}

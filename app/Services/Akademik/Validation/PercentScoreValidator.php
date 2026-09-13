<?php

namespace App\Services\Akademik\Validation;

/**
 * The ONE percent-scale score validator of the grading feature: null
 * (kosongkan) is always valid; otherwise the value must be numeric,
 * between 0 and 100, with at most 2 decimal places.
 *
 * Shared by StudentGradeService::validateCell() (Input Nilai's percent
 * factors) and ClassTaskService::validateScore() (Tugas), so the three
 * Indonesian messages are defined in exactly one place.
 *
 * Pure: no queries, no state.
 */
final class PercentScoreValidator
{
    public const MESSAGE_NOT_NUMERIC = 'Nilai harus berupa angka.';

    public const MESSAGE_OUT_OF_RANGE = 'Nilai harus antara 0 dan 100.';

    public const MESSAGE_TOO_MANY_DECIMALS = 'Nilai maksimal 2 angka desimal.';

    /**
     * @return string|null the Indonesian error message, or null when the score is valid (including null)
     */
    public function validate(mixed $score): ?string
    {
        if ($score === null) {
            return null;
        }

        if (is_bool($score) || ! is_numeric($score)) {
            return self::MESSAGE_NOT_NUMERIC;
        }

        $numericScore = (float) $score;

        if ($numericScore < 0 || $numericScore > 100) {
            return self::MESSAGE_OUT_OF_RANGE;
        }

        if (abs(round($numericScore, 2) - $numericScore) > 1e-9) {
            return self::MESSAGE_TOO_MANY_DECIMALS;
        }

        return null;
    }
}

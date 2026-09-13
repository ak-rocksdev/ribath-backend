<?php

namespace App\Services\Akademik\FactorScores;

use App\Models\GradingFactor;
use InvalidArgumentException;

/**
 * Where a factor's score comes from, as shown in the recap (ruling R4).
 */
enum FactorScoreSource: string
{
    case Manual = 'manual';
    case Tugas = 'tugas';
    case Absensi = 'absensi';
    case Hafalan = 'hafalan';

    public static function forInputType(string $inputType): self
    {
        return match ($inputType) {
            GradingFactor::INPUT_TYPE_MANUAL_ONCE,
            GradingFactor::INPUT_TYPE_END_OF_SEMESTER_BULK => self::Manual,
            GradingFactor::INPUT_TYPE_MANUAL_PERIODIC => self::Tugas,
            GradingFactor::INPUT_TYPE_AUTO_FROM_ATTENDANCE => self::Absensi,
            GradingFactor::INPUT_TYPE_AUTO_FROM_LOG => self::Hafalan,
            default => throw new InvalidArgumentException("Unknown grading factor input type [{$inputType}]."),
        };
    }
}

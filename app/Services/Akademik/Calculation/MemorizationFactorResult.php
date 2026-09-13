<?php

namespace App\Services\Akademik\Calculation;

/**
 * The three auto_from_log Tahfizh grading factors for one santri in one
 * semester akademik, as computed by MemorizationFactorCalculator::calculate()
 * (§4.3, provisional). All fields are ?float, 2 decimals, NULL — never 0 —
 * when the underlying data does not exist yet:
 *
 *  - targetAchievement: NULL without a Target Hafalan ("Target belum diset").
 *  - submissionQuality: NULL without any Setoran log ("Belum ada setoran").
 *  - reviewQuality: NULL without any Murajaah log ("Belum ada murajaah").
 *
 * Read by MemorizationFactorScoreProvider (the target_hafalan /
 * kualitas_setoran / murajaah grading factors) and by
 * MemorizationLogService::progressForStudent() (the Progres Hafalan view) —
 * one calculation, two consumers.
 */
final readonly class MemorizationFactorResult
{
    public function __construct(
        public ?float $targetAchievement,
        public ?float $submissionQuality,
        public ?float $reviewQuality,
    ) {}

    /**
     * @return array{target_achievement: ?float, submission_quality: ?float, review_quality: ?float}
     */
    public function toArray(): array
    {
        return [
            'target_achievement' => $this->targetAchievement,
            'submission_quality' => $this->submissionQuality,
            'review_quality' => $this->reviewQuality,
        ];
    }
}

<?php

namespace App\Services\Akademik\FactorScores;

use App\Models\GradingFactor;
use App\Models\MemorizationLog;
use App\Models\MemorizationTarget;
use App\Models\School;
use App\Models\Student;
use App\Services\Akademik\Calculation\MemorizationFactorCalculator;
use App\Services\Akademik\Calculation\MemorizationFactorResult;

/**
 * Scores the three auto_from_log Tahfizh factors of the fixed catalogue —
 * `target_hafalan`, `kualitas_setoran`, `murajaah` — from MemorizationTarget
 * and MemorizationLog, via MemorizationFactorCalculator::calculate() (§4.3,
 * provisional). Each factor code reads one field of the santri's
 * MemorizationFactorResult (documented in RESULT_FIELD_BY_CODE — the one
 * place this mapping lives, since a provider must know which number each
 * factor code means):
 *
 *  - target_hafalan   => targetAchievement, NULL "Target belum diset" without a Target Hafalan
 *  - kualitas_setoran => submissionQuality, NULL "Belum ada setoran" without a Setoran log
 *  - murajaah         => reviewQuality, NULL "Belum ada murajaah" without a Murajaah log
 *
 * An auto_from_log factor with any other code — never produced by the
 * fixed catalogue, but a provider must not throw on it — scores NULL,
 * "Faktor hafalan tidak dikenal".
 *
 * One pair of bulk queries (targets, logs) computes every student's
 * MemorizationFactorResult once per FactorScoreContext, memoized by
 * context identity for the lifetime of this provider instance — the
 * registry calls scoresFor() once per factor (three times for the three
 * codes above), but the underlying data only needs to be read once.
 */
class MemorizationFactorScoreProvider implements FactorScoreProvider
{
    public const MESSAGE_NO_TARGET = 'Target belum diset';

    public const MESSAGE_NO_SUBMISSIONS = 'Belum ada setoran';

    public const MESSAGE_NO_REVIEWS = 'Belum ada murajaah';

    public const MESSAGE_UNKNOWN_FACTOR = 'Faktor hafalan tidak dikenal';

    /** Grading factor code => MemorizationFactorResult field it reads. */
    private const RESULT_FIELD_BY_CODE = [
        'target_hafalan' => 'targetAchievement',
        'kualitas_setoran' => 'submissionQuality',
        'murajaah' => 'reviewQuality',
    ];

    private const MISSING_REASON_BY_FIELD = [
        'targetAchievement' => self::MESSAGE_NO_TARGET,
        'submissionQuality' => self::MESSAGE_NO_SUBMISSIONS,
        'reviewQuality' => self::MESSAGE_NO_REVIEWS,
    ];

    /** @var array<int, array<string, MemorizationFactorResult>> spl_object_id(context) => student id => result */
    private array $resultsByContextId = [];

    public function __construct(
        private MemorizationFactorCalculator $memorizationFactorCalculator,
    ) {}

    public function supports(GradingFactor $factor): bool
    {
        return $factor->input_type === GradingFactor::INPUT_TYPE_AUTO_FROM_LOG;
    }

    /**
     * @return array<string, FactorScore>
     */
    public function scoresFor(GradingFactor $factor, FactorScoreContext $context): array
    {
        $field = self::RESULT_FIELD_BY_CODE[$factor->code] ?? null;

        if ($field === null) {
            return $context->students->mapWithKeys(
                fn (Student $student) => [$student->id => new FactorScore(null, self::MESSAGE_UNKNOWN_FACTOR)]
            )->all();
        }

        $resultsByStudentId = $this->resultsFor($context);

        return $context->students->mapWithKeys(function (Student $student) use ($resultsByStudentId, $field) {
            $value = $resultsByStudentId[$student->id]->{$field};

            return [$student->id => new FactorScore($value, $value === null ? self::MISSING_REASON_BY_FIELD[$field] : null)];
        })->all();
    }

    /**
     * @return array<string, MemorizationFactorResult> student id => result, memoized per context object
     */
    private function resultsFor(FactorScoreContext $context): array
    {
        $contextId = spl_object_id($context);

        if (isset($this->resultsByContextId[$contextId])) {
            return $this->resultsByContextId[$contextId];
        }

        $school = School::activeOrFail();
        $studentIds = $context->students->pluck('id');

        $targetPagesByStudentId = MemorizationTarget::query()
            ->where('school_id', $school->id)
            ->where('academic_year_id', $context->academicYearId)
            ->where('semester', $context->semester)
            ->whereIn('student_id', $studentIds)
            ->pluck('target_pages', 'student_id');

        $logsByStudentId = MemorizationLog::query()
            ->where('school_id', $school->id)
            ->where('academic_year_id', $context->academicYearId)
            ->where('semester', $context->semester)
            ->whereIn('student_id', $studentIds)
            ->get(['student_id', 'type', 'pages', 'quality_score'])
            ->groupBy('student_id');

        $results = [];

        foreach ($context->students as $student) {
            $studentLogs = $logsByStudentId->get($student->id) ?? collect();
            $newLogs = $studentLogs->where('type', MemorizationLog::TYPE_NEW);
            $reviewLogs = $studentLogs->where('type', MemorizationLog::TYPE_REVIEW);

            $targetPages = $targetPagesByStudentId->has($student->id)
                ? (float) $targetPagesByStudentId->get($student->id)
                : null;
            $totalNewPages = (float) $newLogs->sum(fn (MemorizationLog $log) => (float) $log->pages);

            $results[$student->id] = $this->memorizationFactorCalculator->calculate(
                $targetPages,
                $totalNewPages,
                $newLogs->map(fn (MemorizationLog $log) => (float) $log->quality_score)->all(),
                $reviewLogs->map(fn (MemorizationLog $log) => (float) $log->quality_score)->all(),
            );
        }

        $this->resultsByContextId[$contextId] = $results;

        return $results;
    }
}

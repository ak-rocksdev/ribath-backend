<?php

namespace App\Services\Akademik\FactorScores;

use App\Models\GradingFactor;
use App\Services\Akademik\AttendanceTallyService;
use App\Services\Akademik\Calculation\AttendanceScoreCalculator;

/**
 * Scores the "absensi" (auto_from_attendance) factor: hadir ÷ (hadir +
 * alpa) per student, via AttendanceScoreCalculator, fed by the tallies
 * AttendanceTallyService computes — the same tallies the standalone Rekap
 * Kehadiran endpoint (AttendanceRecapService) reports, so both always
 * agree.
 *
 * NULL (never 0), with a reason: no counted Pertemuan at all for the
 * student ("Belum ada pertemuan tercatat"), or every counted Pertemuan was
 * sakit/izin — a 0 denominator ("Hanya sakit/izin").
 */
class AttendanceFactorScoreProvider implements FactorScoreProvider
{
    public const MESSAGE_NO_SESSIONS = 'Belum ada pertemuan tercatat';

    public const MESSAGE_ONLY_SICK_OR_EXCUSED = 'Hanya sakit/izin';

    public function __construct(
        private AttendanceTallyService $attendanceTallyService,
        private AttendanceScoreCalculator $attendanceScoreCalculator,
    ) {}

    public function supports(GradingFactor $factor): bool
    {
        return $factor->input_type === GradingFactor::INPUT_TYPE_AUTO_FROM_ATTENDANCE;
    }

    /**
     * @return array<string, FactorScore>
     */
    public function scoresFor(GradingFactor $factor, FactorScoreContext $context): array
    {
        $tallies = $this->attendanceTallyService->talliesFor(
            $context->academicYearId,
            $context->semester,
            $context->classLevelId,
            $context->subjectBookId,
            $context->students,
        );

        return $context->students->mapWithKeys(
            fn ($student) => [$student->id => $this->scoreFromTally($tallies[$student->id])]
        )->all();
    }

    /**
     * Maps one student's tally to a FactorScore. Shared with
     * AttendanceRecapService so the standalone Rekap Kehadiran endpoint
     * reports the exact same score and reason as this factor does in Rekap
     * Nilai.
     *
     * @param  array{present: int, sick: int, excused: int, absent: int, recorded_session_count: int}  $tally
     */
    public function scoreFromTally(array $tally): FactorScore
    {
        if ($tally['recorded_session_count'] === 0) {
            return new FactorScore(null, self::MESSAGE_NO_SESSIONS);
        }

        $score = $this->attendanceScoreCalculator->calculate($tally['present'], $tally['absent']);

        if ($score === null) {
            return new FactorScore(null, self::MESSAGE_ONLY_SICK_OR_EXCUSED);
        }

        return new FactorScore($score);
    }
}

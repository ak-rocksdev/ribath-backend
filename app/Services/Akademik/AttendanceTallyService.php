<?php

namespace App\Services\Akademik;

use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Services\Akademik\Calculation\EnrollmentDateRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The ONE place that counts Pertemuan/Absensi for a Kelas × Kitab ×
 * semester — used by AttendanceFactorScoreProvider (Rekap Nilai's Absensi
 * factor) and AttendanceRecapService (the standalone Rekap Kehadiran
 * endpoint), so both report the exact same counts.
 *
 * A session counts for a student when: it is `held` (never `cancelled`,
 * never soft-deleted — the model's default scope already excludes those),
 * it matches the context's (academic_year_id, semester, subject_book_id[,
 * class_level_id]) via the session's own snapshot columns, the student has
 * an attendance row for it, and the session's date is on or after the
 * student's entry_date (EnrollmentDateRule; no entry_date = always
 * expected). A held session with no attendance row for a student was
 * recorded before that student joined the class (or the student was
 * non-active and optional) and is simply not counted for them.
 *
 * Two queries total regardless of student count: every held session of the
 * pair, then every attendance row of those sessions for the given
 * students — no N+1.
 */
class AttendanceTallyService
{
    /**
     * @param  Collection<int, Student>  $students  with at least id and entry_date loaded
     * @return array<string, array{present: int, sick: int, excused: int, absent: int, recorded_session_count: int}> keyed by student id
     */
    public function talliesFor(string $academicYearId, int $semester, ?string $classLevelId, string $subjectBookId, Collection $students): array
    {
        $heldSessions = $this->heldSessionsQuery($academicYearId, $semester, $classLevelId, $subjectBookId)
            ->get(['id', 'session_date']);

        $sessionDateById = $heldSessions->pluck('session_date', 'id');

        $attendanceRowsByStudentId = StudentAttendance::query()
            ->whereIn('class_session_id', $sessionDateById->keys())
            ->whereIn('student_id', $students->pluck('id'))
            ->get(['class_session_id', 'student_id', 'status'])
            ->groupBy('student_id');

        $enrollmentDateRule = new EnrollmentDateRule;

        return $students->mapWithKeys(function (Student $student) use ($attendanceRowsByStudentId, $sessionDateById, $enrollmentDateRule) {
            $countedRows = ($attendanceRowsByStudentId->get($student->id) ?? collect())
                ->filter(function (StudentAttendance $attendance) use ($sessionDateById, $enrollmentDateRule, $student) {
                    $sessionDate = $sessionDateById->get($attendance->class_session_id);

                    return $sessionDate !== null && $enrollmentDateRule->isExpectedOn($student, $sessionDate);
                });

            return [$student->id => [
                'present' => $countedRows->where('status', StudentAttendance::STATUS_PRESENT)->count(),
                'sick' => $countedRows->where('status', StudentAttendance::STATUS_SICK)->count(),
                'excused' => $countedRows->where('status', StudentAttendance::STATUS_EXCUSED)->count(),
                'absent' => $countedRows->where('status', StudentAttendance::STATUS_ABSENT)->count(),
                'recorded_session_count' => $countedRows->count(),
            ]];
        })->all();
    }

    /**
     * Held and cancelled Pertemuan counts of a Kelas × Kitab × semester, for
     * the Rekap Kehadiran header — independent of any single student's
     * enrollment date.
     *
     * @return array{held: int, cancelled: int}
     */
    public function sessionCountsFor(string $academicYearId, int $semester, ?string $classLevelId, string $subjectBookId): array
    {
        $countsByStatus = ClassSession::query()
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->where('subject_book_id', $subjectBookId)
            ->when($classLevelId, fn ($query) => $query->where('class_level_id', $classLevelId))
            ->selectRaw('status, COUNT(*) as session_count')
            ->groupBy('status')
            ->pluck('session_count', 'status');

        return [
            'held' => (int) ($countsByStatus[ClassSession::STATUS_HELD] ?? 0),
            'cancelled' => (int) ($countsByStatus[ClassSession::STATUS_CANCELLED] ?? 0),
        ];
    }

    private function heldSessionsQuery(string $academicYearId, int $semester, ?string $classLevelId, string $subjectBookId): Builder
    {
        return ClassSession::query()
            ->where('status', ClassSession::STATUS_HELD)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->where('subject_book_id', $subjectBookId)
            ->when($classLevelId, fn ($query) => $query->where('class_level_id', $classLevelId));
    }
}

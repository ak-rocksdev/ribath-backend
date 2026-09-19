<?php

namespace App\Services\Akademik;

use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Services\Akademik\Calculation\EnrollmentDateRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The ONE place that counts Pertemuan/Absensi for a Kelas × Kitab ×
 * semester — used by AttendanceFactorScoreProvider (Rekap Nilai's Absensi
 * factor) and AttendanceRecapService (the standalone Rekap Kehadiran
 * endpoint), so both report the exact same counts.
 *
 * Which Pertemuan belong to a Kelas: one of a jadwal gabungan covers
 * several Kelas at once (ADR 0006), so the class_sessions snapshot alone no
 * longer answers it. A Pertemuan counts for a Kelas when
 *
 * - its own snapshot names that Kelas — the ordinary single-class
 *   Pertemuan, and one recorded before its schedule moved to another Kelas,
 *   which keeps counting for the Kelas it was held for;
 * - or an Absensi row of it names that Kelas — the Kelas of a jadwal
 *   gabungan, still counted after the Kelas is taken off the schedule, so
 *   its Rapor can be completed (ADR 0005);
 * - or its Jadwal Mengajar holds that Kelas together with the snapshot one
 *   — a cancelled Pertemuan of a jadwal gabungan, which has no rows.
 *
 * A session counts for a student when: it is `held` (never `cancelled`,
 * never soft-deleted — the model's default scope already excludes those),
 * it belongs to the Kelas × Kitab × semester as above, the student has an
 * attendance row for it recorded for that Kelas, and the session's date is
 * on or after the student's entry_date (EnrollmentDateRule; no entry_date =
 * always expected). A held session with no attendance row for a student was
 * recorded before that student joined the class (or the student was
 * non-active and optional) and is simply not counted for them.
 *
 * A fixed number of queries regardless of student count — the attendance
 * rows of every counted session are read in one go, no N+1.
 */
class AttendanceTallyService
{
    /**
     * @param  Collection<int, Student>  $students  with at least id and entry_date loaded
     * @return array<string, array{present: int, sick: int, excused: int, absent: int, recorded_session_count: int}> keyed by student id
     */
    public function talliesFor(string $academicYearId, int $semester, ?string $classLevelId, string $subjectBookId, Collection $students): array
    {
        $heldSessions = $this->sessionsOfPair($academicYearId, $semester, $classLevelId, $subjectBookId)
            ->where('status', ClassSession::STATUS_HELD);

        $sessionDateById = $heldSessions->pluck('session_date', 'id');

        $attendanceRowsByStudentId = StudentAttendance::query()
            ->whereIn('class_session_id', $sessionDateById->keys())
            ->whereIn('student_id', $students->pluck('id'))
            ->when($classLevelId, fn ($query) => $query->where('class_level_id', $classLevelId))
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
        $countsByStatus = $this->sessionsOfPair($academicYearId, $semester, $classLevelId, $subjectBookId)
            ->countBy('status');

        return [
            'held' => (int) $countsByStatus->get(ClassSession::STATUS_HELD, 0),
            'cancelled' => (int) $countsByStatus->get(ClassSession::STATUS_CANCELLED, 0),
        ];
    }

    /**
     * The Pertemuan of the Kelas × Kitab × semester (see the class doc for
     * what "of a Kelas" means); every Pertemuan of the Kitab when no Kelas
     * is given.
     *
     * @return Collection<int, ClassSession>
     */
    private function sessionsOfPair(string $academicYearId, int $semester, ?string $classLevelId, string $subjectBookId): Collection
    {
        $sessions = ClassSession::query()
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->where('subject_book_id', $subjectBookId)
            ->get(['id', 'status', 'session_date', 'class_level_id', 'teaching_schedule_id']);

        if ($classLevelId === null || $sessions->isEmpty()) {
            return $sessions;
        }

        $sessionIdsWithClassAttendance = StudentAttendance::query()
            ->whereIn('class_session_id', $sessions->pluck('id'))
            ->where('class_level_id', $classLevelId)
            ->distinct()
            ->pluck('class_session_id')
            ->flip();

        $scheduleClassLevelIds = DB::table('teaching_schedule_class_levels')
            ->whereIn('teaching_schedule_id', $sessions->pluck('teaching_schedule_id')->unique()->filter())
            ->get(['teaching_schedule_id', 'class_level_id'])
            ->groupBy('teaching_schedule_id')
            ->map(fn (Collection $rows) => $rows->pluck('class_level_id')->flip());

        return $sessions->filter(function (ClassSession $session) use ($classLevelId, $sessionIdsWithClassAttendance, $scheduleClassLevelIds) {
            if ($session->class_level_id === $classLevelId || $sessionIdsWithClassAttendance->has($session->id)) {
                return true;
            }

            $classLevelIdsOfSchedule = $scheduleClassLevelIds->get($session->teaching_schedule_id);

            return $classLevelIdsOfSchedule !== null
                && $classLevelIdsOfSchedule->has($classLevelId)
                && $classLevelIdsOfSchedule->has($session->class_level_id);
        })->values();
    }
}

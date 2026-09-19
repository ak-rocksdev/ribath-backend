<?php

namespace App\Services\Akademik;

use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentAttendance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The ONE place that counts Pertemuan/Absensi for a Kelas × Kitab ×
 * semester — used by AttendanceFactorScoreProvider (Rekap Nilai's Absensi
 * factor) and AttendanceRecapService (the standalone Rekap Kehadiran
 * endpoint), so both report the exact same counts. Each of them resolves
 * the Pertemuan of the pair once (sessionsOfPair()) and reads both the
 * per-student tallies and the header counts from that one collection.
 *
 * Which Pertemuan belong to a Kelas: one of a jadwal gabungan covers
 * several Kelas at once, so a Pertemuan carries the Kelas it was held for
 * as a set of its own (ADR 0006) — that set, and nothing else, answers it.
 * A Kelas taken off the schedule afterwards therefore keeps every Pertemuan
 * already held for it, so its Rapor can still be completed (ADR 0005).
 *
 * A session counts for a student when: it is `held` (never `cancelled`,
 * never soft-deleted — the model's default scope already excludes those),
 * it belongs to the Kelas × Kitab × semester as above, the student has an
 * attendance row for it recorded for that Kelas, and the session's date is
 * on or after the student's entry_date (EnrollmentDateRule, applied here in
 * SQL; no entry_date = always expected). A held session with no attendance
 * row for a student was recorded before that student joined the class (or
 * the student was non-active and optional) and is simply not counted for
 * them.
 *
 * Two queries whatever the number of students: the Pertemuan of the pair,
 * and one grouped count of their Absensi rows.
 */
class AttendanceTallyService
{
    /**
     * The Pertemuan of a Kelas × Kitab × semester — every Pertemuan of the
     * Kitab when no Kelas is given — with the status and date the counts
     * below need.
     *
     * @return Collection<int, ClassSession>
     */
    public function sessionsOfPair(string $academicYearId, int $semester, ?string $classLevelId, string $subjectBookId): Collection
    {
        return ClassSession::query()
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->where('subject_book_id', $subjectBookId)
            ->when($classLevelId, fn (Builder $query, string $id) => $query->whereHas(
                'classLevels',
                fn (Builder $classLevels) => $classLevels->where('class_levels.id', $id)
            ))
            ->get(['id', 'status', 'session_date']);
    }

    /**
     * @param  Collection<int, ClassSession>  $sessionsOfPair  from sessionsOfPair()
     * @param  Collection<int, Student>  $students  with at least id and entry_date loaded
     * @return array<string, array{present: int, sick: int, excused: int, absent: int, recorded_session_count: int}> keyed by student id
     */
    public function talliesFor(Collection $sessionsOfPair, ?string $classLevelId, Collection $students): array
    {
        $countsByStudentId = $this->attendanceCountsByStatus(
            $sessionsOfPair->where('status', ClassSession::STATUS_HELD)->pluck('id'),
            $classLevelId,
            $students,
        );

        return $students->mapWithKeys(function (Student $student) use ($countsByStudentId) {
            $countOfStatus = $countsByStudentId->get($student->id, collect());

            return [$student->id => [
                'present' => $countOfStatus->get(StudentAttendance::STATUS_PRESENT, 0),
                'sick' => $countOfStatus->get(StudentAttendance::STATUS_SICK, 0),
                'excused' => $countOfStatus->get(StudentAttendance::STATUS_EXCUSED, 0),
                'absent' => $countOfStatus->get(StudentAttendance::STATUS_ABSENT, 0),
                'recorded_session_count' => (int) $countOfStatus->sum(),
            ]];
        })->all();
    }

    /**
     * Held and cancelled Pertemuan counts for the Rekap Kehadiran header —
     * independent of any single student's enrollment date.
     *
     * @param  Collection<int, ClassSession>  $sessionsOfPair  from sessionsOfPair()
     * @return array{held: int, cancelled: int}
     */
    public function sessionCountsFor(Collection $sessionsOfPair): array
    {
        $countsByStatus = $sessionsOfPair->countBy('status');

        return [
            'held' => (int) $countsByStatus->get(ClassSession::STATUS_HELD, 0),
            'cancelled' => (int) $countsByStatus->get(ClassSession::STATUS_CANCELLED, 0),
        ];
    }

    /**
     * How many Absensi rows each student has per status, counted in the
     * database: only rows of these Pertemuan, only for the Kelas asked
     * about, and only from the date the student entered (EnrollmentDateRule)
     * — a santri who joined later is not expected at what happened before
     * him. The santri are grouped by entry_date, so that rule is one date
     * comparison per distinct entry date, not one per row.
     *
     * @param  Collection<int, string>  $heldSessionIds
     * @param  Collection<int, Student>  $students
     * @return Collection<string, Collection<string, int>> counts per status, keyed by student id
     */
    private function attendanceCountsByStatus(Collection $heldSessionIds, ?string $classLevelId, Collection $students): Collection
    {
        if ($heldSessionIds->isEmpty() || $students->isEmpty()) {
            return collect();
        }

        $studentIdsByEntryDate = $students
            ->groupBy(fn (Student $student) => $student->entry_date?->toDateString() ?? '')
            ->map(fn (Collection $group) => $group->pluck('id')->all());

        return StudentAttendance::query()
            ->join('class_sessions', 'class_sessions.id', '=', 'student_attendances.class_session_id')
            ->whereIn('student_attendances.class_session_id', $heldSessionIds)
            ->when($classLevelId, fn ($query, string $id) => $query->where('student_attendances.class_level_id', $id))
            ->where(function ($query) use ($studentIdsByEntryDate) {
                foreach ($studentIdsByEntryDate as $entryDate => $studentIds) {
                    $query->orWhere(fn ($ofEntryDate) => $ofEntryDate
                        ->whereIn('student_attendances.student_id', $studentIds)
                        ->when($entryDate !== '', fn ($dated) => $dated->whereDate('class_sessions.session_date', '>=', $entryDate)));
                }
            })
            ->groupBy('student_attendances.student_id', 'student_attendances.status')
            ->selectRaw('student_attendances.student_id as student_id, student_attendances.status as status, COUNT(*) as attendance_count')
            ->get()
            ->groupBy('student_id')
            ->map(fn (Collection $rows) => $rows->mapWithKeys(
                fn (StudentAttendance $row) => [$row->status => (int) $row->attendance_count]
            ));
    }
}

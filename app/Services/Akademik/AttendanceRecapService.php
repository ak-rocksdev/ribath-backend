<?php

namespace App\Services\Akademik;

use App\Exceptions\OutsideTeachingScopeException;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\Student;
use App\Models\SubjectBook;
use App\Services\Akademik\FactorScores\AttendanceFactorScoreProvider;
use Illuminate\Validation\ValidationException;

/**
 * Rekap Kehadiran (standalone Absensi recap, not tied to a grading
 * template): per student, present/sick/excused/absent counts and the same
 * Nilai Absensi score AttendanceFactorScoreProvider would report for them
 * — both read AttendanceTallyService's tallies through the same
 * scoreFromTally() mapping, so they can never disagree.
 *
 * Unlike the grade recap, a kitab without a grading template may still
 * show attendance: only the pair-is-scheduled and semester-is-configured
 * checks apply (StudentGradeService::assertScheduledPairAndConfiguredSemester(),
 * without resolveClassSubjectContext()'s book-has-template check), so both
 * recaps reject an unscheduled pair or an unconfigured semester with the
 * exact same wording.
 *
 * A user limited to his Cakupan Mengajar (only `view-own-attendance`)
 * sees the recap of his own pairs; another pair is refused with 403
 * before the other checks, as on the grade grid.
 */
class AttendanceRecapService
{
    public function __construct(
        private StudentGradeService $studentGradeService,
        private AttendanceTallyService $attendanceTallyService,
        private AttendanceFactorScoreProvider $attendanceFactorScoreProvider,
        private TeachingScopeResolver $teachingScopeResolver,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws OutsideTeachingScopeException the pair is outside the Cakupan Mengajar
     * @throws ValidationException MESSAGE_PAIR_NOT_SCHEDULED | MESSAGE_SEMESTER_NOT_CONFIGURED
     */
    public function recapForClassSubject(string $academicYearId, int $semester, string $classLevelId, string $subjectBookId): array
    {
        $this->teachingScopeResolver
            ->forCurrentUser('view-attendance', $academicYearId, $semester)
            ->assertIncludesClassSubjectPair($classLevelId, $subjectBookId);

        $this->studentGradeService->assertScheduledPairAndConfiguredSemester($academicYearId, $semester, $classLevelId, $subjectBookId);

        $students = $this->studentGradeService->listClassStudents($classLevelId);

        // Resolved once: the header counts and the per-santri tallies read
        // the same Pertemuan.
        $sessionsOfPair = $this->attendanceTallyService->sessionsOfPair($academicYearId, $semester, $classLevelId, $subjectBookId);
        $tallies = $this->attendanceTallyService->talliesFor($sessionsOfPair, $classLevelId, $students);
        $sessionCounts = $this->attendanceTallyService->sessionCountsFor($sessionsOfPair);

        $school = School::activeOrFail();
        $classLevel = ClassLevel::where('school_id', $school->id)->findOrFail($classLevelId);
        $subjectBook = SubjectBook::where('school_id', $school->id)->findOrFail($subjectBookId);

        return [
            'academic_year_id' => $academicYearId,
            'semester' => $semester,
            'class_level' => $classLevel->summary(),
            'subject_book' => [
                'id' => $subjectBook->id,
                'title' => $subjectBook->title,
            ],
            'held_session_count' => $sessionCounts['held'],
            'cancelled_session_count' => $sessionCounts['cancelled'],
            'rows' => $students
                ->map(fn (Student $student) => $this->presentRow($student, $tallies[$student->id]))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array{present: int, sick: int, excused: int, absent: int, recorded_session_count: int}  $tally
     * @return array<string, mixed>
     */
    private function presentRow(Student $student, array $tally): array
    {
        $factorScore = $this->attendanceFactorScoreProvider->scoreFromTally($tally);

        return [
            'student' => $this->studentGradeService->presentClassStudent($student),
            'present_count' => $tally['present'],
            'sick_count' => $tally['sick'],
            'excused_count' => $tally['excused'],
            'absent_count' => $tally['absent'],
            'recorded_session_count' => $tally['recorded_session_count'],
            'score' => $factorScore->score,
            'missing_reason' => $factorScore->missingReason,
        ];
    }
}

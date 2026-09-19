<?php

namespace App\Services\Akademik;

use App\Exceptions\FinalizedReportCardException;
use App\Exceptions\OutsideTeachingScopeException;
use App\Models\AcademicSemester;
use App\Models\ClassSession;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\TeachingSchedule;
use App\Services\AcademicYearService;
use App\Services\Akademik\Calculation\EnrollmentDateRule;
use App\Support\ScheduleDateRange;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pertemuan & Absensi: record a held session of a teaching schedule on one
 * date with every expected santri's status, edit those statuses, cancel a
 * session (Pertemuan Dibatalkan), and list/show sessions.
 *
 * Who is expected at a session: the santri of EVERY Kelas of the schedule
 * who had entered by the session date (EnrollmentDateRule), ordered per
 * Kelas — a jadwal gabungan is absen once, for both its Kelas (ADR 0006).
 * Of those, the ACTIVE ones must each have a status — a held session
 * ("Pertemuan tercatat") always covers every active expected santri, so the
 * absensi denominator is well defined. Non-active santri (spec: shown with
 * a marker, never blocking) may be recorded but are not required. A row for
 * a santri outside every Kelas of the schedule, or one who entered after
 * the date, is rejected. Errors are keyed "<student_id>" and nothing is
 * written when any row fails (all-or-nothing).
 *
 * Date rules live in SessionDatePolicy. The kitab, teacher and the schedule's
 * FIRST Kelas are snapshotted from the schedule when a session is first
 * stored — the schedule's CURRENT Ustadz, also when a former Ustadz records
 * it; who recorded or changed it is kept in created_by/updated_by. Which
 * Kelas each santri was absen for is kept on his Absensi row, not on the
 * session, so a combined Pertemuan stays one row.
 *
 * Cakupan Mengajar (ADR 0004, 0005): a user holding only the "milik
 * sendiri" attendance permissions works on the schedules and Pertemuan
 * whose Kelas × Kitab pair is in his Cakupan Mengajar. A schedule chosen
 * in the body or the query outside it is refused with 403; a Pertemuan or
 * schedule bound to the route outside it is not found (404), like
 * tenancy. Libur massal (cancelDateRange) keeps its "semua"-only route.
 */
class ClassSessionService
{
    public const MESSAGE_DUPLICATE_SESSION = 'Pertemuan untuk jadwal dan tanggal ini sudah tercatat.';

    public const MESSAGE_SESSION_CANCELLED = 'Pertemuan ini sudah dibatalkan.';

    public const MESSAGE_STUDENT_NOT_YET_ENROLLED = 'Santri belum masuk kelas pada tanggal pertemuan ini.';

    public const MESSAGE_INVALID_STATUS = 'Status absensi tidak valid.';

    public const MESSAGE_NOTES_TOO_LONG = 'Catatan absensi maksimal 255 karakter.';

    public const MESSAGE_ATTENDANCE_INCOMPLETE = 'Absensi belum lengkap untuk santri ini.';

    public const MESSAGE_SCHEDULE_INACTIVE = 'Jadwal mengajar tidak ditemukan atau tidak aktif.';

    public const MESSAGE_RANGE_OUTSIDE_SEMESTER = 'Rentang tanggal di luar rentang semester akademik aktif.';

    public const MESSAGE_RANGE_ALREADY_RECORDED = 'Tanggal ini sudah memiliki pertemuan.';

    private const SESSION_RELATIONS = [
        'classLevel:id,slug,label',
        'teachingSchedule:id',
        'teachingSchedule.classLevels:id,slug,label',
        'subjectBook:id,title',
        'teacher:id,full_name',
        'updater:id,name',
    ];

    public function __construct(
        private StudentGradeService $studentGradeService,
        private SessionDatePolicy $sessionDatePolicy,
        private FinalizedReportCardGuard $finalizedReportCardGuard,
        private AcademicYearService $academicYearService,
        private TeachingScopeResolver $teachingScopeResolver,
    ) {}

    /**
     * The active Jadwal Mengajar of a semester a Pertemuan can be recorded
     * for — the schedule list of the Absensi Pertemuan page. A user
     * limited to his Cakupan Mengajar gets the schedules of his pairs
     * only, including a schedule now held by another Ustadz when the
     * riwayat pengajar keeps its pair in his scope.
     *
     * @return EloquentCollection<int, TeachingSchedule>
     */
    public function listSchedulesForAttendance(string $academicYearId, int $semester): EloquentCollection
    {
        $teachingScope = $this->teachingScopeResolver->forCurrentUser('view-attendance', $academicYearId, $semester);

        return TeachingSchedule::query()
            ->where('school_id', School::activeOrFail()->id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->where('is_active', true)
            ->with(TeachingSchedule::EAGER_LOAD_RELATIONS)
            ->orderBy('day_of_week')
            ->orderBy('created_at')
            ->get()
            ->filter(fn (TeachingSchedule $schedule) => $teachingScope->includesAnyClassSubjectPair($schedule->classLevelIds(), $schedule->subject_book_id))
            ->values();
    }

    /**
     * One schedule for the Absensi Pertemuan page (its `?schedule=` link),
     * active or not; outside the Cakupan Mengajar it is not found.
     */
    public function showScheduleForAttendance(TeachingSchedule $schedule): TeachingSchedule
    {
        $this->ensureScheduleWithinTeachingScope($schedule, 'view-attendance');

        return $schedule->load(TeachingSchedule::EAGER_LOAD_RELATIONS);
    }

    /**
     * A schedule bound to the route whose pair is outside the user's
     * Cakupan Mengajar is not found (404), the same answer as a schedule
     * of another school. The scope is resolved for the schedule's own
     * Semester Akademik, so its riwayat pengajar counts (ADR 0005).
     *
     * @param  string  $allDataPermission  `view-attendance` to read, `manage-attendance` to write
     */
    public function ensureScheduleWithinTeachingScope(TeachingSchedule $schedule, string $allDataPermission): void
    {
        $teachingScope = $this->teachingScopeResolver->forCurrentUser($allDataPermission, $schedule->academic_year_id, $schedule->semester);

        abort_unless($teachingScope->includesAnyClassSubjectPair($schedule->classLevelIds(), $schedule->subject_book_id), 404);
    }

    /**
     * A Pertemuan bound to the route whose own Kelas × Kitab (its
     * snapshot) is outside the user's Cakupan Mengajar for its Semester
     * Akademik is not found (404), like tenancy.
     *
     * @param  string  $allDataPermission  `view-attendance` to read, `manage-attendance` to change its attendances
     */
    public function ensureSessionWithinTeachingScope(ClassSession $session, string $allDataPermission): void
    {
        $teachingScope = $this->teachingScopeResolver->forCurrentUser($allDataPermission, $session->academic_year_id, $session->semester);

        abort_unless($teachingScope->includesClassSubjectPair($session->class_level_id, $session->subject_book_id), 404);
    }

    /**
     * Sessions of one semester, most recent session_date first, each with
     * its attendance_summary.
     *
     * @param  array{academic_year_id: string, semester: int|string, class_level_id?: string|null, teaching_schedule_id?: string|null, date_from?: string|null, date_to?: string|null}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function listSessions(array $filters): array
    {
        $teachingScope = $this->teachingScopeResolver->forCurrentUser('view-attendance', $filters['academic_year_id'], (int) $filters['semester']);

        if (! empty($filters['teaching_schedule_id'])) {
            $chosenSchedule = TeachingSchedule::findOrFail($filters['teaching_schedule_id']);
            $teachingScope->assertIncludesAnyClassSubjectPair($chosenSchedule->classLevelIds(), $chosenSchedule->subject_book_id);
        }

        $sessions = ClassSession::query()
            ->where('school_id', School::activeOrFail()->id)
            ->where('academic_year_id', $filters['academic_year_id'])
            ->where('semester', (int) $filters['semester'])
            ->when($filters['class_level_id'] ?? null, fn ($query, $classLevelId) => $query->where('class_level_id', $classLevelId))
            ->when($filters['teaching_schedule_id'] ?? null, fn ($query, $scheduleId) => $query->where('teaching_schedule_id', $scheduleId))
            ->when($filters['date_from'] ?? null, fn ($query, $dateFrom) => $query->whereDate('session_date', '>=', $dateFrom))
            ->when($filters['date_to'] ?? null, fn ($query, $dateTo) => $query->whereDate('session_date', '<=', $dateTo))
            ->with(self::SESSION_RELATIONS)
            ->orderByDesc('session_date')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (ClassSession $session) => $teachingScope->includesClassSubjectPair($session->class_level_id, $session->subject_book_id))
            ->values();

        $summariesBySessionId = $this->attendanceSummariesFor($sessions->pluck('id'));

        return $sessions
            ->map(fn (ClassSession $session) => $this->presentSession($session, $summariesBySessionId->get($session->id)))
            ->all();
    }

    /**
     * One session with all of its attendance rows (by santri name).
     *
     * @return array<string, mixed>
     */
    public function getSession(ClassSession $session): array
    {
        $this->ensureSessionWithinTeachingScope($session, 'view-attendance');

        $session->loadMissing(self::SESSION_RELATIONS);

        $attendances = StudentAttendance::query()
            ->where('class_session_id', $session->id)
            ->with(['student:id,full_name', 'updater:id,name'])
            ->get()
            ->sortBy(fn (StudentAttendance $attendance) => $attendance->student?->full_name)
            ->values();

        return array_merge($this->presentSession($session), [
            'attendances' => $attendances->map(fn (StudentAttendance $attendance) => $this->presentAttendance($attendance))->all(),
        ]);
    }

    /**
     * The santri who may be recorded at a session of this schedule on this
     * date (entered on or before it), grouped per Kelas in the order of the
     * Kelas master and, within a Kelas, active first then by name, each
     * flagged `is_attendance_required` (active santri only) and carrying
     * the Kelas it belongs to. `class_levels` names the Kelas of the
     * schedule, so the screen can head each group (ADR 0006).
     *
     * @return array{teaching_schedule_id: string, session_date: string, class_levels: array<int, array<string, mixed>>, students: array<int, array<string, mixed>>}
     */
    public function presentExpectedStudents(TeachingSchedule $schedule, string $sessionDate): array
    {
        $this->ensureScheduleWithinTeachingScope($schedule, 'view-attendance');

        $sessionDateAsCarbon = Carbon::parse($sessionDate)->startOfDay();
        $schedule->loadMissing('classLevels');

        return [
            'teaching_schedule_id' => $schedule->id,
            'session_date' => $sessionDateAsCarbon->toDateString(),
            'class_levels' => $schedule->classLevels->map(fn ($classLevel) => $classLevel->summary())->all(),
            'students' => $this->enrolledRosterOn($schedule, $sessionDateAsCarbon)
                ->map(fn (Student $student) => array_merge(
                    $this->studentGradeService->presentClassStudent($student),
                    [
                        'class_level_id' => $student->class_level_id,
                        'is_attendance_required' => $this->isAttendanceRequiredFor($student),
                    ],
                ))
                ->values()
                ->all(),
        ];
    }

    /**
     * Records a held session with one attendance row per posted santri.
     *
     * @param  array<int, array{student_id: string, status: mixed, notes?: mixed}>  $attendanceRows
     * @return array{class_session: array<string, mixed>, attendances: array<int, array<string, mixed>>, requires_override_warning: bool}
     *
     * @throws OutsideTeachingScopeException the schedule's pair is outside the Cakupan Mengajar
     * @throws ValidationException
     */
    public function recordSession(TeachingSchedule $schedule, string $sessionDate, array $attendanceRows): array
    {
        $this->assertScheduleChosenWithinTeachingScope($schedule);

        $sessionDateAsCarbon = Carbon::parse($sessionDate)->startOfDay();
        $actorIsSuperAdmin = $this->actorIsSuperAdmin();

        $this->sessionDatePolicy->assertAllowed(
            $schedule,
            $sessionDateAsCarbon,
            $this->academicSemesterForScheduleOrFail($schedule),
            $actorIsSuperAdmin,
            false,
        );

        if ($this->findLiveSession($schedule, $sessionDateAsCarbon) !== null) {
            throw ValidationException::withMessages(['session_date' => self::MESSAGE_DUPLICATE_SESSION]);
        }

        $roster = $this->rosterOf($schedule);
        $this->assertAttendanceRowsAreValid($attendanceRows, $roster, $sessionDateAsCarbon, collect());

        $classLevelIdByStudentId = $roster->pluck('class_level_id', 'id');
        $schoolId = School::activeOrFail()->id;
        $userId = auth()->id();

        $session = $this->createSessionOrFailAsDuplicate(function () use ($schedule, $sessionDateAsCarbon, $attendanceRows, $classLevelIdByStudentId, $schoolId, $userId) {
            return DB::transaction(function () use ($schedule, $sessionDateAsCarbon, $attendanceRows, $classLevelIdByStudentId, $schoolId, $userId) {
                $session = ClassSession::create(array_merge(
                    $this->snapshotFromSchedule($schedule, $sessionDateAsCarbon),
                    [
                        'school_id' => $schoolId,
                        'status' => ClassSession::STATUS_HELD,
                        'created_by' => $userId,
                        'updated_by' => $userId,
                    ],
                ));

                foreach ($attendanceRows as $attendanceRow) {
                    StudentAttendance::create([
                        'school_id' => $schoolId,
                        'class_session_id' => $session->id,
                        'student_id' => $attendanceRow['student_id'],
                        'class_level_id' => $classLevelIdByStudentId->get($attendanceRow['student_id']),
                        'status' => $attendanceRow['status'],
                        'notes' => $this->normalizeNotes($attendanceRow['notes'] ?? null),
                        'created_by' => $userId,
                        'updated_by' => $userId,
                    ]);
                }

                return $session;
            });
        });

        return $this->presentWriteResult(
            $session,
            $this->attendancesOfStudents($session, collect($attendanceRows)->pluck('student_id')),
            $this->sessionDatePolicy->requiresOverrideWarning($sessionDateAsCarbon, $actorIsSuperAdmin, false),
        );
    }

    /**
     * Upserts attendance rows of a held session by santri. Rows not posted
     * are kept; after the upsert every active expected santri must still
     * have a row.
     *
     * @param  array<int, array{student_id: string, status: mixed, notes?: mixed}>  $attendanceRows
     * @return array{class_session: array<string, mixed>, attendances: array<int, array<string, mixed>>, requires_override_warning: bool}
     *
     * @throws ValidationException
     * @throws FinalizedReportCardException a posted row changes an existing row of a santri whose Rapor is final (keyed by student id)
     */
    public function updateAttendances(ClassSession $session, array $attendanceRows): array
    {
        $this->ensureSessionWithinTeachingScope($session, 'manage-attendance');

        if ($session->isCancelled()) {
            throw ValidationException::withMessages(['class_session' => self::MESSAGE_SESSION_CANCELLED]);
        }

        $actorIsSuperAdmin = $this->actorIsSuperAdmin();
        $this->sessionDatePolicy->assertAttendanceEditAllowed($session->session_date, $actorIsSuperAdmin);

        $recordedAttendancesByStudentId = StudentAttendance::query()
            ->where('class_session_id', $session->id)
            ->get(['student_id', 'class_level_id', 'status', 'notes'])
            ->keyBy('student_id');

        $session->loadMissing('teachingSchedule.classLevels');
        $roster = $this->rosterOfClassLevels(
            $this->classLevelIdsRecordableAt($session, $recordedAttendancesByStudentId->pluck('class_level_id'))
        );

        $this->assertAttendanceRowsAreValid($attendanceRows, $roster, $session->session_date, $recordedAttendancesByStudentId->keys());
        $this->assertNoChangeForFinalizedStudents($session, $attendanceRows, $recordedAttendancesByStudentId);

        $classLevelIdByStudentId = $roster->pluck('class_level_id', 'id');
        $userId = auth()->id();

        DB::transaction(function () use ($session, $attendanceRows, $classLevelIdByStudentId, $userId) {
            $existingAttendancesByStudentId = StudentAttendance::query()
                ->where('class_session_id', $session->id)
                ->whereIn('student_id', collect($attendanceRows)->pluck('student_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('student_id');

            $hasChanges = false;

            foreach ($attendanceRows as $attendanceRow) {
                $existingAttendance = $existingAttendancesByStudentId->get($attendanceRow['student_id']);
                $notes = $this->normalizeNotes($attendanceRow['notes'] ?? null);

                if ($existingAttendance === null) {
                    StudentAttendance::create([
                        'school_id' => $session->school_id,
                        'class_session_id' => $session->id,
                        'student_id' => $attendanceRow['student_id'],
                        'class_level_id' => $classLevelIdByStudentId->get($attendanceRow['student_id']),
                        'status' => $attendanceRow['status'],
                        'notes' => $notes,
                        'created_by' => $userId,
                        'updated_by' => $userId,
                    ]);
                    $hasChanges = true;

                    continue;
                }

                $existingAttendance->fill(['status' => $attendanceRow['status'], 'notes' => $notes]);

                if ($existingAttendance->isDirty()) {
                    $existingAttendance->updated_by = $userId;
                    $existingAttendance->save();
                    $hasChanges = true;
                }
            }

            if ($hasChanges) {
                $session->updated_by = $userId;
                $session->touch();
            }
        });

        return $this->presentWriteResult(
            $session->refresh(),
            $this->attendancesOfStudents($session, collect($attendanceRows)->pluck('student_id')),
            $this->sessionDatePolicy->requiresOverrideWarning($session->session_date, $actorIsSuperAdmin, true),
        );
    }

    /**
     * Marks the schedule's date as a Pertemuan Dibatalkan: creates a
     * cancelled session, or converts the existing one (its attendance rows
     * are kept; calculators ignore rows of cancelled sessions).
     *
     * A NEW cancelled session gets the full recording checks: an active
     * schedule, its weekday, the semester range and the actor limits.
     * Converting an EXISTING session is an edit of a fact recorded earlier,
     * so — like editing its attendances — only the actor limits apply: the
     * schedule may have changed day or been deactivated since.
     *
     * @return array{result: array{class_session: array<string, mixed>, attendances: array<int, array<string, mixed>>, requires_override_warning: bool}, created: bool}
     *
     * An existing session whose own Kelas × Kitab (its snapshot) is outside
     * the Cakupan Mengajar is not found (404), even when the schedule's
     * current pair is inside it.
     *
     * @throws OutsideTeachingScopeException the schedule's pair is outside the Cakupan Mengajar
     * @throws ValidationException
     */
    public function cancelSession(TeachingSchedule $schedule, string $sessionDate, string $reason): array
    {
        $this->assertScheduleChosenWithinTeachingScope($schedule);

        $sessionDateAsCarbon = Carbon::parse($sessionDate)->startOfDay();
        $actorIsSuperAdmin = $this->actorIsSuperAdmin();
        $existingSession = $this->findLiveSession($schedule, $sessionDateAsCarbon);
        $isEditingExistingSession = $existingSession !== null;

        if ($isEditingExistingSession) {
            // The schedule's pair may have moved since: the Pertemuan's own snapshot decides, as on its routes.
            $this->ensureSessionWithinTeachingScope($existingSession, 'manage-attendance');
            $this->sessionDatePolicy->assertAttendanceEditAllowed($existingSession->session_date, $actorIsSuperAdmin);
        } else {
            if (! $schedule->is_active) {
                throw ValidationException::withMessages(['teaching_schedule_id' => self::MESSAGE_SCHEDULE_INACTIVE]);
            }

            $this->sessionDatePolicy->assertAllowed(
                $schedule,
                $sessionDateAsCarbon,
                $this->academicSemesterForScheduleOrFail($schedule),
                $actorIsSuperAdmin,
                false,
            );
        }

        $userId = auth()->id();

        if ($existingSession !== null) {
            $existingSession->fill(['status' => ClassSession::STATUS_CANCELLED, 'cancel_reason' => $reason]);

            if ($existingSession->isDirty()) {
                $existingSession->updated_by = $userId;
                $existingSession->save();
            }

            $session = $existingSession;
        } else {
            $session = $this->createSessionOrFailAsDuplicate(fn () => ClassSession::create(array_merge(
                $this->snapshotFromSchedule($schedule, $sessionDateAsCarbon),
                [
                    'school_id' => School::activeOrFail()->id,
                    'status' => ClassSession::STATUS_CANCELLED,
                    'cancel_reason' => $reason,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ],
            )));
        }

        return [
            'result' => $this->presentWriteResult(
                $session,
                collect(),
                $this->sessionDatePolicy->requiresOverrideWarning($sessionDateAsCarbon, $actorIsSuperAdmin, $isEditingExistingSession),
            ),
            'created' => ! $isEditingExistingSession,
        ];
    }

    /**
     * Libur massal: for every active schedule of the ACTIVE academic year
     * and its active_semester, creates a cancelled Pertemuan on every date
     * in [$startDate, $endDate] that matches the schedule's weekday,
     * clamped to that semester's own start_date/end_date. A date that
     * already has a live session (held or cancelled) is skipped — never
     * converted. This is a planning action (spec: holidays are announced
     * ahead), so a non-super_admin may declare a future date freely, but
     * may not backdate one past the attendance edit window; a
     * super_admin is unrestricted within the semester
     * (SessionDatePolicy::isPastEditWindowForRangeCancel). One transaction.
     *
     * @return array{created: int, skipped: int, created_items: array<int, array<string, mixed>>, skipped_items: array<int, array<string, mixed>>}
     *
     * @throws ValidationException keyed "semester" (not configured) or "start_date" (range outside the semester)
     */
    public function cancelDateRange(string $startDate, string $endDate, string $reason): array
    {
        $school = School::activeOrFail();
        $activeAcademicSemester = $this->activeAcademicSemesterWithDatesOrFail();
        [$effectiveStart, $effectiveEnd] = $this->clampRangeToSemester($startDate, $endDate, $activeAcademicSemester);

        $schedules = TeachingSchedule::query()
            ->where('school_id', $school->id)
            ->where('academic_year_id', $activeAcademicSemester->academic_year_id)
            ->where('semester', $activeAcademicSemester->semester)
            ->where('is_active', true)
            ->get();

        [$createdItems, $skippedItems] = $schedules->isEmpty()
            ? [[], []]
            : $this->cancelScheduleDatesInRange($schedules, $effectiveStart, $effectiveEnd, $reason, $school->id);

        return [
            'created' => count($createdItems),
            'skipped' => count($skippedItems),
            'created_items' => $createdItems,
            'skipped_items' => $skippedItems,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentSession(ClassSession $session, ?array $attendanceSummary = null): array
    {
        $session->loadMissing(self::SESSION_RELATIONS);
        $attendanceSummary ??= $this->attendanceSummariesFor(collect([$session->id]))->get($session->id);

        return [
            'id' => $session->id,
            'teaching_schedule_id' => $session->teaching_schedule_id,
            'session_date' => $session->session_date->toDateString(),
            'academic_year_id' => $session->academic_year_id,
            'semester' => $session->semester,
            'class_level_id' => $session->class_level_id,
            'subject_book_id' => $session->subject_book_id,
            'teacher_id' => $session->teacher_id,
            'class_level' => $session->classLevel?->summary(),
            // Every Kelas the Pertemuan was held for, so a jadwal gabungan
            // reads "Ibtida 2 + Tsanawiyah 1" wherever it is listed.
            'class_levels' => $this->sessionClassLevelSummaries($session),
            'subject_book' => $session->subjectBook ? [
                'id' => $session->subjectBook->id,
                'title' => $session->subjectBook->title,
            ] : null,
            'teacher' => $session->teacher ? [
                'id' => $session->teacher->id,
                'full_name' => $session->teacher->full_name,
            ] : null,
            'status' => $session->status,
            'cancel_reason' => $session->cancel_reason,
            'attendance_summary' => $attendanceSummary ?? $this->emptyAttendanceSummary(),
            'created_by' => $session->created_by,
            'updated_by' => $session->updated_by,
            'updated_by_name' => $session->updater?->name,
            'created_at' => $session->created_at?->toJSON(),
            'updated_at' => $session->updated_at?->toJSON(),
        ];
    }

    /**
     * The Kelas a Pertemuan is shown under: those of its schedule when the
     * schedule still holds the snapshot Kelas, otherwise the snapshot alone
     * — the same reading as classLevelIdsRecordableAt(), without loading
     * the Absensi rows a listing does not need.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sessionClassLevelSummaries(ClassSession $session): array
    {
        $scheduleClassLevels = $session->teachingSchedule?->classLevels ?? collect();

        if (! $scheduleClassLevels->contains('id', $session->class_level_id)) {
            return array_filter([$session->classLevel?->summary()]);
        }

        return $scheduleClassLevels->map(fn ($classLevel) => $classLevel->summary())->all();
    }

    /**
     * Validates every posted row before anything is written.
     *
     * @param  array<int, array{student_id: string, status: mixed, notes?: mixed}>  $attendanceRows
     * @param  Collection<int, Student>  $classStudents  the santri of every Kelas the Pertemuan covers
     * @param  Collection<int, string>  $recordedStudentIds  santri who already have a row (edits only)
     *
     * @throws ValidationException keyed "<student_id>"
     */
    private function assertAttendanceRowsAreValid(array $attendanceRows, Collection $classStudents, CarbonInterface $sessionDate, Collection $recordedStudentIds): void
    {
        $classStudentIds = $classStudents->pluck('id')->flip();
        $enrolledStudents = $this->filterEnrolledOn($classStudents, $sessionDate);
        $enrolledStudentIds = $enrolledStudents->pluck('id')->flip();

        $errors = [];
        $postedStudentIds = [];

        foreach ($attendanceRows as $attendanceRow) {
            $studentId = $attendanceRow['student_id'];

            if (isset($postedStudentIds[$studentId])) {
                $errors[$studentId] = ClassTaskService::MESSAGE_DUPLICATE_STUDENT;

                continue;
            }
            $postedStudentIds[$studentId] = true;

            if (! $classStudentIds->has($studentId)) {
                $errors[$studentId] = ClassTaskService::MESSAGE_STUDENT_NOT_IN_CLASS;

                continue;
            }

            if (! $enrolledStudentIds->has($studentId)) {
                $errors[$studentId] = self::MESSAGE_STUDENT_NOT_YET_ENROLLED;

                continue;
            }

            $rowError = $this->attendanceRowError($attendanceRow);

            if ($rowError !== null) {
                $errors[$studentId] = $rowError;
            }
        }

        $coveredStudentIds = $recordedStudentIds->flip();

        foreach ($enrolledStudents as $student) {
            if ($this->isAttendanceRequiredFor($student) && ! isset($postedStudentIds[$student->id]) && ! $coveredStudentIds->has($student->id)) {
                $errors[$student->id] = self::MESSAGE_ATTENDANCE_INCOMPLETE;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Changing an EXISTING attendance row of a santri whose Rapor is final
     * for the session's semester is a per-santri source write and is
     * rejected (ADR 0001). Adding a missing row, or re-posting a row
     * unchanged, is not a change and stays allowed — like recording a new
     * Pertemuan, which is a class-level action.
     *
     * @param  array<int, array{student_id: string, status: mixed, notes?: mixed}>  $attendanceRows  already validated
     * @param  Collection<string, StudentAttendance>  $recordedAttendancesByStudentId
     *
     * @throws FinalizedReportCardException keyed by student id
     */
    private function assertNoChangeForFinalizedStudents(ClassSession $session, array $attendanceRows, Collection $recordedAttendancesByStudentId): void
    {
        $changedStudentIds = collect($attendanceRows)
            ->filter(function (array $attendanceRow) use ($recordedAttendancesByStudentId) {
                $recordedAttendance = $recordedAttendancesByStudentId->get($attendanceRow['student_id']);

                return $recordedAttendance !== null
                    && ($recordedAttendance->status !== $attendanceRow['status']
                        || $recordedAttendance->notes !== $this->normalizeNotes($attendanceRow['notes'] ?? null));
            })
            ->pluck('student_id');

        $this->finalizedReportCardGuard->assertEditableForStudents($changedStudentIds, $session->academic_year_id, $session->semester);
    }

    /**
     * @param  array{status: mixed, notes?: mixed}  $attendanceRow
     */
    private function attendanceRowError(array $attendanceRow): ?string
    {
        if (! is_string($attendanceRow['status']) || ! in_array($attendanceRow['status'], StudentAttendance::STATUSES, true)) {
            return self::MESSAGE_INVALID_STATUS;
        }

        $notes = $attendanceRow['notes'] ?? null;

        if ($notes !== null && (! is_string($notes) || mb_strlen($notes) > StudentAttendance::NOTES_MAX_LENGTH)) {
            return self::MESSAGE_NOTES_TOO_LONG;
        }

        return null;
    }

    private function normalizeNotes(mixed $notes): ?string
    {
        if (! is_string($notes)) {
            return null;
        }

        $trimmedNotes = trim($notes);

        return $trimmedNotes === '' ? null : $trimmedNotes;
    }

    /**
     * The santri of every Kelas of the schedule, one Kelas after another in
     * the order of the Kelas master — the roster of one Pertemuan, which a
     * jadwal gabungan shares between its Kelas (ADR 0006).
     *
     * @return Collection<int, Student>
     */
    private function rosterOf(TeachingSchedule $schedule): Collection
    {
        return $this->rosterOfClassLevels($schedule->classLevelIds());
    }

    /**
     * @param  array<int, string>  $classLevelIds
     * @return Collection<int, Student>
     */
    private function rosterOfClassLevels(array $classLevelIds): Collection
    {
        return collect($classLevelIds)
            ->flatMap(fn (string $classLevelId) => $this->studentGradeService->listClassStudents($classLevelId))
            ->values();
    }

    /**
     * @return Collection<int, Student>
     */
    private function enrolledRosterOn(TeachingSchedule $schedule, CarbonInterface $sessionDate): Collection
    {
        return $this->filterEnrolledOn($this->rosterOf($schedule), $sessionDate);
    }

    /**
     * The Kelas a recorded Pertemuan may hold Absensi for: its own snapshot
     * Kelas, every Kelas its rows already name, and — when the schedule
     * still holds that snapshot Kelas — every Kelas of the schedule, so a
     * Kelas added to a jadwal gabungan can still be absen at an earlier
     * Pertemuan. A Pertemuan whose schedule has since moved to another
     * Kelas keeps its own, exactly as before ADR 0006.
     *
     * @param  Collection<int, string>  $recordedClassLevelIds  the Kelas the session's Absensi rows name
     * @return array<int, string>
     */
    private function classLevelIdsRecordableAt(ClassSession $session, Collection $recordedClassLevelIds): array
    {
        $scheduleClassLevelIds = $session->teachingSchedule?->classLevelIds() ?? [];
        $ownClassLevelIds = in_array($session->class_level_id, $scheduleClassLevelIds, true)
            ? $scheduleClassLevelIds
            : [$session->class_level_id];

        return array_values(array_unique(array_merge($ownClassLevelIds, $recordedClassLevelIds->filter()->all())));
    }

    /**
     * @param  Collection<int, Student>  $students
     * @return Collection<int, Student>
     */
    private function filterEnrolledOn(Collection $students, CarbonInterface $sessionDate): Collection
    {
        $enrollmentDateRule = new EnrollmentDateRule;

        return $students
            ->filter(fn (Student $student) => $enrollmentDateRule->isExpectedOn($student, $sessionDate))
            ->values();
    }

    private function isAttendanceRequiredFor(Student $student): bool
    {
        return $student->status === Student::STATUS_ACTIVE;
    }

    /**
     * A schedule chosen in the request body to record or cancel a
     * Pertemuan: outside the Cakupan Mengajar of its Semester Akademik it
     * is refused with 403 and the scope message.
     *
     * @throws OutsideTeachingScopeException
     */
    private function assertScheduleChosenWithinTeachingScope(TeachingSchedule $schedule): void
    {
        $this->teachingScopeResolver
            ->forCurrentUser('manage-attendance', $schedule->academic_year_id, $schedule->semester)
            ->assertIncludesAnyClassSubjectPair($schedule->classLevelIds(), $schedule->subject_book_id);
    }

    private function actorIsSuperAdmin(): bool
    {
        return (bool) auth()->user()?->hasRole('super_admin');
    }

    /**
     * The active academic year's active_semester, which libur massal
     * always targets; it must exist with both start_date and end_date.
     *
     * @throws ValidationException keyed "semester"
     */
    private function activeAcademicSemesterWithDatesOrFail(): AcademicSemester
    {
        $activeAcademicYear = $this->academicYearService->getActive();
        $activeAcademicSemester = $activeAcademicYear?->semester($activeAcademicYear->active_semester);

        if ($activeAcademicSemester === null || $activeAcademicSemester->start_date === null || $activeAcademicSemester->end_date === null) {
            throw ValidationException::withMessages(['semester' => StudentGradeService::MESSAGE_SEMESTER_NOT_CONFIGURED]);
        }

        return $activeAcademicSemester;
    }

    /**
     * [$startDate, $endDate] cut down to the semester's own dates.
     *
     * @return array{0: string, 1: string} effective start and end (Y-m-d)
     *
     * @throws ValidationException keyed "start_date" when the range misses the semester entirely
     */
    private function clampRangeToSemester(string $startDate, string $endDate, AcademicSemester $academicSemester): array
    {
        $semesterStart = $academicSemester->start_date->toDateString();
        $semesterEnd = $academicSemester->end_date->toDateString();

        if ($endDate < $semesterStart || $startDate > $semesterEnd) {
            throw ValidationException::withMessages(['start_date' => self::MESSAGE_RANGE_OUTSIDE_SEMESTER]);
        }

        return [
            $startDate > $semesterStart ? $startDate : $semesterStart,
            $endDate < $semesterEnd ? $endDate : $semesterEnd,
        ];
    }

    /**
     * Creates a cancelled Pertemuan on every date of the range matching
     * each schedule's weekday, skipping a date that already has a live
     * session or lies past the edit window. One transaction.
     *
     * @param  Collection<int, TeachingSchedule>  $schedules
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>} created items, skipped items
     */
    private function cancelScheduleDatesInRange(Collection $schedules, string $effectiveStart, string $effectiveEnd, string $reason, string $schoolId): array
    {
        $liveSessionKeys = ClassSession::query()
            ->whereIn('teaching_schedule_id', $schedules->pluck('id'))
            ->whereDate('session_date', '>=', $effectiveStart)
            ->whereDate('session_date', '<=', $effectiveEnd)
            ->get(['teaching_schedule_id', 'session_date'])
            ->map(fn (ClassSession $session) => $session->teaching_schedule_id.'|'.$session->session_date->toDateString())
            ->flip();

        $actorIsSuperAdmin = $this->actorIsSuperAdmin();
        $userId = auth()->id();

        return DB::transaction(function () use ($schedules, $effectiveStart, $effectiveEnd, $liveSessionKeys, $reason, $actorIsSuperAdmin, $schoolId, $userId) {
            $createdItems = [];
            $skippedItems = [];

            foreach ($schedules as $schedule) {
                foreach (ScheduleDateRange::datesMatchingWeekday($schedule->day_of_week, $effectiveStart, $effectiveEnd) as $sessionDate) {
                    if ($liveSessionKeys->has($schedule->id.'|'.$sessionDate)) {
                        $skippedItems[] = [
                            'teaching_schedule_id' => $schedule->id,
                            'session_date' => $sessionDate,
                            'reason' => self::MESSAGE_RANGE_ALREADY_RECORDED,
                        ];

                        continue;
                    }

                    if ($this->sessionDatePolicy->isPastEditWindowForRangeCancel(Carbon::parse($sessionDate), $actorIsSuperAdmin)) {
                        $skippedItems[] = [
                            'teaching_schedule_id' => $schedule->id,
                            'session_date' => $sessionDate,
                            'reason' => SessionDatePolicy::MESSAGE_EDIT_WINDOW,
                        ];

                        continue;
                    }

                    $this->createSessionOrFailAsDuplicate(fn () => ClassSession::create(array_merge(
                        $this->snapshotFromSchedule($schedule, Carbon::parse($sessionDate)),
                        [
                            'school_id' => $schoolId,
                            'status' => ClassSession::STATUS_CANCELLED,
                            'cancel_reason' => $reason,
                            'created_by' => $userId,
                            'updated_by' => $userId,
                        ],
                    )));

                    $createdItems[] = [
                        'teaching_schedule_id' => $schedule->id,
                        'session_date' => $sessionDate,
                    ];
                }
            }

            return [$createdItems, $skippedItems];
        });
    }

    /**
     * @throws ValidationException when the schedule's semester has no academic_semesters row
     */
    private function academicSemesterForScheduleOrFail(TeachingSchedule $schedule): AcademicSemester
    {
        $academicSemester = AcademicSemester::findByPair($schedule->academic_year_id, $schedule->semester);

        if ($academicSemester === null) {
            throw ValidationException::withMessages(['teaching_schedule_id' => StudentGradeService::MESSAGE_SEMESTER_NOT_CONFIGURED]);
        }

        return $academicSemester;
    }

    private function findLiveSession(TeachingSchedule $schedule, CarbonInterface $sessionDate): ?ClassSession
    {
        return ClassSession::query()
            ->where('teaching_schedule_id', $schedule->id)
            ->whereDate('session_date', $sessionDate->toDateString())
            ->first();
    }

    /**
     * A concurrent request can pass the findLiveSession() check at the same
     * time; the partial unique index then rejects the second insert, which
     * is reported as the same 422 instead of a 500.
     *
     * @param  callable(): ClassSession  $createSession
     *
     * @throws ValidationException
     */
    private function createSessionOrFailAsDuplicate(callable $createSession): ClassSession
    {
        try {
            return $createSession();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['session_date' => self::MESSAGE_DUPLICATE_SESSION]);
        }
    }

    /**
     * The schedule's kitab, Ustadz and FIRST Kelas, frozen onto the
     * Pertemuan. A jadwal gabungan keeps a single Pertemuan row (ADR 0006),
     * so its snapshot Kelas is the schedule's first one — the Kelas utama
     * for display and for Pertemuan recorded before this feature; which
     * Kelas each santri was absen for lives on his Absensi row.
     *
     * @return array<string, mixed>
     */
    private function snapshotFromSchedule(TeachingSchedule $schedule, CarbonInterface $sessionDate): array
    {
        return [
            'teaching_schedule_id' => $schedule->id,
            'session_date' => $sessionDate->toDateString(),
            'academic_year_id' => $schedule->academic_year_id,
            'semester' => $schedule->semester,
            'class_level_id' => $schedule->classLevelIds()[0],
            'subject_book_id' => $schedule->subject_book_id,
            'teacher_id' => $schedule->teacher_id,
        ];
    }

    /**
     * @param  Collection<int, string>  $studentIds
     * @return Collection<int, StudentAttendance>
     */
    private function attendancesOfStudents(ClassSession $session, Collection $studentIds): Collection
    {
        $attendancesByStudentId = StudentAttendance::query()
            ->where('class_session_id', $session->id)
            ->whereIn('student_id', $studentIds)
            ->with(['student:id,full_name', 'updater:id,name'])
            ->get()
            ->keyBy('student_id');

        return $studentIds
            ->map(fn (string $studentId) => $attendancesByStudentId->get($studentId))
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, StudentAttendance>  $savedAttendances
     * @return array{class_session: array<string, mixed>, attendances: array<int, array<string, mixed>>, requires_override_warning: bool}
     */
    private function presentWriteResult(ClassSession $session, Collection $savedAttendances, bool $requiresOverrideWarning): array
    {
        return [
            'class_session' => $this->presentSession($session),
            'attendances' => $savedAttendances->map(fn (StudentAttendance $attendance) => $this->presentAttendance($attendance))->all(),
            'requires_override_warning' => $requiresOverrideWarning,
        ];
    }

    /**
     * @param  Collection<int, string>  $sessionIds
     * @return Collection<string, array{present: int, sick: int, excused: int, absent: int, total: int}>
     */
    private function attendanceSummariesFor(Collection $sessionIds): Collection
    {
        if ($sessionIds->isEmpty()) {
            return collect();
        }

        return StudentAttendance::query()
            ->whereIn('class_session_id', $sessionIds)
            ->selectRaw('class_session_id, status, COUNT(*) as attendance_count')
            ->groupBy('class_session_id', 'status')
            ->get()
            ->groupBy('class_session_id')
            ->map(function (Collection $countsByStatus) {
                $summary = $this->emptyAttendanceSummary();

                foreach ($countsByStatus as $statusCount) {
                    $summary[$statusCount->status] = (int) $statusCount->attendance_count;
                    $summary['total'] += (int) $statusCount->attendance_count;
                }

                return $summary;
            });
    }

    /**
     * @return array{present: int, sick: int, excused: int, absent: int, total: int}
     */
    private function emptyAttendanceSummary(): array
    {
        return [
            StudentAttendance::STATUS_PRESENT => 0,
            StudentAttendance::STATUS_SICK => 0,
            StudentAttendance::STATUS_EXCUSED => 0,
            StudentAttendance::STATUS_ABSENT => 0,
            'total' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAttendance(StudentAttendance $attendance): array
    {
        return [
            'id' => $attendance->id,
            'class_session_id' => $attendance->class_session_id,
            'student_id' => $attendance->student_id,
            'student_name' => $attendance->student?->full_name,
            'status' => $attendance->status,
            'notes' => $attendance->notes,
            'created_by' => $attendance->created_by,
            'updated_by' => $attendance->updated_by,
            'updated_by_name' => $attendance->updater?->name,
            'created_at' => $attendance->created_at?->toJSON(),
            'updated_at' => $attendance->updated_at?->toJSON(),
        ];
    }
}

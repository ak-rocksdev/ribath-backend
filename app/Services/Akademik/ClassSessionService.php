<?php

namespace App\Services\Akademik;

use App\Models\AcademicSemester;
use App\Models\ClassSession;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\TeachingSchedule;
use App\Services\Akademik\Calculation\EnrollmentDateRule;
use Carbon\CarbonInterface;
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
 * Who is expected at a session: the santri of the schedule's class who had
 * entered by the session date (EnrollmentDateRule). Of those, the ACTIVE
 * ones must each have a status — a held session ("Pertemuan tercatat")
 * always covers every active expected santri, so the absensi denominator
 * is well defined. Non-active santri of the class (spec: shown with a
 * marker, never blocking) may be recorded but are not required. A row for
 * a santri outside the class, or one who entered after the date, is
 * rejected. Errors are keyed "<student_id>" and nothing is written when
 * any row fails (all-or-nothing).
 *
 * Date rules live in SessionDatePolicy. The class, kitab and teacher are
 * snapshotted from the schedule when a session is first stored.
 */
class ClassSessionService
{
    public const MESSAGE_DUPLICATE_SESSION = 'Pertemuan untuk jadwal dan tanggal ini sudah tercatat.';

    public const MESSAGE_SESSION_CANCELLED = 'Pertemuan ini sudah dibatalkan.';

    public const MESSAGE_STUDENT_NOT_YET_ENROLLED = 'Santri belum masuk kelas pada tanggal pertemuan ini.';

    public const MESSAGE_INVALID_STATUS = 'Status absensi tidak valid.';

    public const MESSAGE_NOTES_TOO_LONG = 'Catatan absensi maksimal 255 karakter.';

    public const MESSAGE_ATTENDANCE_INCOMPLETE = 'Absensi belum lengkap untuk santri ini.';

    private const SESSION_RELATIONS = [
        'classLevel:id,slug,label',
        'subjectBook:id,title',
        'teacher:id,full_name',
        'updater:id,name',
    ];

    public function __construct(
        private StudentGradeService $studentGradeService,
        private SessionDatePolicy $sessionDatePolicy,
    ) {}

    /**
     * Sessions of one semester, most recent session_date first, each with
     * its attendance_summary.
     *
     * @param  array{academic_year_id: string, semester: int|string, class_level_id?: string|null, teaching_schedule_id?: string|null, date_from?: string|null, date_to?: string|null}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function listSessions(array $filters): array
    {
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
            ->get();

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
     * date (entered on or before it), active first then by name, each
     * flagged `is_attendance_required` (active santri only).
     *
     * @return array{teaching_schedule_id: string, session_date: string, students: array<int, array<string, mixed>>}
     */
    public function presentExpectedStudents(TeachingSchedule $schedule, string $sessionDate): array
    {
        $sessionDateAsCarbon = Carbon::parse($sessionDate)->startOfDay();

        return [
            'teaching_schedule_id' => $schedule->id,
            'session_date' => $sessionDateAsCarbon->toDateString(),
            'students' => $this->enrolledClassStudentsOn($schedule->class_level_id, $sessionDateAsCarbon)
                ->map(fn (Student $student) => array_merge(
                    $this->studentGradeService->presentClassStudent($student),
                    ['is_attendance_required' => $this->isAttendanceRequiredFor($student)],
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
     * @throws ValidationException
     */
    public function recordSession(TeachingSchedule $schedule, string $sessionDate, array $attendanceRows): array
    {
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

        $this->assertAttendanceRowsAreValid($attendanceRows, $schedule->class_level_id, $sessionDateAsCarbon, collect());

        $schoolId = School::activeOrFail()->id;
        $userId = auth()->id();

        $session = $this->createSessionOrFailAsDuplicate(function () use ($schedule, $sessionDateAsCarbon, $attendanceRows, $schoolId, $userId) {
            return DB::transaction(function () use ($schedule, $sessionDateAsCarbon, $attendanceRows, $schoolId, $userId) {
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
     */
    public function updateAttendances(ClassSession $session, array $attendanceRows): array
    {
        if ($session->isCancelled()) {
            throw ValidationException::withMessages(['class_session' => self::MESSAGE_SESSION_CANCELLED]);
        }

        $actorIsSuperAdmin = $this->actorIsSuperAdmin();
        $this->sessionDatePolicy->assertAttendanceEditAllowed($session->session_date, $actorIsSuperAdmin);

        $recordedStudentIds = StudentAttendance::query()
            ->where('class_session_id', $session->id)
            ->pluck('student_id');

        $this->assertAttendanceRowsAreValid($attendanceRows, $session->class_level_id, $session->session_date, $recordedStudentIds);

        $userId = auth()->id();

        DB::transaction(function () use ($session, $attendanceRows, $userId) {
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
     * are kept; calculators ignore rows of cancelled sessions). Converting
     * an existing session is an edit for the date rules.
     *
     * @return array{result: array{class_session: array<string, mixed>, attendances: array<int, array<string, mixed>>, requires_override_warning: bool}, created: bool}
     *
     * @throws ValidationException
     */
    public function cancelSession(TeachingSchedule $schedule, string $sessionDate, string $reason): array
    {
        $sessionDateAsCarbon = Carbon::parse($sessionDate)->startOfDay();
        $actorIsSuperAdmin = $this->actorIsSuperAdmin();
        $existingSession = $this->findLiveSession($schedule, $sessionDateAsCarbon);
        $isEditingExistingSession = $existingSession !== null;

        $this->sessionDatePolicy->assertAllowed(
            $schedule,
            $sessionDateAsCarbon,
            $this->academicSemesterForScheduleOrFail($schedule),
            $actorIsSuperAdmin,
            $isEditingExistingSession,
        );

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
            'class_level' => $session->classLevel ? [
                'id' => $session->classLevel->id,
                'slug' => $session->classLevel->slug,
                'label' => $session->classLevel->label,
            ] : null,
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
     * Validates every posted row before anything is written.
     *
     * @param  array<int, array{student_id: string, status: mixed, notes?: mixed}>  $attendanceRows
     * @param  Collection<int, string>  $recordedStudentIds  santri who already have a row (edits only)
     *
     * @throws ValidationException keyed "<student_id>"
     */
    private function assertAttendanceRowsAreValid(array $attendanceRows, string $classLevelId, CarbonInterface $sessionDate, Collection $recordedStudentIds): void
    {
        $classStudents = $this->studentGradeService->listClassStudents($classLevelId);
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
     * @return Collection<int, Student>
     */
    private function enrolledClassStudentsOn(string $classLevelId, CarbonInterface $sessionDate): Collection
    {
        return $this->filterEnrolledOn($this->studentGradeService->listClassStudents($classLevelId), $sessionDate);
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

    private function actorIsSuperAdmin(): bool
    {
        return (bool) auth()->user()?->hasRole('super_admin');
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
     * @return array<string, mixed>
     */
    private function snapshotFromSchedule(TeachingSchedule $schedule, CarbonInterface $sessionDate): array
    {
        return [
            'teaching_schedule_id' => $schedule->id,
            'session_date' => $sessionDate->toDateString(),
            'academic_year_id' => $schedule->academic_year_id,
            'semester' => $schedule->semester,
            'class_level_id' => $schedule->class_level_id,
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

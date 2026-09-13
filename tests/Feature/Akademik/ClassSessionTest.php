<?php

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\ClassSession;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\SubjectBook;
use App\Models\SubjectCategory;
use App\Models\Teacher;
use App\Models\TeachingSchedule;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Akademik\AcademicSemesterService;
use Database\Seeders\ClassLevelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/*
 * "Today" is frozen at Wednesday 2025-09-10 for every test in this file.
 * The schedule under test meets on Mondays, so:
 *   2025-09-08  last Monday (2 days ago)
 *   2025-09-01  9 days ago   — inside the 14-day edit window
 *   2025-08-25  16 days ago  — outside the window (today − 14 = 2025-08-27)
 *   2025-09-15  next Monday  — a future date
 *   2025-06-30  a Monday before the semester start (2025-07-01)
 *   2026-01-05  a Monday after the semester end (2025-12-31)
 */
beforeEach(function () {
    Carbon::setTestNow('2025-09-10 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Seeds roles, the active school and its class levels, creates an academic
 * year (semester 1: 2025-07-01 … 2025-12-31) and a Monday teaching schedule
 * for the "tamhidi" class. `user` is super_admin, `pengurus` is
 * pengurus_pesantren.
 *
 * @return array{user: User, pengurus: User, school: School, academicYear: AcademicYear, classLevel: ClassLevel, subjectBook: SubjectBook, teacher: Teacher, schedule: TeachingSchedule}
 */
function setUpClassSessionContext(): array
{
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    (new ClassLevelSeeder)->run();

    $user = User::factory()->create(['name' => 'Admin Absensi']);
    $user->assignRole('super_admin');

    $pengurus = User::factory()->create(['name' => 'Pengurus Absensi']);
    $pengurus->assignRole('pengurus_pesantren');

    $school = School::where('is_active', true)->firstOrFail();

    $academicYear = AcademicYear::factory()->create([
        'school_id' => $school->id,
        'name' => '2025/2026',
        'start_date' => '2025-07-01',
        'end_date' => '2026-06-30',
        'is_active' => true,
        'active_semester' => 1,
    ]);
    app(AcademicSemesterService::class)->createSemestersForAcademicYear($academicYear);
    app(AcademicSemesterService::class)->updateSemester($academicYear, 1, [
        'start_date' => '2025-07-01',
        'end_date' => '2025-12-31',
    ]);

    $classLevel = ClassLevel::where('school_id', $school->id)->where('slug', 'tamhidi')->firstOrFail();
    $subjectBook = SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => SubjectCategory::factory()->create(['school_id' => $school->id])->id,
        'title' => 'Safinatun Najah',
    ]);
    $teacher = Teacher::factory()->create(['school_id' => $school->id, 'full_name' => 'Ustadz Ahmad']);

    $schedule = classSessionCreateSchedule($school, $academicYear, $classLevel, $subjectBook, $teacher, 'monday');

    return compact('user', 'pengurus', 'school', 'academicYear', 'classLevel', 'subjectBook', 'teacher', 'schedule');
}

function classSessionCreateSchedule(
    School $school,
    AcademicYear $academicYear,
    ClassLevel $classLevel,
    SubjectBook $subjectBook,
    Teacher $teacher,
    string $dayOfWeek,
    bool $isActive = true,
): TeachingSchedule {
    return TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'day_of_week' => $dayOfWeek,
        'time_slot_id' => TimeSlot::factory()->create(['school_id' => $school->id])->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
        'is_active' => $isActive,
    ]);
}

/**
 * Creates a student through the real POST /students endpoint (so school_id
 * and class_level_id are resolved the same way production does it).
 */
function classSessionCreateStudent($testCase, User $user, string $fullName, string $entryDate = '2025-07-01', string $classLevelSlug = 'tamhidi'): Student
{
    $response = $testCase->actingAs($user)->postJson('/api/v1/students', [
        'full_name' => $fullName,
        'birth_date' => '2012-05-15',
        'gender' => 'L',
        'program' => 'regular',
        'entry_date' => $entryDate,
        'class_level' => $classLevelSlug,
        'address' => 'Jl. Contoh No. 1',
    ]);

    $response->assertCreated();

    return Student::findOrFail($response->json('data.id'));
}

/**
 * @param  array<int, Student>  $students
 * @return array<int, array{student_id: string, status: string, notes: string|null}>
 */
function classSessionAttendanceRows(array $students, string $status = 'present'): array
{
    return array_map(fn (Student $student) => [
        'student_id' => $student->id,
        'status' => $status,
        'notes' => null,
    ], $students);
}

function classSessionRecordPayload(TeachingSchedule $schedule, string $sessionDate, array $attendances): array
{
    return [
        'teaching_schedule_id' => $schedule->id,
        'session_date' => $sessionDate,
        'attendances' => $attendances,
    ];
}

function classSessionRecordThroughEndpoint($testCase, User $actingUser, TeachingSchedule $schedule, string $sessionDate, array $attendances): ClassSession
{
    $response = $testCase->actingAs($actingUser)
        ->postJson('/api/v1/class-sessions', classSessionRecordPayload($schedule, $sessionDate, $attendances));
    $response->assertCreated();

    return ClassSession::findOrFail($response->json('data.class_session.id'));
}

function classSessionListQuery(array $context, array $extra = []): string
{
    return '/api/v1/class-sessions?'.http_build_query(array_merge([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
    ], $extra));
}

// ── POST /class-sessions (record a Pertemuan) ─────────────────────────────

test('recording a session creates a held session with one attendance row per expected student', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');
    $zaid = classSessionCreateStudent($this, $context['user'], 'Zaid');

    $response = $this->actingAs($context['pengurus'])->postJson('/api/v1/class-sessions', classSessionRecordPayload(
        $context['schedule'],
        '2025-09-08',
        [
            ['student_id' => $ali->id, 'status' => 'present', 'notes' => null],
            ['student_id' => $zaid->id, 'status' => 'sick', 'notes' => 'Demam'],
        ],
    ));

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.class_session.status', 'held')
        ->assertJsonPath('data.class_session.session_date', '2025-09-08')
        ->assertJsonPath('data.class_session.teaching_schedule_id', $context['schedule']->id)
        ->assertJsonPath('data.class_session.academic_year_id', $context['academicYear']->id)
        ->assertJsonPath('data.class_session.semester', 1)
        ->assertJsonPath('data.class_session.class_level_id', $context['classLevel']->id)
        ->assertJsonPath('data.class_session.subject_book_id', $context['subjectBook']->id)
        ->assertJsonPath('data.class_session.teacher_id', $context['teacher']->id)
        ->assertJsonPath('data.class_session.teacher.full_name', 'Ustadz Ahmad')
        ->assertJsonPath('data.class_session.subject_book.title', 'Safinatun Najah')
        ->assertJsonPath('data.class_session.attendance_summary.present', 1)
        ->assertJsonPath('data.class_session.attendance_summary.sick', 1)
        ->assertJsonPath('data.class_session.attendance_summary.total', 2)
        ->assertJsonPath('data.class_session.created_by', $context['pengurus']->id)
        ->assertJsonPath('data.requires_override_warning', false)
        ->assertJsonCount(2, 'data.attendances');

    $zaidRow = collect($response->json('data.attendances'))->firstWhere('student_id', $zaid->id);
    expect($zaidRow['status'])->toBe('sick');
    expect($zaidRow['notes'])->toBe('Demam');
    expect($zaidRow['student_name'])->toBe('Zaid');
    expect($zaidRow['updated_by'])->toBe($context['pengurus']->id);
    expect($zaidRow['updated_at'])->not->toBeNull();

    $session = ClassSession::findOrFail($response->json('data.class_session.id'));
    expect($session->school_id)->toBe($context['school']->id);
    expect($session->created_by)->toBe($context['pengurus']->id);
    expect($session->updated_by)->toBe($context['pengurus']->id);
    expect(StudentAttendance::where('class_session_id', $session->id)->count())->toBe(2);
    expect(StudentAttendance::where('class_session_id', $session->id)->pluck('school_id')->unique()->all())
        ->toBe([$context['school']->id]);
});

test('the session keeps its class, kitab and teacher snapshot when the schedule changes later', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');
    $session = classSessionRecordThroughEndpoint($this, $context['user'], $context['schedule'], '2025-09-08', classSessionAttendanceRows([$ali]));

    $otherTeacher = Teacher::factory()->create(['school_id' => $context['school']->id, 'full_name' => 'Ustadz Budi']);
    $context['schedule']->update(['teacher_id' => $otherTeacher->id]);

    $this->actingAs($context['user'])->getJson("/api/v1/class-sessions/{$session->id}")
        ->assertOk()
        ->assertJsonPath('data.teacher_id', $context['teacher']->id)
        ->assertJsonPath('data.teacher.full_name', 'Ustadz Ahmad');
});

test('recording a session requires a status for every expected student', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');
    $zaid = classSessionCreateStudent($this, $context['user'], 'Zaid');

    $this->actingAs($context['user'])
        ->postJson('/api/v1/class-sessions', classSessionRecordPayload($context['schedule'], '2025-09-08', classSessionAttendanceRows([$ali])))
        ->assertUnprocessable()
        ->assertJsonPath("errors.{$zaid->id}.0", 'Absensi belum lengkap untuk santri ini.');

    expect(ClassSession::count())->toBe(0);
    expect(StudentAttendance::count())->toBe(0);
});

test('recording a session rejects a student who is not in the schedules class', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');
    $otherClassStudent = classSessionCreateStudent($this, $context['user'], 'Umar', '2025-07-01', 'ibtida_1');

    $this->actingAs($context['user'])
        ->postJson('/api/v1/class-sessions', classSessionRecordPayload(
            $context['schedule'],
            '2025-09-08',
            classSessionAttendanceRows([$ali, $otherClassStudent]),
        ))
        ->assertUnprocessable()
        ->assertJsonPath("errors.{$otherClassStudent->id}.0", 'Santri tidak terdaftar di kelas ini.');

    expect(ClassSession::count())->toBe(0);
});

test('recording a session rejects a duplicate student and an invalid status, keyed by student_id', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');
    $zaid = classSessionCreateStudent($this, $context['user'], 'Zaid');

    $this->actingAs($context['user'])
        ->postJson('/api/v1/class-sessions', classSessionRecordPayload($context['schedule'], '2025-09-08', [
            ['student_id' => $ali->id, 'status' => 'present', 'notes' => null],
            ['student_id' => $ali->id, 'status' => 'absent', 'notes' => null],
            ['student_id' => $zaid->id, 'status' => 'late', 'notes' => null],
        ]))
        ->assertUnprocessable()
        ->assertJsonPath("errors.{$ali->id}.0", 'Santri tercantum lebih dari sekali.')
        ->assertJsonPath("errors.{$zaid->id}.0", 'Status absensi tidak valid.');

    expect(ClassSession::count())->toBe(0);
});

test('recording a session rejects notes longer than 255 characters, keyed by student_id', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');

    $this->actingAs($context['user'])
        ->postJson('/api/v1/class-sessions', classSessionRecordPayload($context['schedule'], '2025-09-08', [
            ['student_id' => $ali->id, 'status' => 'excused', 'notes' => str_repeat('a', 256)],
        ]))
        ->assertUnprocessable()
        ->assertJsonPath("errors.{$ali->id}.0", 'Catatan absensi maksimal 255 karakter.');
});

test('recording a session validates the request shape', function () {
    $context = setUpClassSessionContext();

    $this->actingAs($context['user'])
        ->postJson('/api/v1/class-sessions', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['teaching_schedule_id', 'session_date', 'attendances']);

    $this->actingAs($context['user'])
        ->postJson('/api/v1/class-sessions', [
            'teaching_schedule_id' => $context['schedule']->id,
            'session_date' => '08-09-2025',
            'attendances' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.session_date.0', 'Format tanggal pertemuan harus YYYY-MM-DD.')
        ->assertJsonPath('errors.attendances.0', 'Daftar absensi santri wajib diisi.');
});

test('recording a session rejects an inactive teaching schedule', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');
    $inactiveSchedule = classSessionCreateSchedule(
        $context['school'], $context['academicYear'], $context['classLevel'], $context['subjectBook'], $context['teacher'], 'monday', false,
    );

    $this->actingAs($context['user'])
        ->postJson('/api/v1/class-sessions', classSessionRecordPayload($inactiveSchedule, '2025-09-08', classSessionAttendanceRows([$ali])))
        ->assertUnprocessable()
        ->assertJsonPath('errors.teaching_schedule_id.0', 'Jadwal mengajar tidak ditemukan atau tidak aktif.');
});

test('only one session per schedule and date, but a soft-deleted one does not block re-recording', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');
    $session = classSessionRecordThroughEndpoint($this, $context['user'], $context['schedule'], '2025-09-08', classSessionAttendanceRows([$ali]));

    $this->actingAs($context['user'])
        ->postJson('/api/v1/class-sessions', classSessionRecordPayload($context['schedule'], '2025-09-08', classSessionAttendanceRows([$ali])))
        ->assertUnprocessable()
        ->assertJsonPath('errors.session_date.0', 'Pertemuan untuk jadwal dan tanggal ini sudah tercatat.');

    expect(ClassSession::count())->toBe(1);

    $session->delete();

    classSessionRecordThroughEndpoint($this, $context['user'], $context['schedule'], '2025-09-08', classSessionAttendanceRows([$ali]));

    expect(ClassSession::count())->toBe(1);
    expect(ClassSession::withTrashed()->count())->toBe(2);
});

test('the partial unique index rejects a second live session for the same schedule and date', function () {
    $context = setUpClassSessionContext();
    $attributes = [
        'school_id' => $context['school']->id,
        'teaching_schedule_id' => $context['schedule']->id,
        'session_date' => '2025-09-08',
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'subject_book_id' => $context['subjectBook']->id,
        'teacher_id' => $context['teacher']->id,
        'status' => ClassSession::STATUS_HELD,
    ];

    ClassSession::create($attributes)->delete();
    ClassSession::create($attributes);

    expect(fn () => ClassSession::create($attributes))->toThrow(UniqueConstraintViolationException::class);
});

test('the same date may be recorded for two different schedules', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');
    $secondMondaySchedule = classSessionCreateSchedule(
        $context['school'], $context['academicYear'], $context['classLevel'], $context['subjectBook'], $context['teacher'], 'monday',
    );

    classSessionRecordThroughEndpoint($this, $context['user'], $context['schedule'], '2025-09-08', classSessionAttendanceRows([$ali]));
    classSessionRecordThroughEndpoint($this, $context['user'], $secondMondaySchedule, '2025-09-08', classSessionAttendanceRows([$ali]));

    expect(ClassSession::count())->toBe(2);
});

test('recording a session on a date that does not match the schedules weekday is rejected', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');

    // 2025-09-09 is a Tuesday; the schedule meets on Mondays.
    $this->actingAs($context['user'])
        ->postJson('/api/v1/class-sessions', classSessionRecordPayload($context['schedule'], '2025-09-09', classSessionAttendanceRows([$ali])))
        ->assertUnprocessable()
        ->assertJsonPath('errors.session_date.0', 'Tanggal tidak sesuai hari jadwal.');
});

test('a date before the semester start is rejected for everyone', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali', '2025-06-01');

    foreach ([$context['user'], $context['pengurus']] as $actingUser) {
        $this->actingAs($actingUser)
            ->postJson('/api/v1/class-sessions', classSessionRecordPayload($context['schedule'], '2025-06-30', classSessionAttendanceRows([$ali])))
            ->assertUnprocessable()
            ->assertJsonPath('errors.session_date.0', 'Tanggal di luar rentang semester.');
    }
});

test('a pengurus cannot record a session for a future date', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');

    $this->actingAs($context['pengurus'])
        ->postJson('/api/v1/class-sessions', classSessionRecordPayload($context['schedule'], '2025-09-15', classSessionAttendanceRows([$ali])))
        ->assertUnprocessable()
        ->assertJsonPath('errors.session_date.0', 'Pertemuan tidak boleh dicatat untuk tanggal mendatang.');
});

test('a pengurus can record a session for today', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');
    $wednesdaySchedule = classSessionCreateSchedule(
        $context['school'], $context['academicYear'], $context['classLevel'], $context['subjectBook'], $context['teacher'], 'wednesday',
    );

    $this->actingAs($context['pengurus'])
        ->postJson('/api/v1/class-sessions', classSessionRecordPayload($wednesdaySchedule, '2025-09-10', classSessionAttendanceRows([$ali])))
        ->assertCreated()
        ->assertJsonPath('data.requires_override_warning', false);
});

test('a pengurus may record a missed session older than 14 days but may not edit it afterwards', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');

    $oldSession = classSessionRecordThroughEndpoint($this, $context['pengurus'], $context['schedule'], '2025-08-25', classSessionAttendanceRows([$ali]));

    $this->actingAs($context['pengurus'])
        ->putJson("/api/v1/class-sessions/{$oldSession->id}/attendances", [
            'attendances' => classSessionAttendanceRows([$ali], 'absent'),
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.session_date.0', 'Perubahan absensi hanya boleh sampai 14 hari ke belakang.');

    expect(StudentAttendance::where('class_session_id', $oldSession->id)->value('status'))->toBe('present');
});

test('a pengurus can edit a session inside the 14-day window, including its first day', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');
    $recentSession = classSessionRecordThroughEndpoint($this, $context['pengurus'], $context['schedule'], '2025-09-01', classSessionAttendanceRows([$ali]));

    $this->actingAs($context['pengurus'])
        ->putJson("/api/v1/class-sessions/{$recentSession->id}/attendances", [
            'attendances' => classSessionAttendanceRows([$ali], 'absent'),
        ])
        ->assertOk()
        ->assertJsonPath('data.requires_override_warning', false);

    // today − 14 days = 2025-08-27 (a Wednesday): still editable.
    $wednesdaySchedule = classSessionCreateSchedule(
        $context['school'], $context['academicYear'], $context['classLevel'], $context['subjectBook'], $context['teacher'], 'wednesday',
    );
    $boundarySession = classSessionRecordThroughEndpoint($this, $context['pengurus'], $wednesdaySchedule, '2025-08-27', classSessionAttendanceRows([$ali]));

    $this->actingAs($context['pengurus'])
        ->putJson("/api/v1/class-sessions/{$boundarySession->id}/attendances", [
            'attendances' => classSessionAttendanceRows([$ali], 'sick'),
        ])
        ->assertOk();
});

test('super_admin may record a future date inside the semester, flagged with an override warning', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');

    $this->actingAs($context['user'])
        ->postJson('/api/v1/class-sessions', classSessionRecordPayload($context['schedule'], '2025-09-15', classSessionAttendanceRows([$ali])))
        ->assertCreated()
        ->assertJsonPath('data.requires_override_warning', true);

    $this->actingAs($context['user'])
        ->postJson('/api/v1/class-sessions', classSessionRecordPayload($context['schedule'], '2025-09-08', classSessionAttendanceRows([$ali])))
        ->assertCreated()
        ->assertJsonPath('data.requires_override_warning', false);
});

test('super_admin cannot record a date after the semester end', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');

    $this->actingAs($context['user'])
        ->postJson('/api/v1/class-sessions', classSessionRecordPayload($context['schedule'], '2026-01-05', classSessionAttendanceRows([$ali])))
        ->assertUnprocessable()
        ->assertJsonPath('errors.session_date.0', 'Tanggal di luar rentang semester.');
});

test('super_admin may edit a session older than 14 days, flagged with an override warning', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');
    $oldSession = classSessionRecordThroughEndpoint($this, $context['user'], $context['schedule'], '2025-08-25', classSessionAttendanceRows([$ali]));

    $this->actingAs($context['user'])
        ->putJson("/api/v1/class-sessions/{$oldSession->id}/attendances", [
            'attendances' => classSessionAttendanceRows([$ali], 'absent'),
        ])
        ->assertOk()
        ->assertJsonPath('data.requires_override_warning', true);
});

// ── Expected students (entry_date rule) ───────────────────────────────────

test('a student who entered after the session date is not expected at that session', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali', '2025-07-01');
    $lateStudent = classSessionCreateStudent($this, $context['user'], 'Zaid', '2025-09-05');
    $onTheDayStudent = classSessionCreateStudent($this, $context['user'], 'Umar', '2025-09-01');

    $response = $this->actingAs($context['user'])
        ->getJson("/api/v1/teaching-schedules/{$context['schedule']->id}/expected-students?session_date=2025-09-01");

    $response->assertOk()
        ->assertJsonPath('data.teaching_schedule_id', $context['schedule']->id)
        ->assertJsonPath('data.session_date', '2025-09-01');
    expect(collect($response->json('data.students'))->pluck('full_name')->all())->toBe(['Ali', 'Umar']);
    expect($response->json('data.students.0.is_attendance_required'))->toBeTrue();

    // Recording without the late student succeeds…
    classSessionRecordThroughEndpoint($this, $context['user'], $context['schedule'], '2025-09-01', classSessionAttendanceRows([$ali, $onTheDayStudent]));

    // …and posting them is rejected on a date before their entry.
    $this->actingAs($context['user'])
        ->postJson('/api/v1/class-sessions', classSessionRecordPayload(
            $context['schedule'],
            '2025-08-25',
            classSessionAttendanceRows([$ali, $lateStudent]),
        ))
        ->assertUnprocessable()
        ->assertJsonPath("errors.{$lateStudent->id}.0", 'Santri belum masuk kelas pada tanggal pertemuan ini.');
});

test('an inactive student is listed but not required, and may still be recorded', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');
    $withdrawn = classSessionCreateStudent($this, $context['user'], 'Zaid');
    $withdrawn->update(['status' => Student::STATUS_WITHDRAWN]);

    $response = $this->actingAs($context['user'])
        ->getJson("/api/v1/teaching-schedules/{$context['schedule']->id}/expected-students?session_date=2025-09-08");

    $students = collect($response->json('data.students'))->keyBy('full_name');
    expect($students['Ali']['is_attendance_required'])->toBeTrue();
    expect($students['Zaid']['is_attendance_required'])->toBeFalse();
    expect($students['Zaid']['is_active_student'])->toBeFalse();

    // Not required: recording without them succeeds.
    classSessionRecordThroughEndpoint($this, $context['user'], $context['schedule'], '2025-09-08', classSessionAttendanceRows([$ali]));

    // Still allowed when posted.
    classSessionRecordThroughEndpoint($this, $context['user'], $context['schedule'], '2025-09-01', classSessionAttendanceRows([$ali, $withdrawn]));
});

test('expected students requires a valid session_date', function () {
    $context = setUpClassSessionContext();

    $this->actingAs($context['user'])
        ->getJson("/api/v1/teaching-schedules/{$context['schedule']->id}/expected-students")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['session_date']);
});

// ── PUT /class-sessions/{classSession}/attendances ────────────────────────

test('updating attendances upserts posted rows by student and returns them with audit fields', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');
    $zaid = classSessionCreateStudent($this, $context['user'], 'Zaid');
    $session = classSessionRecordThroughEndpoint($this, $context['user'], $context['schedule'], '2025-09-08', classSessionAttendanceRows([$ali, $zaid]));

    $response = $this->actingAs($context['pengurus'])
        ->putJson("/api/v1/class-sessions/{$session->id}/attendances", [
            'attendances' => [['student_id' => $zaid->id, 'status' => 'excused', 'notes' => 'Pulang kampung']],
        ]);

    $response->assertOk()
        ->assertJsonCount(1, 'data.attendances')
        ->assertJsonPath('data.attendances.0.student_id', $zaid->id)
        ->assertJsonPath('data.attendances.0.status', 'excused')
        ->assertJsonPath('data.attendances.0.notes', 'Pulang kampung')
        ->assertJsonPath('data.attendances.0.updated_by', $context['pengurus']->id)
        ->assertJsonPath('data.attendances.0.created_by', $context['user']->id)
        ->assertJsonPath('data.class_session.attendance_summary.present', 1)
        ->assertJsonPath('data.class_session.attendance_summary.excused', 1)
        ->assertJsonPath('data.class_session.updated_by', $context['pengurus']->id);

    expect(StudentAttendance::where('class_session_id', $session->id)->count())->toBe(2);
    expect(StudentAttendance::where('student_id', $ali->id)->value('updated_by'))->toBe($context['user']->id);
});

test('updating attendances keeps every expected student covered', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');
    $session = classSessionRecordThroughEndpoint($this, $context['user'], $context['schedule'], '2025-09-08', classSessionAttendanceRows([$ali]));

    // A student moved into the class later with an entry_date before the session.
    $movedIn = classSessionCreateStudent($this, $context['user'], 'Zaid', '2025-07-01', 'ibtida_1');
    $movedIn->update(['class_level_id' => $context['classLevel']->id]);

    $this->actingAs($context['user'])
        ->putJson("/api/v1/class-sessions/{$session->id}/attendances", [
            'attendances' => classSessionAttendanceRows([$ali], 'absent'),
        ])
        ->assertUnprocessable()
        ->assertJsonPath("errors.{$movedIn->id}.0", 'Absensi belum lengkap untuk santri ini.');

    $this->actingAs($context['user'])
        ->putJson("/api/v1/class-sessions/{$session->id}/attendances", [
            'attendances' => classSessionAttendanceRows([$movedIn]),
        ])
        ->assertOk();
});

test('updating attendances rejects a student outside the class', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');
    $outsider = classSessionCreateStudent($this, $context['user'], 'Umar', '2025-07-01', 'ibtida_1');
    $session = classSessionRecordThroughEndpoint($this, $context['user'], $context['schedule'], '2025-09-08', classSessionAttendanceRows([$ali]));

    $this->actingAs($context['user'])
        ->putJson("/api/v1/class-sessions/{$session->id}/attendances", [
            'attendances' => classSessionAttendanceRows([$outsider]),
        ])
        ->assertUnprocessable()
        ->assertJsonPath("errors.{$outsider->id}.0", 'Santri tidak terdaftar di kelas ini.');
});

test('updating attendances of a cancelled session is rejected', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');

    $this->actingAs($context['user'])->postJson('/api/v1/class-sessions/cancel', [
        'teaching_schedule_id' => $context['schedule']->id,
        'session_date' => '2025-09-08',
        'reason' => 'Libur maulid',
    ])->assertCreated();

    $session = ClassSession::firstOrFail();

    $this->actingAs($context['user'])
        ->putJson("/api/v1/class-sessions/{$session->id}/attendances", [
            'attendances' => classSessionAttendanceRows([$ali]),
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.class_session.0', 'Pertemuan ini sudah dibatalkan.');
});

// ── POST /class-sessions/cancel ───────────────────────────────────────────

test('cancelling a date without a session creates a cancelled session with its reason', function () {
    $context = setUpClassSessionContext();

    $response = $this->actingAs($context['pengurus'])->postJson('/api/v1/class-sessions/cancel', [
        'teaching_schedule_id' => $context['schedule']->id,
        'session_date' => '2025-09-08',
        'reason' => 'Ustadz berhalangan',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.class_session.status', 'cancelled')
        ->assertJsonPath('data.class_session.cancel_reason', 'Ustadz berhalangan')
        ->assertJsonPath('data.class_session.teacher_id', $context['teacher']->id)
        ->assertJsonPath('data.class_session.created_by', $context['pengurus']->id)
        ->assertJsonPath('data.attendances', [])
        ->assertJsonPath('data.requires_override_warning', false);

    expect(ClassSession::where('status', 'cancelled')->count())->toBe(1);
});

test('cancelling a held session converts it and keeps its attendance rows', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');
    $session = classSessionRecordThroughEndpoint($this, $context['user'], $context['schedule'], '2025-09-08', classSessionAttendanceRows([$ali]));

    $this->actingAs($context['pengurus'])->postJson('/api/v1/class-sessions/cancel', [
        'teaching_schedule_id' => $context['schedule']->id,
        'session_date' => '2025-09-08',
        'reason' => 'Libur mendadak',
    ])
        ->assertOk()
        ->assertJsonPath('data.class_session.id', $session->id)
        ->assertJsonPath('data.class_session.status', 'cancelled')
        ->assertJsonPath('data.class_session.cancel_reason', 'Libur mendadak')
        ->assertJsonPath('data.class_session.updated_by', $context['pengurus']->id)
        ->assertJsonPath('data.class_session.created_by', $context['user']->id);

    expect(ClassSession::count())->toBe(1);
    expect(StudentAttendance::where('class_session_id', $session->id)->count())->toBe(1);
});

test('cancelling follows the same date rules', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');
    $oldSession = classSessionRecordThroughEndpoint($this, $context['user'], $context['schedule'], '2025-08-25', classSessionAttendanceRows([$ali]));

    $cancel = fn (User $actingUser, string $sessionDate) => $this->actingAs($actingUser)->postJson('/api/v1/class-sessions/cancel', [
        'teaching_schedule_id' => $context['schedule']->id,
        'session_date' => $sessionDate,
        'reason' => 'Libur',
    ]);

    $cancel($context['pengurus'], '2025-09-09')
        ->assertUnprocessable()
        ->assertJsonPath('errors.session_date.0', 'Tanggal tidak sesuai hari jadwal.');

    $cancel($context['pengurus'], '2025-09-15')
        ->assertUnprocessable()
        ->assertJsonPath('errors.session_date.0', 'Pertemuan tidak boleh dicatat untuk tanggal mendatang.');

    // Converting an existing session older than 14 days is an edit.
    $cancel($context['pengurus'], '2025-08-25')
        ->assertUnprocessable()
        ->assertJsonPath('errors.session_date.0', 'Perubahan absensi hanya boleh sampai 14 hari ke belakang.');
    expect($oldSession->fresh()->status)->toBe('held');

    // A missed date older than 14 days without a session can still be cancelled.
    $cancel($context['pengurus'], '2025-08-18')->assertCreated();

    $cancel($context['user'], '2025-09-15')
        ->assertCreated()
        ->assertJsonPath('data.requires_override_warning', true);
});

test('cancelling requires a reason of at most 255 characters', function () {
    $context = setUpClassSessionContext();

    $this->actingAs($context['user'])->postJson('/api/v1/class-sessions/cancel', [
        'teaching_schedule_id' => $context['schedule']->id,
        'session_date' => '2025-09-08',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.reason.0', 'Alasan pembatalan wajib diisi.');

    $this->actingAs($context['user'])->postJson('/api/v1/class-sessions/cancel', [
        'teaching_schedule_id' => $context['schedule']->id,
        'session_date' => '2025-09-08',
        'reason' => str_repeat('a', 256),
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.reason.0', 'Alasan pembatalan maksimal 255 karakter.');
});

// ── GET /class-sessions, GET /class-sessions/{classSession} ───────────────

test('sessions are listed per semester with filters and attendance summaries', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');
    $zaid = classSessionCreateStudent($this, $context['user'], 'Zaid');
    $otherSchedule = classSessionCreateSchedule(
        $context['school'], $context['academicYear'], $context['classLevel'], $context['subjectBook'], $context['teacher'], 'tuesday',
    );

    classSessionRecordThroughEndpoint($this, $context['user'], $context['schedule'], '2025-09-01', [
        ['student_id' => $ali->id, 'status' => 'present', 'notes' => null],
        ['student_id' => $zaid->id, 'status' => 'absent', 'notes' => null],
    ]);
    classSessionRecordThroughEndpoint($this, $context['user'], $context['schedule'], '2025-09-08', classSessionAttendanceRows([$ali, $zaid]));
    $this->actingAs($context['user'])->postJson('/api/v1/class-sessions/cancel', [
        'teaching_schedule_id' => $context['schedule']->id,
        'session_date' => '2025-08-25',
        'reason' => 'Libur',
    ])->assertCreated();
    classSessionRecordThroughEndpoint($this, $context['user'], $otherSchedule, '2025-09-09', classSessionAttendanceRows([$ali, $zaid]));

    $response = $this->actingAs($context['pengurus'])
        ->getJson(classSessionListQuery($context, ['teaching_schedule_id' => $context['schedule']->id]));

    $response->assertOk()->assertJsonCount(3, 'data');
    // Most recent session_date first.
    expect(collect($response->json('data'))->pluck('session_date')->all())->toBe(['2025-09-08', '2025-09-01', '2025-08-25']);
    expect(collect($response->json('data'))->pluck('status')->all())->toBe(['held', 'held', 'cancelled']);
    $firstOfSeptember = collect($response->json('data'))->firstWhere('session_date', '2025-09-01');
    expect($firstOfSeptember['attendance_summary'])->toBe(['present' => 1, 'sick' => 0, 'excused' => 0, 'absent' => 1, 'total' => 2]);

    $this->actingAs($context['user'])
        ->getJson(classSessionListQuery($context, ['date_from' => '2025-09-02', 'date_to' => '2025-09-09']))
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAs($context['user'])
        ->getJson(classSessionListQuery($context, ['class_level_id' => $context['classLevel']->id]))
        ->assertOk()
        ->assertJsonCount(4, 'data');

    $this->actingAs($context['user'])
        ->getJson(classSessionListQuery($context, ['date_from' => '2025-09-09', 'date_to' => '2025-09-01']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date_to']);
});

test('listing sessions requires the semester pair', function () {
    $context = setUpClassSessionContext();

    $this->actingAs($context['user'])
        ->getJson('/api/v1/class-sessions')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['academic_year_id', 'semester']);
});

test('a session is shown with its attendances', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');
    $zaid = classSessionCreateStudent($this, $context['user'], 'Zaid');
    $session = classSessionRecordThroughEndpoint($this, $context['user'], $context['schedule'], '2025-09-08', [
        ['student_id' => $zaid->id, 'status' => 'absent', 'notes' => 'Tanpa kabar'],
        ['student_id' => $ali->id, 'status' => 'present', 'notes' => null],
    ]);

    $response = $this->actingAs($context['pengurus'])->getJson("/api/v1/class-sessions/{$session->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $session->id)
        ->assertJsonPath('data.status', 'held')
        ->assertJsonPath('data.class_level.label', 'Tamhidi')
        ->assertJsonCount(2, 'data.attendances');
    expect(collect($response->json('data.attendances'))->pluck('student_name')->all())->toBe(['Ali', 'Zaid']);
    expect(collect($response->json('data.attendances'))->firstWhere('student_name', 'Zaid')['notes'])->toBe('Tanpa kabar');
});

test('a soft-deleted session is not found', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');
    $session = classSessionRecordThroughEndpoint($this, $context['user'], $context['schedule'], '2025-09-08', classSessionAttendanceRows([$ali]));
    $session->delete();

    $this->actingAs($context['user'])->getJson("/api/v1/class-sessions/{$session->id}")->assertNotFound();
    $this->actingAs($context['user'])->getJson(classSessionListQuery($context))->assertOk()->assertJsonCount(0, 'data');
});

// ── Permissions ───────────────────────────────────────────────────────────

test('reading sessions requires view-attendance and writing requires manage-attendance', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');
    $session = classSessionRecordThroughEndpoint($this, $context['user'], $context['schedule'], '2025-09-08', classSessionAttendanceRows([$ali]));

    $noPermissionUser = User::factory()->create();

    $this->actingAs($noPermissionUser)->getJson(classSessionListQuery($context))->assertForbidden();
    $this->actingAs($noPermissionUser)->getJson("/api/v1/class-sessions/{$session->id}")->assertForbidden();
    $this->actingAs($noPermissionUser)
        ->getJson("/api/v1/teaching-schedules/{$context['schedule']->id}/expected-students?session_date=2025-09-08")
        ->assertForbidden();

    $viewOnlyUser = User::factory()->create();
    $viewOnlyUser->givePermissionTo('view-attendance');

    $this->actingAs($viewOnlyUser)->getJson(classSessionListQuery($context))->assertOk();
    $this->actingAs($viewOnlyUser)->getJson("/api/v1/class-sessions/{$session->id}")->assertOk();
    $this->actingAs($viewOnlyUser)
        ->postJson('/api/v1/class-sessions', classSessionRecordPayload($context['schedule'], '2025-09-01', classSessionAttendanceRows([$ali])))
        ->assertForbidden();
    $this->actingAs($viewOnlyUser)
        ->putJson("/api/v1/class-sessions/{$session->id}/attendances", ['attendances' => classSessionAttendanceRows([$ali])])
        ->assertForbidden();
    $this->actingAs($viewOnlyUser)
        ->postJson('/api/v1/class-sessions/cancel', [
            'teaching_schedule_id' => $context['schedule']->id,
            'session_date' => '2025-09-01',
            'reason' => 'Libur',
        ])
        ->assertForbidden();
});

// ── Tenancy ───────────────────────────────────────────────────────────────

test('another schools session and schedule are hidden behind 404 and rejected in request bodies', function () {
    $context = setUpClassSessionContext();
    $ali = classSessionCreateStudent($this, $context['user'], 'Ali');

    $otherSchool = School::factory()->create();
    $otherAcademicYear = AcademicYear::factory()->create(['school_id' => $otherSchool->id]);
    $otherClassLevel = ClassLevel::factory()->create(['school_id' => $otherSchool->id]);
    $otherSubjectBook = SubjectBook::factory()->create([
        'school_id' => $otherSchool->id,
        'subject_category_id' => SubjectCategory::factory()->create(['school_id' => $otherSchool->id])->id,
    ]);
    $otherTeacher = Teacher::factory()->create(['school_id' => $otherSchool->id]);
    $otherSchedule = classSessionCreateSchedule($otherSchool, $otherAcademicYear, $otherClassLevel, $otherSubjectBook, $otherTeacher, 'monday');

    $otherSession = ClassSession::create([
        'school_id' => $otherSchool->id,
        'teaching_schedule_id' => $otherSchedule->id,
        'session_date' => '2025-09-08',
        'academic_year_id' => $otherAcademicYear->id,
        'semester' => 1,
        'class_level_id' => $otherClassLevel->id,
        'subject_book_id' => $otherSubjectBook->id,
        'teacher_id' => $otherTeacher->id,
        'status' => ClassSession::STATUS_HELD,
    ]);

    $this->actingAs($context['user'])->getJson("/api/v1/class-sessions/{$otherSession->id}")->assertNotFound();
    // Tenancy runs before validation: an invalid body still gets 404, not 422.
    $this->actingAs($context['user'])
        ->putJson("/api/v1/class-sessions/{$otherSession->id}/attendances", ['attendances' => []])
        ->assertNotFound();
    $this->actingAs($context['user'])
        ->getJson("/api/v1/teaching-schedules/{$otherSchedule->id}/expected-students")
        ->assertNotFound();
    $this->actingAs($context['user'])
        ->putJson("/api/v1/class-sessions/{$otherSession->id}/attendances", ['attendances' => classSessionAttendanceRows([$ali])])
        ->assertNotFound();
    $this->actingAs($context['user'])
        ->getJson("/api/v1/teaching-schedules/{$otherSchedule->id}/expected-students?session_date=2025-09-08")
        ->assertNotFound();

    $this->actingAs($context['user'])
        ->postJson('/api/v1/class-sessions', classSessionRecordPayload($otherSchedule, '2025-09-08', classSessionAttendanceRows([$ali])))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['teaching_schedule_id']);
    $this->actingAs($context['user'])
        ->postJson('/api/v1/class-sessions/cancel', [
            'teaching_schedule_id' => $otherSchedule->id,
            'session_date' => '2025-09-08',
            'reason' => 'Libur',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['teaching_schedule_id']);
    $this->actingAs($context['user'])
        ->getJson(classSessionListQuery($context, ['teaching_schedule_id' => $otherSchedule->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['teaching_schedule_id']);

    expect(ClassSession::where('school_id', $context['school']->id)->count())->toBe(0);
});

<?php

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\ClassSession;
use App\Models\GradingTemplate;
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
use App\Services\Akademik\GradingDefaultsInstaller;
use App\Services\Akademik\StudentGradeService;
use Database\Seeders\ClassLevelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;

/**
 * Seeds roles, the active school, class levels and grading defaults;
 * creates an academic year (semester 1: 2025-07-01 … 2025-12-31) with a
 * Monday teaching schedule of a teori_kitab kitab for the "tamhidi" class.
 * `user` is super_admin, `pengurus` is pengurus_pesantren. All test dates
 * used against this context are in the past relative to the real clock, so
 * SessionDatePolicy's future-date rule never gets in the way and there is
 * no need to freeze "today".
 *
 * @return array{user: User, pengurus: User, school: School, academicYear: AcademicYear, classLevel: ClassLevel, subjectBook: SubjectBook, teacher: Teacher, schedule: TeachingSchedule}
 */
function setUpAttendanceRecapContext(): array
{
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    (new ClassLevelSeeder)->run();

    $user = User::factory()->create(['name' => 'Admin Rekap Kehadiran']);
    $user->assignRole('super_admin');

    $pengurus = User::factory()->create(['name' => 'Pengurus Rekap Kehadiran']);
    $pengurus->assignRole('pengurus_pesantren');

    $school = School::where('is_active', true)->firstOrFail();
    app(GradingDefaultsInstaller::class)->installForSchool($school);

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
    $teoriKitabTemplateId = GradingTemplate::where('school_id', $school->id)
        ->where('code', GradingTemplate::CODE_TEORI_KITAB)
        ->value('id');

    $subjectBook = attendanceRecapCreateSubjectBook($school, 'Safinatun Najah', $teoriKitabTemplateId);
    $teacher = Teacher::factory()->create(['school_id' => $school->id, 'full_name' => 'Ustadz Ahmad']);
    $schedule = attendanceRecapCreateSchedule($school, $academicYear, $classLevel, $subjectBook, $teacher);

    return compact('user', 'pengurus', 'school', 'academicYear', 'classLevel', 'subjectBook', 'teacher', 'schedule');
}

function attendanceRecapCreateSubjectBook(School $school, string $title, ?string $gradingTemplateId): SubjectBook
{
    return SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => SubjectCategory::factory()->create(['school_id' => $school->id])->id,
        'grading_template_id' => $gradingTemplateId,
        'title' => $title,
    ]);
}

function attendanceRecapCreateSchedule(School $school, AcademicYear $academicYear, ClassLevel $classLevel, SubjectBook $subjectBook, Teacher $teacher, int $semester = 1): TeachingSchedule
{
    return TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'semester' => $semester,
        'day_of_week' => 'monday',
        'time_slot_id' => TimeSlot::factory()->create(['school_id' => $school->id])->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
        'is_active' => true,
    ]);
}

/**
 * Creates a student through the real POST /students endpoint.
 */
function attendanceRecapCreateStudent($testCase, User $user, string $fullName, string $entryDate = '2025-07-01', string $classLevelSlug = 'tamhidi'): Student
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
 * Records one held Pertemuan through the real POST /class-sessions
 * endpoint. $statusByStudent must cover every currently active santri of
 * the class as of $sessionDate.
 *
 * @param  array<string, string>  $statusByStudent  student id => status
 */
function attendanceRecapRecordSession($testCase, User $actingUser, TeachingSchedule $schedule, string $sessionDate, array $statusByStudent): void
{
    $testCase->actingAs($actingUser)->postJson('/api/v1/class-sessions', [
        'teaching_schedule_id' => $schedule->id,
        'session_date' => $sessionDate,
        'attendances' => collect($statusByStudent)
            ->map(fn (string $status, string $studentId) => ['student_id' => $studentId, 'status' => $status, 'notes' => null])
            ->values()
            ->all(),
    ])->assertCreated();
}

function attendanceRecapQuery(array $context, array $overrides = []): string
{
    return '/api/v1/attendance-recaps?'.http_build_query(array_merge([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'subject_book_id' => $context['subjectBook']->id,
    ], $overrides));
}

function attendanceRecapRowFor(array $rows, Student $student): array
{
    return collect($rows)->firstWhere('student.id', $student->id);
}

// ── GET /attendance-recaps ─────────────────────────────────────────────────

test('a student with no recorded Pertemuan gets a NULL score, "Belum ada pertemuan tercatat"', function () {
    $context = setUpAttendanceRecapContext();
    $ali = attendanceRecapCreateStudent($this, $context['user'], 'Ali');

    $response = $this->actingAs($context['user'])->getJson(attendanceRecapQuery($context))->assertOk();

    $row = attendanceRecapRowFor($response->json('data.rows'), $ali);
    expect($row)->toMatchArray([
        'present_count' => 0,
        'sick_count' => 0,
        'excused_count' => 0,
        'absent_count' => 0,
        'recorded_session_count' => 0,
        'score' => null,
        'missing_reason' => 'Belum ada pertemuan tercatat',
    ]);
    expect($response->json('data.held_session_count'))->toBe(0);
    expect($response->json('data.cancelled_session_count'))->toBe(0);
});

test('present and absent sessions compute the calculator score; sakit and izin are neutral', function () {
    $context = setUpAttendanceRecapContext();
    $ali = attendanceRecapCreateStudent($this, $context['user'], 'Ali');
    $zaid = attendanceRecapCreateStudent($this, $context['user'], 'Zaid');
    $schedule = $context['schedule'];

    attendanceRecapRecordSession($this, $context['user'], $schedule, '2025-07-07', [$ali->id => 'present', $zaid->id => 'absent']);
    attendanceRecapRecordSession($this, $context['user'], $schedule, '2025-07-14', [$ali->id => 'present', $zaid->id => 'sick']);
    attendanceRecapRecordSession($this, $context['user'], $schedule, '2025-07-21', [$ali->id => 'absent', $zaid->id => 'excused']);
    attendanceRecapRecordSession($this, $context['user'], $schedule, '2025-07-28', [$ali->id => 'present', $zaid->id => 'present']);

    $response = $this->actingAs($context['user'])->getJson(attendanceRecapQuery($context))->assertOk();
    $rows = $response->json('data.rows');

    // Ali: 3 hadir, 1 alpa → 3 / 4 * 100 = 75.
    expect(attendanceRecapRowFor($rows, $ali))->toMatchArray([
        'present_count' => 3, 'sick_count' => 0, 'excused_count' => 0, 'absent_count' => 1,
        'recorded_session_count' => 4, 'score' => 75.0, 'missing_reason' => null,
    ]);
    // Zaid: 1 hadir, 1 alpa, 1 sakit, 1 izin → sakit/izin excluded → 1 / 2 * 100 = 50.
    expect(attendanceRecapRowFor($rows, $zaid))->toMatchArray([
        'present_count' => 1, 'sick_count' => 1, 'excused_count' => 1, 'absent_count' => 1,
        'recorded_session_count' => 4, 'score' => 50.0, 'missing_reason' => null,
    ]);
    expect($response->json('data.held_session_count'))->toBe(4);
    expect($response->json('data.cancelled_session_count'))->toBe(0);
});

test('every counted Pertemuan being sakit/izin yields a NULL score, "Hanya sakit/izin"', function () {
    $context = setUpAttendanceRecapContext();
    $faisal = attendanceRecapCreateStudent($this, $context['user'], 'Faisal');
    $schedule = $context['schedule'];

    attendanceRecapRecordSession($this, $context['user'], $schedule, '2025-07-07', [$faisal->id => 'sick']);
    attendanceRecapRecordSession($this, $context['user'], $schedule, '2025-07-14', [$faisal->id => 'excused']);

    $response = $this->actingAs($context['user'])->getJson(attendanceRecapQuery($context))->assertOk();

    expect(attendanceRecapRowFor($response->json('data.rows'), $faisal))->toMatchArray([
        'present_count' => 0, 'sick_count' => 1, 'excused_count' => 1, 'absent_count' => 0,
        'recorded_session_count' => 2, 'score' => null, 'missing_reason' => 'Hanya sakit/izin',
    ]);
});

test('a late-entry student is only counted from their entry_date, even if an attendance row exists for an earlier Pertemuan', function () {
    $context = setUpAttendanceRecapContext();
    $ali = attendanceRecapCreateStudent($this, $context['user'], 'Ali', '2025-07-01');
    $schedule = $context['schedule'];

    attendanceRecapRecordSession($this, $context['user'], $schedule, '2025-07-07', [$ali->id => 'present']);

    $budi = attendanceRecapCreateStudent($this, $context['user'], 'Budi', '2025-07-14');
    attendanceRecapRecordSession($this, $context['user'], $schedule, '2025-07-14', [$ali->id => 'present', $budi->id => 'present']);

    // Defense in depth: even if a row exists for a Pertemuan before entry_date
    // (e.g. entry_date changed after the fact), EnrollmentDateRule excludes it.
    $earlierSession = ClassSession::where('teaching_schedule_id', $schedule->id)
        ->whereDate('session_date', '2025-07-07')->firstOrFail();
    StudentAttendance::create([
        'school_id' => $context['school']->id,
        'class_session_id' => $earlierSession->id,
        'student_id' => $budi->id,
        'status' => 'present',
        'created_by' => $context['user']->id,
        'updated_by' => $context['user']->id,
    ]);

    $response = $this->actingAs($context['user'])->getJson(attendanceRecapQuery($context))->assertOk();
    $rows = $response->json('data.rows');

    expect(attendanceRecapRowFor($rows, $ali))->toMatchArray(['present_count' => 2, 'recorded_session_count' => 2, 'score' => 100.0]);
    expect(attendanceRecapRowFor($rows, $budi))->toMatchArray(['present_count' => 1, 'recorded_session_count' => 1, 'score' => 100.0]);
});

test('a cancelled Pertemuan is excluded from the tally even though its attendance rows are kept', function () {
    $context = setUpAttendanceRecapContext();
    $ali = attendanceRecapCreateStudent($this, $context['user'], 'Ali');
    $schedule = $context['schedule'];

    attendanceRecapRecordSession($this, $context['user'], $schedule, '2025-07-07', [$ali->id => 'present']);

    $this->actingAs($context['user'])->postJson('/api/v1/class-sessions/cancel', [
        'teaching_schedule_id' => $schedule->id,
        'session_date' => '2025-07-07',
        'reason' => 'Libur nasional',
    ])->assertOk();

    $response = $this->actingAs($context['user'])->getJson(attendanceRecapQuery($context))->assertOk();

    expect(attendanceRecapRowFor($response->json('data.rows'), $ali))->toMatchArray([
        'present_count' => 0, 'recorded_session_count' => 0, 'score' => null, 'missing_reason' => 'Belum ada pertemuan tercatat',
    ]);
    expect($response->json('data.held_session_count'))->toBe(0);
    expect($response->json('data.cancelled_session_count'))->toBe(1);
    expect(StudentAttendance::where('class_session_id', ClassSession::withTrashed()->where('teaching_schedule_id', $schedule->id)->firstOrFail()->id)->count())
        ->toBe(1);
});

test('the Rekap Nilai absensi factor equals the calculator output for the same recorded sessions', function () {
    $context = setUpAttendanceRecapContext();
    $ali = attendanceRecapCreateStudent($this, $context['user'], 'Ali');
    $zaid = attendanceRecapCreateStudent($this, $context['user'], 'Zaid');
    $schedule = $context['schedule'];

    attendanceRecapRecordSession($this, $context['user'], $schedule, '2025-07-07', [$ali->id => 'present', $zaid->id => 'absent']);
    attendanceRecapRecordSession($this, $context['user'], $schedule, '2025-07-14', [$ali->id => 'absent', $zaid->id => 'present']);
    attendanceRecapRecordSession($this, $context['user'], $schedule, '2025-07-21', [$ali->id => 'present', $zaid->id => 'present']);

    $recapQuery = '/api/v1/grade-recaps/class?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'subject_book_id' => $context['subjectBook']->id,
    ]);

    $response = $this->actingAs($context['user'])->getJson($recapQuery)->assertOk();
    $rows = $response->json('data.rows');

    $aliAbsensi = collect(collect($rows)->firstWhere('student.id', $ali->id)['factors'])->firstWhere('code', 'absensi');
    $zaidAbsensi = collect(collect($rows)->firstWhere('student.id', $zaid->id)['factors'])->firstWhere('code', 'absensi');

    // Ali: 2 hadir, 1 alpa → 66.67. Zaid: 2 hadir, 1 alpa → 66.67.
    expect($aliAbsensi)->toMatchArray(['score' => 66.67, 'source' => 'absensi', 'is_missing' => false]);
    expect($zaidAbsensi)->toMatchArray(['score' => 66.67, 'source' => 'absensi', 'is_missing' => false]);
});

test('a kitab without a grading template still shows an attendance recap, unlike the grade recap', function () {
    $context = setUpAttendanceRecapContext();
    $untemplatedBook = attendanceRecapCreateSubjectBook($context['school'], 'Kitab Tanpa Template', null);
    $schedule = attendanceRecapCreateSchedule($context['school'], $context['academicYear'], $context['classLevel'], $untemplatedBook, $context['teacher']);
    $ali = attendanceRecapCreateStudent($this, $context['user'], 'Ali');

    attendanceRecapRecordSession($this, $context['user'], $schedule, '2025-07-07', [$ali->id => 'present']);

    $query = attendanceRecapQuery($context, ['subject_book_id' => $untemplatedBook->id]);

    $this->actingAs($context['user'])->getJson($query)->assertOk()
        ->assertJsonPath('data.rows.0.present_count', 1);

    $gradeRecapQuery = '/api/v1/grade-recaps/class?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'subject_book_id' => $untemplatedBook->id,
    ]);
    $this->actingAs($context['user'])->getJson($gradeRecapQuery)
        ->assertUnprocessable()
        ->assertJsonPath('message', StudentGradeService::MESSAGE_BOOK_WITHOUT_TEMPLATE);
});

// ── Rejections ───────────────────────────────────────────────────────────

test('rejects a pair that is not scheduled', function () {
    $context = setUpAttendanceRecapContext();

    $this->actingAs($context['user'])
        ->getJson(attendanceRecapQuery($context, ['semester' => 2]))
        ->assertUnprocessable()
        ->assertJsonPath('message', StudentGradeService::MESSAGE_PAIR_NOT_SCHEDULED);
});

test('rejects a semester without academic semester configuration', function () {
    $context = setUpAttendanceRecapContext();

    $unconfiguredYear = AcademicYear::factory()->create(['school_id' => $context['school']->id]);
    attendanceRecapCreateSchedule($context['school'], $unconfiguredYear, $context['classLevel'], $context['subjectBook'], $context['teacher']);

    $this->actingAs($context['user'])
        ->getJson(attendanceRecapQuery($context, ['academic_year_id' => $unconfiguredYear->id]))
        ->assertUnprocessable()
        ->assertJsonPath('message', StudentGradeService::MESSAGE_SEMESTER_NOT_CONFIGURED);
});

test('requires all query params', function () {
    $context = setUpAttendanceRecapContext();

    $this->actingAs($context['user'])
        ->getJson('/api/v1/attendance-recaps?semester=3')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['academic_year_id', 'semester', 'class_level_id', 'subject_book_id']);
});

// ── Permissions ──────────────────────────────────────────────────────────

test('requires view-attendance permission', function () {
    $context = setUpAttendanceRecapContext();

    $this->actingAs(User::factory()->create())
        ->getJson(attendanceRecapQuery($context))
        ->assertForbidden();
});

test('pengurus_pesantren can view the attendance recap', function () {
    $context = setUpAttendanceRecapContext();

    $this->actingAs($context['pengurus'])->getJson(attendanceRecapQuery($context))->assertOk();
});

// ── Tenancy ──────────────────────────────────────────────────────────────

test('rejects another schools class level and kitab', function () {
    $context = setUpAttendanceRecapContext();

    $otherSchool = School::factory()->create();
    $otherClassLevel = ClassLevel::factory()->create(['school_id' => $otherSchool->id]);
    $otherBook = attendanceRecapCreateSubjectBook($otherSchool, 'Kitab Sekolah Lain', null);

    $this->actingAs($context['user'])
        ->getJson(attendanceRecapQuery($context, ['class_level_id' => $otherClassLevel->id, 'subject_book_id' => $otherBook->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['class_level_id', 'subject_book_id']);
});

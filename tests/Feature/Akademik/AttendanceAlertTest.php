<?php

use App\Models\AcademicSemester;
use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\Student;
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
use Illuminate\Support\Carbon;

/*
 * Alert Pertemuan Bolong (Task 11): a Pertemuan terjadwal whose date has
 * passed without ever being recorded (held or cancelled).
 *
 * Schedules are created while "now" is frozen at the semester start
 * (2025-09-01, a Monday) so their created_at never pushes the enumeration
 * start later than the semester itself. Individual tests then move "now"
 * forward before calling the endpoint. "Today" is frozen at Wednesday
 * 2025-09-10, so "yesterday" is 2025-09-09 and the two Mondays inside
 * [semester start, yesterday] are 2025-09-01 and 2025-09-08.
 */
afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @return array{user: User, pengurus: User, school: School, academicYear: AcademicYear, classLevel: ClassLevel, subjectBook: SubjectBook, teacher: Teacher, schedule: TeachingSchedule}
 */
function setUpAttendanceAlertContext(string $semesterStart = '2025-09-01', string $semesterEnd = '2025-12-31'): array
{
    Carbon::setTestNow($semesterStart.' 08:00:00');

    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    (new ClassLevelSeeder)->run();

    $user = User::factory()->create(['name' => 'Admin Alert']);
    $user->assignRole('super_admin');

    $pengurus = User::factory()->create(['name' => 'Pengurus Alert']);
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
        'start_date' => $semesterStart,
        'end_date' => $semesterEnd,
    ]);

    $classLevel = ClassLevel::where('school_id', $school->id)->where('slug', 'tamhidi')->firstOrFail();
    $subjectBook = SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => SubjectCategory::factory()->create(['school_id' => $school->id])->id,
        'title' => 'Safinatun Najah',
    ]);
    $teacher = Teacher::factory()->create(['school_id' => $school->id, 'full_name' => 'Ustadz Ahmad']);

    $schedule = attendanceAlertCreateSchedule($school, $academicYear, $classLevel, $subjectBook, $teacher, 'monday');

    return compact('user', 'pengurus', 'school', 'academicYear', 'classLevel', 'subjectBook', 'teacher', 'schedule');
}

function attendanceAlertCreateSchedule(
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
        'time_slot_id' => TimeSlot::factory()->create(['school_id' => $school->id, 'label' => 'Ba\'da Subuh'])->id,
        'class_level_ids' => [$classLevel->id],
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
        'is_active' => $isActive,
    ]);
}

function attendanceAlertRecordSession(array $context, User $actingUser, string $sessionDate): void
{
    $student = attendanceAlertCreateStudent($actingUser, $context);

    Carbon::setTestNow($sessionDate.' 10:00:00');

    test()->actingAs($actingUser)->postJson('/api/v1/class-sessions', [
        'teaching_schedule_id' => $context['schedule']->id,
        'session_date' => $sessionDate,
        'attendances' => [
            ['student_id' => $student->id, 'status' => 'present', 'notes' => null],
        ],
    ])->assertCreated();
}

function attendanceAlertCancelSession(array $context, User $actingUser, string $sessionDate): void
{
    Carbon::setTestNow($sessionDate.' 10:00:00');

    test()->actingAs($actingUser)->postJson('/api/v1/class-sessions/cancel', [
        'teaching_schedule_id' => $context['schedule']->id,
        'session_date' => $sessionDate,
        'reason' => 'Libur',
    ])->assertCreated();
}

function attendanceAlertCreateStudent(User $actingUser, array $context): Student
{
    $response = test()->actingAs($actingUser)->postJson('/api/v1/students', [
        'full_name' => 'Santri '.uniqid(),
        'birth_date' => '2012-05-15',
        'gender' => 'L',
        'program' => 'regular',
        'entry_date' => '2025-07-01',
        'class_level' => 'tamhidi',
        'address' => 'Jl. Contoh No. 1',
    ]);
    $response->assertCreated();

    return Student::findOrFail($response->json('data.id'));
}

function attendanceAlertQuery(array $extra = []): string
{
    return '/api/v1/attendance-alerts'.($extra === [] ? '' : '?'.http_build_query($extra));
}

test('two unrecorded Mondays inside the semester up to yesterday are alerted; today is never bolong', function () {
    $context = setUpAttendanceAlertContext();
    // A second, Wednesday schedule for the same teacher: today (09-10) is
    // itself a Wednesday, but must never appear even though unrecorded.
    attendanceAlertCreateSchedule($context['school'], $context['academicYear'], $context['classLevel'], $context['subjectBook'], $context['teacher'], 'wednesday');

    Carbon::setTestNow('2025-09-10 10:00:00');

    $response = test()->actingAs($context['user'])->getJson(attendanceAlertQuery());

    $response->assertOk()
        ->assertJsonPath('data.configured', true)
        ->assertJsonPath('data.academic_year_id', $context['academicYear']->id)
        ->assertJsonPath('data.semester', 1)
        ->assertJsonPath('data.total_missing', 3)
        ->assertJsonCount(1, 'data.teachers');

    $items = collect($response->json('data.teachers.0.items'))->pluck('session_date')->all();
    expect($items)->toBe(['2025-09-01', '2025-09-03', '2025-09-08']);
    expect($items)->not->toContain('2025-09-10');
});

test('a held or cancelled session removes its date from the alert', function () {
    $context = setUpAttendanceAlertContext();
    attendanceAlertRecordSession($context, $context['user'], '2025-09-01');
    attendanceAlertCancelSession($context, $context['user'], '2025-09-08');

    Carbon::setTestNow('2025-09-10 10:00:00');

    test()->actingAs($context['user'])->getJson(attendanceAlertQuery())
        ->assertOk()
        ->assertJsonPath('data.total_missing', 0)
        ->assertJsonCount(0, 'data.teachers');
});

test('a schedule created mid-semester is not expected before it existed', function () {
    $context = setUpAttendanceAlertContext();
    // A second teacher whose schedule is created later, on 2025-09-08.
    $laterTeacher = Teacher::factory()->create(['school_id' => $context['school']->id, 'full_name' => 'Ustadz Budi']);
    Carbon::setTestNow('2025-09-08 09:00:00');
    attendanceAlertCreateSchedule($context['school'], $context['academicYear'], $context['classLevel'], $context['subjectBook'], $laterTeacher, 'monday');

    Carbon::setTestNow('2025-09-10 10:00:00');

    $response = test()->actingAs($context['user'])->getJson(attendanceAlertQuery());
    $response->assertOk();

    $byTeacher = collect($response->json('data.teachers'))->keyBy('teacher.full_name');
    // The original teacher: both 09-01 and 09-08 are missing.
    expect($byTeacher['Ustadz Ahmad']['missing_count'])->toBe(2);
    // The later teacher's schedule only exists from 09-08 onward: only that Monday is missing.
    expect($byTeacher['Ustadz Budi']['missing_count'])->toBe(1);
    expect(collect($byTeacher['Ustadz Budi']['items'])->pluck('session_date')->all())->toBe(['2025-09-08']);
});

test('teachers are sorted by missing_count desc then name, items by date asc', function () {
    $context = setUpAttendanceAlertContext();
    // Ustadz Ahmad (the context teacher) has 2 missing Mondays (09-01, 09-08).
    // Zaid has only 1 missing Monday because his session on 09-01 is recorded.
    $zaid = Teacher::factory()->create(['school_id' => $context['school']->id, 'full_name' => 'Ustadz Zaid']);
    $zaidSchedule = attendanceAlertCreateSchedule($context['school'], $context['academicYear'], $context['classLevel'], $context['subjectBook'], $zaid, 'monday');

    Carbon::setTestNow('2025-09-01 10:00:00');
    $student = attendanceAlertCreateStudent($context['user'], $context);
    test()->actingAs($context['user'])->postJson('/api/v1/class-sessions', [
        'teaching_schedule_id' => $zaidSchedule->id,
        'session_date' => '2025-09-01',
        'attendances' => [['student_id' => $student->id, 'status' => 'present', 'notes' => null]],
    ])->assertCreated();

    Carbon::setTestNow('2025-09-10 10:00:00');

    $response = test()->actingAs($context['user'])->getJson(attendanceAlertQuery());
    $response->assertOk()->assertJsonCount(2, 'data.teachers');

    $teacherNames = collect($response->json('data.teachers'))->pluck('teacher.full_name')->all();
    expect($teacherNames)->toBe(['Ustadz Ahmad', 'Ustadz Zaid']);
    expect($response->json('data.teachers.0.missing_count'))->toBe(2);
    expect($response->json('data.teachers.1.missing_count'))->toBe(1);
    expect(collect($response->json('data.teachers.0.items'))->pluck('session_date')->all())->toBe(['2025-09-01', '2025-09-08']);

    $item = $response->json('data.teachers.0.items.0');
    expect($item['teaching_schedule_id'])->toBe($context['schedule']->id);
    expect(collect($item['class_levels'])->pluck('label')->all())->toBe(['Tamhidi']);
    expect($item['subject_book']['title'])->toBe('Safinatun Najah');
    expect($item['time_slot']['label'])->toBe("Ba'da Subuh");
});

test('a combined schedule raises one alert per date, naming every Kelas', function () {
    $context = setUpAttendanceAlertContext();
    $ibtida = ClassLevel::where('school_id', $context['school']->id)->where('slug', '!=', 'tamhidi')->firstOrFail();
    $context['schedule']->syncClassLevelRows([$context['classLevel']->id, $ibtida->id]);

    Carbon::setTestNow('2025-09-10 10:00:00');

    $response = test()->actingAs($context['user'])->getJson(attendanceAlertQuery());
    $response->assertOk();

    $items = collect($response->json('data.teachers.0.items'))
        ->where('session_date', '2025-09-01');

    expect($items)->toHaveCount(1);
    expect(collect($items->first()['class_levels'])->pluck('label')->all())
        ->toContain('Tamhidi')
        ->toContain($ibtida->label);
});

test('an inactive schedule is excluded from the alert', function () {
    $context = setUpAttendanceAlertContext();
    $context['schedule']->update(['is_active' => false]);

    Carbon::setTestNow('2025-09-10 10:00:00');

    test()->actingAs($context['user'])->getJson(attendanceAlertQuery())
        ->assertOk()
        ->assertJsonPath('data.total_missing', 0)
        ->assertJsonCount(0, 'data.teachers');
});

test('items per teacher are capped at 50 but missing_count stays exact', function () {
    Carbon::setTestNow('2020-01-06 08:00:00'); // a Monday

    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    (new ClassLevelSeeder)->run();

    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $school = School::where('is_active', true)->firstOrFail();

    $academicYear = AcademicYear::factory()->create([
        'school_id' => $school->id,
        'start_date' => '2020-01-01',
        'end_date' => '2026-06-30',
        'is_active' => true,
        'active_semester' => 1,
    ]);
    app(AcademicSemesterService::class)->createSemestersForAcademicYear($academicYear);
    app(AcademicSemesterService::class)->updateSemester($academicYear, 1, [
        'start_date' => '2020-01-01',
        'end_date' => '2026-06-30',
    ]);

    $classLevel = ClassLevel::where('school_id', $school->id)->where('slug', 'tamhidi')->firstOrFail();
    $subjectBook = SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => SubjectCategory::factory()->create(['school_id' => $school->id])->id,
    ]);
    $teacher = Teacher::factory()->create(['school_id' => $school->id]);
    $schedule = attendanceAlertCreateSchedule($school, $academicYear, $classLevel, $subjectBook, $teacher, 'monday');

    $today = '2025-09-10'; // roughly 5.5 years after the schedule started
    Carbon::setTestNow($today.' 10:00:00');
    $yesterday = Carbon::parse($today)->subDay()->toDateString();
    $expectedCount = 0;
    $cursor = Carbon::parse('2020-01-06');
    $end = Carbon::parse($yesterday);
    while ($cursor->lte($end)) {
        $expectedCount++;
        $cursor->addDays(7);
    }
    expect($expectedCount)->toBeGreaterThan(50);

    $response = test()->actingAs($user)->getJson(attendanceAlertQuery());

    $response->assertOk()->assertJsonCount(1, 'data.teachers');
    expect($response->json('data.teachers.0.missing_count'))->toBe($expectedCount);
    expect($response->json('data.total_missing'))->toBe($expectedCount);
    expect(count($response->json('data.teachers.0.items')))->toBe(50);
});

test('no active academic year returns configured false', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    test()->actingAs($user)->getJson(attendanceAlertQuery())
        ->assertOk()
        ->assertJsonPath('data.configured', false)
        ->assertJsonPath('data.reason', 'no_active_academic_year')
        ->assertJsonPath('data.teachers', []);
});

test('a semester without configured dates returns configured false', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $school = School::where('is_active', true)->firstOrFail();

    $academicYear = AcademicYear::factory()->create([
        'school_id' => $school->id,
        'is_active' => true,
        'active_semester' => 1,
    ]);
    app(AcademicSemesterService::class)->createSemestersForAcademicYear($academicYear);

    test()->actingAs($user)->getJson(attendanceAlertQuery())
        ->assertOk()
        ->assertJsonPath('data.configured', false)
        ->assertJsonPath('data.reason', 'semester_dates_missing')
        ->assertJsonPath('data.academic_year_id', $academicYear->id)
        ->assertJsonPath('data.semester', 1);
});

test('academic_year_id and semester may be given explicitly to check a different pair', function () {
    $context = setUpAttendanceAlertContext();
    $secondSemester = AcademicSemester::findByPair($context['academicYear']->id, 2);
    $secondSemester->update(['start_date' => '2026-01-05', 'end_date' => '2026-06-30']);

    Carbon::setTestNow('2026-02-10 10:00:00');

    // Default (active_semester is still 1) still reports semester 1's gaps.
    test()->actingAs($context['user'])->getJson(attendanceAlertQuery())
        ->assertOk()
        ->assertJsonPath('data.semester', 1);

    // Explicitly asking for semester 2 reports that pair instead: it is
    // configured (dates are set) but has no schedules, so nothing is missing.
    test()->actingAs($context['user'])
        ->getJson(attendanceAlertQuery(['academic_year_id' => $context['academicYear']->id, 'semester' => 2]))
        ->assertOk()
        ->assertJsonPath('data.configured', true)
        ->assertJsonPath('data.semester', 2)
        ->assertJsonPath('data.total_missing', 0);
});

test('another schools academic_year_id is rejected, not leaked as configured', function () {
    $context = setUpAttendanceAlertContext();
    Carbon::setTestNow('2025-09-10 10:00:00');

    $otherSchool = School::factory()->create();
    $otherAcademicYear = AcademicYear::factory()->create(['school_id' => $otherSchool->id, 'is_active' => true, 'active_semester' => 1]);

    test()->actingAs($context['user'])
        ->getJson(attendanceAlertQuery(['academic_year_id' => $otherAcademicYear->id, 'semester' => 1]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['academic_year_id']);
});

test('reading the alert requires view-attendance', function () {
    $context = setUpAttendanceAlertContext();
    Carbon::setTestNow('2025-09-10 10:00:00');

    $noPermissionUser = User::factory()->create();
    test()->actingAs($noPermissionUser)->getJson(attendanceAlertQuery())->assertForbidden();

    $viewOnlyUser = User::factory()->create();
    $viewOnlyUser->givePermissionTo('view-attendance');
    test()->actingAs($viewOnlyUser)->getJson(attendanceAlertQuery())->assertOk();
});

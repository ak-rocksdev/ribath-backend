<?php

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\MemorizationLog;
use App\Models\MemorizationTarget;
use App\Models\School;
use App\Models\Student;
use App\Models\SubjectBook;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Akademik\AcademicSemesterService;
use App\Services\Akademik\GradingDefaultsInstaller;
use Database\Seeders\ClassLevelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;
use Database\Seeders\SubjectCategorySeeder;
use Database\Seeders\TahfizhSubjectBookSeeder;
use Illuminate\Support\Carbon;

/**
 * Seeds roles, the active school, its class levels, the grading defaults
 * (2 templates, 10 factors) and the "Tahfizh Al-Qur'an" subject book, and
 * creates an academic year with both semesters configured (Task 4's
 * seeders). One active ustadz is created for the log's teacher_id.
 *
 * @return array{user: User, school: School, academicYear: AcademicYear, classLevel: ClassLevel, teacher: Teacher, tahfizhBook: SubjectBook}
 */
function setUpMemorizationLogContext(): array
{
    Carbon::setTestNow(Carbon::parse('2025-09-15 09:00:00', 'Asia/Jakarta'));

    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    (new ClassLevelSeeder)->run();

    $user = User::factory()->create(['name' => 'Admin Tahfidz']);
    $user->assignRole('super_admin');

    $school = School::where('is_active', true)->firstOrFail();
    app(GradingDefaultsInstaller::class)->installForSchool($school);
    (new SubjectCategorySeeder)->run();
    (new TahfizhSubjectBookSeeder)->run();

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
    $teacher = Teacher::factory()->create([
        'school_id' => $school->id,
        'full_name' => 'Ustadz Hafalan',
        'status' => Teacher::STATUS_ACTIVE,
    ]);
    $tahfizhBook = SubjectBook::where('school_id', $school->id)->where('title', "Tahfizh Al-Qur'an")->firstOrFail();

    return compact('user', 'school', 'academicYear', 'classLevel', 'teacher', 'tahfizhBook');
}

/**
 * Creates a student through the real POST /students endpoint.
 */
function logCreateStudent($testCase, User $user, string $fullName, string $classLevelSlug = 'tamhidi'): Student
{
    $response = $testCase->actingAs($user)->postJson('/api/v1/students', [
        'full_name' => $fullName,
        'birth_date' => '2012-05-15',
        'gender' => 'L',
        'program' => 'regular',
        'entry_date' => '2025-07-01',
        'class_level' => $classLevelSlug,
        'address' => 'Jl. Contoh No. 1',
    ]);

    $response->assertCreated();

    return Student::findOrFail($response->json('data.id'));
}

function logStorePayload(array $context, Student $student, array $overrides = []): array
{
    return array_merge([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'student_id' => $student->id,
        'teacher_id' => $context['teacher']->id,
        'log_date' => '2025-09-10',
        'type' => 'new',
        'juz' => 1,
        'start_page' => 1,
        'end_page' => 10,
        'quality_score' => 85,
        'notes' => null,
    ], $overrides);
}

afterEach(function () {
    Carbon::setTestNow();
});

// ── CRUD ─────────────────────────────────────────────────────────────────

test('a Setoran log can be created from start_page/end_page (pages derived) and listed', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Setoran Satu');

    $response = $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student));

    $response->assertCreated()
        ->assertJsonPath('data.student.id', $student->id)
        ->assertJsonPath('data.teacher.id', $context['teacher']->id)
        ->assertJsonPath('data.type', 'new')
        ->assertJsonPath('data.log_date', '2025-09-10');
    expect($response->json('data.pages'))->toEqual(10.0);

    $listResponse = $this->actingAs($context['user'])->getJson('/api/v1/memorization-logs?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
    ]));

    $listResponse->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.student.full_name', 'Santri Setoran Satu')
        ->assertJsonPath('data.0.student.class_level.slug', 'tamhidi');
});

test('a Murajaah log can be created with an explicit pages value (no start/end)', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Murajaah Satu');

    $response = $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student, [
        'type' => 'review',
        'start_page' => null,
        'end_page' => null,
        'pages' => 5.5,
    ]));

    $response->assertCreated()->assertJsonPath('data.type', 'review');
    expect($response->json('data.pages'))->toEqual(5.5);
});

test('a log does not require the santri to have a Target Hafalan for the semester', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Tanpa Target');

    $response = $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student));

    $response->assertCreated();
});

test('a log can be updated (teacher, pages, quality_score, notes)', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Update');
    $otherTeacher = Teacher::factory()->create(['school_id' => $context['school']->id, 'status' => Teacher::STATUS_ACTIVE]);

    $created = $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student))->assertCreated();
    $logId = $created->json('data.id');

    $response = $this->actingAs($context['user'])->putJson("/api/v1/memorization-logs/{$logId}", [
        'teacher_id' => $otherTeacher->id,
        'pages' => 12.5,
        'quality_score' => 90,
        'notes' => 'Perbaikan makhraj',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.teacher.id', $otherTeacher->id)
        ->assertJsonPath('data.quality_score', 90)
        ->assertJsonPath('data.notes', 'Perbaikan makhraj');
    expect($response->json('data.pages'))->toEqual(12.5);
});

test('deleting a log soft-deletes it and it no longer appears in the list', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Hapus');

    $created = $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student))->assertCreated();
    $logId = $created->json('data.id');

    $this->actingAs($context['user'])->deleteJson("/api/v1/memorization-logs/{$logId}")->assertOk();

    $this->assertSoftDeleted('memorization_logs', ['id' => $logId]);

    $listResponse = $this->actingAs($context['user'])->getJson('/api/v1/memorization-logs?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
    ]));
    $listResponse->assertOk()->assertJsonCount(0, 'data');
});

// ── Validation ───────────────────────────────────────────────────────────

test('creating a log requires either pages or both start_page and end_page', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Tanpa Halaman');

    $response = $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student, [
        'start_page' => null,
        'end_page' => null,
    ]));

    $response->assertStatus(422)->assertJsonValidationErrors(['pages']);
});

test('end_page must be greater than or equal to start_page', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Halaman Terbalik');

    $response = $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student, [
        'start_page' => 10,
        'end_page' => 5,
    ]));

    $response->assertStatus(422)->assertJsonValidationErrors(['end_page']);
});

test('an explicit pages value that is not a multiple of 0.5 is rejected', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Halaman Ganjil');

    $response = $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student, [
        'start_page' => null,
        'end_page' => null,
        'pages' => 3.3,
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['pages'])
        ->assertJsonPath('errors.pages.0', 'Jumlah halaman harus kelipatan 0,5.');
});

test('quality_score must be between 0 and 100', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Nilai Salah');

    $response = $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student, ['quality_score' => 101]));

    $response->assertStatus(422)->assertJsonValidationErrors(['quality_score']);
});

test('type must be new or review', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Jenis Salah');

    $response = $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student, ['type' => 'wrong']));

    $response->assertStatus(422)->assertJsonValidationErrors(['type']);
});

test('teacher_id must be an active teacher of the active school', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Guru Salah');
    $inactiveTeacher = Teacher::factory()->create(['school_id' => $context['school']->id, 'status' => Teacher::STATUS_INACTIVE]);

    $response = $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student, ['teacher_id' => $inactiveTeacher->id]));

    $response->assertStatus(422)->assertJsonValidationErrors(['teacher_id']);
});

test('log_date cannot be in the future (Jakarta business today)', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Tanggal Depan');

    $response = $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student, ['log_date' => '2025-09-20']));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['log_date'])
        ->assertJsonPath('errors.log_date.0', 'Tanggal setoran tidak boleh di masa depan.');
});

test('log_date must be within the semester akademik range', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Tanggal Luar');

    Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00', 'Asia/Jakarta'));

    $response = $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student, ['log_date' => '2026-06-15']));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['log_date'])
        ->assertJsonPath('errors.log_date.0', 'Tanggal setoran di luar rentang semester.');
});

test('the semester akademik must be configured', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Semester Kosong');

    $unconfiguredYear = AcademicYear::factory()->create([
        'school_id' => $context['school']->id,
        'name' => '2024/2025',
        'start_date' => '2024-07-01',
        'end_date' => '2025-06-30',
        'is_active' => false,
    ]);

    $response = $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student, [
        'academic_year_id' => $unconfiguredYear->id,
    ]));

    $response->assertStatus(422)->assertJsonValidationErrors(['semester'])
        ->assertJsonPath('errors.semester.0', 'Semester akademik belum dikonfigurasi.');
});

test('creating a log 422s when the school has no Tahfizh kitab yet', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Tanpa Kitab');

    SubjectBook::where('school_id', $context['school']->id)->where('title', "Tahfizh Al-Qur'an")->delete();

    $response = $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['subject_book_id'])
        ->assertJsonPath('errors.subject_book_id.0', 'Kitab Tahfizh belum tersedia.');
});

// ── Progress ─────────────────────────────────────────────────────────────

test('progress with a target reports achievement_percent capped at 100', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Progres Bertarget');

    MemorizationTarget::create([
        'school_id' => $context['school']->id,
        'student_id' => $student->id,
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'target_pages' => 20,
        'teacher_id' => $context['teacher']->id,
    ]);

    $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student, [
        'start_page' => 1, 'end_page' => 15, 'type' => 'new', 'quality_score' => 80,
    ]))->assertCreated();
    $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student, [
        'start_page' => 16, 'end_page' => 25, 'type' => 'new', 'quality_score' => 90, 'log_date' => '2025-09-11',
    ]))->assertCreated();
    $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student, [
        'start_page' => 1, 'end_page' => 5, 'type' => 'review', 'quality_score' => 70, 'log_date' => '2025-09-12',
    ]))->assertCreated();

    $response = $this->actingAs($context['user'])->getJson("/api/v1/students/{$student->id}/memorization-progress?".http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
    ]));

    $response->assertOk();
    expect($response->json('data.target_pages'))->toEqual(20.0);
    expect($response->json('data.total_new_pages'))->toEqual(25.0);
    expect($response->json('data.achievement_percent'))->toEqual(100.0);
    expect($response->json('data.new_count'))->toBe(2);
    expect($response->json('data.review_count'))->toBe(1);
    expect($response->json('data.average_new_quality'))->toEqual(85.0);
    expect($response->json('data.average_review_quality'))->toEqual(70.0);
    expect($response->json('data.recent_logs'))->toHaveCount(3);
});

test('progress without a target reports NULL achievement_percent, never 0', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Progres Tanpa Target');

    $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student))->assertCreated();

    $response = $this->actingAs($context['user'])->getJson("/api/v1/students/{$student->id}/memorization-progress?".http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
    ]));

    $response->assertOk();
    expect($response->json('data.target_pages'))->toBeNull();
    expect($response->json('data.achievement_percent'))->toBeNull();
});

test('progress with no logs at all reports zero counts and NULL averages', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Progres Kosong');

    $response = $this->actingAs($context['user'])->getJson("/api/v1/students/{$student->id}/memorization-progress?".http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
    ]));

    $response->assertOk();
    expect($response->json('data.total_new_pages'))->toEqual(0.0);
    expect($response->json('data.new_count'))->toBe(0);
    expect($response->json('data.review_count'))->toBe(0);
    expect($response->json('data.average_new_quality'))->toBeNull();
    expect($response->json('data.average_review_quality'))->toBeNull();
    expect($response->json('data.recent_logs'))->toBe([]);
});

test('a soft-deleted log is excluded from progress totals', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Progres Terhapus');

    $created = $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student))->assertCreated();
    $this->actingAs($context['user'])->deleteJson('/api/v1/memorization-logs/'.$created->json('data.id'))->assertOk();

    $response = $this->actingAs($context['user'])->getJson("/api/v1/students/{$student->id}/memorization-progress?".http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
    ]));

    $response->assertOk();
    expect($response->json('data.total_new_pages'))->toEqual(0.0);
    expect($response->json('data.new_count'))->toBe(0);
});

// ── List filters ─────────────────────────────────────────────────────────

test('the list can be filtered by student_id, type and date range', function () {
    $context = setUpMemorizationLogContext();
    $studentA = logCreateStudent($this, $context['user'], 'Ahmad Setoran');
    $studentB = logCreateStudent($this, $context['user'], 'Budi Setoran');

    $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $studentA, ['type' => 'new', 'log_date' => '2025-09-05']))->assertCreated();
    $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $studentA, ['type' => 'review', 'log_date' => '2025-09-10']))->assertCreated();
    $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $studentB, ['type' => 'new', 'log_date' => '2025-09-12']))->assertCreated();

    $byStudent = $this->actingAs($context['user'])->getJson('/api/v1/memorization-logs?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id, 'semester' => 1, 'student_id' => $studentA->id,
    ]));
    $byStudent->assertOk()->assertJsonCount(2, 'data');

    $byType = $this->actingAs($context['user'])->getJson('/api/v1/memorization-logs?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id, 'semester' => 1, 'type' => 'review',
    ]));
    $byType->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.student.full_name', 'Ahmad Setoran');

    $byDateRange = $this->actingAs($context['user'])->getJson('/api/v1/memorization-logs?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id, 'semester' => 1, 'date_from' => '2025-09-11', 'date_to' => '2025-09-30',
    ]));
    $byDateRange->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.student.full_name', 'Budi Setoran');
});

test('the list is ordered by log_date desc then created_at desc', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Urutan');

    $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student, ['log_date' => '2025-09-05']))->assertCreated();
    $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student, ['log_date' => '2025-09-12']))->assertCreated();
    $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student, ['log_date' => '2025-09-08']))->assertCreated();

    $response = $this->actingAs($context['user'])->getJson('/api/v1/memorization-logs?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id, 'semester' => 1,
    ]));

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('log_date')->all())->toBe(['2025-09-12', '2025-09-08', '2025-09-05']);
});

test('listing logs without academic_year_id or semester is rejected', function () {
    $context = setUpMemorizationLogContext();

    $response = $this->actingAs($context['user'])->getJson('/api/v1/memorization-logs');

    $response->assertStatus(422)->assertJsonValidationErrors(['academic_year_id', 'semester']);
});

// ── Permissions ──────────────────────────────────────────────────────────

test('viewing the log list requires view-memorization permission', function () {
    $context = setUpMemorizationLogContext();
    $noPermissionUser = User::factory()->create();

    $response = $this->actingAs($noPermissionUser)->getJson('/api/v1/memorization-logs?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
    ]));

    $response->assertStatus(403);
});

test('creating a log requires manage-memorization permission', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Izin');

    $viewOnlyUser = User::factory()->create();
    $viewOnlyUser->givePermissionTo('view-memorization');

    $response = $this->actingAs($viewOnlyUser)->postJson('/api/v1/memorization-logs', logStorePayload($context, $student));

    $response->assertStatus(403);
});

test('updating a log requires manage-memorization permission', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Izin Update');
    $created = $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student))->assertCreated();

    $viewOnlyUser = User::factory()->create();
    $viewOnlyUser->givePermissionTo('view-memorization');

    $response = $this->actingAs($viewOnlyUser)->putJson('/api/v1/memorization-logs/'.$created->json('data.id'), ['notes' => 'x']);

    $response->assertStatus(403);
});

test('deleting a log requires manage-memorization permission', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Izin Hapus');
    $created = $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student))->assertCreated();

    $viewOnlyUser = User::factory()->create();
    $viewOnlyUser->givePermissionTo('view-memorization');

    $response = $this->actingAs($viewOnlyUser)->deleteJson('/api/v1/memorization-logs/'.$created->json('data.id'));

    $response->assertStatus(403);
});

test('viewing memorization progress requires view-memorization permission', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Progres Izin');
    $noPermissionUser = User::factory()->create();

    $response = $this->actingAs($noPermissionUser)->getJson("/api/v1/students/{$student->id}/memorization-progress?".http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
    ]));

    $response->assertStatus(403);
});

// ── Tenancy ──────────────────────────────────────────────────────────────

test('a log from another school 404s on update', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Tenancy Update');
    $created = $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student))->assertCreated();
    $logId = $created->json('data.id');

    $otherSchool = School::factory()->create(['is_active' => false]);
    $log = MemorizationLog::findOrFail($logId);
    $log->school_id = $otherSchool->id;
    $log->save();

    $this->actingAs($context['user'])->putJson("/api/v1/memorization-logs/{$logId}", ['notes' => 'x'])->assertNotFound();
});

test('a log from another school 404s on delete', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Tenancy Hapus');
    $created = $this->actingAs($context['user'])->postJson('/api/v1/memorization-logs', logStorePayload($context, $student))->assertCreated();
    $logId = $created->json('data.id');

    $otherSchool = School::factory()->create(['is_active' => false]);
    $log = MemorizationLog::findOrFail($logId);
    $log->school_id = $otherSchool->id;
    $log->save();

    $this->actingAs($context['user'])->deleteJson("/api/v1/memorization-logs/{$logId}")->assertNotFound();
});

test('a student from another school 404s on memorization-progress', function () {
    $context = setUpMemorizationLogContext();
    $student = logCreateStudent($this, $context['user'], 'Santri Tenancy Progres');

    $otherSchool = School::factory()->create(['is_active' => false]);
    $student->school_id = $otherSchool->id;
    $student->save();

    $response = $this->actingAs($context['user'])->getJson("/api/v1/students/{$student->id}/memorization-progress?".http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
    ]));

    $response->assertNotFound();
});

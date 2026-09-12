<?php

use App\Models\AcademicSemester;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\User;
use App\Services\Akademik\AcademicSemesterService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;

function createSchoolAndUserForSemesters(): array
{
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $school = School::where('is_active', true)->first();

    return [$user, $school];
}

// ── Auto-creation on academic year creation ─────────────────────────────

test('creating an academic year auto-creates two semesters', function () {
    [$user, $school] = createSchoolAndUserForSemesters();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/academic-years', [
            'name' => '2025/2026',
            'start_date' => '2025-07-01',
            'end_date' => '2026-06-30',
        ]);

    $response->assertCreated();

    $academicYearId = $response->json('data.id');

    expect(AcademicSemester::where('academic_year_id', $academicYearId)->count())->toBe(2);

    $this->assertDatabaseHas('academic_semesters', [
        'academic_year_id' => $academicYearId,
        'semester' => 1,
        'school_id' => $school->id,
        'uts_enabled' => true,
    ]);

    $this->assertDatabaseHas('academic_semesters', [
        'academic_year_id' => $academicYearId,
        'semester' => 2,
        'school_id' => $school->id,
        'uts_enabled' => true,
    ]);
});

// ── Index ────────────────────────────────────────────────────────────────

test('academic years index includes semesters', function () {
    [$user, $school] = createSchoolAndUserForSemesters();

    $academicYear = AcademicYear::factory()->create(['school_id' => $school->id]);
    app(AcademicSemesterService::class)->createSemestersForAcademicYear($academicYear);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/academic-years');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'semesters' => ['*' => ['id', 'semester', 'uts_enabled']]],
            ],
        ]);
});

test('active academic year endpoint includes semesters', function () {
    [$user, $school] = createSchoolAndUserForSemesters();

    $academicYear = AcademicYear::factory()->create(['school_id' => $school->id, 'is_active' => true]);
    app(AcademicSemesterService::class)->createSemestersForAcademicYear($academicYear);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/academic-years/active');

    $response->assertOk()
        ->assertJsonCount(2, 'data.semesters');
});

test('can list semesters for an academic year', function () {
    [$user, $school] = createSchoolAndUserForSemesters();

    $academicYear = AcademicYear::factory()->create(['school_id' => $school->id]);
    app(AcademicSemesterService::class)->createSemestersForAcademicYear($academicYear);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/academic-years/{$academicYear->id}/semesters");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data');
});

test('listing semesters requires permission', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    $school = School::where('is_active', true)->first();
    $user = User::factory()->create();

    $academicYear = AcademicYear::factory()->create(['school_id' => $school->id]);
    app(AcademicSemesterService::class)->createSemestersForAcademicYear($academicYear);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/academic-years/{$academicYear->id}/semesters");

    $response->assertForbidden();
});

test('listing semesters for another schools academic year returns 404', function () {
    [$user] = createSchoolAndUserForSemesters();

    $otherSchool = School::factory()->create();
    $otherAcademicYear = AcademicYear::factory()->create(['school_id' => $otherSchool->id]);
    app(AcademicSemesterService::class)->createSemestersForAcademicYear($otherAcademicYear);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/academic-years/{$otherAcademicYear->id}/semesters");

    $response->assertNotFound();
});

// ── Update ───────────────────────────────────────────────────────────────

test('can update a semester dates and uts flag', function () {
    [$user, $school] = createSchoolAndUserForSemesters();

    $academicYear = AcademicYear::factory()->create(['school_id' => $school->id]);
    app(AcademicSemesterService::class)->createSemestersForAcademicYear($academicYear);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/academic-years/{$academicYear->id}/semesters/1", [
            'start_date' => '2025-07-01',
            'end_date' => '2025-12-20',
            'midterm_exam_date' => '2025-09-15',
            'uts_enabled' => false,
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.semester', 1)
        ->assertJsonPath('data.start_date', '2025-07-01T00:00:00.000000Z')
        ->assertJsonPath('data.end_date', '2025-12-20T00:00:00.000000Z')
        ->assertJsonPath('data.midterm_exam_date', '2025-09-15T00:00:00.000000Z')
        ->assertJsonPath('data.uts_enabled', false);

    $this->assertDatabaseHas('academic_semesters', [
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'uts_enabled' => false,
    ]);
});

test('can partially update a semester and prior dates are preserved for cross-field validation', function () {
    [$user, $school] = createSchoolAndUserForSemesters();

    $academicYear = AcademicYear::factory()->create(['school_id' => $school->id]);
    app(AcademicSemesterService::class)->createSemestersForAcademicYear($academicYear);

    $this->actingAs($user)
        ->putJson("/api/v1/academic-years/{$academicYear->id}/semesters/1", [
            'start_date' => '2025-07-01',
            'end_date' => '2025-12-20',
        ])->assertOk();

    // Sending only a new end_date earlier than the stored start_date must fail.
    $response = $this->actingAs($user)
        ->putJson("/api/v1/academic-years/{$academicYear->id}/semesters/1", [
            'end_date' => '2025-06-01',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['end_date']);
});

test('updating a semester fails when end_date is before start_date', function () {
    [$user, $school] = createSchoolAndUserForSemesters();

    $academicYear = AcademicYear::factory()->create(['school_id' => $school->id]);
    app(AcademicSemesterService::class)->createSemestersForAcademicYear($academicYear);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/academic-years/{$academicYear->id}/semesters/1", [
            'start_date' => '2025-12-20',
            'end_date' => '2025-07-01',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['end_date']);
});

test('updating a semester fails when midterm_exam_date is outside start and end date', function () {
    [$user, $school] = createSchoolAndUserForSemesters();

    $academicYear = AcademicYear::factory()->create(['school_id' => $school->id]);
    app(AcademicSemesterService::class)->createSemestersForAcademicYear($academicYear);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/academic-years/{$academicYear->id}/semesters/1", [
            'start_date' => '2025-07-01',
            'end_date' => '2025-12-20',
            'midterm_exam_date' => '2026-01-05',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['midterm_exam_date']);
});

test('updating a semester allows midterm_exam_date equal to start_date or end_date', function () {
    [$user, $school] = createSchoolAndUserForSemesters();

    $academicYear = AcademicYear::factory()->create(['school_id' => $school->id]);
    app(AcademicSemesterService::class)->createSemestersForAcademicYear($academicYear);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/academic-years/{$academicYear->id}/semesters/1", [
            'start_date' => '2025-07-01',
            'end_date' => '2025-12-20',
            'midterm_exam_date' => '2025-07-01',
        ]);

    $response->assertOk();
});

test('updating a semester fails with invalid uts_enabled value', function () {
    [$user, $school] = createSchoolAndUserForSemesters();

    $academicYear = AcademicYear::factory()->create(['school_id' => $school->id]);
    app(AcademicSemesterService::class)->createSemestersForAcademicYear($academicYear);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/academic-years/{$academicYear->id}/semesters/1", [
            'uts_enabled' => 'not-a-boolean',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['uts_enabled']);
});

test('updating a semester with an invalid semester number returns 404', function () {
    [$user, $school] = createSchoolAndUserForSemesters();

    $academicYear = AcademicYear::factory()->create(['school_id' => $school->id]);
    app(AcademicSemesterService::class)->createSemestersForAcademicYear($academicYear);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/academic-years/{$academicYear->id}/semesters/3", [
            'uts_enabled' => false,
        ]);

    $response->assertNotFound();
});

test('updating a semester requires permission', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    $school = School::where('is_active', true)->first();
    $user = User::factory()->create();

    $academicYear = AcademicYear::factory()->create(['school_id' => $school->id]);
    app(AcademicSemesterService::class)->createSemestersForAcademicYear($academicYear);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/academic-years/{$academicYear->id}/semesters/1", [
            'uts_enabled' => false,
        ]);

    $response->assertForbidden();
});

test('updating a semester for another schools academic year returns 404', function () {
    [$user] = createSchoolAndUserForSemesters();

    $otherSchool = School::factory()->create();
    $otherAcademicYear = AcademicYear::factory()->create(['school_id' => $otherSchool->id]);
    app(AcademicSemesterService::class)->createSemestersForAcademicYear($otherAcademicYear);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/academic-years/{$otherAcademicYear->id}/semesters/1", [
            'uts_enabled' => false,
        ]);

    $response->assertNotFound();
});

// ── Migration backfill ───────────────────────────────────────────────────

test('migration backfills semesters for pre-existing academic years', function () {
    [$user, $school] = createSchoolAndUserForSemesters();

    // Simulate legacy data: an academic year created before this feature
    // existed, so it has no academic_semesters rows.
    $academicYear = AcademicYear::factory()->create(['school_id' => $school->id]);

    expect(AcademicSemester::where('academic_year_id', $academicYear->id)->count())->toBe(0);

    $migration = require base_path('database/migrations/2026_09_13_100000_create_academic_semesters_table.php');
    $migration->backfillExistingAcademicYears();

    expect(AcademicSemester::where('academic_year_id', $academicYear->id)->count())->toBe(2);

    $this->assertDatabaseHas('academic_semesters', [
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
    ]);
    $this->assertDatabaseHas('academic_semesters', [
        'academic_year_id' => $academicYear->id,
        'semester' => 2,
    ]);

    // Running it again must not create duplicates.
    $migration->backfillExistingAcademicYears();

    expect(AcademicSemester::where('academic_year_id', $academicYear->id)->count())->toBe(2);
});

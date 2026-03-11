<?php

use App\Models\AcademicYear;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;

function createSchoolAndUser(): array
{
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $school = School::where('is_active', true)->first();

    return [$user, $school];
}

// ── Index tests ────────────────────────────────────────────────────────

test('unauthenticated user cannot access academic years', function () {
    $response = $this->getJson('/api/v1/academic-years');

    $response->assertUnauthorized();
});

test('user without permission cannot access academic years', function () {
    (new RolePermissionSeeder)->run();

    $user = User::factory()->create();
    // No role assigned = no permission

    $response = $this->actingAs($user)
        ->getJson('/api/v1/academic-years');

    $response->assertForbidden();
});

test('authenticated user with permission can list academic years', function () {
    [$user, $school] = createSchoolAndUser();

    AcademicYear::factory()->count(3)->create(['school_id' => $school->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/academic-years');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(3, 'data');
});

test('academic years are ordered by name descending', function () {
    [$user, $school] = createSchoolAndUser();

    AcademicYear::factory()->create(['school_id' => $school->id, 'name' => '2024/2025']);
    AcademicYear::factory()->create(['school_id' => $school->id, 'name' => '2026/2027']);
    AcademicYear::factory()->create(['school_id' => $school->id, 'name' => '2025/2026']);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/academic-years');

    $response->assertOk();

    $names = array_column($response->json('data'), 'name');
    expect($names)->toBe(['2026/2027', '2025/2026', '2024/2025']);
});

test('academic year response has correct structure', function () {
    [$user, $school] = createSchoolAndUser();

    AcademicYear::factory()->create(['school_id' => $school->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/academic-years');

    $response->assertOk()
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'school_id', 'name', 'start_date', 'end_date', 'active_semester', 'is_active'],
            ],
            'message',
        ]);
});

// ── Show tests ─────────────────────────────────────────────────────────

test('can show a single academic year', function () {
    [$user, $school] = createSchoolAndUser();

    $academicYear = AcademicYear::factory()->create([
        'school_id' => $school->id,
        'name' => '2025/2026',
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/academic-years/{$academicYear->id}");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', '2025/2026');
});

// ── Create tests ───────────────────────────────────────────────────────

test('can create an academic year', function () {
    [$user, $school] = createSchoolAndUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/academic-years', [
            'name' => '2025/2026',
            'start_date' => '2025-07-01',
            'end_date' => '2026-06-30',
            'active_semester' => 1,
        ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', '2025/2026')
        ->assertJsonPath('data.active_semester', 1);

    $this->assertDatabaseHas('academic_years', [
        'name' => '2025/2026',
        'school_id' => $school->id,
        'is_active' => false,
    ]);
});

test('create academic year auto-assigns school_id', function () {
    [$user, $school] = createSchoolAndUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/academic-years', [
            'name' => '2025/2026',
            'start_date' => '2025-07-01',
            'end_date' => '2026-06-30',
        ]);

    $response->assertCreated();

    $this->assertDatabaseHas('academic_years', [
        'name' => '2025/2026',
        'school_id' => $school->id,
    ]);
});

test('create academic year requires permission', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    $user = User::factory()->create();
    // No role assigned

    $response = $this->actingAs($user)
        ->postJson('/api/v1/academic-years', [
            'name' => '2025/2026',
            'start_date' => '2025-07-01',
            'end_date' => '2026-06-30',
        ]);

    $response->assertForbidden();
});

test('create academic year fails with invalid name format', function () {
    [$user] = createSchoolAndUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/academic-years', [
            'name' => '2025-2026',
            'start_date' => '2025-07-01',
            'end_date' => '2026-06-30',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('create academic year fails when end_date is before start_date', function () {
    [$user] = createSchoolAndUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/academic-years', [
            'name' => '2025/2026',
            'start_date' => '2026-06-30',
            'end_date' => '2025-07-01',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['end_date']);
});

test('create academic year fails with missing required fields', function () {
    [$user] = createSchoolAndUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/academic-years', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'start_date', 'end_date']);
});

test('create academic year fails with invalid semester value', function () {
    [$user] = createSchoolAndUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/academic-years', [
            'name' => '2025/2026',
            'start_date' => '2025-07-01',
            'end_date' => '2026-06-30',
            'active_semester' => 3,
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['active_semester']);
});

test('create academic year fails with duplicate name for same school', function () {
    [$user, $school] = createSchoolAndUser();

    AcademicYear::factory()->create([
        'school_id' => $school->id,
        'name' => '2025/2026',
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/academic-years', [
            'name' => '2025/2026',
            'start_date' => '2025-07-01',
            'end_date' => '2026-06-30',
        ]);

    // Should fail due to unique constraint on [school_id, name]
    expect($response->status())->toBeGreaterThanOrEqual(400);
});

// ── Update tests ───────────────────────────────────────────────────────

test('can update an academic year', function () {
    [$user, $school] = createSchoolAndUser();

    $academicYear = AcademicYear::factory()->create([
        'school_id' => $school->id,
        'name' => '2025/2026',
    ]);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/academic-years/{$academicYear->id}", [
            'name' => '2026/2027',
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', '2026/2027');
});

test('can partial update an academic year', function () {
    [$user, $school] = createSchoolAndUser();

    $academicYear = AcademicYear::factory()->create([
        'school_id' => $school->id,
        'name' => '2025/2026',
        'start_date' => '2025-07-01',
        'end_date' => '2026-06-30',
    ]);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/academic-years/{$academicYear->id}", [
            'active_semester' => 2,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.active_semester', 2)
        ->assertJsonPath('data.name', '2025/2026');
});

// ── Delete tests ───────────────────────────────────────────────────────

test('can delete an academic year without dependents', function () {
    [$user, $school] = createSchoolAndUser();

    $academicYear = AcademicYear::factory()->create([
        'school_id' => $school->id,
    ]);

    $response = $this->actingAs($user)
        ->deleteJson("/api/v1/academic-years/{$academicYear->id}");

    $response->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('academic_years', ['id' => $academicYear->id]);
});

// Note: Test for "cannot delete with teaching schedules" is skipped because
// the teaching_schedules table doesn't exist yet (Task 6).

// ── Activate tests ─────────────────────────────────────────────────────

test('can activate an academic year', function () {
    [$user, $school] = createSchoolAndUser();

    $academicYear = AcademicYear::factory()->create([
        'school_id' => $school->id,
        'is_active' => false,
    ]);

    $response = $this->actingAs($user)
        ->patchJson("/api/v1/academic-years/{$academicYear->id}/activate");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.is_active', true);

    $this->assertDatabaseHas('academic_years', [
        'id' => $academicYear->id,
        'is_active' => true,
    ]);
});

test('activating an academic year deactivates all others for the same school', function () {
    [$user, $school] = createSchoolAndUser();

    $firstYear = AcademicYear::factory()->create([
        'school_id' => $school->id,
        'name' => '2024/2025',
        'is_active' => true,
    ]);

    $secondYear = AcademicYear::factory()->create([
        'school_id' => $school->id,
        'name' => '2025/2026',
        'is_active' => false,
    ]);

    $response = $this->actingAs($user)
        ->patchJson("/api/v1/academic-years/{$secondYear->id}/activate");

    $response->assertOk()
        ->assertJsonPath('data.is_active', true);

    // First year should now be deactivated
    $this->assertDatabaseHas('academic_years', [
        'id' => $firstYear->id,
        'is_active' => false,
    ]);

    // Second year should be active
    $this->assertDatabaseHas('academic_years', [
        'id' => $secondYear->id,
        'is_active' => true,
    ]);
});

test('activating does not affect academic years from other schools', function () {
    [$user, $school] = createSchoolAndUser();

    $otherSchool = School::factory()->create();

    $otherSchoolYear = AcademicYear::factory()->create([
        'school_id' => $otherSchool->id,
        'name' => '2024/2025',
        'is_active' => true,
    ]);

    $thisSchoolYear = AcademicYear::factory()->create([
        'school_id' => $school->id,
        'name' => '2025/2026',
        'is_active' => false,
    ]);

    $this->actingAs($user)
        ->patchJson("/api/v1/academic-years/{$thisSchoolYear->id}/activate");

    // Other school's academic year should remain active
    $this->assertDatabaseHas('academic_years', [
        'id' => $otherSchoolYear->id,
        'is_active' => true,
    ]);
});

// ── Switch semester tests ──────────────────────────────────────────────

test('can switch semester', function () {
    [$user, $school] = createSchoolAndUser();

    $academicYear = AcademicYear::factory()->create([
        'school_id' => $school->id,
        'active_semester' => 1,
    ]);

    $response = $this->actingAs($user)
        ->patchJson("/api/v1/academic-years/{$academicYear->id}/semester", [
            'semester' => 2,
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.active_semester', 2);

    $this->assertDatabaseHas('academic_years', [
        'id' => $academicYear->id,
        'active_semester' => 2,
    ]);
});

test('switch semester fails with invalid value', function () {
    [$user, $school] = createSchoolAndUser();

    $academicYear = AcademicYear::factory()->create([
        'school_id' => $school->id,
    ]);

    $response = $this->actingAs($user)
        ->patchJson("/api/v1/academic-years/{$academicYear->id}/semester", [
            'semester' => 3,
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['semester']);
});

test('switch semester requires permission', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    $school = School::where('is_active', true)->first();
    $user = User::factory()->create();
    // No role assigned

    $academicYear = AcademicYear::factory()->create([
        'school_id' => $school->id,
    ]);

    $response = $this->actingAs($user)
        ->patchJson("/api/v1/academic-years/{$academicYear->id}/semester", [
            'semester' => 2,
        ]);

    $response->assertForbidden();
});

<?php

use App\Models\AcademicYear;
use App\Models\Registration;
use App\Models\RegistrationPeriod;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->school = School::factory()->create(['is_active' => true]);
});

function createAuthenticatedUser(string $role = 'super_admin'): User
{
    $seeder = new RolePermissionSeeder;
    $seeder->run();

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * Helper: create an AY tied to the active school. Tests that POST a period
 * call this to get the FK they need, instead of the old hard-coded year string.
 */
function createActiveSchoolAcademicYear(string $name = '2025/2026'): AcademicYear
{
    $school = School::where('is_active', true)->firstOrFail();

    return AcademicYear::factory()->create([
        'school_id' => $school->id,
        'name' => $name,
    ]);
}

// ──────────────────────────────────────────────────────
// Authentication & Authorization
// ──────────────────────────────────────────────────────

test('unauthenticated user cannot access any period endpoint', function () {
    $period = RegistrationPeriod::factory()->forSchool($this->school)->create();

    $this->getJson('/api/v1/psb/periods')->assertUnauthorized();
    $this->postJson('/api/v1/psb/periods')->assertUnauthorized();
    $this->getJson("/api/v1/psb/periods/{$period->id}")->assertUnauthorized();
    $this->putJson("/api/v1/psb/periods/{$period->id}")->assertUnauthorized();
    $this->deleteJson("/api/v1/psb/periods/{$period->id}")->assertUnauthorized();
});

test('user without permission cannot list periods', function () {
    $seeder = new RolePermissionSeeder;
    $seeder->run();

    $userWithoutPermission = User::factory()->create();

    $this->actingAs($userWithoutPermission)
        ->getJson('/api/v1/psb/periods')
        ->assertForbidden();
});

test('user without permission cannot create period', function () {
    $seeder = new RolePermissionSeeder;
    $seeder->run();

    $ay = createActiveSchoolAcademicYear();
    $userWithoutPermission = User::factory()->create();

    $this->actingAs($userWithoutPermission)
        ->postJson('/api/v1/psb/periods', [
            'name' => 'Test Period',
            'academic_year_id' => $ay->id,
            'wave' => 1,
            'registration_open' => '2025-01-01',
            'registration_close' => '2025-03-01',
            'entry_date' => '2025-07-01',
        ])
        ->assertForbidden();
});

test('super admin can access all endpoints bypassing permissions', function () {
    $superAdmin = createAuthenticatedUser('super_admin');
    $ay = createActiveSchoolAcademicYear();

    $this->actingAs($superAdmin)
        ->getJson('/api/v1/psb/periods')
        ->assertOk();

    $response = $this->actingAs($superAdmin)
        ->postJson('/api/v1/psb/periods', [
            'name' => 'Test Period',
            'academic_year_id' => $ay->id,
            'wave' => 1,
            'registration_open' => '2025-01-01',
            'registration_close' => '2025-03-01',
            'entry_date' => '2025-07-01',
        ]);

    $response->assertCreated();

    $periodId = $response->json('data.id');

    $this->actingAs($superAdmin)
        ->getJson("/api/v1/psb/periods/{$periodId}")
        ->assertOk();

    $this->actingAs($superAdmin)
        ->putJson("/api/v1/psb/periods/{$periodId}", [
            'name' => 'Updated Period',
            'academic_year_id' => $ay->id,
            'wave' => 1,
            'registration_open' => '2025-01-01',
            'registration_close' => '2025-03-01',
            'entry_date' => '2025-07-01',
        ])
        ->assertOk();

    $this->actingAs($superAdmin)
        ->deleteJson("/api/v1/psb/periods/{$periodId}")
        ->assertOk();
});

test('user with view-registration-periods can list and show periods', function () {
    $seeder = new RolePermissionSeeder;
    $seeder->run();

    $user = User::factory()->create();
    $user->givePermissionTo('view-registration-periods');

    $period = RegistrationPeriod::factory()->forSchool($this->school)->create();

    $this->actingAs($user)
        ->getJson('/api/v1/psb/periods')
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($user)
        ->getJson("/api/v1/psb/periods/{$period->id}")
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('user with manage-registration-periods can create update and delete', function () {
    $seeder = new RolePermissionSeeder;
    $seeder->run();

    $ay = createActiveSchoolAcademicYear();
    $user = User::factory()->create();
    $user->givePermissionTo('manage-registration-periods');

    $response = $this->actingAs($user)
        ->postJson('/api/v1/psb/periods', [
            'name' => 'Test Period',
            'academic_year_id' => $ay->id,
            'wave' => 1,
            'registration_open' => '2025-01-01',
            'registration_close' => '2025-03-01',
            'entry_date' => '2025-07-01',
        ]);

    $response->assertCreated();

    $periodId = $response->json('data.id');

    $this->actingAs($user)
        ->putJson("/api/v1/psb/periods/{$periodId}", [
            'name' => 'Updated Period',
            'academic_year_id' => $ay->id,
            'wave' => 1,
            'registration_open' => '2025-01-01',
            'registration_close' => '2025-03-01',
            'entry_date' => '2025-07-01',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->deleteJson("/api/v1/psb/periods/{$periodId}")
        ->assertOk();
});

// ──────────────────────────────────────────────────────
// CRUD Operations
// ──────────────────────────────────────────────────────

test('can list periods with pagination', function () {
    $user = createAuthenticatedUser('super_admin');

    RegistrationPeriod::factory()->forSchool($this->school)->count(20)->create();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/psb/periods');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            'message',
        ])
        ->assertJsonPath('meta.total', 20)
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('meta.last_page', 2);

    expect(count($response->json('data')))->toBe(15);
});

test('can create a period with valid data', function () {
    $user = createAuthenticatedUser('super_admin');
    $ay = createActiveSchoolAcademicYear();

    $periodData = [
        'name' => 'Pendaftaran 2025/2026',
        'academic_year_id' => $ay->id,
        'wave' => 1,
        'registration_open' => '2025-01-15 08:00:00',
        'registration_close' => '2025-03-15 23:59:59',
        'entry_date' => '2025-07-01',
        'student_quota' => 50,
        'description' => 'First wave registration for academic year 2025/2026',
        'is_active' => true,
    ];

    $response = $this->actingAs($user)
        ->postJson('/api/v1/psb/periods', $periodData);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Registration period created')
        ->assertJsonPath('data.name', 'Pendaftaran 2025/2026')
        ->assertJsonPath('data.academic_year_id', $ay->id)
        ->assertJsonPath('data.wave', 1)
        ->assertJsonPath('data.student_quota', 50);

    $this->assertDatabaseHas('registration_periods', [
        'name' => 'Pendaftaran 2025/2026',
        'academic_year_id' => $ay->id,
        'wave' => 1,
    ]);
});

test('create validates required fields', function () {
    $user = createAuthenticatedUser('super_admin');

    $response = $this->actingAs($user)
        ->postJson('/api/v1/psb/periods', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'name',
            'academic_year_id',
            'wave',
            'registration_open',
            'registration_close',
            'entry_date',
        ]);
});

test('create rejects academic_year_id from another school (no cross-tenant leak)', function () {
    $user = createAuthenticatedUser('super_admin');
    $otherSchool = School::factory()->create();
    $otherAY = AcademicYear::factory()->create(['school_id' => $otherSchool->id]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/psb/periods', [
            'name' => 'Test Period',
            'academic_year_id' => $otherAY->id,
            'wave' => 1,
            'registration_open' => '2025-01-01',
            'registration_close' => '2025-03-01',
            'entry_date' => '2025-07-01',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['academic_year_id']);
});

test('create validates date ordering', function () {
    $user = createAuthenticatedUser('super_admin');
    $ay = createActiveSchoolAcademicYear();

    // registration_close must be after registration_open
    $response = $this->actingAs($user)
        ->postJson('/api/v1/psb/periods', [
            'name' => 'Test Period',
            'academic_year_id' => $ay->id,
            'wave' => 1,
            'registration_open' => '2025-03-01',
            'registration_close' => '2025-01-01',
            'entry_date' => '2025-07-01',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['registration_close']);

    // entry_date must be after registration_close
    $response = $this->actingAs($user)
        ->postJson('/api/v1/psb/periods', [
            'name' => 'Test Period',
            'academic_year_id' => $ay->id,
            'wave' => 1,
            'registration_open' => '2025-01-01',
            'registration_close' => '2025-03-01',
            'entry_date' => '2025-02-01',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['entry_date']);
});

test('can show a single period', function () {
    $user = createAuthenticatedUser('super_admin');
    $period = RegistrationPeriod::factory()->forSchool($this->school)->create([
        'name' => 'Pendaftaran Gelombang 1',
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/psb/periods/{$period->id}");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Registration period retrieved')
        ->assertJsonPath('data.name', 'Pendaftaran Gelombang 1')
        ->assertJsonPath('data.id', $period->id);
});

test('show includes registrations count', function () {
    $user = createAuthenticatedUser('super_admin');
    $period = RegistrationPeriod::factory()->forSchool($this->school)->create();

    Registration::factory()->count(5)->create([
        'registration_period_id' => $period->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/psb/periods/{$period->id}");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.registrations_count', 5);
});

test('can update a period', function () {
    $user = createAuthenticatedUser('super_admin');
    $ay = createActiveSchoolAcademicYear();
    $period = RegistrationPeriod::factory()->forSchool($this->school)->create([
        'name' => 'Original Name',
    ]);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/psb/periods/{$period->id}", [
            'name' => 'Updated Name',
            'academic_year_id' => $ay->id,
            'wave' => 2,
            'registration_open' => '2025-04-01',
            'registration_close' => '2025-06-01',
            'entry_date' => '2025-08-01',
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Registration period updated')
        ->assertJsonPath('data.name', 'Updated Name')
        ->assertJsonPath('data.wave', 2);

    $this->assertDatabaseHas('registration_periods', [
        'id' => $period->id,
        'name' => 'Updated Name',
        'wave' => 2,
    ]);
});

test('can delete a period with no registrations', function () {
    $user = createAuthenticatedUser('super_admin');
    $period = RegistrationPeriod::factory()->forSchool($this->school)->create();

    $response = $this->actingAs($user)
        ->deleteJson("/api/v1/psb/periods/{$period->id}");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Registration period deleted');

    $this->assertDatabaseMissing('registration_periods', [
        'id' => $period->id,
    ]);
});

test('cannot delete a period that has registrations', function () {
    $user = createAuthenticatedUser('super_admin');
    $period = RegistrationPeriod::factory()->forSchool($this->school)->create();

    Registration::factory()->create([
        'registration_period_id' => $period->id,
    ]);

    $response = $this->actingAs($user)
        ->deleteJson("/api/v1/psb/periods/{$period->id}");

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Cannot delete period with existing registrations');

    $this->assertDatabaseHas('registration_periods', [
        'id' => $period->id,
    ]);
});

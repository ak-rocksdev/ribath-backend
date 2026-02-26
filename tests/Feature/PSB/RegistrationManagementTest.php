<?php

use App\Models\Registration;
use App\Models\RegistrationPeriod;
use App\Models\User;
use Database\Seeders\ClassLevelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;
use Spatie\Permission\Models\Role;

function createAdminUser(): User
{
    (new RolePermissionSeeder)->run();
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    return $user;
}

function createUserWithPermissions(array $permissions): User
{
    (new RolePermissionSeeder)->run();
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'pengurus_pesantren']);
    $user->assignRole($role);

    return $user;
}

function createUserWithoutPermissions(): User
{
    (new RolePermissionSeeder)->run();
    $user = User::factory()->create();

    return $user;
}

function seedClassLevels(): void
{
    (new SchoolSeeder)->run();
    (new ClassLevelSeeder)->run();
}

// -------------------------------------------------------
// Authentication & Authorization
// -------------------------------------------------------

test('unauthenticated user cannot access registration list', function () {
    $response = $this->getJson('/api/v1/psb/registrations');

    $response->assertUnauthorized();
});

test('unauthenticated user cannot access registration show', function () {
    $registration = Registration::factory()->create();

    $response = $this->getJson("/api/v1/psb/registrations/{$registration->id}");

    $response->assertUnauthorized();
});

test('unauthenticated user cannot access registration stats', function () {
    $response = $this->getJson('/api/v1/psb/registrations/stats');

    $response->assertUnauthorized();
});

test('user without permission gets 403 on registration list', function () {
    $user = createUserWithoutPermissions();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/psb/registrations');

    $response->assertForbidden();
});

test('user without permission gets 403 on manage actions', function () {
    $user = createUserWithoutPermissions();
    $registration = Registration::factory()->create();

    $response = $this->actingAs($user)
        ->patchJson("/api/v1/psb/registrations/{$registration->id}/status", [
            'status' => 'contacted',
        ]);

    $response->assertForbidden();
});

test('super_admin can access all registration endpoints', function () {
    $admin = createAdminUser();
    $registration = Registration::factory()->create();

    $listResponse = $this->actingAs($admin)
        ->getJson('/api/v1/psb/registrations');
    $listResponse->assertOk();

    $statsResponse = $this->actingAs($admin)
        ->getJson('/api/v1/psb/registrations/stats');
    $statsResponse->assertOk();

    $showResponse = $this->actingAs($admin)
        ->getJson("/api/v1/psb/registrations/{$registration->id}");
    $showResponse->assertOk();

    $statusResponse = $this->actingAs($admin)
        ->patchJson("/api/v1/psb/registrations/{$registration->id}/status", [
            'status' => 'contacted',
        ]);
    $statusResponse->assertOk();
});

// -------------------------------------------------------
// List & Filter
// -------------------------------------------------------

test('can list registrations with pagination', function () {
    $admin = createAdminUser();
    Registration::factory()->count(20)->create();

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/psb/registrations');

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
        ->assertJsonCount(15, 'data');
});

test('can filter registrations by status', function () {
    $admin = createAdminUser();
    Registration::factory()->count(3)->create(['status' => Registration::STATUS_NEW]);
    Registration::factory()->count(2)->create(['status' => Registration::STATUS_CONTACTED]);

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/psb/registrations?status=contacted');

    $response->assertOk()
        ->assertJsonPath('meta.total', 2);

    $allData = $response->json('data');
    foreach ($allData as $item) {
        expect($item['status'])->toBe('contacted');
    }
});

test('can filter registrations by registration_period_id', function () {
    $admin = createAdminUser();
    $periodOne = RegistrationPeriod::factory()->create();
    $periodTwo = RegistrationPeriod::factory()->create();

    Registration::factory()->count(4)->create(['registration_period_id' => $periodOne->id]);
    Registration::factory()->count(2)->create(['registration_period_id' => $periodTwo->id]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/psb/registrations?registration_period_id={$periodOne->id}");

    $response->assertOk()
        ->assertJsonPath('meta.total', 4);
});

test('can search registrations by full_name', function () {
    $admin = createAdminUser();
    Registration::factory()->create(['full_name' => 'Ahmad Fauzan']);
    Registration::factory()->create(['full_name' => 'Budi Santoso']);
    Registration::factory()->create(['full_name' => 'Ahmad Rizki']);

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/psb/registrations?search=Ahmad');

    $response->assertOk()
        ->assertJsonPath('meta.total', 2);
});

test('can search registrations by registration_number', function () {
    $admin = createAdminUser();
    $registration = Registration::factory()->create([
        'registration_number' => 'PSB-2026-00099',
    ]);
    Registration::factory()->create([
        'registration_number' => 'PSB-2026-00100',
    ]);

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/psb/registrations?search=00099');

    $response->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.registration_number', 'PSB-2026-00099');
});

// -------------------------------------------------------
// Stats
// -------------------------------------------------------

test('stats returns count per status', function () {
    $admin = createAdminUser();
    Registration::factory()->count(3)->create(['status' => Registration::STATUS_NEW]);
    Registration::factory()->count(2)->create(['status' => Registration::STATUS_CONTACTED]);
    Registration::factory()->count(1)->create(['status' => Registration::STATUS_ACCEPTED]);

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/psb/registrations/stats');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.total', 6)
        ->assertJsonPath('data.new', 3)
        ->assertJsonPath('data.contacted', 2)
        ->assertJsonPath('data.accepted', 1)
        ->assertJsonPath('data.rejected', 0)
        ->assertJsonPath('data.interview', 0)
        ->assertJsonPath('data.waitlist', 0)
        ->assertJsonPath('data.cancelled', 0);
});

test('stats can filter by registration_period_id', function () {
    $admin = createAdminUser();
    $periodOne = RegistrationPeriod::factory()->create();
    $periodTwo = RegistrationPeriod::factory()->create();

    Registration::factory()->count(5)->create([
        'registration_period_id' => $periodOne->id,
        'status' => Registration::STATUS_NEW,
    ]);
    Registration::factory()->count(3)->create([
        'registration_period_id' => $periodTwo->id,
        'status' => Registration::STATUS_NEW,
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/psb/registrations/stats?registration_period_id={$periodOne->id}");

    $response->assertOk()
        ->assertJsonPath('data.total', 5)
        ->assertJsonPath('data.new', 5);
});

// -------------------------------------------------------
// Show
// -------------------------------------------------------

test('can show single registration with relationships loaded', function () {
    $admin = createAdminUser();
    $period = RegistrationPeriod::factory()->create();
    $registration = Registration::factory()->create([
        'registration_period_id' => $period->id,
    ]);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/psb/registrations/{$registration->id}");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $registration->id)
        ->assertJsonPath('data.full_name', $registration->full_name)
        ->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'registration_period_id',
                'registration_number',
                'status',
                'full_name',
                'period',
            ],
            'message',
        ]);
});

// -------------------------------------------------------
// Status Updates
// -------------------------------------------------------

test('can update status to contacted and sets contacted_at and contacted_by', function () {
    $admin = createAdminUser();
    $registration = Registration::factory()->create(['status' => Registration::STATUS_NEW]);

    $response = $this->actingAs($admin)
        ->patchJson("/api/v1/psb/registrations/{$registration->id}/status", [
            'status' => 'contacted',
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'contacted');

    $registration->refresh();
    expect($registration->status)->toBe('contacted')
        ->and($registration->contacted_at)->not->toBeNull()
        ->and($registration->contacted_by)->toBe($admin->id);
});

test('can update status to interview', function () {
    $admin = createAdminUser();
    $registration = Registration::factory()->create(['status' => Registration::STATUS_CONTACTED]);

    $response = $this->actingAs($admin)
        ->patchJson("/api/v1/psb/registrations/{$registration->id}/status", [
            'status' => 'interview',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'interview');
});

test('can update status to visited and sets visited_at', function () {
    $admin = createAdminUser();
    $registration = Registration::factory()->create(['status' => Registration::STATUS_INTERVIEW]);

    $response = $this->actingAs($admin)
        ->patchJson("/api/v1/psb/registrations/{$registration->id}/status", [
            'status' => 'visited',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'visited');

    $registration->refresh();
    expect($registration->visited_at)->not()->toBeNull();
});

test('can update status to waitlist', function () {
    $admin = createAdminUser();
    $registration = Registration::factory()->create(['status' => Registration::STATUS_NEW]);

    $response = $this->actingAs($admin)
        ->patchJson("/api/v1/psb/registrations/{$registration->id}/status", [
            'status' => 'waitlist',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'waitlist');
});

test('can update status to cancelled', function () {
    $admin = createAdminUser();
    $registration = Registration::factory()->create(['status' => Registration::STATUS_NEW]);

    $response = $this->actingAs($admin)
        ->patchJson("/api/v1/psb/registrations/{$registration->id}/status", [
            'status' => 'cancelled',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

test('can update status with admin_notes', function () {
    $admin = createAdminUser();
    $registration = Registration::factory()->create(['status' => Registration::STATUS_NEW]);

    $response = $this->actingAs($admin)
        ->patchJson("/api/v1/psb/registrations/{$registration->id}/status", [
            'status' => 'contacted',
            'admin_notes' => 'Called the guardian, will visit next week.',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'contacted')
        ->assertJsonPath('data.admin_notes', 'Called the guardian, will visit next week.');
});

test('cannot update to invalid status via status endpoint', function () {
    $admin = createAdminUser();
    $registration = Registration::factory()->create(['status' => Registration::STATUS_NEW]);

    $response = $this->actingAs($admin)
        ->patchJson("/api/v1/psb/registrations/{$registration->id}/status", [
            'status' => 'accepted',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

// -------------------------------------------------------
// Accept
// -------------------------------------------------------

test('accept creates student record with data from registration', function () {
    $admin = createAdminUser();
    seedClassLevels();
    $period = RegistrationPeriod::factory()->create();
    $registration = Registration::factory()->create([
        'registration_period_id' => $period->id,
        'status' => Registration::STATUS_INTERVIEW,
        'registrant_type' => 'guardian',
        'guardian_name' => 'Bapak Ahmad',
        'guardian_phone' => '081234567890',
        'guardian_email' => 'ahmad@test.com',
    ]);

    $response = $this->actingAs($admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/accept", [
            'class_level' => 'tamhidi',
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Registration accepted successfully');

    $this->assertDatabaseHas('students', [
        'registration_id' => $registration->id,
        'full_name' => $registration->full_name,
        'birth_place' => $registration->birth_place,
        'gender' => $registration->gender,
        'program' => $registration->preferred_program,
        'class_level' => 'tamhidi',
        'status' => 'active',
    ]);
});

test('accept creates guardian user when guardian_name is present', function () {
    $admin = createAdminUser();
    seedClassLevels();
    $period = RegistrationPeriod::factory()->create();
    $registration = Registration::factory()->create([
        'registration_period_id' => $period->id,
        'status' => Registration::STATUS_INTERVIEW,
        'registrant_type' => 'guardian',
        'guardian_name' => 'Bapak Umar',
        'guardian_phone' => '081299998888',
        'guardian_email' => 'umar@test.com',
    ]);

    $response = $this->actingAs($admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/accept", [
            'class_level' => 'tamhidi',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.guardian_user.name', 'Bapak Umar')
        ->assertJsonPath('data.guardian_user.email', 'umar@test.com')
        ->assertJsonStructure([
            'data' => [
                'student',
                'registration',
                'guardian_user' => ['id', 'name', 'email', 'temporary_password'],
            ],
        ]);

    $this->assertDatabaseHas('users', [
        'name' => 'Bapak Umar',
        'email' => 'umar@test.com',
    ]);

    $guardianUser = User::where('email', 'umar@test.com')->first();
    expect($guardianUser->hasRole('wali_santri'))->toBeTrue();
});

test('accept does not create guardian user for self-registered student', function () {
    $admin = createAdminUser();
    seedClassLevels();
    $period = RegistrationPeriod::factory()->create();
    $registration = Registration::factory()->selfRegistered()->create([
        'registration_period_id' => $period->id,
        'status' => Registration::STATUS_INTERVIEW,
    ]);

    $initialUserCount = User::count();

    $response = $this->actingAs($admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/accept", [
            'class_level' => 'tamhidi',
        ]);

    $response->assertOk();

    // Only the admin user and the seeder user exist — no new guardian user
    expect(User::count())->toBe($initialUserCount);

    // Response should NOT contain guardian_user key
    $responseData = $response->json('data');
    expect($responseData)->not->toHaveKey('guardian_user');
});

test('accept updates registration status to accepted with reviewed_at and reviewed_by', function () {
    $admin = createAdminUser();
    seedClassLevels();
    $period = RegistrationPeriod::factory()->create();
    $registration = Registration::factory()->create([
        'registration_period_id' => $period->id,
        'status' => Registration::STATUS_INTERVIEW,
        'registrant_type' => 'guardian',
        'guardian_name' => 'Bapak Test',
    ]);

    $this->actingAs($admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/accept", [
            'class_level' => 'tamhidi',
        ]);

    $registration->refresh();
    expect($registration->status)->toBe(Registration::STATUS_ACCEPTED)
        ->and($registration->reviewed_at)->not->toBeNull()
        ->and($registration->reviewed_by)->toBe($admin->id);
});

test('accept increments period enrolled_count', function () {
    $admin = createAdminUser();
    seedClassLevels();
    $period = RegistrationPeriod::factory()->create(['enrolled_count' => 5]);
    $registration = Registration::factory()->create([
        'registration_period_id' => $period->id,
        'status' => Registration::STATUS_INTERVIEW,
        'registrant_type' => 'guardian',
        'guardian_name' => 'Bapak Enrolled',
    ]);

    $this->actingAs($admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/accept", [
            'class_level' => 'tamhidi',
        ]);

    $period->refresh();
    expect($period->enrolled_count)->toBe(6);
});

test('accept returns student data and guardian credentials in response', function () {
    $admin = createAdminUser();
    seedClassLevels();
    $period = RegistrationPeriod::factory()->create();
    $registration = Registration::factory()->create([
        'registration_period_id' => $period->id,
        'status' => Registration::STATUS_INTERVIEW,
        'registrant_type' => 'guardian',
        'guardian_name' => 'Bapak Credentials',
        'guardian_phone' => '081212341234',
        'guardian_email' => 'credentials@test.com',
    ]);

    $response = $this->actingAs($admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/accept", [
            'class_level' => 'tamhidi',
        ]);

    $response->assertOk()
        ->assertJsonStructure([
            'success',
            'data' => [
                'student' => ['id', 'registration_id', 'full_name', 'program', 'status'],
                'registration' => ['id', 'status'],
                'guardian_user' => ['id', 'name', 'email', 'temporary_password'],
            ],
            'message',
        ]);

    $temporaryPassword = $response->json('data.guardian_user.temporary_password');
    expect($temporaryPassword)->toBeString()->not->toBeEmpty();
});

test('cannot accept already accepted registration', function () {
    $admin = createAdminUser();
    seedClassLevels();
    $period = RegistrationPeriod::factory()->create();
    $registration = Registration::factory()->create([
        'registration_period_id' => $period->id,
        'status' => Registration::STATUS_ACCEPTED,
    ]);

    $response = $this->actingAs($admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/accept", [
            'class_level' => 'tamhidi',
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Registration is already accepted');
});

test('accept requires class_level field', function () {
    $admin = createAdminUser();
    seedClassLevels();
    $period = RegistrationPeriod::factory()->create();
    $registration = Registration::factory()->create([
        'registration_period_id' => $period->id,
        'status' => Registration::STATUS_INTERVIEW,
    ]);

    $response = $this->actingAs($admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/accept", []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['class_level']);
});

test('accept rejects invalid class_level value', function () {
    $admin = createAdminUser();
    seedClassLevels();
    $period = RegistrationPeriod::factory()->create();
    $registration = Registration::factory()->create([
        'registration_period_id' => $period->id,
        'status' => Registration::STATUS_INTERVIEW,
    ]);

    $response = $this->actingAs($admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/accept", [
            'class_level' => 'nonexistent_class',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['class_level']);
});

// -------------------------------------------------------
// Reject
// -------------------------------------------------------

test('reject updates status to rejected with rejection reason', function () {
    $admin = createAdminUser();
    $registration = Registration::factory()->create(['status' => Registration::STATUS_INTERVIEW]);

    $response = $this->actingAs($admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/reject", [
            'rejection_reason' => 'Does not meet age requirement for this program.',
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.rejection_reason', 'Does not meet age requirement for this program.');

    $registration->refresh();
    expect($registration->status)->toBe(Registration::STATUS_REJECTED)
        ->and($registration->reviewed_at)->not->toBeNull()
        ->and($registration->reviewed_by)->toBe($admin->id)
        ->and($registration->rejection_reason)->toBe('Does not meet age requirement for this program.');
});

test('reject requires rejection_reason', function () {
    $admin = createAdminUser();
    $registration = Registration::factory()->create(['status' => Registration::STATUS_INTERVIEW]);

    $response = $this->actingAs($admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/reject", []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['rejection_reason']);
});

test('reject requires rejection_reason to be at least 5 characters', function () {
    $admin = createAdminUser();
    $registration = Registration::factory()->create(['status' => Registration::STATUS_INTERVIEW]);

    $response = $this->actingAs($admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/reject", [
            'rejection_reason' => 'No',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['rejection_reason']);
});

test('cannot reject already rejected registration', function () {
    $admin = createAdminUser();
    $registration = Registration::factory()->create(['status' => Registration::STATUS_REJECTED]);

    $response = $this->actingAs($admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/reject", [
            'rejection_reason' => 'Already rejected but trying again.',
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Registration is already rejected');
});

// -------------------------------------------------------
// Delete
// -------------------------------------------------------

test('can soft delete a registration', function () {
    $admin = createAdminUser();
    $registration = Registration::factory()->create();

    $response = $this->actingAs($admin)
        ->deleteJson("/api/v1/psb/registrations/{$registration->id}");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Registration deleted');

    $this->assertSoftDeleted('registrations', ['id' => $registration->id]);
});

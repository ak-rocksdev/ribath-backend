<?php

use App\Models\Registration;
use App\Models\RegistrationPeriod;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
});

// -------------------------------------------------------
// Auth & Permission Checks
// -------------------------------------------------------

test('unauthenticated user cannot archive a registration', function () {
    $registration = Registration::factory()->create([
        'status' => Registration::STATUS_REJECTED,
    ]);

    $this->patchJson("/api/v1/psb/registrations/{$registration->id}/archive")
        ->assertUnauthorized();
});

test('user without permission cannot archive a registration', function () {
    $user = User::factory()->create();
    $registration = Registration::factory()->create([
        'status' => Registration::STATUS_REJECTED,
    ]);

    $this->actingAs($user)
        ->patchJson("/api/v1/psb/registrations/{$registration->id}/archive")
        ->assertForbidden();
});

test('user without permission cannot unarchive a registration', function () {
    $user = User::factory()->create();
    $registration = Registration::factory()->archived()->create();

    $this->actingAs($user)
        ->patchJson("/api/v1/psb/registrations/{$registration->id}/unarchive")
        ->assertForbidden();
});

// -------------------------------------------------------
// Archive
// -------------------------------------------------------

test('can archive rejected registration', function () {
    $registration = Registration::factory()->create([
        'status' => Registration::STATUS_REJECTED,
    ]);

    $response = $this->actingAs($this->admin)
        ->patchJson("/api/v1/psb/registrations/{$registration->id}/archive");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Registration archived')
        ->assertJsonPath('data.is_archived', true);

    $registration->refresh();
    expect($registration->is_archived)->toBeTrue();
});

test('can archive cancelled registration', function () {
    $registration = Registration::factory()->create([
        'status' => Registration::STATUS_CANCELLED,
    ]);

    $response = $this->actingAs($this->admin)
        ->patchJson("/api/v1/psb/registrations/{$registration->id}/archive");

    $response->assertOk()
        ->assertJsonPath('data.is_archived', true);
});

test('cannot archive new registration', function () {
    $registration = Registration::factory()->create([
        'status' => Registration::STATUS_NEW,
    ]);

    $response = $this->actingAs($this->admin)
        ->patchJson("/api/v1/psb/registrations/{$registration->id}/archive");

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Only rejected or cancelled registrations can be archived');
});

test('cannot archive contacted registration', function () {
    $registration = Registration::factory()->create([
        'status' => Registration::STATUS_CONTACTED,
    ]);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/psb/registrations/{$registration->id}/archive")
        ->assertStatus(422);
});

test('cannot archive interview registration', function () {
    $registration = Registration::factory()->create([
        'status' => Registration::STATUS_INTERVIEW,
    ]);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/psb/registrations/{$registration->id}/archive")
        ->assertStatus(422);
});

test('cannot archive visited registration', function () {
    $registration = Registration::factory()->create([
        'status' => Registration::STATUS_VISITED,
    ]);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/psb/registrations/{$registration->id}/archive")
        ->assertStatus(422);
});

test('cannot archive accepted registration', function () {
    $registration = Registration::factory()->create([
        'status' => Registration::STATUS_ACCEPTED,
    ]);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/psb/registrations/{$registration->id}/archive")
        ->assertStatus(422);
});

test('cannot archive already-archived registration', function () {
    $registration = Registration::factory()->archived()->create();

    $response = $this->actingAs($this->admin)
        ->patchJson("/api/v1/psb/registrations/{$registration->id}/archive");

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Registration is already archived');
});

// -------------------------------------------------------
// Unarchive
// -------------------------------------------------------

test('can unarchive an archived registration', function () {
    $registration = Registration::factory()->archived()->create();

    $response = $this->actingAs($this->admin)
        ->patchJson("/api/v1/psb/registrations/{$registration->id}/unarchive");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Registration unarchived')
        ->assertJsonPath('data.is_archived', false);

    $registration->refresh();
    expect($registration->is_archived)->toBeFalse();
});

test('cannot unarchive non-archived registration', function () {
    $registration = Registration::factory()->create([
        'status' => Registration::STATUS_REJECTED,
    ]);

    $response = $this->actingAs($this->admin)
        ->patchJson("/api/v1/psb/registrations/{$registration->id}/unarchive");

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Registration is not archived');
});

// -------------------------------------------------------
// List Filtering
// -------------------------------------------------------

test('list excludes archived registrations by default', function () {
    Registration::factory()->count(3)->create(['status' => Registration::STATUS_NEW]);
    Registration::factory()->archived()->count(2)->create();

    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/psb/registrations');

    $response->assertOk()
        ->assertJsonPath('meta.total', 3);
});

test('list includes archived with include_archived=true', function () {
    Registration::factory()->count(3)->create(['status' => Registration::STATUS_NEW]);
    Registration::factory()->archived()->count(2)->create();

    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/psb/registrations?include_archived=true');

    $response->assertOk()
        ->assertJsonPath('meta.total', 5);
});

test('list shows only archived with archived_only=true', function () {
    Registration::factory()->count(3)->create(['status' => Registration::STATUS_NEW]);
    Registration::factory()->archived()->count(2)->create();

    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/psb/registrations?archived_only=true');

    $response->assertOk()
        ->assertJsonPath('meta.total', 2);
});

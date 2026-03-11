<?php

use App\Models\School;
use App\Models\TimeSlot;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;
use Database\Seeders\TimeSlotSeeder;

function createTimeSlotTestUser(): array
{
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $school = School::where('is_active', true)->first();

    return [$user, $school];
}

// ── Index tests ────────────────────────────────────────────────────────

test('unauthenticated user cannot access time slots', function () {
    $response = $this->getJson('/api/v1/time-slots');

    $response->assertUnauthorized();
});

test('user without permission cannot access time slots', function () {
    (new RolePermissionSeeder)->run();

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/time-slots');

    $response->assertForbidden();
});

test('authenticated user with permission can list time slots', function () {
    [$user, $school] = createTimeSlotTestUser();

    TimeSlot::factory()->count(3)->create(['school_id' => $school->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/time-slots');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(3, 'data');
});

test('time slots are ordered by sort_order ascending', function () {
    [$user, $school] = createTimeSlotTestUser();

    TimeSlot::factory()->create(['school_id' => $school->id, 'code' => 'slot_c', 'sort_order' => 3]);
    TimeSlot::factory()->create(['school_id' => $school->id, 'code' => 'slot_a', 'sort_order' => 1]);
    TimeSlot::factory()->create(['school_id' => $school->id, 'code' => 'slot_b', 'sort_order' => 2]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/time-slots');

    $response->assertOk();

    $codes = array_column($response->json('data'), 'code');
    expect($codes)->toBe(['slot_a', 'slot_b', 'slot_c']);
});

test('time slot response has correct structure', function () {
    [$user, $school] = createTimeSlotTestUser();

    TimeSlot::factory()->create(['school_id' => $school->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/time-slots');

    $response->assertOk()
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'school_id', 'code', 'label', 'type', 'start_time', 'end_time', 'sort_order', 'is_active'],
            ],
            'message',
        ]);
});

// ── Show tests ─────────────────────────────────────────────────────────

test('can show a single time slot', function () {
    [$user, $school] = createTimeSlotTestUser();

    $timeSlot = TimeSlot::factory()->create([
        'school_id' => $school->id,
        'code' => 'after_fajr',
        'label' => "Ba'da Subuh",
        'type' => 'prayer_based',
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/time-slots/{$timeSlot->id}");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.code', 'after_fajr')
        ->assertJsonPath('data.type', 'prayer_based');
});

// ── Create tests ───────────────────────────────────────────────────────

test('can create a time slot', function () {
    [$user, $school] = createTimeSlotTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/time-slots', [
            'code' => 'after_fajr',
            'label' => "Ba'da Subuh",
            'type' => 'prayer_based',
            'start_time' => '05:45',
            'end_time' => '06:45',
            'sort_order' => 1,
        ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.code', 'after_fajr')
        ->assertJsonPath('data.type', 'prayer_based');

    $this->assertDatabaseHas('time_slots', [
        'code' => 'after_fajr',
        'school_id' => $school->id,
        'is_active' => true,
    ]);
});

test('create time slot auto-assigns school_id', function () {
    [$user, $school] = createTimeSlotTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/time-slots', [
            'code' => '07:00-08:00',
            'label' => '07:00 - 08:00',
            'type' => 'fixed_clock',
            'start_time' => '07:00',
            'end_time' => '08:00',
        ]);

    $response->assertCreated();

    $this->assertDatabaseHas('time_slots', [
        'code' => '07:00-08:00',
        'school_id' => $school->id,
    ]);
});

test('create time slot requires permission', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/time-slots', [
            'code' => 'after_fajr',
            'label' => "Ba'da Subuh",
            'type' => 'prayer_based',
        ]);

    $response->assertForbidden();
});

test('create time slot fails with invalid code format', function () {
    [$user] = createTimeSlotTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/time-slots', [
            'code' => 'After Fajr',
            'label' => "Ba'da Subuh",
            'type' => 'prayer_based',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

test('create time slot fails with invalid type', function () {
    [$user] = createTimeSlotTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/time-slots', [
            'code' => 'test_slot',
            'label' => 'Test Slot',
            'type' => 'invalid_type',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['type']);
});

test('create time slot fails with missing required fields', function () {
    [$user] = createTimeSlotTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/time-slots', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['code', 'label', 'type']);
});

test('create time slot fails with end_time before start_time', function () {
    [$user] = createTimeSlotTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/time-slots', [
            'code' => 'test_slot',
            'label' => 'Test Slot',
            'type' => 'fixed_clock',
            'start_time' => '09:00',
            'end_time' => '08:00',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['end_time']);
});

test('create time slot fails with duplicate code for same school', function () {
    [$user, $school] = createTimeSlotTestUser();

    TimeSlot::factory()->create([
        'school_id' => $school->id,
        'code' => 'after_fajr',
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/time-slots', [
            'code' => 'after_fajr',
            'label' => "Ba'da Subuh",
            'type' => 'prayer_based',
        ]);

    expect($response->status())->toBe(422);
});

test('create time slot allows same code for different school', function () {
    [$user, $school] = createTimeSlotTestUser();

    $otherSchool = School::factory()->create();
    TimeSlot::factory()->create([
        'school_id' => $otherSchool->id,
        'code' => 'after_fajr',
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/time-slots', [
            'code' => 'after_fajr',
            'label' => "Ba'da Subuh",
            'type' => 'prayer_based',
        ]);

    $response->assertCreated();
});

// ── Update tests ───────────────────────────────────────────────────────

test('can update a time slot', function () {
    [$user, $school] = createTimeSlotTestUser();

    $timeSlot = TimeSlot::factory()->create([
        'school_id' => $school->id,
        'code' => 'after_fajr',
        'label' => "Ba'da Subuh",
    ]);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/time-slots/{$timeSlot->id}", [
            'label' => "Ba'da Shubuh",
            'start_time' => '05:30',
            'end_time' => '06:30',
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.label', "Ba'da Shubuh");
});

test('can partial update a time slot', function () {
    [$user, $school] = createTimeSlotTestUser();

    $timeSlot = TimeSlot::factory()->create([
        'school_id' => $school->id,
        'code' => 'after_fajr',
        'label' => "Ba'da Subuh",
        'sort_order' => 1,
    ]);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/time-slots/{$timeSlot->id}", [
            'sort_order' => 5,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.sort_order', 5)
        ->assertJsonPath('data.code', 'after_fajr');
});

test('update time slot fails with duplicate code for same school', function () {
    [$user, $school] = createTimeSlotTestUser();

    TimeSlot::factory()->create([
        'school_id' => $school->id,
        'code' => 'after_fajr',
    ]);

    $timeSlot = TimeSlot::factory()->create([
        'school_id' => $school->id,
        'code' => 'after_dhuhr',
    ]);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/time-slots/{$timeSlot->id}", [
            'code' => 'after_fajr',
        ]);

    expect($response->status())->toBe(422);
});

test('update time slot allows keeping same code', function () {
    [$user, $school] = createTimeSlotTestUser();

    $timeSlot = TimeSlot::factory()->create([
        'school_id' => $school->id,
        'code' => 'after_fajr',
    ]);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/time-slots/{$timeSlot->id}", [
            'code' => 'after_fajr',
            'label' => 'Updated Label',
        ]);

    $response->assertOk();
});

// ── Delete tests ───────────────────────────────────────────────────────

test('can delete a time slot without dependents', function () {
    [$user, $school] = createTimeSlotTestUser();

    $timeSlot = TimeSlot::factory()->create([
        'school_id' => $school->id,
    ]);

    $response = $this->actingAs($user)
        ->deleteJson("/api/v1/time-slots/{$timeSlot->id}");

    $response->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('time_slots', ['id' => $timeSlot->id]);
});

test('delete time slot requires permission', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    $school = School::where('is_active', true)->first();
    $user = User::factory()->create();

    $timeSlot = TimeSlot::factory()->create([
        'school_id' => $school->id,
    ]);

    $response = $this->actingAs($user)
        ->deleteJson("/api/v1/time-slots/{$timeSlot->id}");

    $response->assertForbidden();
});

// Note: Test for "cannot delete with teaching schedules" is skipped because
// the teaching_schedules table doesn't exist yet (Task 6).

// ── Reorder tests ─────────────────────────────────────────────────────

test('can reorder time slots', function () {
    [$user, $school] = createTimeSlotTestUser();

    $slotA = TimeSlot::factory()->create(['school_id' => $school->id, 'code' => 'slot_a', 'sort_order' => 1]);
    $slotB = TimeSlot::factory()->create(['school_id' => $school->id, 'code' => 'slot_b', 'sort_order' => 2]);
    $slotC = TimeSlot::factory()->create(['school_id' => $school->id, 'code' => 'slot_c', 'sort_order' => 3]);

    $response = $this->actingAs($user)
        ->patchJson('/api/v1/time-slots/reorder', [
            'ordered_ids' => [$slotC->id, $slotA->id, $slotB->id],
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('time_slots', ['id' => $slotC->id, 'sort_order' => 1]);
    $this->assertDatabaseHas('time_slots', ['id' => $slotA->id, 'sort_order' => 2]);
    $this->assertDatabaseHas('time_slots', ['id' => $slotB->id, 'sort_order' => 3]);
});

test('reorder requires permission', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    $school = School::where('is_active', true)->first();
    $user = User::factory()->create();

    $slot = TimeSlot::factory()->create(['school_id' => $school->id]);

    $response = $this->actingAs($user)
        ->patchJson('/api/v1/time-slots/reorder', [
            'ordered_ids' => [$slot->id],
        ]);

    $response->assertForbidden();
});

test('reorder fails with empty ordered_ids', function () {
    [$user] = createTimeSlotTestUser();

    $response = $this->actingAs($user)
        ->patchJson('/api/v1/time-slots/reorder', [
            'ordered_ids' => [],
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['ordered_ids']);
});

test('reorder fails with invalid uuid', function () {
    [$user] = createTimeSlotTestUser();

    $response = $this->actingAs($user)
        ->patchJson('/api/v1/time-slots/reorder', [
            'ordered_ids' => ['not-a-valid-uuid'],
        ]);

    $response->assertUnprocessable();
});

// ── Type validation tests ─────────────────────────────────────────────

test('can create prayer_based time slot', function () {
    [$user] = createTimeSlotTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/time-slots', [
            'code' => 'after_fajr',
            'label' => "Ba'da Subuh",
            'type' => 'prayer_based',
            'start_time' => '05:45',
            'end_time' => '06:45',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.type', 'prayer_based');
});

test('can create fixed_clock time slot', function () {
    [$user] = createTimeSlotTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/time-slots', [
            'code' => '07:00-08:00',
            'label' => '07:00 - 08:00',
            'type' => 'fixed_clock',
            'start_time' => '07:00',
            'end_time' => '08:00',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.type', 'fixed_clock');
});

test('can create time slot without start_time and end_time', function () {
    [$user] = createTimeSlotTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/time-slots', [
            'code' => 'after_fajr',
            'label' => "Ba'da Subuh",
            'type' => 'prayer_based',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.start_time', null)
        ->assertJsonPath('data.end_time', null);
});

// ── Seeder tests ──────────────────────────────────────────────────────

test('seeder creates expected time slots', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    (new TimeSlotSeeder)->run();

    $school = School::where('is_active', true)->first();

    $slots = TimeSlot::where('school_id', $school->id)->orderBy('sort_order')->get();

    expect($slots)->toHaveCount(7);
    expect($slots[0]->code)->toBe('after_fajr');
    expect($slots[0]->type)->toBe('prayer_based');
    expect($slots[1]->code)->toBe('07:00-08:00');
    expect($slots[1]->type)->toBe('fixed_clock');
    expect($slots[6]->code)->toBe('after_isha');
    expect($slots[6]->sort_order)->toBe(7);
});

test('seeder is idempotent', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    (new TimeSlotSeeder)->run();
    (new TimeSlotSeeder)->run();

    $school = School::where('is_active', true)->first();

    $slots = TimeSlot::where('school_id', $school->id)->get();

    expect($slots)->toHaveCount(7);
});

<?php

use App\Models\Registration;
use App\Models\RegistrationPeriod;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\ClassLevelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    (new ClassLevelSeeder)->run();
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
});

// -------------------------------------------------------
// Computed Fields
// -------------------------------------------------------

test('student with all required fields has is_profile_complete true and empty incomplete_fields', function () {
    $student = Student::factory()->profileComplete()->create();

    expect($student->is_profile_complete)->toBeTrue()
        ->and($student->incomplete_fields)->toBeEmpty();
});

test('student with null class_level is incomplete', function () {
    $student = Student::factory()->create(['class_level' => null]);

    expect($student->is_profile_complete)->toBeFalse()
        ->and($student->incomplete_fields)->toContain('class_level');
});

test('student with null address is incomplete', function () {
    $student = Student::factory()->create(['address' => null]);

    expect($student->is_profile_complete)->toBeFalse()
        ->and($student->incomplete_fields)->toContain('address');
});

test('student with multiple null fields reports all missing fields', function () {
    $student = Student::factory()->create([
        'class_level' => null,
        'address' => null,
        'birth_place' => null,
    ]);

    expect($student->is_profile_complete)->toBeFalse()
        ->and($student->incomplete_fields)->toContain('class_level')
        ->and($student->incomplete_fields)->toContain('address')
        ->and($student->incomplete_fields)->toContain('birth_place');
});

test('photo_url and notes are not required for profile completeness', function () {
    $student = Student::factory()->create([
        'photo_url' => null,
        'notes' => null,
    ]);

    expect($student->is_profile_complete)->toBeTrue();
});

// -------------------------------------------------------
// API Response Includes Computed Fields
// -------------------------------------------------------

test('show endpoint includes is_profile_complete and incomplete_fields', function () {
    $student = Student::factory()->incompleteProfile()->create();

    $response = $this->actingAs($this->admin)
        ->getJson("/api/v1/students/{$student->id}");

    $response->assertOk()
        ->assertJsonPath('data.is_profile_complete', false)
        ->assertJsonStructure(['data' => ['is_profile_complete', 'incomplete_fields']]);

    $incompleteFields = $response->json('data.incomplete_fields');
    expect($incompleteFields)->toContain('class_level')
        ->and($incompleteFields)->toContain('address');
});

test('list endpoint includes is_profile_complete and incomplete_fields', function () {
    Student::factory()->profileComplete()->create();
    Student::factory()->incompleteProfile()->create();

    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/students');

    $response->assertOk();

    $data = $response->json('data');
    foreach ($data as $student) {
        expect($student)->toHaveKeys(['is_profile_complete', 'incomplete_fields']);
    }
});

// -------------------------------------------------------
// profile_completed_at Sync
// -------------------------------------------------------

test('creating student with all required fields sets profile_completed_at', function () {
    $student = Student::factory()->profileComplete()->make()->toArray();
    unset($student['is_profile_complete'], $student['incomplete_fields'], $student['profile_completed_at']);

    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/students', $student);

    $response->assertStatus(201);

    $createdStudent = Student::find($response->json('data.id'));
    expect($createdStudent->profile_completed_at)->not->toBeNull();
});

test('creating student with missing fields leaves profile_completed_at null', function () {
    $studentData = Student::factory()->incompleteProfile()->make()->toArray();
    unset($studentData['is_profile_complete'], $studentData['incomplete_fields']);

    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/students', $studentData);

    $response->assertStatus(201);

    $createdStudent = Student::find($response->json('data.id'));
    expect($createdStudent->profile_completed_at)->toBeNull();
});

test('updating student to fill all fields sets profile_completed_at', function () {
    $student = Student::factory()->incompleteProfile()->create();
    expect($student->profile_completed_at)->toBeNull();

    $response = $this->actingAs($this->admin)
        ->putJson("/api/v1/students/{$student->id}", [
            'class_level' => 'tamhidi',
            'address' => 'Jl. Test No. 1',
        ]);

    $response->assertOk();

    $student->refresh();
    expect($student->profile_completed_at)->not->toBeNull();
});

test('updating student to clear a required field clears profile_completed_at', function () {
    $student = Student::factory()->profileComplete()->create();
    expect($student->profile_completed_at)->not->toBeNull();

    $response = $this->actingAs($this->admin)
        ->putJson("/api/v1/students/{$student->id}", [
            'class_level' => null,
        ]);

    $response->assertOk();

    $student->refresh();
    expect($student->profile_completed_at)->toBeNull();
});

test('PSB accept creates student with profile_completed_at null (incomplete profile)', function () {
    $period = RegistrationPeriod::factory()->create();
    $registration = Registration::factory()->create([
        'registration_period_id' => $period->id,
        'status' => Registration::STATUS_INTERVIEW,
        'registrant_type' => 'guardian',
        'guardian_name' => 'Test Guardian',
        'guardian_phone' => '081234567890',
    ]);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/accept", [
            'class_level' => 'tamhidi',
        ]);

    $response->assertOk();

    $student = Student::where('registration_id', $registration->id)->first();
    expect($student)->not->toBeNull()
        ->and($student->profile_completed_at)->toBeNull()
        ->and($student->is_profile_complete)->toBeFalse()
        ->and($student->class_level)->toBe('tamhidi')
        ->and($student->incomplete_fields)->toContain('address');
});

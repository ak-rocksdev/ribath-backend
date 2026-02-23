<?php

use App\Models\RegistrationPeriod;

test('active period endpoint returns active period when one exists', function () {
    $activePeriod = RegistrationPeriod::factory()->create([
        'is_active' => true,
        'registration_open' => now()->subDay(),
        'registration_close' => now()->addMonth(),
    ]);

    $response = $this->getJson('/api/v1/public/psb/active-period');

    $response->assertOk()
        ->assertJsonStructure([
            'success',
            'data' => ['id', 'name', 'year', 'wave', 'registration_open', 'registration_close'],
            'message',
        ])
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Active registration period retrieved')
        ->assertJsonPath('data.id', $activePeriod->id);
});

test('active period endpoint returns 404 when no active period exists', function () {
    $response = $this->getJson('/api/v1/public/psb/active-period');

    $response->assertNotFound()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'No active registration period found');
});

test('active period endpoint excludes inactive periods', function () {
    RegistrationPeriod::factory()->inactive()->create([
        'registration_open' => now()->subDay(),
        'registration_close' => now()->addMonth(),
    ]);

    $response = $this->getJson('/api/v1/public/psb/active-period');

    $response->assertNotFound()
        ->assertJsonPath('success', false);
});

test('active period endpoint excludes periods outside date range', function () {
    // Period that hasn't opened yet
    RegistrationPeriod::factory()->create([
        'is_active' => true,
        'registration_open' => now()->addWeek(),
        'registration_close' => now()->addMonths(2),
    ]);

    // Period that already closed
    RegistrationPeriod::factory()->create([
        'is_active' => true,
        'registration_open' => now()->subMonths(2),
        'registration_close' => now()->subDay(),
    ]);

    $response = $this->getJson('/api/v1/public/psb/active-period');

    $response->assertNotFound()
        ->assertJsonPath('success', false);
});

test('register endpoint successfully registers with guardian type', function () {
    RegistrationPeriod::factory()->create([
        'is_active' => true,
        'registration_open' => now()->subDay(),
        'registration_close' => now()->addMonth(),
    ]);

    $registrationData = [
        'registrant_type' => 'guardian',
        'full_name' => 'Ahmad Fauzi',
        'birth_place' => 'Solo',
        'birth_date' => '2010-05-15',
        'gender' => 'L',
        'preferred_program' => 'tahfidz',
        'guardian_name' => 'Budi Fauzi',
        'guardian_phone' => '081234567890',
        'guardian_email' => 'budi@example.com',
        'info_source' => 'instagram',
    ];

    $response = $this->postJson('/api/v1/public/psb/register', $registrationData);

    $response->assertCreated()
        ->assertJsonStructure([
            'success',
            'data' => ['id', 'registration_number', 'status', 'full_name', 'registrant_type'],
            'message',
        ])
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Registration submitted successfully')
        ->assertJsonPath('data.full_name', 'Ahmad Fauzi')
        ->assertJsonPath('data.registrant_type', 'guardian')
        ->assertJsonPath('data.guardian_name', 'Budi Fauzi')
        ->assertJsonPath('data.status', 'new');

    $this->assertDatabaseHas('registrations', [
        'full_name' => 'Ahmad Fauzi',
        'registrant_type' => 'guardian',
        'guardian_name' => 'Budi Fauzi',
    ]);
});

test('register endpoint successfully registers with student type (self-registered)', function () {
    RegistrationPeriod::factory()->create([
        'is_active' => true,
        'registration_open' => now()->subDay(),
        'registration_close' => now()->addMonth(),
    ]);

    $registrationData = [
        'registrant_type' => 'student',
        'full_name' => 'Siti Aminah',
        'birth_place' => 'Jakarta',
        'birth_date' => '2008-03-20',
        'gender' => 'P',
        'preferred_program' => 'regular',
        'guardian_phone' => '082345678901',
    ];

    $response = $this->postJson('/api/v1/public/psb/register', $registrationData);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.full_name', 'Siti Aminah')
        ->assertJsonPath('data.registrant_type', 'student')
        ->assertJsonPath('data.status', 'new');

    $this->assertDatabaseHas('registrations', [
        'full_name' => 'Siti Aminah',
        'registrant_type' => 'student',
    ]);
});

test('register endpoint validates required fields', function () {
    $response = $this->postJson('/api/v1/public/psb/register', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'registrant_type',
            'full_name',
            'birth_date',
            'gender',
            'preferred_program',
            'guardian_phone',
        ]);
});

test('register endpoint validates gender must be L or P', function () {
    $registrationData = [
        'registrant_type' => 'student',
        'full_name' => 'Test Student',
        'birth_date' => '2010-01-01',
        'gender' => 'X',
        'preferred_program' => 'tahfidz',
        'guardian_phone' => '081234567890',
    ];

    $response = $this->postJson('/api/v1/public/psb/register', $registrationData);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['gender']);
});

test('register endpoint validates guardian_name required when registrant_type is guardian', function () {
    $registrationData = [
        'registrant_type' => 'guardian',
        'full_name' => 'Ahmad Fauzi',
        'birth_date' => '2010-05-15',
        'gender' => 'L',
        'preferred_program' => 'tahfidz',
        'guardian_phone' => '081234567890',
        // guardian_name intentionally omitted
    ];

    $response = $this->postJson('/api/v1/public/psb/register', $registrationData);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['guardian_name']);
});

test('register endpoint generates registration number in PSB-YYYY-NNNNN format', function () {
    RegistrationPeriod::factory()->create([
        'is_active' => true,
        'registration_open' => now()->subDay(),
        'registration_close' => now()->addMonth(),
    ]);

    $registrationData = [
        'registrant_type' => 'student',
        'full_name' => 'Test Student',
        'birth_date' => '2010-01-01',
        'gender' => 'L',
        'preferred_program' => 'tahfidz',
        'guardian_phone' => '081234567890',
    ];

    $response = $this->postJson('/api/v1/public/psb/register', $registrationData);

    $response->assertCreated();

    $registrationNumber = $response->json('data.registration_number');
    $expectedPattern = '/^PSB-'.now()->year.'-\d{5}$/';
    expect($registrationNumber)->toMatch($expectedPattern);
});

test('register endpoint links to active period if one exists', function () {
    $activePeriod = RegistrationPeriod::factory()->create([
        'is_active' => true,
        'registration_open' => now()->subDay(),
        'registration_close' => now()->addMonth(),
    ]);

    $registrationData = [
        'registrant_type' => 'student',
        'full_name' => 'Test Student',
        'birth_date' => '2010-01-01',
        'gender' => 'L',
        'preferred_program' => 'tahfidz',
        'guardian_phone' => '081234567890',
    ];

    $response = $this->postJson('/api/v1/public/psb/register', $registrationData);

    $response->assertCreated()
        ->assertJsonPath('data.registration_period_id', $activePeriod->id);

    $this->assertDatabaseHas('registrations', [
        'full_name' => 'Test Student',
        'registration_period_id' => $activePeriod->id,
    ]);
});

test('register endpoint assigns waitlist status when quota is full', function () {
    RegistrationPeriod::factory()->full()->create([
        'is_active' => true,
        'registration_open' => now()->subDay(),
        'registration_close' => now()->addMonth(),
    ]);

    $registrationData = [
        'registrant_type' => 'student',
        'full_name' => 'Waitlisted Student',
        'birth_date' => '2010-01-01',
        'gender' => 'L',
        'preferred_program' => 'tahfidz',
        'guardian_phone' => '081234567890',
    ];

    $response = $this->postJson('/api/v1/public/psb/register', $registrationData);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'waitlist');

    $this->assertDatabaseHas('registrations', [
        'full_name' => 'Waitlisted Student',
        'status' => 'waitlist',
    ]);
});

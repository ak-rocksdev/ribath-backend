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

test('accept response includes guardian_whatsapp_link when guardian exists with phone', function () {
    $period = RegistrationPeriod::factory()->create();
    $registration = Registration::factory()->create([
        'registration_period_id' => $period->id,
        'status' => Registration::STATUS_INTERVIEW,
        'registrant_type' => 'guardian',
        'guardian_name' => 'Bapak Ahmad',
        'guardian_phone' => '081234567890',
        'guardian_email' => 'ahmad@test.com',
    ]);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/accept");

    $response->assertOk()
        ->assertJsonStructure([
            'data' => ['guardian_whatsapp_link'],
        ]);

    $link = $response->json('data.guardian_whatsapp_link');
    expect($link)->toBeString()->not->toBeEmpty();
});

test('whatsapp link starts with https://wa.me/62', function () {
    $period = RegistrationPeriod::factory()->create();
    $registration = Registration::factory()->create([
        'registration_period_id' => $period->id,
        'status' => Registration::STATUS_INTERVIEW,
        'registrant_type' => 'guardian',
        'guardian_name' => 'Bapak Test',
        'guardian_phone' => '081234567890',
    ]);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/accept");

    $link = $response->json('data.guardian_whatsapp_link');
    expect($link)->toStartWith('https://wa.me/62');
});

test('phone 08123456789 formats to 628123456789 in whatsapp link', function () {
    $period = RegistrationPeriod::factory()->create();
    $registration = Registration::factory()->create([
        'registration_period_id' => $period->id,
        'status' => Registration::STATUS_INTERVIEW,
        'registrant_type' => 'guardian',
        'guardian_name' => 'Bapak Format',
        'guardian_phone' => '08123456789',
    ]);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/accept");

    $link = $response->json('data.guardian_whatsapp_link');
    expect($link)->toStartWith('https://wa.me/628123456789?text=');
});

test('whatsapp link contains student name and credentials in message', function () {
    $period = RegistrationPeriod::factory()->create();
    $registration = Registration::factory()->create([
        'registration_period_id' => $period->id,
        'status' => Registration::STATUS_INTERVIEW,
        'registrant_type' => 'guardian',
        'full_name' => 'Muhammad Ali',
        'guardian_name' => 'Bapak Ali',
        'guardian_phone' => '081299990000',
        'guardian_email' => 'ali@test.com',
    ]);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/accept");

    $link = $response->json('data.guardian_whatsapp_link');
    $decodedMessage = urldecode(parse_url($link, PHP_URL_QUERY));

    expect($decodedMessage)->toContain('Muhammad Ali')
        ->and($decodedMessage)->toContain('Bapak Ali')
        ->and($decodedMessage)->toContain('ali@test.com')
        ->and($decodedMessage)->toContain('Password:');
});

test('self-registered student (no guardian) has no whatsapp link in response', function () {
    $period = RegistrationPeriod::factory()->create();
    $registration = Registration::factory()->selfRegistered()->create([
        'registration_period_id' => $period->id,
        'status' => Registration::STATUS_INTERVIEW,
    ]);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/accept");

    $response->assertOk();

    $responseData = $response->json('data');
    expect($responseData)->not->toHaveKey('guardian_whatsapp_link');
});

test('phone already in 62 format is handled correctly in whatsapp link', function () {
    $period = RegistrationPeriod::factory()->create();
    $registration = Registration::factory()->create([
        'registration_period_id' => $period->id,
        'status' => Registration::STATUS_INTERVIEW,
        'registrant_type' => 'guardian',
        'guardian_name' => 'Bapak Intl',
        'guardian_phone' => '6281234567890',
    ]);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/accept");

    $link = $response->json('data.guardian_whatsapp_link');
    expect($link)->toStartWith('https://wa.me/6281234567890?text=');
    // Should NOT double-prefix to 626281...
    expect($link)->not->toContain('wa.me/626');
});

test('whatsapp link contains app URL from config', function () {
    config(['app.url' => 'https://ribath.example.com']);

    $period = RegistrationPeriod::factory()->create();
    $registration = Registration::factory()->create([
        'registration_period_id' => $period->id,
        'status' => Registration::STATUS_INTERVIEW,
        'registrant_type' => 'guardian',
        'guardian_name' => 'Bapak URL',
        'guardian_phone' => '081234567890',
    ]);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/psb/registrations/{$registration->id}/accept");

    $link = $response->json('data.guardian_whatsapp_link');
    $decodedMessage = urldecode(parse_url($link, PHP_URL_QUERY));

    expect($decodedMessage)->toContain('https://ribath.example.com');
});

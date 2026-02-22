<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::create(['name' => 'super_admin']);

    $this->adminUser = User::factory()->create([
        'email' => 'admin@test.com',
        'password' => bcrypt('password123'),
    ]);
    $this->adminUser->assignRole('super_admin');
});

test('user can login with valid credentials', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@test.com',
        'password' => 'password123',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'success',
            'data' => ['user' => ['id', 'name', 'email', 'roles'], 'token'],
            'message',
        ])
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.email', 'admin@test.com')
        ->assertJsonPath('data.user.roles.0', 'super_admin');
});

test('login fails with invalid credentials', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@test.com',
        'password' => 'wrong-password',
    ]);

    $response->assertUnauthorized()
        ->assertJsonPath('success', false);
});

test('login validates required fields', function () {
    $response = $this->postJson('/api/v1/auth/login', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);
});

test('authenticated user can get profile', function () {
    $response = $this->actingAs($this->adminUser)
        ->getJson('/api/v1/auth/me');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.email', 'admin@test.com')
        ->assertJsonPath('data.roles.0', 'super_admin');
});

test('unauthenticated user cannot get profile', function () {
    $response = $this->getJson('/api/v1/auth/me');

    $response->assertUnauthorized();
});

test('authenticated user can logout', function () {
    $token = $this->adminUser->createToken('test-token')->plainTextToken;

    $response = $this->withHeaders(['Authorization' => "Bearer $token"])
        ->postJson('/api/v1/auth/logout');

    $response->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('authenticated user can change password', function () {
    $response = $this->actingAs($this->adminUser)
        ->putJson('/api/v1/auth/change-password', [
            'current_password' => 'password123',
            'new_password' => 'new-password456',
            'new_password_confirmation' => 'new-password456',
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    expect(Hash::check('new-password456', $this->adminUser->fresh()->password))->toBeTrue();
});

test('change password fails with wrong current password', function () {
    $response = $this->actingAs($this->adminUser)
        ->putJson('/api/v1/auth/change-password', [
            'current_password' => 'wrong-current',
            'new_password' => 'new-password456',
            'new_password_confirmation' => 'new-password456',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);
});

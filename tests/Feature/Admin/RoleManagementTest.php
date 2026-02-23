<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);
});

function createRoleAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    return $user;
}

// Auth & Authorization
test('unauthenticated user cannot access roles', function () {
    $this->getJson('/api/v1/roles')
        ->assertStatus(401);
});

test('user without permission cannot access roles', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/roles')
        ->assertStatus(403);
});

// List Roles
test('list roles with permission and user counts', function () {
    $admin = createRoleAdmin();

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/roles')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    $roles = collect($response->json('data'));
    expect($roles->count())->toBeGreaterThanOrEqual(2);

    $pengurusPesantren = $roles->firstWhere('name', 'pengurus_pesantren');
    expect($pengurusPesantren)->not->toBeNull()
        ->and($pengurusPesantren['permissions_count'])->toBeGreaterThan(0);
});

// Assign Roles
test('assign roles to user', function () {
    $admin = createRoleAdmin();
    $user = User::factory()->create();

    $response = $this->actingAs($admin)
        ->postJson("/api/v1/users/{$user->id}/roles", [
            'roles' => ['pengurus_pesantren'],
        ])
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    $roleNames = collect($response->json('data.roles'))->pluck('name');
    expect($roleNames)->toContain('pengurus_pesantren');
});

test('assign roles validates role exists', function () {
    $admin = createRoleAdmin();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->postJson("/api/v1/users/{$user->id}/roles", [
            'roles' => ['nonexistent_role'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['roles.0']);
});

test('assign roles requires roles array', function () {
    $admin = createRoleAdmin();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->postJson("/api/v1/users/{$user->id}/roles", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['roles']);
});

// Remove Role
test('remove role from user', function () {
    $admin = createRoleAdmin();
    $user = User::factory()->create();
    $user->assignRole('pengurus_pesantren');

    $role = Role::findByName('pengurus_pesantren');

    $response = $this->actingAs($admin)
        ->deleteJson("/api/v1/users/{$user->id}/roles/{$role->id}")
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    $roleNames = collect($response->json('data.roles'))->pluck('name');
    expect($roleNames)->not->toContain('pengurus_pesantren');
});

test('user without manage-roles cannot assign roles', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();

    $this->actingAs($user)
        ->postJson("/api/v1/users/{$target->id}/roles", [
            'roles' => ['pengurus_pesantren'],
        ])
        ->assertStatus(403);
});

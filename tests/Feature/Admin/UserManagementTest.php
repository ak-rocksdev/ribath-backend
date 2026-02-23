<?php

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);
});

function createUserWithSpecificPermissions(array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::create(['name' => 'test_role_'.uniqid()]);
    $role->syncPermissions($permissions);
    $user->assignRole($role);

    return $user;
}

function createUserManagementAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    return $user;
}

// Auth & Authorization
test('unauthenticated user cannot access users', function () {
    $this->getJson('/api/v1/users')
        ->assertStatus(401);
});

test('user without permission cannot access users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/users')
        ->assertStatus(403);
});

test('super_admin can access users', function () {
    $admin = createUserManagementAdmin();

    $this->actingAs($admin)
        ->getJson('/api/v1/users')
        ->assertStatus(200)
        ->assertJsonPath('success', true);
});

// List Users
test('list users returns paginated results', function () {
    $admin = createUserManagementAdmin();
    User::factory()->count(20)->create();

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/users')
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);

    expect($response->json('meta.total'))->toBeGreaterThanOrEqual(20);
});

test('list users can search by name', function () {
    $admin = createUserManagementAdmin();
    User::factory()->create(['name' => 'John Unique Name']);
    User::factory()->count(5)->create();

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/users?search=John Unique')
        ->assertStatus(200);

    $names = collect($response->json('data'))->pluck('name');
    expect($names)->each->toContain('John Unique Name');
});

test('list users can search by email', function () {
    $admin = createUserManagementAdmin();
    User::factory()->create(['email' => 'unique-search@test.com']);

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/users?search=unique-search@test')
        ->assertStatus(200);

    expect($response->json('meta.total'))->toBe(1);
});

test('list users can search by phone', function () {
    $admin = createUserManagementAdmin();
    User::factory()->create(['phone' => '081234567890']);

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/users?search=081234567890')
        ->assertStatus(200);

    expect($response->json('meta.total'))->toBe(1);
});

test('list users can filter by role', function () {
    $admin = createUserManagementAdmin();
    $userWithRole = User::factory()->create();
    $userWithRole->assignRole('pengurus_pesantren');
    User::factory()->count(3)->create();

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/users?role=pengurus_pesantren')
        ->assertStatus(200);

    expect($response->json('meta.total'))->toBe(1);
});

test('list users can filter by active status', function () {
    $admin = createUserManagementAdmin();
    User::factory()->count(3)->create(['is_active' => true]);
    User::factory()->count(2)->inactive()->create();

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/users?is_active=false')
        ->assertStatus(200);

    expect($response->json('meta.total'))->toBe(2);
});

// Create User
test('create user with role', function () {
    $admin = createUserManagementAdmin();

    $response = $this->actingAs($admin)
        ->postJson('/api/v1/users', [
            'name' => 'New User',
            'email' => 'newuser@test.com',
            'password' => 'password123',
            'phone' => '081234567890',
            'role' => 'pengurus_pesantren',
        ])
        ->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'New User')
        ->assertJsonPath('data.email', 'newuser@test.com')
        ->assertJsonPath('data.phone', '081234567890');

    $roles = collect($response->json('data.roles'))->pluck('name');
    expect($roles)->toContain('pengurus_pesantren');
});

test('create user without role', function () {
    $admin = createUserManagementAdmin();

    $this->actingAs($admin)
        ->postJson('/api/v1/users', [
            'name' => 'No Role User',
            'email' => 'norole@test.com',
            'password' => 'password123',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.name', 'No Role User');
});

test('create user validates required fields', function () {
    $admin = createUserManagementAdmin();

    $this->actingAs($admin)
        ->postJson('/api/v1/users', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'email', 'password']);
});

test('create user validates unique email', function () {
    $admin = createUserManagementAdmin();
    User::factory()->create(['email' => 'taken@test.com']);

    $this->actingAs($admin)
        ->postJson('/api/v1/users', [
            'name' => 'Duplicate',
            'email' => 'taken@test.com',
            'password' => 'password123',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

// Show User
test('show user with roles', function () {
    $admin = createUserManagementAdmin();
    $user = User::factory()->create();
    $user->assignRole('pengurus_pesantren');

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/users/{$user->id}")
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $user->id);

    expect($response->json('data.roles'))->not->toBeEmpty();
});

// Update User
test('update user', function () {
    $admin = createUserManagementAdmin();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->putJson("/api/v1/users/{$user->id}", [
            'name' => 'Updated Name',
            'phone' => '089876543210',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Updated Name')
        ->assertJsonPath('data.phone', '089876543210');
});

// Delete User
test('soft delete user', function () {
    $admin = createUserManagementAdmin();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/v1/users/{$user->id}")
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    expect(User::find($user->id))->toBeNull();
    expect(User::withTrashed()->find($user->id))->not->toBeNull();
});

test('cannot delete yourself', function () {
    $admin = createUserManagementAdmin();

    $this->actingAs($admin)
        ->deleteJson("/api/v1/users/{$admin->id}")
        ->assertStatus(403);
});

// Toggle Status
test('toggle user status to inactive', function () {
    $admin = createUserManagementAdmin();
    $user = User::factory()->create(['is_active' => true]);

    $this->actingAs($admin)
        ->patchJson("/api/v1/users/{$user->id}/toggle-status")
        ->assertStatus(200)
        ->assertJsonPath('data.is_active', false);
});

test('toggle user status to active', function () {
    $admin = createUserManagementAdmin();
    $user = User::factory()->inactive()->create();

    $this->actingAs($admin)
        ->patchJson("/api/v1/users/{$user->id}/toggle-status")
        ->assertStatus(200)
        ->assertJsonPath('data.is_active', true);
});

test('cannot toggle your own status', function () {
    $admin = createUserManagementAdmin();

    $this->actingAs($admin)
        ->patchJson("/api/v1/users/{$admin->id}/toggle-status")
        ->assertStatus(403);
});

test('deactivating user revokes tokens', function () {
    $admin = createUserManagementAdmin();
    $user = User::factory()->create(['is_active' => true]);
    $user->createToken('test-token');

    expect($user->tokens()->count())->toBe(1);

    $this->actingAs($admin)
        ->patchJson("/api/v1/users/{$user->id}/toggle-status")
        ->assertStatus(200);

    expect($user->fresh()->tokens()->count())->toBe(0);
});

// Reset Password
test('reset user password', function () {
    $admin = createUserManagementAdmin();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->patchJson("/api/v1/users/{$user->id}/reset-password", [
            'new_password' => 'newpassword123',
        ])
        ->assertStatus(200)
        ->assertJsonPath('success', true);
});

test('reset password validates minimum length', function () {
    $admin = createUserManagementAdmin();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->patchJson("/api/v1/users/{$user->id}/reset-password", [
            'new_password' => 'short',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['new_password']);
});

test('reset password revokes all tokens', function () {
    $admin = createUserManagementAdmin();
    $user = User::factory()->create();
    $user->createToken('token-1');
    $user->createToken('token-2');

    expect($user->tokens()->count())->toBe(2);

    $this->actingAs($admin)
        ->patchJson("/api/v1/users/{$user->id}/reset-password", [
            'new_password' => 'newpassword123',
        ])
        ->assertStatus(200);

    expect($user->fresh()->tokens()->count())->toBe(0);
});

// Permission-based access
test('user with view-users permission can list users', function () {
    $user = createUserWithSpecificPermissions(['view-users']);

    $this->actingAs($user)
        ->getJson('/api/v1/users')
        ->assertStatus(200);
});

test('user with create-users permission can create users', function () {
    $user = createUserWithSpecificPermissions(['create-users']);

    $this->actingAs($user)
        ->postJson('/api/v1/users', [
            'name' => 'Test',
            'email' => 'test-perm@test.com',
            'password' => 'password123',
        ])
        ->assertStatus(201);
});

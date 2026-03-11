<?php

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);
});

function createPermissionAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    return $user;
}

// ── List Permissions ──────────────────────────────────────────

test('unauthenticated user cannot list permissions', function () {
    $this->getJson('/api/v1/permissions')
        ->assertStatus(401);
});

test('user without manage-roles cannot list permissions', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/permissions')
        ->assertStatus(403);
});

test('list permissions returns all seeded permissions', function () {
    $admin = createPermissionAdmin();

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/permissions')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    $permissions = $response->json('data');
    expect(count($permissions))->toBe(29);

    $permissionNames = collect($permissions)->pluck('name')->toArray();
    expect($permissionNames)->toContain('view-users')
        ->toContain('manage-roles')
        ->toContain('view-registrations')
        ->toContain('manage-class-levels');
});

test('permissions are ordered by name', function () {
    $admin = createPermissionAdmin();

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/permissions')
        ->assertStatus(200);

    $permissionNames = collect($response->json('data'))->pluck('name')->toArray();
    $sorted = $permissionNames;
    sort($sorted);
    expect($permissionNames)->toBe($sorted);
});

// ── Enhanced Roles Index ──────────────────────────────────────

test('roles index includes permissions and users arrays', function () {
    $admin = createPermissionAdmin();

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/roles')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    $roles = collect($response->json('data'));
    $superAdmin = $roles->firstWhere('name', 'super_admin');

    expect($superAdmin)->not->toBeNull()
        ->and($superAdmin)->toHaveKey('permissions')
        ->and($superAdmin)->toHaveKey('users')
        ->and($superAdmin)->toHaveKey('permissions_count')
        ->and($superAdmin)->toHaveKey('users_count')
        ->and($superAdmin['users_count'])->toBeGreaterThanOrEqual(1);

    $pengurusPesantren = $roles->firstWhere('name', 'pengurus_pesantren');
    expect($pengurusPesantren)->not->toBeNull()
        ->and(count($pengurusPesantren['permissions']))->toBeGreaterThan(0)
        ->and($pengurusPesantren['permissions_count'])->toBe(count($pengurusPesantren['permissions']));
});

test('roles index user objects have correct shape', function () {
    $admin = createPermissionAdmin();

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/roles')
        ->assertStatus(200);

    $superAdmin = collect($response->json('data'))->firstWhere('name', 'super_admin');
    $firstUser = $superAdmin['users'][0];

    expect($firstUser)->toHaveKey('id')
        ->and($firstUser)->toHaveKey('name')
        ->and($firstUser)->toHaveKey('email')
        ->and($firstUser)->toHaveKey('is_active');
});

test('roles index permission objects have correct shape', function () {
    $admin = createPermissionAdmin();

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/roles')
        ->assertStatus(200);

    $pengurusPesantren = collect($response->json('data'))->firstWhere('name', 'pengurus_pesantren');
    $firstPermission = $pengurusPesantren['permissions'][0];

    expect($firstPermission)->toHaveKey('id')
        ->and($firstPermission)->toHaveKey('name')
        ->and($firstPermission)->toHaveKey('guard_name');
});

// ── Sync Permissions ──────────────────────────────────────────

test('sync permissions to role works', function () {
    $admin = createPermissionAdmin();
    $role = Role::findByName('pengurus_pesantren');

    $response = $this->actingAs($admin)
        ->putJson("/api/v1/roles/{$role->id}/permissions", [
            'permissions' => ['view-users', 'edit-users'],
        ])
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Role permissions updated');

    $permissionNames = collect($response->json('data.permissions'))->pluck('name')->toArray();
    expect($permissionNames)->toContain('view-users')
        ->toContain('edit-users')
        ->and(count($permissionNames))->toBe(2);
});

test('sync permissions replaces existing permissions', function () {
    $admin = createPermissionAdmin();
    $role = Role::findByName('pengurus_pesantren');

    // First sync: set 2 permissions
    $this->actingAs($admin)
        ->putJson("/api/v1/roles/{$role->id}/permissions", [
            'permissions' => ['view-users', 'edit-users'],
        ])
        ->assertStatus(200);

    // Second sync: replace with different permissions
    $response = $this->actingAs($admin)
        ->putJson("/api/v1/roles/{$role->id}/permissions", [
            'permissions' => ['view-students', 'create-students'],
        ])
        ->assertStatus(200);

    $permissionNames = collect($response->json('data.permissions'))->pluck('name')->toArray();
    expect($permissionNames)->toContain('view-students')
        ->toContain('create-students')
        ->not->toContain('view-users')
        ->not->toContain('edit-users');
});

test('sync empty permissions array clears all permissions', function () {
    $admin = createPermissionAdmin();
    $role = Role::findByName('pengurus_pesantren');

    $response = $this->actingAs($admin)
        ->putJson("/api/v1/roles/{$role->id}/permissions", [
            'permissions' => [],
        ])
        ->assertStatus(200);

    expect(count($response->json('data.permissions')))->toBe(0);
});

test('cannot sync permissions to super_admin', function () {
    $admin = createPermissionAdmin();
    $superAdminRole = Role::findByName('super_admin');

    $this->actingAs($admin)
        ->putJson("/api/v1/roles/{$superAdminRole->id}/permissions", [
            'permissions' => ['view-users'],
        ])
        ->assertStatus(403)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Cannot modify permissions for super_admin role');
});

test('sync validates permission names exist', function () {
    $admin = createPermissionAdmin();
    $role = Role::findByName('pengurus_pesantren');

    $this->actingAs($admin)
        ->putJson("/api/v1/roles/{$role->id}/permissions", [
            'permissions' => ['nonexistent-permission'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['permissions.0']);
});

test('sync validates permissions field must be present', function () {
    $admin = createPermissionAdmin();
    $role = Role::findByName('pengurus_pesantren');

    $this->actingAs($admin)
        ->putJson("/api/v1/roles/{$role->id}/permissions", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['permissions']);
});

test('sync validates permissions must be array', function () {
    $admin = createPermissionAdmin();
    $role = Role::findByName('pengurus_pesantren');

    $this->actingAs($admin)
        ->putJson("/api/v1/roles/{$role->id}/permissions", [
            'permissions' => 'view-users',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['permissions']);
});

// ── Authorization ─────────────────────────────────────────────

test('user without manage-roles cannot sync permissions', function () {
    $user = User::factory()->create();
    $role = Role::findByName('pengurus_pesantren');

    $this->actingAs($user)
        ->putJson("/api/v1/roles/{$role->id}/permissions", [
            'permissions' => ['view-users'],
        ])
        ->assertStatus(403);
});

test('unauthenticated user cannot sync permissions', function () {
    $role = Role::findByName('pengurus_pesantren');

    $this->putJson("/api/v1/roles/{$role->id}/permissions", [
        'permissions' => ['view-users'],
    ])
        ->assertStatus(401);
});

test('sync returns updated role with users and permissions', function () {
    $admin = createPermissionAdmin();
    $role = Role::findByName('pengurus_pesantren');

    // Add a user to the role
    $pengurusUser = User::factory()->create();
    $pengurusUser->assignRole('pengurus_pesantren');

    $response = $this->actingAs($admin)
        ->putJson("/api/v1/roles/{$role->id}/permissions", [
            'permissions' => ['view-users', 'view-students'],
        ])
        ->assertStatus(200);

    $data = $response->json('data');
    expect($data)->toHaveKey('id')
        ->and($data)->toHaveKey('permissions')
        ->and($data)->toHaveKey('users')
        ->and($data)->toHaveKey('permissions_count')
        ->and($data)->toHaveKey('users_count')
        ->and($data['users_count'])->toBeGreaterThanOrEqual(1)
        ->and($data['permissions_count'])->toBe(2);
});

<?php

use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $permissions = [
        'view-users',
        'create-users',
        'edit-users',
        'delete-users',
        'view-teachers',
        'create-teachers',
        'edit-teachers',
        'delete-teachers',
    ];

    foreach ($permissions as $permissionName) {
        Permission::firstOrCreate(['name' => $permissionName]);
    }

    $this->school = School::factory()->create();
});

function createAdminWithUserPermissions(): User
{
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'pengurus_pesantren']);
    $role->syncPermissions([
        'view-users',
        'create-users',
        'edit-users',
        'delete-users',
        'view-teachers',
        'create-teachers',
        'edit-teachers',
        'delete-teachers',
    ]);
    $user->assignRole($role);

    return $user;
}

// --- Relationships Endpoint ---

test('get user relationships returns teacher data when linked', function () {
    $admin = createAdminWithUserPermissions();
    $targetUser = User::factory()->create();
    $teacher = Teacher::factory()->create([
        'school_id' => $this->school->id,
        'user_id' => $targetUser->id,
    ]);

    $response = $this->actingAs($admin)->getJson("/api/v1/users/{$targetUser->id}/relationships");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.teacher.id', $teacher->id)
        ->assertJsonPath('data.teacher.full_name', $teacher->full_name)
        ->assertJsonPath('data.teacher.code', $teacher->code);
});

test('get user relationships returns guardian students when linked', function () {
    $admin = createAdminWithUserPermissions();
    $guardianUser = User::factory()->create();
    $student = Student::factory()->create([
        'guardian_user_id' => $guardianUser->id,
        'school_id' => $this->school->id,
    ]);

    $response = $this->actingAs($admin)->getJson("/api/v1/users/{$guardianUser->id}/relationships");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.guardian_students.0.id', $student->id)
        ->assertJsonPath('data.guardian_students.0.full_name', $student->full_name);
});

test('get user relationships returns empty when no relationships', function () {
    $admin = createAdminWithUserPermissions();
    $targetUser = User::factory()->create();

    $response = $this->actingAs($admin)->getJson("/api/v1/users/{$targetUser->id}/relationships");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.teacher', null)
        ->assertJsonPath('data.guardian_students', []);
});

// --- Cascade Delete ---

test('delete user without cascade keeps teacher', function () {
    $admin = createAdminWithUserPermissions();
    $targetUser = User::factory()->create();
    $teacher = Teacher::factory()->create([
        'school_id' => $this->school->id,
        'user_id' => $targetUser->id,
    ]);

    $response = $this->actingAs($admin)->deleteJson("/api/v1/users/{$targetUser->id}");

    $response->assertStatus(200);
    $this->assertSoftDeleted('users', ['id' => $targetUser->id]);
    $this->assertDatabaseHas('teachers', ['id' => $teacher->id, 'deleted_at' => null]);
});

test('delete user with cascade_teacher deletes teacher too', function () {
    $admin = createAdminWithUserPermissions();
    $targetUser = User::factory()->create();
    $teacher = Teacher::factory()->create([
        'school_id' => $this->school->id,
        'user_id' => $targetUser->id,
    ]);

    $response = $this->actingAs($admin)->deleteJson("/api/v1/users/{$targetUser->id}?cascade_teacher=true");

    $response->assertStatus(200);
    $this->assertSoftDeleted('users', ['id' => $targetUser->id]);
    $this->assertSoftDeleted('teachers', ['id' => $teacher->id]);
});

test('delete user revokes tokens regardless of cascade', function () {
    $admin = createAdminWithUserPermissions();
    $targetUser = User::factory()->create();
    $targetUser->createToken('test-token');

    expect($targetUser->tokens()->count())->toBe(1);

    $response = $this->actingAs($admin)->deleteJson("/api/v1/users/{$targetUser->id}");

    $response->assertStatus(200);
    expect($targetUser->tokens()->count())->toBe(0);
});

test('cannot delete self is preserved', function () {
    $admin = createAdminWithUserPermissions();

    $response = $this->actingAs($admin)->deleteJson("/api/v1/users/{$admin->id}");

    $response->assertStatus(403)
        ->assertJsonPath('message', 'Cannot delete your own account');
});

// --- Check Email ---

test('check-email returns soft-deleted user info', function () {
    $admin = createAdminWithUserPermissions();
    $deletedUser = User::factory()->create(['email' => 'deleted@example.com']);
    $deletedUser->delete();

    $response = $this->actingAs($admin)->postJson('/api/v1/users/check-email', [
        'email' => 'deleted@example.com',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $deletedUser->id)
        ->assertJsonPath('data.name', $deletedUser->name)
        ->assertJsonPath('data.email', 'deleted@example.com');

    expect($response->json('data.deleted_at'))->not->toBeNull();
});

test('check-email returns null when no match', function () {
    $admin = createAdminWithUserPermissions();

    $response = $this->actingAs($admin)->postJson('/api/v1/users/check-email', [
        'email' => 'nonexistent@example.com',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data', null);
});

test('create user with email of soft-deleted user succeeds', function () {
    $admin = createAdminWithUserPermissions();
    $deletedUser = User::factory()->create(['email' => 'reuse@example.com']);
    $deletedUser->delete();

    $response = $this->actingAs($admin)->postJson('/api/v1/users', [
        'name' => 'New User',
        'email' => 'reuse@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.email', 'reuse@example.com');
});

test('grant teacher access with email of soft-deleted user succeeds', function () {
    $admin = createAdminWithUserPermissions();
    $deletedUser = User::factory()->create(['email' => 'teacher-reuse@example.com']);
    $deletedUser->delete();

    $teacher = Teacher::factory()->create([
        'school_id' => $this->school->id,
        'user_id' => null,
    ]);

    $response = $this->actingAs($admin)->postJson("/api/v1/teachers/{$teacher->id}/grant-access", [
        'email' => 'teacher-reuse@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true);
});

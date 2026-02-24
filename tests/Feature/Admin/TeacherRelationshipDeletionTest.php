<?php

use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $permissions = [
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

function createTeacherAdmin(): User
{
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'pengurus_pesantren']);
    $role->syncPermissions([
        'view-teachers',
        'create-teachers',
        'edit-teachers',
        'delete-teachers',
    ]);
    $user->assignRole($role);

    return $user;
}

// --- Relationships Endpoint ---

test('get teacher relationships returns user data when linked', function () {
    $admin = createTeacherAdmin();
    $linkedUser = User::factory()->create();
    $linkedUser->assignRole(Role::firstOrCreate(['name' => 'ustadz']));
    $teacher = Teacher::factory()->create([
        'school_id' => $this->school->id,
        'user_id' => $linkedUser->id,
    ]);

    $response = $this->actingAs($admin)->getJson("/api/v1/teachers/{$teacher->id}/relationships");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.id', $linkedUser->id)
        ->assertJsonPath('data.user.name', $linkedUser->name)
        ->assertJsonPath('data.user.email', $linkedUser->email)
        ->assertJsonPath('data.user.is_active', true)
        ->assertJsonPath('data.user.roles.0', 'ustadz');
});

test('get teacher relationships returns null user when not linked', function () {
    $admin = createTeacherAdmin();
    $teacher = Teacher::factory()->create([
        'school_id' => $this->school->id,
        'user_id' => null,
    ]);

    $response = $this->actingAs($admin)->getJson("/api/v1/teachers/{$teacher->id}/relationships");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user', null);
});

// --- Cascade Delete ---

test('delete teacher without cascade keeps user', function () {
    $admin = createTeacherAdmin();
    $linkedUser = User::factory()->create();
    $teacher = Teacher::factory()->create([
        'school_id' => $this->school->id,
        'user_id' => $linkedUser->id,
    ]);

    $response = $this->actingAs($admin)->deleteJson("/api/v1/teachers/{$teacher->id}");

    $response->assertStatus(200);
    $this->assertSoftDeleted('teachers', ['id' => $teacher->id]);
    $this->assertDatabaseHas('users', ['id' => $linkedUser->id, 'deleted_at' => null]);
});

test('delete teacher with cascade_user deletes user too', function () {
    $admin = createTeacherAdmin();
    $linkedUser = User::factory()->create();
    $teacher = Teacher::factory()->create([
        'school_id' => $this->school->id,
        'user_id' => $linkedUser->id,
    ]);

    $response = $this->actingAs($admin)->deleteJson("/api/v1/teachers/{$teacher->id}?cascade_user=true");

    $response->assertStatus(200);
    $this->assertSoftDeleted('teachers', ['id' => $teacher->id]);
    $this->assertSoftDeleted('users', ['id' => $linkedUser->id]);
});

test('delete teacher with cascade_user revokes user tokens', function () {
    $admin = createTeacherAdmin();
    $linkedUser = User::factory()->create();
    $linkedUser->createToken('test-token');
    $teacher = Teacher::factory()->create([
        'school_id' => $this->school->id,
        'user_id' => $linkedUser->id,
    ]);

    expect($linkedUser->tokens()->count())->toBe(1);

    $response = $this->actingAs($admin)->deleteJson("/api/v1/teachers/{$teacher->id}?cascade_user=true");

    $response->assertStatus(200);
    expect($linkedUser->tokens()->count())->toBe(0);
});

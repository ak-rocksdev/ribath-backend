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

function createUserWithTeacherPermissions(): User
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

function createSuperAdmin(): User
{
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'super_admin']);
    $user->assignRole($role);

    return $user;
}

// --- Auth / Authorization ---

test('unauthenticated user cannot access teacher endpoints', function () {
    $response = $this->getJson('/api/v1/teachers');

    $response->assertStatus(401);
});

test('user without permission cannot access teacher endpoints', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/teachers');

    $response->assertStatus(403);
});

test('super admin can access teacher endpoints', function () {
    $superAdmin = createSuperAdmin();

    $response = $this->actingAs($superAdmin)->getJson('/api/v1/teachers');

    $response->assertStatus(200);
});

test('pengurus pesantren with permissions can access teacher endpoints', function () {
    $user = createUserWithTeacherPermissions();

    $response = $this->actingAs($user)->getJson('/api/v1/teachers');

    $response->assertStatus(200);
});

// --- List ---

test('list teachers returns paginated results with meta', function () {
    $user = createUserWithTeacherPermissions();
    Teacher::factory()->count(3)->create(['school_id' => $this->school->id]);

    $response = $this->actingAs($user)->getJson('/api/v1/teachers');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ])
        ->assertJsonPath('meta.total', 3);
});

test('list teachers can search by full name', function () {
    $user = createUserWithTeacherPermissions();
    Teacher::factory()->create(['school_id' => $this->school->id, 'full_name' => 'Ahmad Fauzi']);
    Teacher::factory()->create(['school_id' => $this->school->id, 'full_name' => 'Budi Santoso']);

    $response = $this->actingAs($user)->getJson('/api/v1/teachers?search=ahmad');

    $response->assertStatus(200)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.full_name', 'Ahmad Fauzi');
});

test('list teachers can search by code', function () {
    $user = createUserWithTeacherPermissions();
    Teacher::factory()->create([
        'school_id' => $this->school->id,
        'code' => 'ZXQ',
        'full_name' => 'John Doe',
        'email' => 'john@example.com',
    ]);
    Teacher::factory()->create([
        'school_id' => $this->school->id,
        'code' => 'BS',
        'full_name' => 'Jane Smith',
        'email' => 'jane@example.com',
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/teachers?search=zxq');

    $response->assertStatus(200)
        ->assertJsonPath('meta.total', 1);
});

test('list teachers can filter by status', function () {
    $user = createUserWithTeacherPermissions();
    Teacher::factory()->create(['school_id' => $this->school->id, 'status' => 'active']);
    Teacher::factory()->onLeave()->create(['school_id' => $this->school->id]);

    $response = $this->actingAs($user)->getJson('/api/v1/teachers?status=on_leave');

    $response->assertStatus(200)
        ->assertJsonPath('meta.total', 1);
});

// --- Create ---

test('create teacher with valid data returns 201', function () {
    $user = createUserWithTeacherPermissions();

    $response = $this->actingAs($user)->postJson('/api/v1/teachers', [
        'school_id' => $this->school->id,
        'code' => 'AH',
        'full_name' => 'Ahmad Husain',
        'status' => 'active',
        'email' => 'ahmad@example.com',
        'phone' => '081234567890',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.full_name', 'Ahmad Husain')
        ->assertJsonPath('data.code', 'AH')
        ->assertJsonPath('data.school.id', $this->school->id);
});

test('create teacher with missing required fields returns 422', function () {
    $user = createUserWithTeacherPermissions();

    $response = $this->actingAs($user)->postJson('/api/v1/teachers', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['school_id', 'code', 'full_name', 'status']);
});

test('create teacher with invalid code format returns 422', function () {
    $user = createUserWithTeacherPermissions();

    $response = $this->actingAs($user)->postJson('/api/v1/teachers', [
        'school_id' => $this->school->id,
        'code' => 'ah',
        'full_name' => 'Ahmad Husain',
        'status' => 'active',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['code']);
});

test('create teacher with duplicate code in same school returns 422', function () {
    $user = createUserWithTeacherPermissions();
    Teacher::factory()->create(['school_id' => $this->school->id, 'code' => 'AH']);

    $response = $this->actingAs($user)->postJson('/api/v1/teachers', [
        'school_id' => $this->school->id,
        'code' => 'AH',
        'full_name' => 'Another Teacher',
        'status' => 'active',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['code']);
});

test('create teacher with same code in different school returns 201', function () {
    $user = createUserWithTeacherPermissions();
    $otherSchool = School::factory()->create();
    Teacher::factory()->create(['school_id' => $otherSchool->id, 'code' => 'AH']);

    $response = $this->actingAs($user)->postJson('/api/v1/teachers', [
        'school_id' => $this->school->id,
        'code' => 'AH',
        'full_name' => 'Ahmad in Different School',
        'status' => 'active',
    ]);

    $response->assertStatus(201);
});

// --- Show ---

test('show teacher loads school and user relations', function () {
    $user = createUserWithTeacherPermissions();
    $teacher = Teacher::factory()->create(['school_id' => $this->school->id]);

    $response = $this->actingAs($user)->getJson("/api/v1/teachers/{$teacher->id}");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $teacher->id)
        ->assertJsonStructure([
            'data' => ['id', 'full_name', 'code', 'school'],
        ]);
});

// --- Update ---

test('update teacher with partial data returns 200', function () {
    $user = createUserWithTeacherPermissions();
    $teacher = Teacher::factory()->create(['school_id' => $this->school->id, 'full_name' => 'Old Name']);

    $response = $this->actingAs($user)->putJson("/api/v1/teachers/{$teacher->id}", [
        'full_name' => 'New Name',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.full_name', 'New Name');
});

test('update teacher with duplicate code returns 422', function () {
    $user = createUserWithTeacherPermissions();
    Teacher::factory()->create(['school_id' => $this->school->id, 'code' => 'AH']);
    $teacher = Teacher::factory()->create(['school_id' => $this->school->id, 'code' => 'BS']);

    $response = $this->actingAs($user)->putJson("/api/v1/teachers/{$teacher->id}", [
        'code' => 'AH',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['code']);
});

test('update teacher keeping same code returns 200', function () {
    $user = createUserWithTeacherPermissions();
    $teacher = Teacher::factory()->create(['school_id' => $this->school->id, 'code' => 'AH']);

    $response = $this->actingAs($user)->putJson("/api/v1/teachers/{$teacher->id}", [
        'code' => 'AH',
        'full_name' => 'Updated Name',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.full_name', 'Updated Name');
});

// --- Delete ---

test('delete teacher soft deletes the record', function () {
    $user = createUserWithTeacherPermissions();
    $teacher = Teacher::factory()->create(['school_id' => $this->school->id]);

    $response = $this->actingAs($user)->deleteJson("/api/v1/teachers/{$teacher->id}");

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    $this->assertSoftDeleted('teachers', ['id' => $teacher->id]);
});

// --- Status ---

test('update teacher status to on_leave returns 200', function () {
    $user = createUserWithTeacherPermissions();
    $teacher = Teacher::factory()->create(['school_id' => $this->school->id, 'status' => 'active']);

    $response = $this->actingAs($user)->patchJson("/api/v1/teachers/{$teacher->id}/status", [
        'status' => 'on_leave',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'on_leave');
});

test('update teacher status to inactive returns 200', function () {
    $user = createUserWithTeacherPermissions();
    $teacher = Teacher::factory()->create(['school_id' => $this->school->id, 'status' => 'active']);

    $response = $this->actingAs($user)->patchJson("/api/v1/teachers/{$teacher->id}/status", [
        'status' => 'inactive',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'inactive');
});

test('update teacher with invalid status returns 422', function () {
    $user = createUserWithTeacherPermissions();
    $teacher = Teacher::factory()->create(['school_id' => $this->school->id]);

    $response = $this->actingAs($user)->patchJson("/api/v1/teachers/{$teacher->id}/status", [
        'status' => 'invalid_status',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

// --- Grant Access ---

test('grant access creates user with ustadz role and links to teacher', function () {
    $user = createUserWithTeacherPermissions();
    $teacher = Teacher::factory()->create(['school_id' => $this->school->id, 'user_id' => null]);

    $response = $this->actingAs($user)->postJson("/api/v1/teachers/{$teacher->id}/grant-access", [
        'email' => 'teacher@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.credentials.email', 'teacher@example.com')
        ->assertJsonPath('data.credentials.password', 'password123');

    $teacher->refresh();
    expect($teacher->user_id)->not->toBeNull();

    $teacherUser = User::find($teacher->user_id);
    expect($teacherUser->hasRole('ustadz'))->toBeTrue()
        ->and($teacherUser->school_id)->toBe($this->school->id);
});

test('grant access to teacher who already has access returns 422', function () {
    $user = createUserWithTeacherPermissions();
    $existingUser = User::factory()->create();
    $teacher = Teacher::factory()->create([
        'school_id' => $this->school->id,
        'user_id' => $existingUser->id,
    ]);

    $response = $this->actingAs($user)->postJson("/api/v1/teachers/{$teacher->id}/grant-access", [
        'email' => 'new@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Teacher already has system access');
});

test('grant access with duplicate email returns 422', function () {
    $user = createUserWithTeacherPermissions();
    User::factory()->create(['email' => 'existing@example.com']);
    $teacher = Teacher::factory()->create(['school_id' => $this->school->id, 'user_id' => null]);

    $response = $this->actingAs($user)->postJson("/api/v1/teachers/{$teacher->id}/grant-access", [
        'email' => 'existing@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('grant access with short password returns 422', function () {
    $user = createUserWithTeacherPermissions();
    $teacher = Teacher::factory()->create(['school_id' => $this->school->id, 'user_id' => null]);

    $response = $this->actingAs($user)->postJson("/api/v1/teachers/{$teacher->id}/grant-access", [
        'email' => 'teacher@example.com',
        'password' => 'short',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

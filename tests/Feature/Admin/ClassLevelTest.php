<?php

use App\Models\ClassLevel;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\ClassLevelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;

function seedClassLevelsForTest(): void
{
    (new SchoolSeeder)->run();
    (new ClassLevelSeeder)->run();
}

// ── Existing read-only index tests ──────────────────────────────────────

test('unauthenticated user cannot access class levels', function () {
    $response = $this->getJson('/api/v1/class-levels');

    $response->assertUnauthorized();
});

test('authenticated user can list class levels', function () {
    (new RolePermissionSeeder)->run();
    seedClassLevelsForTest();

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $response = $this->actingAs($user)
        ->getJson('/api/v1/class-levels');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(11, 'data');
});

test('class levels are ordered by sort_order', function () {
    (new RolePermissionSeeder)->run();
    seedClassLevelsForTest();

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $response = $this->actingAs($user)
        ->getJson('/api/v1/class-levels');

    $response->assertOk();

    $data = $response->json('data');
    $sortOrders = array_column($data, 'sort_order');
    $sortedOrders = $sortOrders;
    sort($sortedOrders);

    expect($sortOrders)->toBe($sortedOrders);
    expect($data[0]['slug'])->toBe('tamhidi');
    expect($data[10]['slug'])->toBe('takhassus_3');
});

test('inactive class levels are excluded from list', function () {
    (new RolePermissionSeeder)->run();
    seedClassLevelsForTest();

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    // Deactivate one class level
    ClassLevel::where('slug', 'tamhidi')->update(['is_active' => false]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/class-levels');

    $response->assertOk()
        ->assertJsonCount(10, 'data');

    $slugs = array_column($response->json('data'), 'slug');
    expect($slugs)->not->toContain('tamhidi');
});

test('class level response has correct structure', function () {
    (new RolePermissionSeeder)->run();
    seedClassLevelsForTest();

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $response = $this->actingAs($user)
        ->getJson('/api/v1/class-levels');

    $response->assertOk()
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'slug', 'label', 'category', 'sort_order'],
            ],
            'message',
        ]);

    $firstItem = $response->json('data.0');
    expect($firstItem)->toHaveKeys(['id', 'slug', 'label', 'category', 'sort_order']);
    expect($firstItem)->not->toHaveKey('school_id');
    expect($firstItem)->not->toHaveKey('is_active');
});

// ── Admin index tests ───────────────────────────────────────────────────

test('admin index returns all class levels including inactive with student counts', function () {
    (new RolePermissionSeeder)->run();
    seedClassLevelsForTest();

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    // Deactivate one class level
    ClassLevel::where('slug', 'tamhidi')->update(['is_active' => false]);

    // Create a student with a class level
    $school = School::where('is_active', true)->first();
    Student::factory()->create([
        'school_id' => $school->id,
        'class_level' => 'ibtida_1',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/class-levels/admin');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(11, 'data');

    $data = $response->json('data');

    // Find ibtida_1 and check student_count
    $ibtida1 = collect($data)->firstWhere('slug', 'ibtida_1');
    expect($ibtida1['student_count'])->toBe(1);
    expect($ibtida1['is_active'])->toBeTrue();

    // Inactive item should be included
    $tamhidi = collect($data)->firstWhere('slug', 'tamhidi');
    expect($tamhidi['is_active'])->toBeFalse();
});

test('admin index requires manage-class-levels permission', function () {
    (new RolePermissionSeeder)->run();
    seedClassLevelsForTest();

    $user = User::factory()->create();
    // No role assigned = no permission

    $response = $this->actingAs($user)
        ->getJson('/api/v1/class-levels/admin');

    $response->assertForbidden();
});

// ── Create tests ────────────────────────────────────────────────────────

test('can create a class level', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $response = $this->actingAs($user)
        ->postJson('/api/v1/class-levels', [
            'slug' => 'mutaqaddim',
            'label' => 'Mutaqaddim',
            'category' => 'akademik',
        ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.slug', 'mutaqaddim')
        ->assertJsonPath('data.label', 'Mutaqaddim')
        ->assertJsonPath('data.category', 'akademik');

    $this->assertDatabaseHas('class_levels', [
        'slug' => 'mutaqaddim',
        'label' => 'Mutaqaddim',
    ]);
});

test('create class level fails with duplicate slug', function () {
    (new RolePermissionSeeder)->run();
    seedClassLevelsForTest();

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $response = $this->actingAs($user)
        ->postJson('/api/v1/class-levels', [
            'slug' => 'tamhidi',
            'label' => 'Tamhidi Duplicate',
            'category' => 'akademik',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['slug']);
});

test('create class level fails with invalid data', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $response = $this->actingAs($user)
        ->postJson('/api/v1/class-levels', [
            'slug' => 'INVALID SLUG!',
            'label' => '',
            'category' => 'invalid_category',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['slug', 'label', 'category']);
});

test('create class level requires permission', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/class-levels', [
            'slug' => 'test_level',
            'label' => 'Test Level',
            'category' => 'akademik',
        ]);

    $response->assertForbidden();
});

// ── Update tests ────────────────────────────────────────────────────────

test('can update a class level', function () {
    (new RolePermissionSeeder)->run();
    seedClassLevelsForTest();

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $classLevel = ClassLevel::where('slug', 'tamhidi')->first();

    $response = $this->actingAs($user)
        ->putJson("/api/v1/class-levels/{$classLevel->id}", [
            'label' => 'Tamhidi Updated',
            'category' => 'tahfidz',
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.label', 'Tamhidi Updated')
        ->assertJsonPath('data.category', 'tahfidz');
});

test('can partial update a class level', function () {
    (new RolePermissionSeeder)->run();
    seedClassLevelsForTest();

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $classLevel = ClassLevel::where('slug', 'tamhidi')->first();

    $response = $this->actingAs($user)
        ->putJson("/api/v1/class-levels/{$classLevel->id}", [
            'label' => 'Just Label Change',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.label', 'Just Label Change')
        ->assertJsonPath('data.slug', 'tamhidi');
});

// ── Delete tests ────────────────────────────────────────────────────────

test('can delete a class level with no students', function () {
    (new RolePermissionSeeder)->run();
    seedClassLevelsForTest();

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $classLevel = ClassLevel::where('slug', 'takhassus_3')->first();

    $response = $this->actingAs($user)
        ->deleteJson("/api/v1/class-levels/{$classLevel->id}");

    $response->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('class_levels', ['id' => $classLevel->id]);
});

test('cannot delete a class level that has students', function () {
    (new RolePermissionSeeder)->run();
    seedClassLevelsForTest();

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $school = School::where('is_active', true)->first();
    Student::factory()->create([
        'school_id' => $school->id,
        'class_level' => 'tamhidi',
        'status' => 'active',
    ]);

    $classLevel = ClassLevel::where('slug', 'tamhidi')->first();

    $response = $this->actingAs($user)
        ->deleteJson("/api/v1/class-levels/{$classLevel->id}");

    $response->assertUnprocessable()
        ->assertJsonPath('success', false);

    $this->assertDatabaseHas('class_levels', ['id' => $classLevel->id]);
});

// ── Toggle status tests ─────────────────────────────────────────────────

test('can toggle class level status', function () {
    (new RolePermissionSeeder)->run();
    seedClassLevelsForTest();

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $classLevel = ClassLevel::where('slug', 'tamhidi')->first();
    expect($classLevel->is_active)->toBeTrue();

    $response = $this->actingAs($user)
        ->patchJson("/api/v1/class-levels/{$classLevel->id}/status", [
            'is_active' => false,
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.is_active', false);

    $this->assertDatabaseHas('class_levels', [
        'id' => $classLevel->id,
        'is_active' => false,
    ]);
});

// ── Reorder tests ───────────────────────────────────────────────────────

test('can reorder class levels', function () {
    (new RolePermissionSeeder)->run();
    seedClassLevelsForTest();

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $classLevels = ClassLevel::orderBy('sort_order')->get();
    // Reverse the order
    $reversedIds = $classLevels->reverse()->pluck('id')->values()->toArray();

    $response = $this->actingAs($user)
        ->patchJson('/api/v1/class-levels/reorder', [
            'ordered_ids' => $reversedIds,
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    // Verify first item now has sort_order 1
    $firstClassLevel = ClassLevel::find($reversedIds[0]);
    expect($firstClassLevel->sort_order)->toBe(1);

    // Verify last item now has sort_order = count
    $lastClassLevel = ClassLevel::find($reversedIds[count($reversedIds) - 1]);
    expect($lastClassLevel->sort_order)->toBe(count($reversedIds));
});

test('reorder requires valid class level ids', function () {
    (new RolePermissionSeeder)->run();
    seedClassLevelsForTest();

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $response = $this->actingAs($user)
        ->patchJson('/api/v1/class-levels/reorder', [
            'ordered_ids' => ['non-existent-id'],
        ]);

    $response->assertUnprocessable();
});

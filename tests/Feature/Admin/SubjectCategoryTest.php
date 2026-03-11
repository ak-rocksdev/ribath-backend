<?php

use App\Models\School;
use App\Models\SubjectCategory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;
use Database\Seeders\SubjectCategorySeeder;

function createSubjectCategoryTestUser(): array
{
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $school = School::where('is_active', true)->first();

    return [$user, $school];
}

// ── Index tests ────────────────────────────────────────────────────────

test('unauthenticated user cannot access subject categories', function () {
    $response = $this->getJson('/api/v1/subject-categories');

    $response->assertUnauthorized();
});

test('user without permission cannot access subject categories', function () {
    (new RolePermissionSeeder)->run();

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/subject-categories');

    $response->assertForbidden();
});

test('authenticated user with permission can list subject categories', function () {
    [$user, $school] = createSubjectCategoryTestUser();

    SubjectCategory::factory()->count(3)->create(['school_id' => $school->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/subject-categories');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(3, 'data');
});

test('subject categories are ordered by sort_order ascending', function () {
    [$user, $school] = createSubjectCategoryTestUser();

    SubjectCategory::factory()->create(['school_id' => $school->id, 'slug' => 'fiqh', 'sort_order' => 3]);
    SubjectCategory::factory()->create(['school_id' => $school->id, 'slug' => 'nahwu', 'sort_order' => 1]);
    SubjectCategory::factory()->create(['school_id' => $school->id, 'slug' => 'shorof', 'sort_order' => 2]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/subject-categories');

    $response->assertOk();

    $slugs = array_column($response->json('data'), 'slug');
    expect($slugs)->toBe(['nahwu', 'shorof', 'fiqh']);
});

test('subject category response has correct structure', function () {
    [$user, $school] = createSubjectCategoryTestUser();

    SubjectCategory::factory()->create(['school_id' => $school->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/subject-categories');

    $response->assertOk()
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'school_id', 'slug', 'name', 'color', 'description', 'sort_order', 'is_active'],
            ],
            'message',
        ]);
});

test('subject category id is integer not uuid', function () {
    [$user, $school] = createSubjectCategoryTestUser();

    $category = SubjectCategory::factory()->create(['school_id' => $school->id]);

    expect($category->id)->toBeInt();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/subject-categories');

    $response->assertOk();

    $firstId = $response->json('data.0.id');
    expect($firstId)->toBeInt();
});

// ── Show tests ─────────────────────────────────────────────────────────

test('can show a single subject category', function () {
    [$user, $school] = createSubjectCategoryTestUser();

    $category = SubjectCategory::factory()->create([
        'school_id' => $school->id,
        'slug' => 'nahwu',
        'name' => 'Nahwu',
        'color' => 'bg-blue-100',
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/subject-categories/{$category->id}");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.slug', 'nahwu')
        ->assertJsonPath('data.name', 'Nahwu')
        ->assertJsonPath('data.color', 'bg-blue-100');
});

// ── Create tests ───────────────────────────────────────────────────────

test('can create a subject category', function () {
    [$user, $school] = createSubjectCategoryTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/subject-categories', [
            'slug' => 'nahwu',
            'name' => 'Nahwu',
            'color' => 'bg-blue-100',
            'description' => 'Arabic grammar',
            'sort_order' => 1,
        ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.slug', 'nahwu')
        ->assertJsonPath('data.name', 'Nahwu');

    $this->assertDatabaseHas('subject_categories', [
        'slug' => 'nahwu',
        'school_id' => $school->id,
        'is_active' => true,
    ]);
});

test('create subject category auto-assigns school_id', function () {
    [$user, $school] = createSubjectCategoryTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/subject-categories', [
            'slug' => 'fiqh',
            'name' => 'Fiqh',
        ]);

    $response->assertCreated();

    $this->assertDatabaseHas('subject_categories', [
        'slug' => 'fiqh',
        'school_id' => $school->id,
    ]);
});

test('create subject category requires permission', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/subject-categories', [
            'slug' => 'nahwu',
            'name' => 'Nahwu',
        ]);

    $response->assertForbidden();
});

test('create subject category fails with invalid slug format', function () {
    [$user] = createSubjectCategoryTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/subject-categories', [
            'slug' => 'Invalid Slug!',
            'name' => 'Test',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['slug']);
});

test('create subject category fails with missing required fields', function () {
    [$user] = createSubjectCategoryTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/subject-categories', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['slug', 'name']);
});

test('create subject category fails with duplicate slug for same school', function () {
    [$user, $school] = createSubjectCategoryTestUser();

    SubjectCategory::factory()->create([
        'school_id' => $school->id,
        'slug' => 'nahwu',
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/subject-categories', [
            'slug' => 'nahwu',
            'name' => 'Nahwu Duplicate',
        ]);

    expect($response->status())->toBe(422);
});

test('create subject category allows same slug for different school', function () {
    [$user, $school] = createSubjectCategoryTestUser();

    $otherSchool = School::factory()->create();
    SubjectCategory::factory()->create([
        'school_id' => $otherSchool->id,
        'slug' => 'nahwu',
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/subject-categories', [
            'slug' => 'nahwu',
            'name' => 'Nahwu',
        ]);

    $response->assertCreated();
});

test('create subject category with default color', function () {
    [$user, $school] = createSubjectCategoryTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/subject-categories', [
            'slug' => 'test-cat',
            'name' => 'Test Category',
        ]);

    $response->assertCreated();

    $this->assertDatabaseHas('subject_categories', [
        'slug' => 'test-cat',
        'school_id' => $school->id,
        'color' => 'bg-gray-100',
    ]);
});

// ── Update tests ───────────────────────────────────────────────────────

test('can update a subject category', function () {
    [$user, $school] = createSubjectCategoryTestUser();

    $category = SubjectCategory::factory()->create([
        'school_id' => $school->id,
        'slug' => 'nahwu',
        'name' => 'Nahwu',
    ]);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/subject-categories/{$category->id}", [
            'name' => 'Nahwu (Grammar)',
            'color' => 'bg-blue-200',
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Nahwu (Grammar)')
        ->assertJsonPath('data.color', 'bg-blue-200');
});

test('can partial update a subject category', function () {
    [$user, $school] = createSubjectCategoryTestUser();

    $category = SubjectCategory::factory()->create([
        'school_id' => $school->id,
        'slug' => 'nahwu',
        'name' => 'Nahwu',
        'sort_order' => 1,
    ]);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/subject-categories/{$category->id}", [
            'sort_order' => 5,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.sort_order', 5)
        ->assertJsonPath('data.slug', 'nahwu');
});

test('update subject category fails with duplicate slug for same school', function () {
    [$user, $school] = createSubjectCategoryTestUser();

    SubjectCategory::factory()->create([
        'school_id' => $school->id,
        'slug' => 'nahwu',
    ]);

    $category = SubjectCategory::factory()->create([
        'school_id' => $school->id,
        'slug' => 'fiqh',
    ]);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/subject-categories/{$category->id}", [
            'slug' => 'nahwu',
        ]);

    expect($response->status())->toBe(422);
});

test('update subject category allows keeping same slug', function () {
    [$user, $school] = createSubjectCategoryTestUser();

    $category = SubjectCategory::factory()->create([
        'school_id' => $school->id,
        'slug' => 'nahwu',
    ]);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/subject-categories/{$category->id}", [
            'slug' => 'nahwu',
            'name' => 'Updated Name',
        ]);

    $response->assertOk();
});

// ── Delete tests ───────────────────────────────────────────────────────

test('can delete a subject category without dependents', function () {
    [$user, $school] = createSubjectCategoryTestUser();

    $category = SubjectCategory::factory()->create([
        'school_id' => $school->id,
    ]);

    $response = $this->actingAs($user)
        ->deleteJson("/api/v1/subject-categories/{$category->id}");

    $response->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('subject_categories', ['id' => $category->id]);
});

test('delete subject category requires permission', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    $school = School::where('is_active', true)->first();
    $user = User::factory()->create();

    $category = SubjectCategory::factory()->create([
        'school_id' => $school->id,
    ]);

    $response = $this->actingAs($user)
        ->deleteJson("/api/v1/subject-categories/{$category->id}");

    $response->assertForbidden();
});

// Note: Test for "cannot delete with subject books" is skipped because
// the subject_books table doesn't exist yet (Task 5).

// ── Seeder tests ──────────────────────────────────────────────────────

test('seeder creates expected subject categories', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    (new SubjectCategorySeeder)->run();

    $school = School::where('is_active', true)->first();

    $categories = SubjectCategory::where('school_id', $school->id)->orderBy('sort_order')->get();

    expect($categories)->toHaveCount(10);
    expect($categories[0]->slug)->toBe('nahwu');
    expect($categories[0]->color)->toBe('bg-blue-100');
    expect($categories[1]->slug)->toBe('shorof');
    expect($categories[9]->slug)->toBe('lughah');
    expect($categories[9]->sort_order)->toBe(10);
});

test('seeder is idempotent', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    (new SubjectCategorySeeder)->run();
    (new SubjectCategorySeeder)->run();

    $school = School::where('is_active', true)->first();

    $categories = SubjectCategory::where('school_id', $school->id)->get();

    expect($categories)->toHaveCount(10);
});

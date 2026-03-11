<?php

use App\Models\ClassLevel;
use App\Models\School;
use App\Models\SubjectBook;
use App\Models\SubjectCategory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;

function createSubjectBookTestUser(): array
{
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $school = School::where('is_active', true)->first();

    // Create a subject category for the school
    $category = SubjectCategory::factory()->create(['school_id' => $school->id]);

    // Create class levels for the school
    $classLevel = ClassLevel::factory()->create([
        'school_id' => $school->id,
        'slug' => 'tamhidi',
    ]);

    return [$user, $school, $category, $classLevel];
}

// ── Index tests ────────────────────────────────────────────────────────

test('unauthenticated user cannot access subject books', function () {
    $response = $this->getJson('/api/v1/subject-books');

    $response->assertUnauthorized();
});

test('user without permission cannot access subject books', function () {
    (new RolePermissionSeeder)->run();

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/subject-books');

    $response->assertForbidden();
});

test('authenticated user with permission can list subject books', function () {
    [$user, $school, $category] = createSubjectBookTestUser();

    SubjectBook::factory()->count(3)->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/subject-books');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(3, 'data');
});

test('subject books are paginated', function () {
    [$user, $school, $category] = createSubjectBookTestUser();

    SubjectBook::factory()->count(20)->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/subject-books?per_page=5');

    $response->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.per_page', 5)
        ->assertJsonPath('meta.total', 20);
});

test('subject book response has correct structure', function () {
    [$user, $school, $category] = createSubjectBookTestUser();

    SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/subject-books');

    $response->assertOk()
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => [
                    'id', 'school_id', 'subject_category_id', 'title',
                    'class_levels', 'semesters', 'sessions_per_week',
                    'description', 'is_active',
                    'subject_category' => ['id', 'name', 'color'],
                ],
            ],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            'message',
        ]);
});

test('subject books eager load subjectCategory', function () {
    [$user, $school, $category] = createSubjectBookTestUser();

    SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/subject-books');

    $response->assertOk();

    $firstBook = $response->json('data.0');
    expect($firstBook['subject_category'])->not->toBeNull();
    expect($firstBook['subject_category']['id'])->toBe($category->id);
    expect($firstBook['subject_category']['name'])->toBe($category->name);
});

// ── Filter tests ──────────────────────────────────────────────────────

test('can filter subject books by subject_category_id', function () {
    [$user, $school, $category] = createSubjectBookTestUser();

    $otherCategory = SubjectCategory::factory()->create(['school_id' => $school->id]);

    SubjectBook::factory()->count(2)->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
    ]);
    SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $otherCategory->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/subject-books?subject_category_id={$category->id}");

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('can filter subject books by is_active', function () {
    [$user, $school, $category] = createSubjectBookTestUser();

    SubjectBook::factory()->count(2)->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
        'is_active' => true,
    ]);
    SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
        'is_active' => false,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/subject-books?is_active=true');

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('can search subject books by title', function () {
    [$user, $school, $category] = createSubjectBookTestUser();

    SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
        'title' => 'Jurumiyyah',
    ]);
    SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
        'title' => 'Safinatun Najah',
    ]);
    SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
        'title' => 'Alfiyyah Ibn Malik',
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/subject-books?search=jurumiyyah');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Jurumiyyah');
});

// ── Show tests ─────────────────────────────────────────────────────────

test('can show a single subject book', function () {
    [$user, $school, $category] = createSubjectBookTestUser();

    $book = SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
        'title' => 'Jurumiyyah',
        'class_levels' => ['tamhidi'],
        'semesters' => [1, 2],
        'sessions_per_week' => 3,
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/subject-books/{$book->id}");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.title', 'Jurumiyyah')
        ->assertJsonPath('data.class_levels', ['tamhidi'])
        ->assertJsonPath('data.semesters', [1, 2])
        ->assertJsonPath('data.sessions_per_week', 3);
});

test('show subject book includes subjectCategory', function () {
    [$user, $school, $category] = createSubjectBookTestUser();

    $book = SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/subject-books/{$book->id}");

    $response->assertOk()
        ->assertJsonPath('data.subject_category.id', $category->id);
});

// ── Create tests ───────────────────────────────────────────────────────

test('can create a subject book', function () {
    [$user, $school, $category, $classLevel] = createSubjectBookTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/subject-books', [
            'title' => 'Jurumiyyah',
            'subject_category_id' => $category->id,
            'class_levels' => ['tamhidi'],
            'semesters' => [1],
            'sessions_per_week' => 3,
            'description' => 'Basic Arabic grammar',
        ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.title', 'Jurumiyyah')
        ->assertJsonPath('data.class_levels', ['tamhidi'])
        ->assertJsonPath('data.semesters', [1])
        ->assertJsonPath('data.sessions_per_week', 3);

    $this->assertDatabaseHas('subject_books', [
        'title' => 'Jurumiyyah',
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
        'is_active' => true,
    ]);
});

test('create subject book auto-assigns school_id', function () {
    [$user, $school, $category, $classLevel] = createSubjectBookTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/subject-books', [
            'title' => 'Safinatun Najah',
            'subject_category_id' => $category->id,
            'class_levels' => ['tamhidi'],
            'semesters' => [1],
        ]);

    $response->assertCreated();

    $this->assertDatabaseHas('subject_books', [
        'title' => 'Safinatun Najah',
        'school_id' => $school->id,
    ]);
});

test('create subject book includes subjectCategory in response', function () {
    [$user, $school, $category, $classLevel] = createSubjectBookTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/subject-books', [
            'title' => 'Test Book',
            'subject_category_id' => $category->id,
            'class_levels' => ['tamhidi'],
            'semesters' => [1],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.subject_category.id', $category->id)
        ->assertJsonPath('data.subject_category.name', $category->name);
});

test('create subject book requires permission', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/subject-books', [
            'title' => 'Jurumiyyah',
            'subject_category_id' => 1,
            'class_levels' => ['tamhidi'],
            'semesters' => [1],
        ]);

    $response->assertForbidden();
});

test('create subject book fails with missing required fields', function () {
    [$user] = createSubjectBookTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/subject-books', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['title', 'subject_category_id', 'class_levels', 'semesters']);
});

test('create subject book fails with invalid subject_category_id', function () {
    [$user, $school, $category, $classLevel] = createSubjectBookTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/subject-books', [
            'title' => 'Test Book',
            'subject_category_id' => 99999,
            'class_levels' => ['tamhidi'],
            'semesters' => [1],
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['subject_category_id']);
});

test('create subject book fails with title exceeding max length', function () {
    [$user, $school, $category, $classLevel] = createSubjectBookTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/subject-books', [
            'title' => str_repeat('A', 101),
            'subject_category_id' => $category->id,
            'class_levels' => ['tamhidi'],
            'semesters' => [1],
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['title']);
});

test('create subject book with default sessions_per_week', function () {
    [$user, $school, $category, $classLevel] = createSubjectBookTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/subject-books', [
            'title' => 'Default Sessions Book',
            'subject_category_id' => $category->id,
            'class_levels' => ['tamhidi'],
            'semesters' => [1],
        ]);

    $response->assertCreated();

    $this->assertDatabaseHas('subject_books', [
        'title' => 'Default Sessions Book',
        'sessions_per_week' => 1,
    ]);
});

// ── Class levels validation tests ──────────────────────────────────────

test('create subject book fails with empty class_levels array', function () {
    [$user, $school, $category] = createSubjectBookTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/subject-books', [
            'title' => 'Test Book',
            'subject_category_id' => $category->id,
            'class_levels' => [],
            'semesters' => [1],
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['class_levels']);
});

test('create subject book fails with non-existent class_level slug', function () {
    [$user, $school, $category] = createSubjectBookTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/subject-books', [
            'title' => 'Test Book',
            'subject_category_id' => $category->id,
            'class_levels' => ['non_existent_level'],
            'semesters' => [1],
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['class_levels.0']);
});

test('create subject book with multiple valid class_levels', function () {
    [$user, $school, $category] = createSubjectBookTestUser();

    ClassLevel::factory()->create([
        'school_id' => $school->id,
        'slug' => 'ibtida_1',
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/subject-books', [
            'title' => 'Multi-Level Book',
            'subject_category_id' => $category->id,
            'class_levels' => ['tamhidi', 'ibtida_1'],
            'semesters' => [1],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.class_levels', ['tamhidi', 'ibtida_1']);
});

// ── Semesters validation tests ─────────────────────────────────────────

test('create subject book fails with empty semesters array', function () {
    [$user, $school, $category, $classLevel] = createSubjectBookTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/subject-books', [
            'title' => 'Test Book',
            'subject_category_id' => $category->id,
            'class_levels' => ['tamhidi'],
            'semesters' => [],
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['semesters']);
});

test('create subject book fails with invalid semester number', function () {
    [$user, $school, $category, $classLevel] = createSubjectBookTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/subject-books', [
            'title' => 'Test Book',
            'subject_category_id' => $category->id,
            'class_levels' => ['tamhidi'],
            'semesters' => [3],
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['semesters.0']);
});

test('create subject book with both semesters', function () {
    [$user, $school, $category, $classLevel] = createSubjectBookTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/subject-books', [
            'title' => 'Full Year Book',
            'subject_category_id' => $category->id,
            'class_levels' => ['tamhidi'],
            'semesters' => [1, 2],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.semesters', [1, 2]);
});

// ── Sessions per week validation tests ─────────────────────────────────

test('create subject book fails with sessions_per_week out of range', function () {
    [$user, $school, $category, $classLevel] = createSubjectBookTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/subject-books', [
            'title' => 'Test Book',
            'subject_category_id' => $category->id,
            'class_levels' => ['tamhidi'],
            'semesters' => [1],
            'sessions_per_week' => 8,
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['sessions_per_week']);
});

test('create subject book fails with sessions_per_week zero', function () {
    [$user, $school, $category, $classLevel] = createSubjectBookTestUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/subject-books', [
            'title' => 'Test Book',
            'subject_category_id' => $category->id,
            'class_levels' => ['tamhidi'],
            'semesters' => [1],
            'sessions_per_week' => 0,
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['sessions_per_week']);
});

// ── Update tests ───────────────────────────────────────────────────────

test('can update a subject book', function () {
    [$user, $school, $category] = createSubjectBookTestUser();

    $book = SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
        'title' => 'Jurumiyyah',
    ]);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/subject-books/{$book->id}", [
            'title' => 'Jurumiyyah (Updated)',
            'sessions_per_week' => 5,
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.title', 'Jurumiyyah (Updated)')
        ->assertJsonPath('data.sessions_per_week', 5);
});

test('can partial update a subject book', function () {
    [$user, $school, $category] = createSubjectBookTestUser();

    $book = SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
        'title' => 'Jurumiyyah',
        'sessions_per_week' => 2,
    ]);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/subject-books/{$book->id}", [
            'description' => 'Updated description',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.title', 'Jurumiyyah')
        ->assertJsonPath('data.sessions_per_week', 2)
        ->assertJsonPath('data.description', 'Updated description');
});

test('update subject book returns subjectCategory in response', function () {
    [$user, $school, $category] = createSubjectBookTestUser();

    $book = SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
    ]);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/subject-books/{$book->id}", [
            'title' => 'Updated Title',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.subject_category.id', $category->id);
});

test('update subject book can change category', function () {
    [$user, $school, $category] = createSubjectBookTestUser();

    $newCategory = SubjectCategory::factory()->create(['school_id' => $school->id]);

    $book = SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
    ]);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/subject-books/{$book->id}", [
            'subject_category_id' => $newCategory->id,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.subject_category_id', $newCategory->id);
});

// ── Delete tests ───────────────────────────────────────────────────────

test('can delete a subject book without dependents', function () {
    [$user, $school, $category] = createSubjectBookTestUser();

    $book = SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
    ]);

    $response = $this->actingAs($user)
        ->deleteJson("/api/v1/subject-books/{$book->id}");

    $response->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('subject_books', ['id' => $book->id]);
});

test('delete subject book requires permission', function () {
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    $school = School::where('is_active', true)->first();
    $user = User::factory()->create();

    $category = SubjectCategory::factory()->create(['school_id' => $school->id]);
    $book = SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
    ]);

    $response = $this->actingAs($user)
        ->deleteJson("/api/v1/subject-books/{$book->id}");

    $response->assertForbidden();
});

// Note: Test for "cannot delete book with teaching schedules" is deferred
// because the teaching_schedules table doesn't exist yet (Task 6).
// The class_exists guard in SubjectBookService will skip the check until then.

// ── Active list tests ─────────────────────────────────────────────────

test('active list returns only active subject books', function () {
    [$user, $school, $category] = createSubjectBookTestUser();

    SubjectBook::factory()->count(2)->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
        'is_active' => true,
    ]);
    SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
        'is_active' => false,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/subject-books/active');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data');
});

test('active list is not paginated', function () {
    [$user, $school, $category] = createSubjectBookTestUser();

    SubjectBook::factory()->count(20)->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/subject-books/active');

    $response->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonMissing(['meta']);
});

test('active list eager loads subjectCategory', function () {
    [$user, $school, $category] = createSubjectBookTestUser();

    SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/subject-books/active');

    $response->assertOk();

    $firstBook = $response->json('data.0');
    expect($firstBook['subject_category'])->not->toBeNull();
    expect($firstBook['subject_category']['id'])->toBe($category->id);
});

test('active list ordered by title', function () {
    [$user, $school, $category] = createSubjectBookTestUser();

    SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
        'title' => 'Zubad',
        'is_active' => true,
    ]);
    SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
        'title' => 'Alfiyyah',
        'is_active' => true,
    ]);
    SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
        'title' => 'Jurumiyyah',
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/subject-books/active');

    $response->assertOk();

    $titles = array_column($response->json('data'), 'title');
    expect($titles)->toBe(['Alfiyyah', 'Jurumiyyah', 'Zubad']);
});

test('active list requires permission', function () {
    (new RolePermissionSeeder)->run();

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/subject-books/active');

    $response->assertForbidden();
});

// ── Category deletion with books test ──────────────────────────────────

test('cannot delete subject category with existing books', function () {
    [$user, $school, $category] = createSubjectBookTestUser();

    SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
    ]);

    $response = $this->actingAs($user)
        ->deleteJson("/api/v1/subject-categories/{$category->id}");

    $response->assertStatus(422)
        ->assertJsonPath('success', false);

    $this->assertDatabaseHas('subject_categories', ['id' => $category->id]);
});

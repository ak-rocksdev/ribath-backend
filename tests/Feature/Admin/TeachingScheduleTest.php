<?php

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\SubjectBook;
use App\Models\SubjectCategory;
use App\Models\Teacher;
use App\Models\TeachingSchedule;
use App\Models\TimeSlot;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;

function createScheduleTestData(): array
{
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $school = School::where('is_active', true)->first();

    $academicYear = AcademicYear::factory()->create([
        'school_id' => $school->id,
        'is_active' => true,
    ]);

    $timeSlot = TimeSlot::factory()->create(['school_id' => $school->id]);

    $category = SubjectCategory::factory()->create(['school_id' => $school->id]);

    $classLevel = ClassLevel::factory()->create([
        'school_id' => $school->id,
        'slug' => 'tamhidi',
        'label' => 'Tamhidi',
    ]);

    $subjectBook = SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => $category->id,
    ]);

    $teacher = Teacher::factory()->create(['school_id' => $school->id]);

    return [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher, $category];
}

function createSchedulePayload(array $overrides = [], array $testData = []): array
{
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    return array_merge([
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'day_of_week' => 'monday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ], $overrides);
}

// ── Auth & Permission tests ──────────────────────────────────────────

test('unauthenticated user cannot access teaching schedules', function () {
    $response = $this->getJson('/api/v1/teaching-schedules');

    $response->assertUnauthorized();
});

test('user without permission cannot access teaching schedules', function () {
    (new RolePermissionSeeder)->run();

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/teaching-schedules');

    $response->assertForbidden();
});

test('user without permission cannot create teaching schedule', function () {
    (new RolePermissionSeeder)->run();

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/teaching-schedules', []);

    $response->assertForbidden();
});

test('user without permission cannot delete teaching schedule', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    $schedule = TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);

    $unprivilegedUser = User::factory()->create();

    $response = $this->actingAs($unprivilegedUser)
        ->deleteJson("/api/v1/teaching-schedules/{$schedule->id}");

    $response->assertForbidden();
});

// ── Index / List tests ───────────────────────────────────────────────

test('authenticated user with permission can list teaching schedules', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    $days = ['monday', 'tuesday', 'wednesday'];
    foreach ($days as $day) {
        TeachingSchedule::factory()->create([
            'school_id' => $school->id,
            'academic_year_id' => $academicYear->id,
            'day_of_week' => $day,
            'time_slot_id' => $timeSlot->id,
            'class_level_id' => $classLevel->id,
            'subject_book_id' => $subjectBook->id,
            'teacher_id' => $teacher->id,
        ]);
    }

    $response = $this->actingAs($user)
        ->getJson('/api/v1/teaching-schedules');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(3, 'data');
});

test('teaching schedule list is not paginated', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    // Create 20 schedules spread across different days and unique class levels
    $days = TeachingSchedule::DAYS_OF_WEEK;
    for ($i = 0; $i < 20; $i++) {
        $cl = ClassLevel::factory()->create(['school_id' => $school->id]);
        TeachingSchedule::factory()->create([
            'school_id' => $school->id,
            'academic_year_id' => $academicYear->id,
            'day_of_week' => $days[$i % count($days)],
            'time_slot_id' => $timeSlot->id,
            'class_level_id' => $cl->id,
            'subject_book_id' => $subjectBook->id,
            'teacher_id' => $teacher->id,
        ]);
    }

    $response = $this->actingAs($user)
        ->getJson('/api/v1/teaching-schedules');

    $response->assertOk()
        ->assertJsonCount(20, 'data');

    // Verify no pagination meta key exists
    expect($response->json('meta'))->toBeNull();
});

test('teaching schedule response has correct structure with eager loaded relations', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/teaching-schedules');

    $response->assertOk()
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => [
                    'id', 'school_id', 'academic_year_id', 'semester',
                    'day_of_week', 'time_slot_id', 'class_level_id',
                    'subject_book_id', 'teacher_id', 'is_active',
                    'subject_book' => ['id', 'title', 'subject_category_id', 'sessions_per_week',
                        'subject_category' => ['id', 'name', 'color'],
                    ],
                    'teacher' => ['id', 'full_name', 'code'],
                    'time_slot' => ['id', 'code', 'label', 'type', 'start_time', 'end_time', 'sort_order'],
                    'class_level' => ['id', 'slug', 'label', 'category'],
                    'academic_year' => ['id', 'name'],
                ],
            ],
            'message',
        ]);
});

test('teaching schedules eager load correct related data values', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher, $category] = $testData;

    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/teaching-schedules');

    $response->assertOk();

    $firstSchedule = $response->json('data.0');
    expect($firstSchedule['teacher']['id'])->toBe($teacher->id);
    expect($firstSchedule['teacher']['full_name'])->toBe($teacher->full_name);
    expect($firstSchedule['time_slot']['id'])->toBe($timeSlot->id);
    expect($firstSchedule['time_slot']['label'])->toBe($timeSlot->label);
    expect($firstSchedule['class_level']['id'])->toBe($classLevel->id);
    expect($firstSchedule['class_level']['label'])->toBe($classLevel->label);
    expect($firstSchedule['subject_book']['id'])->toBe($subjectBook->id);
    expect($firstSchedule['subject_book']['title'])->toBe($subjectBook->title);
    expect($firstSchedule['subject_book']['subject_category']['id'])->toBe($category->id);
    expect($firstSchedule['academic_year']['id'])->toBe($academicYear->id);
    expect($firstSchedule['academic_year']['name'])->toBe($academicYear->name);
});

// ── Filter tests ─────────────────────────────────────────────────────

test('can filter schedules by academic_year_id', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    $otherAcademicYear = AcademicYear::factory()->create(['school_id' => $school->id]);

    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'day_of_week' => 'monday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);
    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'day_of_week' => 'tuesday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);
    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $otherAcademicYear->id,
        'day_of_week' => 'monday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/teaching-schedules?academic_year_id={$academicYear->id}");

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('can filter schedules by semester', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    // Create 2 schedules in semester 1 with unique day/time_slot/class_level combos
    $ts1 = TimeSlot::factory()->create(['school_id' => $school->id]);
    $cl1 = ClassLevel::factory()->create(['school_id' => $school->id]);
    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'day_of_week' => 'monday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);
    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'day_of_week' => 'tuesday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);

    // Create 1 schedule in semester 2
    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'semester' => 2,
        'day_of_week' => 'monday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/teaching-schedules?semester=1');

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('can filter schedules by class_level_id', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    $otherClassLevel = ClassLevel::factory()->create(['school_id' => $school->id]);

    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'day_of_week' => 'monday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);
    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'day_of_week' => 'tuesday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);
    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'day_of_week' => 'monday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $otherClassLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/teaching-schedules?class_level_id={$classLevel->id}");

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('can filter schedules by day_of_week', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    // Create 2 schedules on Monday with unique time_slot/class_level combos
    $ts2 = TimeSlot::factory()->create(['school_id' => $school->id]);
    $cl2 = ClassLevel::factory()->create(['school_id' => $school->id]);

    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'day_of_week' => 'monday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);
    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'day_of_week' => 'monday',
        'time_slot_id' => $ts2->id,
        'class_level_id' => $cl2->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);

    // Create 1 schedule on Friday
    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'day_of_week' => 'friday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $cl2->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/teaching-schedules?day_of_week=monday');

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('can filter schedules by teacher_id', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    $otherTeacher = Teacher::factory()->create(['school_id' => $school->id]);
    $cl2 = ClassLevel::factory()->create(['school_id' => $school->id]);

    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'day_of_week' => 'monday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);
    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'day_of_week' => 'tuesday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);
    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'day_of_week' => 'monday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $cl2->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $otherTeacher->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/teaching-schedules?teacher_id={$teacher->id}");

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

// ── Show tests ───────────────────────────────────────────────────────

test('can show a single teaching schedule', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    $schedule = TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'day_of_week' => 'tuesday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/teaching-schedules/{$schedule->id}");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $schedule->id)
        ->assertJsonPath('data.semester', 1)
        ->assertJsonPath('data.day_of_week', 'tuesday')
        ->assertJsonPath('data.teacher.full_name', $teacher->full_name)
        ->assertJsonPath('data.class_level.label', $classLevel->label)
        ->assertJsonPath('data.time_slot.label', $timeSlot->label)
        ->assertJsonPath('data.subject_book.title', $subjectBook->title)
        ->assertJsonPath('data.academic_year.name', $academicYear->name);
});

test('show returns 404 for non-existent schedule', function () {
    $testData = createScheduleTestData();
    [$user] = $testData;

    $fakeUuid = '00000000-0000-0000-0000-000000000000';

    $response = $this->actingAs($user)
        ->getJson("/api/v1/teaching-schedules/{$fakeUuid}");

    $response->assertNotFound();
});

// ── Create tests ─────────────────────────────────────────────────────

test('can create a teaching schedule', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    $payload = createSchedulePayload(testData: $testData);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/teaching-schedules', $payload);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.semester', 1)
        ->assertJsonPath('data.day_of_week', 'monday')
        ->assertJsonPath('data.teacher.id', $teacher->id)
        ->assertJsonPath('data.class_level.id', $classLevel->id);

    $this->assertDatabaseHas('teaching_schedules', [
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'day_of_week' => 'monday',
        'teacher_id' => $teacher->id,
        'is_active' => true,
    ]);
});

test('create schedule auto-assigns school_id', function () {
    $testData = createScheduleTestData();
    [$user, $school] = $testData;

    $payload = createSchedulePayload(testData: $testData);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/teaching-schedules', $payload);

    $response->assertCreated();

    $this->assertDatabaseHas('teaching_schedules', [
        'school_id' => $school->id,
    ]);
});

test('create schedule includes all eager loaded relations in response', function () {
    $testData = createScheduleTestData();
    [$user] = $testData;

    $payload = createSchedulePayload(testData: $testData);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/teaching-schedules', $payload);

    $response->assertCreated()
        ->assertJsonStructure([
            'data' => [
                'subject_book' => ['id', 'title', 'subject_category' => ['id', 'name', 'color']],
                'teacher' => ['id', 'full_name', 'code'],
                'time_slot' => ['id', 'code', 'label'],
                'class_level' => ['id', 'slug', 'label'],
                'academic_year' => ['id', 'name'],
            ],
        ]);
});

test('create schedule with is_active false', function () {
    $testData = createScheduleTestData();
    [$user] = $testData;

    $payload = createSchedulePayload(['is_active' => false], $testData);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/teaching-schedules', $payload);

    $response->assertCreated()
        ->assertJsonPath('data.is_active', false);
});

// ── Validation tests ─────────────────────────────────────────────────

test('create schedule fails with missing required fields', function () {
    $testData = createScheduleTestData();
    [$user] = $testData;

    $response = $this->actingAs($user)
        ->postJson('/api/v1/teaching-schedules', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'academic_year_id', 'semester', 'day_of_week',
            'time_slot_id', 'class_level_id', 'subject_book_id', 'teacher_id',
        ]);
});

test('create schedule fails with invalid day_of_week', function () {
    $testData = createScheduleTestData();
    [$user] = $testData;

    $payload = createSchedulePayload(['day_of_week' => 'invalidday'], $testData);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/teaching-schedules', $payload);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['day_of_week']);
});

test('create schedule rejects non-english day names', function () {
    $testData = createScheduleTestData();
    [$user] = $testData;

    $invalidDays = ['senin', 'selasa', 'rabu', 'Montag', 'lundi'];

    foreach ($invalidDays as $day) {
        $payload = createSchedulePayload(['day_of_week' => $day], $testData);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/teaching-schedules', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['day_of_week']);
    }
});

test('create schedule fails with invalid semester', function () {
    $testData = createScheduleTestData();
    [$user] = $testData;

    $payload = createSchedulePayload(['semester' => 3], $testData);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/teaching-schedules', $payload);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['semester']);
});

test('create schedule fails with non-existent academic_year_id', function () {
    $testData = createScheduleTestData();
    [$user] = $testData;

    $fakeUuid = '00000000-0000-0000-0000-000000000000';
    $payload = createSchedulePayload(['academic_year_id' => $fakeUuid], $testData);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/teaching-schedules', $payload);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['academic_year_id']);
});

test('create schedule fails with non-existent time_slot_id', function () {
    $testData = createScheduleTestData();
    [$user] = $testData;

    $fakeUuid = '00000000-0000-0000-0000-000000000000';
    $payload = createSchedulePayload(['time_slot_id' => $fakeUuid], $testData);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/teaching-schedules', $payload);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['time_slot_id']);
});

test('create schedule fails with non-existent class_level_id', function () {
    $testData = createScheduleTestData();
    [$user] = $testData;

    $fakeUuid = '00000000-0000-0000-0000-000000000000';
    $payload = createSchedulePayload(['class_level_id' => $fakeUuid], $testData);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/teaching-schedules', $payload);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['class_level_id']);
});

test('create schedule fails with non-existent subject_book_id', function () {
    $testData = createScheduleTestData();
    [$user] = $testData;

    $fakeUuid = '00000000-0000-0000-0000-000000000000';
    $payload = createSchedulePayload(['subject_book_id' => $fakeUuid], $testData);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/teaching-schedules', $payload);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['subject_book_id']);
});

test('create schedule fails with non-existent teacher_id', function () {
    $testData = createScheduleTestData();
    [$user] = $testData;

    $fakeUuid = '00000000-0000-0000-0000-000000000000';
    $payload = createSchedulePayload(['teacher_id' => $fakeUuid], $testData);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/teaching-schedules', $payload);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['teacher_id']);
});

// ── Teacher conflict detection tests ─────────────────────────────────

test('create schedule detects teacher conflict at same time slot', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    // Create first schedule
    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'day_of_week' => 'monday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
        'is_active' => true,
    ]);

    // Try to create another schedule with same teacher, day, time slot
    $otherClassLevel = ClassLevel::factory()->create([
        'school_id' => $school->id,
        'slug' => 'ibtida_1',
        'label' => 'Ibtida 1',
    ]);

    $payload = createSchedulePayload([
        'class_level_id' => $otherClassLevel->id,
    ], $testData);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/teaching-schedules', $payload);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['teacher_id']);

    // Verify the error message mentions the conflicting class
    $errorMessage = $response->json('errors.teacher_id.0');
    expect($errorMessage)->toContain($classLevel->label);
});

test('no teacher conflict when different day_of_week', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    // Create first schedule on Monday
    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'day_of_week' => 'monday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
        'is_active' => true,
    ]);

    // Create another schedule on Tuesday — no conflict
    $otherClassLevel = ClassLevel::factory()->create(['school_id' => $school->id]);

    $payload = createSchedulePayload([
        'day_of_week' => 'tuesday',
        'class_level_id' => $otherClassLevel->id,
    ], $testData);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/teaching-schedules', $payload);

    $response->assertCreated();
});

test('no teacher conflict when different time_slot', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    // Create first schedule
    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'day_of_week' => 'monday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
        'is_active' => true,
    ]);

    // Create another schedule at a different time slot — no conflict
    $otherTimeSlot = TimeSlot::factory()->create(['school_id' => $school->id]);
    $otherClassLevel = ClassLevel::factory()->create(['school_id' => $school->id]);

    $payload = createSchedulePayload([
        'time_slot_id' => $otherTimeSlot->id,
        'class_level_id' => $otherClassLevel->id,
    ], $testData);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/teaching-schedules', $payload);

    $response->assertCreated();
});

test('no teacher conflict when different semester', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    // Create first schedule in semester 1
    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'day_of_week' => 'monday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
        'is_active' => true,
    ]);

    // Create same schedule in semester 2 — no conflict
    $otherClassLevel = ClassLevel::factory()->create(['school_id' => $school->id]);

    $payload = createSchedulePayload([
        'semester' => 2,
        'class_level_id' => $otherClassLevel->id,
    ], $testData);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/teaching-schedules', $payload);

    $response->assertCreated();
});

test('no teacher conflict when different teacher', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    // Create first schedule
    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'day_of_week' => 'monday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
        'is_active' => true,
    ]);

    // Create schedule with different teacher — no conflict
    $otherTeacher = Teacher::factory()->create(['school_id' => $school->id]);
    $otherClassLevel = ClassLevel::factory()->create(['school_id' => $school->id]);

    $payload = createSchedulePayload([
        'teacher_id' => $otherTeacher->id,
        'class_level_id' => $otherClassLevel->id,
    ], $testData);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/teaching-schedules', $payload);

    $response->assertCreated();
});

test('no teacher conflict when existing schedule is inactive', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    // Create an inactive schedule
    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'day_of_week' => 'monday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
        'is_active' => false,
    ]);

    // Create same schedule for same teacher — no conflict because first is inactive
    $otherClassLevel = ClassLevel::factory()->create(['school_id' => $school->id]);

    $payload = createSchedulePayload([
        'class_level_id' => $otherClassLevel->id,
    ], $testData);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/teaching-schedules', $payload);

    $response->assertCreated();
});

// ── Class slot uniqueness test ────────────────────────────────────────

test('application-layer validation prevents double-booking a class at the same time slot', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    // Create first schedule
    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'day_of_week' => 'monday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);

    // Try to create exact same class+time+day slot with a different teacher and book
    $otherTeacher = Teacher::factory()->create(['school_id' => $school->id]);

    $payload = createSchedulePayload([
        'teacher_id' => $otherTeacher->id,
    ], $testData);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/teaching-schedules', $payload);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['class_level_id']);

    $errorMessage = $response->json('errors.class_level_id.0');
    expect($errorMessage)->toContain('already has a schedule');
});

// ── Update tests ─────────────────────────────────────────────────────

test('can update a teaching schedule', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    $schedule = TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'day_of_week' => 'monday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/teaching-schedules/{$schedule->id}", [
            'day_of_week' => 'wednesday',
            'semester' => 2,
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.day_of_week', 'wednesday')
        ->assertJsonPath('data.semester', 2);
});

test('update schedule returns eager loaded relations', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    $schedule = TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/teaching-schedules/{$schedule->id}", [
            'day_of_week' => 'friday',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.teacher.id', $teacher->id)
        ->assertJsonPath('data.class_level.id', $classLevel->id)
        ->assertJsonPath('data.time_slot.id', $timeSlot->id);
});

test('update schedule detects teacher conflict', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    // Existing schedule on Monday
    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'day_of_week' => 'monday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
        'is_active' => true,
    ]);

    // Another schedule on Tuesday for same teacher
    $otherClassLevel = ClassLevel::factory()->create(['school_id' => $school->id]);
    $otherTimeSlot = TimeSlot::factory()->create(['school_id' => $school->id]);

    $scheduleToUpdate = TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'day_of_week' => 'tuesday',
        'time_slot_id' => $otherTimeSlot->id,
        'class_level_id' => $otherClassLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
        'is_active' => true,
    ]);

    // Try to move the second schedule to Monday at the same time slot — conflict!
    $response = $this->actingAs($user)
        ->putJson("/api/v1/teaching-schedules/{$scheduleToUpdate->id}", [
            'day_of_week' => 'monday',
            'time_slot_id' => $timeSlot->id,
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['teacher_id']);
});

test('update schedule allows updating itself without conflict', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    $schedule = TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'day_of_week' => 'monday',
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
        'is_active' => true,
    ]);

    // Update the same schedule (only change is_active) — should not conflict with itself
    $response = $this->actingAs($user)
        ->putJson("/api/v1/teaching-schedules/{$schedule->id}", [
            'is_active' => false,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.is_active', false);
});

test('can change teacher on existing schedule', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    $schedule = TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);

    $newTeacher = Teacher::factory()->create(['school_id' => $school->id]);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/teaching-schedules/{$schedule->id}", [
            'teacher_id' => $newTeacher->id,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.teacher.id', $newTeacher->id);
});

// ── Delete tests ─────────────────────────────────────────────────────

test('can delete a teaching schedule (soft deactivation)', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    $schedule = TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->deleteJson("/api/v1/teaching-schedules/{$schedule->id}");

    $response->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('teaching_schedules', [
        'id' => $schedule->id,
        'is_active' => false,
    ]);
});

test('delete sets is_active to false instead of removing the record', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    $schedule = TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
        'is_active' => true,
    ]);

    $scheduleId = $schedule->id;

    $this->actingAs($user)
        ->deleteJson("/api/v1/teaching-schedules/{$scheduleId}");

    // Verify the record still exists in the database but is deactivated
    $this->assertDatabaseHas('teaching_schedules', [
        'id' => $scheduleId,
        'is_active' => false,
    ]);
    expect(TeachingSchedule::find($scheduleId))->not->toBeNull();
    expect(TeachingSchedule::find($scheduleId)->is_active)->toBeFalse();
});

// ── Cross-entity deletion protection tests ───────────────────────────

test('cannot delete academic year with existing teaching schedules', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);

    $response = $this->actingAs($user)
        ->deleteJson("/api/v1/academic-years/{$academicYear->id}");

    $response->assertStatus(422)
        ->assertJsonPath('success', false);

    $this->assertDatabaseHas('academic_years', ['id' => $academicYear->id]);
});

test('cannot delete time slot with existing teaching schedules', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);

    $response = $this->actingAs($user)
        ->deleteJson("/api/v1/time-slots/{$timeSlot->id}");

    $response->assertStatus(422)
        ->assertJsonPath('success', false);

    $this->assertDatabaseHas('time_slots', ['id' => $timeSlot->id]);
});

test('cannot delete subject book with existing teaching schedules', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'time_slot_id' => $timeSlot->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
    ]);

    $response = $this->actingAs($user)
        ->deleteJson("/api/v1/subject-books/{$subjectBook->id}");

    $response->assertStatus(422)
        ->assertJsonPath('success', false);

    $this->assertDatabaseHas('subject_books', ['id' => $subjectBook->id]);
});

// ── Day of week valid values tests ───────────────────────────────────

test('all valid english day names are accepted', function () {
    $testData = createScheduleTestData();
    [$user, $school, $academicYear, $timeSlot, $classLevel, $subjectBook, $teacher] = $testData;

    $validDays = TeachingSchedule::DAYS_OF_WEEK;

    foreach ($validDays as $day) {
        $newTimeSlot = TimeSlot::factory()->create(['school_id' => $school->id]);
        $newClassLevel = ClassLevel::factory()->create(['school_id' => $school->id]);

        $payload = createSchedulePayload([
            'day_of_week' => $day,
            'time_slot_id' => $newTimeSlot->id,
            'class_level_id' => $newClassLevel->id,
        ], $testData);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/teaching-schedules', $payload);

        $response->assertCreated();
    }

    expect(TeachingSchedule::count())->toBe(7);
});

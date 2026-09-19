<?php

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\ClassTask;
use App\Models\GradingFactor;
use App\Models\GradingTemplate;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentTaskScore;
use App\Models\SubjectBook;
use App\Models\SubjectCategory;
use App\Models\Teacher;
use App\Models\TeachingSchedule;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Akademik\AcademicSemesterService;
use App\Services\Akademik\GradingDefaultsInstaller;
use Database\Seeders\ClassLevelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;
use Illuminate\Support\Collection;

/**
 * Seeds roles, the active school, its class levels and grading defaults,
 * creates an academic year with both semesters (with start/end dates), and
 * schedules one teori_kitab kitab for the "tamhidi" class in semester 1.
 *
 * @return array{user: User, school: School, academicYear: AcademicYear, classLevel: ClassLevel, subjectBook: SubjectBook, teacher: Teacher, factorsByCode: Collection}
 */
function setUpClassTaskContext(): array
{
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    (new ClassLevelSeeder)->run();

    $user = User::factory()->create(['name' => 'Ustadz Tugas']);
    $user->assignRole('super_admin');

    $school = School::where('is_active', true)->firstOrFail();
    app(GradingDefaultsInstaller::class)->installForSchool($school);

    $academicYear = AcademicYear::factory()->create([
        'school_id' => $school->id,
        'name' => '2025/2026',
        'start_date' => '2025-07-01',
        'end_date' => '2026-06-30',
        'is_active' => true,
        'active_semester' => 1,
    ]);
    app(AcademicSemesterService::class)->createSemestersForAcademicYear($academicYear);
    app(AcademicSemesterService::class)->updateSemester($academicYear, 1, [
        'start_date' => '2025-07-01',
        'end_date' => '2025-12-31',
    ]);

    $classLevel = ClassLevel::where('school_id', $school->id)->where('slug', 'tamhidi')->firstOrFail();
    $teoriKitabTemplate = GradingTemplate::where('school_id', $school->id)->where('code', 'teori_kitab')->firstOrFail();

    $subjectBook = classTaskCreateSubjectBook($school, 'Safinatun Najah', $teoriKitabTemplate->id);
    $teacher = Teacher::factory()->create(['school_id' => $school->id, 'full_name' => 'Ustadz Ahmad']);

    classTaskScheduleSubjectBook($school, $academicYear, 1, $classLevel, $subjectBook, $teacher);

    $factorsByCode = GradingFactor::where('school_id', $school->id)->get()->keyBy('code');

    return compact('user', 'school', 'academicYear', 'classLevel', 'subjectBook', 'teacher', 'factorsByCode');
}

function classTaskCreateSubjectBook(School $school, string $title, ?string $gradingTemplateId): SubjectBook
{
    return SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => SubjectCategory::factory()->create(['school_id' => $school->id])->id,
        'grading_template_id' => $gradingTemplateId,
        'title' => $title,
    ]);
}

function classTaskScheduleSubjectBook(
    School $school,
    AcademicYear $academicYear,
    int $semester,
    ClassLevel $classLevel,
    SubjectBook $subjectBook,
    Teacher $teacher,
    bool $isActive = true,
): TeachingSchedule {
    return TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'semester' => $semester,
        'time_slot_id' => TimeSlot::factory()->create(['school_id' => $school->id])->id,
        'class_level_ids' => [$classLevel->id],
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
        'is_active' => $isActive,
    ]);
}

/**
 * Creates a student through the real POST /students endpoint (so school_id
 * and class_level_id are resolved the same way production does it).
 */
function classTaskCreateStudent($testCase, User $user, string $fullName, string $classLevelSlug = 'tamhidi'): Student
{
    $response = $testCase->actingAs($user)->postJson('/api/v1/students', [
        'full_name' => $fullName,
        'birth_date' => '2012-05-15',
        'gender' => 'L',
        'program' => 'regular',
        'entry_date' => '2025-07-01',
        'class_level' => $classLevelSlug,
        'address' => 'Jl. Contoh No. 1',
    ]);

    $response->assertCreated();

    return Student::findOrFail($response->json('data.id'));
}

/**
 * Same as classTaskCreateStudent(), with a caller-chosen entry_date — for
 * "late" students who join the class after some tasks already exist
 * (EnrollmentDateRule).
 */
function classTaskCreateStudentWithEntryDate($testCase, User $user, string $fullName, string $entryDate, string $classLevelSlug = 'tamhidi'): Student
{
    $response = $testCase->actingAs($user)->postJson('/api/v1/students', [
        'full_name' => $fullName,
        'birth_date' => '2012-05-15',
        'gender' => 'L',
        'program' => 'regular',
        'entry_date' => $entryDate,
        'class_level' => $classLevelSlug,
        'address' => 'Jl. Contoh No. 1',
    ]);

    $response->assertCreated();

    return Student::findOrFail($response->json('data.id'));
}

function classTaskListQuery(array $context): string
{
    return '/api/v1/class-tasks?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'subject_book_id' => $context['subjectBook']->id,
    ]);
}

function classTaskCreatePayload(array $context, array $overrides = []): array
{
    return array_merge([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'subject_book_id' => $context['subjectBook']->id,
        'title' => 'Hafalan Bab 1',
        'task_date' => '2025-08-01',
        'description' => 'Menghafal bab pertama.',
    ], $overrides);
}

function createClassTaskThroughEndpoint($testCase, User $user, array $context, array $overrides = []): ClassTask
{
    $response = $testCase->actingAs($user)->postJson('/api/v1/class-tasks', classTaskCreatePayload($context, $overrides));
    $response->assertCreated();

    return ClassTask::findOrFail($response->json('data.id'));
}

// ── GET /class-tasks ──────────────────────────────────────────────────────

test('class tasks are listed with scored and student counts', function () {
    $context = setUpClassTaskContext();
    $ali = classTaskCreateStudent($this, $context['user'], 'Ali');
    classTaskCreateStudent($this, $context['user'], 'Zaid');

    $task = createClassTaskThroughEndpoint($this, $context['user'], $context, ['title' => 'Hafalan Bab 1']);
    createClassTaskThroughEndpoint($this, $context['user'], $context, ['title' => 'Hafalan Bab 2', 'task_date' => '2025-08-15']);

    $this->actingAs($context['user'])->putJson("/api/v1/class-tasks/{$task->id}/scores/bulk", [
        'rows' => [['student_id' => $ali->id, 'score' => 90]],
    ])->assertOk();

    $response = $this->actingAs($context['user'])->getJson(classTaskListQuery($context));

    $response->assertOk()->assertJsonPath('success', true)->assertJsonCount(2, 'data');

    $tasks = collect($response->json('data'));
    // Most recent task_date first.
    expect($tasks->pluck('title')->all())->toBe(['Hafalan Bab 2', 'Hafalan Bab 1']);

    $bab1 = $tasks->firstWhere('title', 'Hafalan Bab 1');
    expect($bab1['student_count'])->toBe(2);
    expect($bab1['scored_count'])->toBe(1);

    $bab2 = $tasks->firstWhere('title', 'Hafalan Bab 2');
    expect($bab2['scored_count'])->toBe(0);
});

test('class tasks list requires view-grades permission', function () {
    $context = setUpClassTaskContext();

    $this->actingAs(User::factory()->create())
        ->getJson(classTaskListQuery($context))
        ->assertForbidden();
});

test('class tasks list rejects a kitab that is not scheduled for the class in that semester', function () {
    $context = setUpClassTaskContext();
    $ibtidaClass = ClassLevel::where('school_id', $context['school']->id)->where('slug', 'ibtida_1')->firstOrFail();

    $this->actingAs($context['user'])
        ->getJson('/api/v1/class-tasks?'.http_build_query([
            'academic_year_id' => $context['academicYear']->id,
            'semester' => 1,
            'class_level_id' => $ibtidaClass->id,
            'subject_book_id' => $context['subjectBook']->id,
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Kitab ini tidak dijadwalkan untuk kelas tersebut pada semester ini.');
});

test('class tasks list rejects another schools class level and kitab', function () {
    $context = setUpClassTaskContext();

    $otherSchool = School::factory()->create();
    $otherClassLevel = ClassLevel::factory()->create(['school_id' => $otherSchool->id]);
    $otherBook = classTaskCreateSubjectBook($otherSchool, 'Kitab Sekolah Lain', null);

    $this->actingAs($context['user'])
        ->getJson('/api/v1/class-tasks?'.http_build_query([
            'academic_year_id' => $context['academicYear']->id,
            'semester' => 1,
            'class_level_id' => $otherClassLevel->id,
            'subject_book_id' => $otherBook->id,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['class_level_id', 'subject_book_id']);
});

// ── POST /class-tasks ─────────────────────────────────────────────────────

test('a task can be created for a gradable class and kitab', function () {
    $context = setUpClassTaskContext();

    $response = $this->actingAs($context['user'])->postJson('/api/v1/class-tasks', classTaskCreatePayload($context));

    $response->assertCreated()->assertJsonPath('success', true);
    expect($response->json('data.title'))->toBe('Hafalan Bab 1');
    expect($response->json('data.task_date'))->toBe('2025-08-01');
    expect($response->json('data.scored_count'))->toBe(0);
    expect($response->json('data.student_count'))->toBe(0);

    $task = ClassTask::findOrFail($response->json('data.id'));
    expect($task->school_id)->toBe($context['school']->id);
    expect($task->created_by)->toBe($context['user']->id);
    expect($task->updated_by)->toBe($context['user']->id);
});

test('creating a task rejects a kitab without a grading template', function () {
    $context = setUpClassTaskContext();

    $untemplatedBook = classTaskCreateSubjectBook($context['school'], 'Kitab Tanpa Template', null);
    classTaskScheduleSubjectBook($context['school'], $context['academicYear'], 1, $context['classLevel'], $untemplatedBook, $context['teacher']);

    $payload = classTaskCreatePayload($context, ['subject_book_id' => $untemplatedBook->id]);

    $this->actingAs($context['user'])->postJson('/api/v1/class-tasks', $payload)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Kitab ini belum memiliki template penilaian.');

    expect(ClassTask::count())->toBe(0);
});

test('creating a task rejects an unscheduled class and kitab pair', function () {
    $context = setUpClassTaskContext();
    $unscheduledBook = classTaskCreateSubjectBook($context['school'], 'Kitab Tidak Terjadwal', $context['subjectBook']->grading_template_id);

    $this->actingAs($context['user'])
        ->postJson('/api/v1/class-tasks', classTaskCreatePayload($context, ['subject_book_id' => $unscheduledBook->id]))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Kitab ini tidak dijadwalkan untuk kelas tersebut pada semester ini.');
});

test('creating a task rejects a date outside the semester range', function () {
    $context = setUpClassTaskContext();

    $this->actingAs($context['user'])
        ->postJson('/api/v1/class-tasks', classTaskCreatePayload($context, ['task_date' => '2026-01-15']))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Tanggal tugas di luar rentang semester.')
        ->assertJsonValidationErrors(['task_date']);

    expect(ClassTask::count())->toBe(0);
});

test('creating a task requires manage-grades permission', function () {
    $context = setUpClassTaskContext();

    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/class-tasks', classTaskCreatePayload($context))
        ->assertForbidden();
});

test('pengurus_pesantren can create, list and score a task', function () {
    $context = setUpClassTaskContext();
    $pengurus = User::factory()->create();
    $pengurus->assignRole('pengurus_pesantren');
    $ali = classTaskCreateStudent($this, $context['user'], 'Ali');

    $created = $this->actingAs($pengurus)->postJson('/api/v1/class-tasks', classTaskCreatePayload($context))->assertCreated();
    $taskId = $created->json('data.id');

    $this->actingAs($pengurus)->getJson(classTaskListQuery($context))->assertOk()->assertJsonCount(1, 'data');

    $this->actingAs($pengurus)->putJson("/api/v1/class-tasks/{$taskId}/scores/bulk", [
        'rows' => [['student_id' => $ali->id, 'score' => 88]],
    ])->assertOk();
});

// ── GET/PUT/DELETE /class-tasks/{classTask} ──────────────────────────────

test('a task can be shown, updated and soft-deleted', function () {
    $context = setUpClassTaskContext();
    $task = createClassTaskThroughEndpoint($this, $context['user'], $context);

    $this->actingAs($context['user'])->getJson("/api/v1/class-tasks/{$task->id}")
        ->assertOk()
        ->assertJsonPath('data.title', 'Hafalan Bab 1');

    $updater = User::factory()->create(['name' => 'Pengurus Lain']);
    $updater->assignRole('super_admin');

    $this->actingAs($updater)->putJson("/api/v1/class-tasks/{$task->id}", [
        'title' => 'Hafalan Bab 1 (Revisi)',
        'task_date' => '2025-09-01',
        'description' => null,
    ])->assertOk()
        ->assertJsonPath('data.title', 'Hafalan Bab 1 (Revisi)')
        ->assertJsonPath('data.task_date', '2025-09-01')
        ->assertJsonPath('data.description', null);

    $task->refresh();
    expect($task->updated_by)->toBe($updater->id);

    $this->actingAs($context['user'])->deleteJson("/api/v1/class-tasks/{$task->id}")->assertOk();

    expect(ClassTask::find($task->id))->toBeNull();
    expect(ClassTask::withTrashed()->find($task->id))->not->toBeNull();

    $this->actingAs($context['user'])->getJson("/api/v1/class-tasks/{$task->id}")->assertNotFound();
    $this->actingAs($context['user'])->getJson(classTaskListQuery($context))->assertOk()->assertJsonCount(0, 'data');
});

test('updating a task rejects a date outside the semester range', function () {
    $context = setUpClassTaskContext();
    $task = createClassTaskThroughEndpoint($this, $context['user'], $context);

    $this->actingAs($context['user'])->putJson("/api/v1/class-tasks/{$task->id}", [
        'task_date' => '2026-02-01',
    ])->assertUnprocessable()->assertJsonPath('message', 'Tanggal tugas di luar rentang semester.');

    $task->refresh();
    expect($task->task_date->toDateString())->toBe('2025-08-01');
});

test('updating and deleting a task requires manage-grades permission', function () {
    $context = setUpClassTaskContext();
    $task = createClassTaskThroughEndpoint($this, $context['user'], $context);
    $unauthorizedUser = User::factory()->create();

    $this->actingAs($unauthorizedUser)->putJson("/api/v1/class-tasks/{$task->id}", ['title' => 'X'])->assertForbidden();
    $this->actingAs($unauthorizedUser)->deleteJson("/api/v1/class-tasks/{$task->id}")->assertForbidden();
});

test('a task belonging to another school is hidden behind 404', function () {
    $context = setUpClassTaskContext();

    $otherSchool = School::factory()->create();
    $otherTask = ClassTask::create([
        'school_id' => $otherSchool->id,
        'class_level_id' => ClassLevel::factory()->create(['school_id' => $otherSchool->id])->id,
        'subject_book_id' => classTaskCreateSubjectBook($otherSchool, 'Kitab Sekolah Lain', null)->id,
        'academic_year_id' => AcademicYear::factory()->create(['school_id' => $otherSchool->id])->id,
        'semester' => 1,
        'title' => 'Tugas Sekolah Lain',
        'task_date' => '2025-08-01',
    ]);

    $this->actingAs($context['user'])->getJson("/api/v1/class-tasks/{$otherTask->id}")->assertNotFound();
    $this->actingAs($context['user'])->putJson("/api/v1/class-tasks/{$otherTask->id}", ['title' => 'X'])->assertNotFound();
    $this->actingAs($context['user'])->deleteJson("/api/v1/class-tasks/{$otherTask->id}")->assertNotFound();
    $this->actingAs($context['user'])->getJson("/api/v1/class-tasks/{$otherTask->id}/scores")->assertNotFound();
    $this->actingAs($context['user'])->putJson("/api/v1/class-tasks/{$otherTask->id}/scores/bulk", ['rows' => []])->assertNotFound();
});

// ── GET /class-tasks/{classTask}/scores ───────────────────────────────────

test('a tasks scores list the class students with any stored score', function () {
    $context = setUpClassTaskContext();
    $ali = classTaskCreateStudent($this, $context['user'], 'Ali');
    $zaid = classTaskCreateStudent($this, $context['user'], 'Zaid');
    $task = createClassTaskThroughEndpoint($this, $context['user'], $context);

    $this->actingAs($context['user'])->putJson("/api/v1/class-tasks/{$task->id}/scores/bulk", [
        'rows' => [['student_id' => $ali->id, 'score' => 92.5]],
    ])->assertOk();

    $response = $this->actingAs($context['user'])->getJson("/api/v1/class-tasks/{$task->id}/scores");

    $response->assertOk();
    expect(collect($response->json('data.students'))->pluck('full_name')->all())->toBe(['Ali', 'Zaid']);
    expect($response->json("data.scores.{$ali->id}.score"))->toEqual(92.5);
    expect($response->json("data.scores.{$zaid->id}"))->toBeNull();
    expect($response->json('data.class_task.scored_count'))->toBe(1);
    expect($response->json('data.class_task.student_count'))->toBe(2);
});

test('a tasks scores marks a student who joined after task_date as not yet enrolled, excluded from the counts', function () {
    $context = setUpClassTaskContext();
    $ali = classTaskCreateStudent($this, $context['user'], 'Ali'); // entry_date 2025-07-01
    $task = createClassTaskThroughEndpoint($this, $context['user'], $context, ['task_date' => '2025-08-01']);
    // Joins after the task's date.
    $lateStudent = classTaskCreateStudentWithEntryDate($this, $context['user'], 'Zaid Terlambat', '2025-08-15');

    $this->actingAs($context['user'])->putJson("/api/v1/class-tasks/{$task->id}/scores/bulk", [
        'rows' => [['student_id' => $ali->id, 'score' => 90]],
    ])->assertOk();

    $response = $this->actingAs($context['user'])->getJson("/api/v1/class-tasks/{$task->id}/scores");

    $response->assertOk();
    expect($response->json('data.not_yet_enrolled_student_ids'))->toBe([$lateStudent->id]);
    // Ali counts; the late student does not, on either side of the ratio.
    expect($response->json('data.class_task.scored_count'))->toBe(1);
    expect($response->json('data.class_task.student_count'))->toBe(1);
});

test('class tasks list computes student_count per task, excluding students who join after that tasks date', function () {
    $context = setUpClassTaskContext();
    classTaskCreateStudent($this, $context['user'], 'Ali'); // entry_date 2025-07-01
    $earlyTask = createClassTaskThroughEndpoint($this, $context['user'], $context, ['title' => 'Tugas Awal', 'task_date' => '2025-08-01']);
    $lateTask = createClassTaskThroughEndpoint($this, $context['user'], $context, ['title' => 'Tugas Akhir', 'task_date' => '2025-11-01']);
    // Joins between the two tasks.
    classTaskCreateStudentWithEntryDate($this, $context['user'], 'Zaid Terlambat', '2025-09-01');

    $response = $this->actingAs($context['user'])->getJson(classTaskListQuery($context));
    $response->assertOk();

    $tasks = collect($response->json('data'));
    // Only Ali was enrolled for the early task; both are enrolled for the later one.
    expect($tasks->firstWhere('title', 'Tugas Awal')['student_count'])->toBe(1);
    expect($tasks->firstWhere('title', 'Tugas Akhir')['student_count'])->toBe(2);
});

// ── PUT /class-tasks/{classTask}/scores/bulk ──────────────────────────────

test('bulk scores creates then a resubmit updates without duplicates', function () {
    $context = setUpClassTaskContext();
    $ali = classTaskCreateStudent($this, $context['user'], 'Ali');
    $zaid = classTaskCreateStudent($this, $context['user'], 'Zaid');
    $task = createClassTaskThroughEndpoint($this, $context['user'], $context);

    $payload = ['rows' => [
        ['student_id' => $ali->id, 'score' => 80],
        ['student_id' => $zaid->id, 'score' => 70.5],
    ]];

    $this->actingAs($context['user'])->putJson("/api/v1/class-tasks/{$task->id}/scores/bulk", $payload)
        ->assertOk()->assertJsonCount(2, 'data');

    expect(StudentTaskScore::count())->toBe(2);

    $payload['rows'][0]['score'] = 88;
    $this->actingAs($context['user'])->putJson("/api/v1/class-tasks/{$task->id}/scores/bulk", $payload)->assertOk();

    expect(StudentTaskScore::count())->toBe(2);
    $aliScore = StudentTaskScore::where('class_task_id', $task->id)->where('student_id', $ali->id)->firstOrFail();
    expect((float) $aliScore->score)->toBe(88.0);
    expect($aliScore->school_id)->toBe($context['school']->id);
    expect($aliScore->created_by)->toBe($context['user']->id);
});

test('bulk scores keeps NULL as NULL, 0 as 0, and clearing keeps the row', function () {
    $context = setUpClassTaskContext();
    $ali = classTaskCreateStudent($this, $context['user'], 'Ali');
    $task = createClassTaskThroughEndpoint($this, $context['user'], $context);

    $this->actingAs($context['user'])->putJson("/api/v1/class-tasks/{$task->id}/scores/bulk", [
        'rows' => [['student_id' => $ali->id, 'score' => 0]],
    ])->assertOk()->assertJsonCount(1, 'data');

    $score = StudentTaskScore::where('class_task_id', $task->id)->where('student_id', $ali->id)->firstOrFail();
    expect($score->score)->not->toBeNull();
    expect((float) $score->score)->toBe(0.0);

    $clearer = User::factory()->create(['name' => 'Pengurus Pembersih']);
    $clearer->assignRole('super_admin');

    $response = $this->actingAs($clearer)->putJson("/api/v1/class-tasks/{$task->id}/scores/bulk", [
        'rows' => [['student_id' => $ali->id, 'score' => null]],
    ])->assertOk();

    $score->refresh();
    expect($score->score)->toBeNull();
    expect($score->updated_by)->toBe($clearer->id);
    expect($score->created_by)->toBe($context['user']->id);
    expect($response->json('data.0.score'))->toBeNull();
    expect($response->json('data.0.updated_by_name'))->toBe('Pengurus Pembersih');
});

test('bulk scores response rows carry updated_at, updated_by and updated_by_name', function () {
    $context = setUpClassTaskContext();
    $ali = classTaskCreateStudent($this, $context['user'], 'Ali');
    $task = createClassTaskThroughEndpoint($this, $context['user'], $context);

    $response = $this->actingAs($context['user'])->putJson("/api/v1/class-tasks/{$task->id}/scores/bulk", [
        'rows' => [['student_id' => $ali->id, 'score' => 77.75]],
    ])->assertOk();

    $row = $response->json('data.0');
    expect($row)->toHaveKeys(['id', 'student_id', 'class_task_id', 'score', 'updated_at', 'updated_by', 'updated_by_name']);
    expect($row['student_id'])->toBe($ali->id);
    expect($row['score'])->toEqual(77.75);
    expect($row['updated_by'])->toBe($context['user']->id);
    expect($row['updated_by_name'])->toBe('Ustadz Tugas');
});

test('bulk scores rejects a student outside the class, a duplicate student, and an invalid score', function () {
    $context = setUpClassTaskContext();
    $ali = classTaskCreateStudent($this, $context['user'], 'Ali');
    $otherClassStudent = classTaskCreateStudent($this, $context['user'], 'Umar Kelas Lain', 'ibtida_1');
    $task = createClassTaskThroughEndpoint($this, $context['user'], $context);

    $response = $this->actingAs($context['user'])->putJson("/api/v1/class-tasks/{$task->id}/scores/bulk", [
        'rows' => [
            ['student_id' => $ali->id, 'score' => 150],
            ['student_id' => $ali->id, 'score' => 50],
            ['student_id' => $otherClassStudent->id, 'score' => 80],
        ],
    ]);

    $response->assertUnprocessable();
    $errors = $response->json('errors');
    expect($errors)->toHaveKey($ali->id);
    expect($errors)->toHaveKey($otherClassStudent->id);
    expect(StudentTaskScore::count())->toBe(0);
});

test('bulk scores rejects a score for a student who joined the class after the tasks date', function () {
    $context = setUpClassTaskContext();
    $ali = classTaskCreateStudent($this, $context['user'], 'Ali'); // entry_date 2025-07-01
    $task = createClassTaskThroughEndpoint($this, $context['user'], $context, ['task_date' => '2025-08-01']);
    $lateStudent = classTaskCreateStudentWithEntryDate($this, $context['user'], 'Zaid Terlambat', '2025-08-15');

    $response = $this->actingAs($context['user'])->putJson("/api/v1/class-tasks/{$task->id}/scores/bulk", [
        'rows' => [
            ['student_id' => $ali->id, 'score' => 80],
            ['student_id' => $lateStudent->id, 'score' => 90],
        ],
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([$lateStudent->id => 'Santri belum masuk kelas pada tanggal tugas ini.']);
    // All-or-nothing: Ali's valid row is not saved either.
    expect(StudentTaskScore::count())->toBe(0);
});

test('bulk scores requires manage-grades permission', function () {
    $context = setUpClassTaskContext();
    $ali = classTaskCreateStudent($this, $context['user'], 'Ali');
    $task = createClassTaskThroughEndpoint($this, $context['user'], $context);

    $this->actingAs(User::factory()->create())
        ->putJson("/api/v1/class-tasks/{$task->id}/scores/bulk", ['rows' => [['student_id' => $ali->id, 'score' => 80]]])
        ->assertForbidden();
});

// ── Tugas factor in the class grade recap ─────────────────────────────────

function classTaskRecapQuery(array $context): string
{
    return '/api/v1/grade-recaps/class?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'subject_book_id' => $context['subjectBook']->id,
    ]);
}

function classTaskRecapFactor(array $rows, string $studentId, string $factorCode): array
{
    $row = collect($rows)->firstWhere('student.id', $studentId);

    return collect($row['factors'])->firstWhere('code', $factorCode);
}

test('the recap averages a students task scores for the Tugas factor', function () {
    $context = setUpClassTaskContext();
    $ali = classTaskCreateStudent($this, $context['user'], 'Ali');

    $taskOne = createClassTaskThroughEndpoint($this, $context['user'], $context, ['title' => 'Tugas 1']);
    $taskTwo = createClassTaskThroughEndpoint($this, $context['user'], $context, ['title' => 'Tugas 2', 'task_date' => '2025-08-10']);

    $this->actingAs($context['user'])->putJson("/api/v1/class-tasks/{$taskOne->id}/scores/bulk", [
        'rows' => [['student_id' => $ali->id, 'score' => 80]],
    ])->assertOk();
    $this->actingAs($context['user'])->putJson("/api/v1/class-tasks/{$taskTwo->id}/scores/bulk", [
        'rows' => [['student_id' => $ali->id, 'score' => 90]],
    ])->assertOk();

    $response = $this->actingAs($context['user'])->getJson(classTaskRecapQuery($context));
    $response->assertOk();

    $tugasFactor = classTaskRecapFactor($response->json('data.rows'), $ali->id, 'tugas');
    expect($tugasFactor['score'])->toEqual(85.0);
    expect($tugasFactor['source'])->toBe('tugas');
    expect($tugasFactor['is_missing'])->toBeFalse();
    expect($tugasFactor['missing_reason'])->toBeNull();
});

test('the recap Tugas factor is null with a reason when a student has an unscored task', function () {
    $context = setUpClassTaskContext();
    $ali = classTaskCreateStudent($this, $context['user'], 'Ali');
    $zaid = classTaskCreateStudent($this, $context['user'], 'Zaid');

    $taskOne = createClassTaskThroughEndpoint($this, $context['user'], $context, ['title' => 'Tugas 1']);

    // Only Ali is scored; Zaid has no student_task_scores row for this task.
    $this->actingAs($context['user'])->putJson("/api/v1/class-tasks/{$taskOne->id}/scores/bulk", [
        'rows' => [['student_id' => $ali->id, 'score' => 80]],
    ])->assertOk();

    $response = $this->actingAs($context['user'])->getJson(classTaskRecapQuery($context));
    $response->assertOk();

    $aliFactor = classTaskRecapFactor($response->json('data.rows'), $ali->id, 'tugas');
    expect($aliFactor['score'])->toEqual(80.0);

    $zaidFactor = classTaskRecapFactor($response->json('data.rows'), $zaid->id, 'tugas');
    expect($zaidFactor['score'])->toBeNull();
    expect($zaidFactor['is_missing'])->toBeTrue();
    expect($zaidFactor['missing_reason'])->toBe('Ada tugas yang belum dinilai');
});

test('the recap Tugas factor is null with a reason when there are no tasks yet', function () {
    $context = setUpClassTaskContext();
    $ali = classTaskCreateStudent($this, $context['user'], 'Ali');

    $response = $this->actingAs($context['user'])->getJson(classTaskRecapQuery($context));
    $response->assertOk();

    $factor = classTaskRecapFactor($response->json('data.rows'), $ali->id, 'tugas');
    expect($factor['score'])->toBeNull();
    expect($factor['is_missing'])->toBeTrue();
    expect($factor['missing_reason'])->toBe('Belum ada tugas');
});

test('a soft-deleted task is excluded from the Tugas average', function () {
    $context = setUpClassTaskContext();
    $ali = classTaskCreateStudent($this, $context['user'], 'Ali');

    $taskOne = createClassTaskThroughEndpoint($this, $context['user'], $context, ['title' => 'Tugas 1']);
    $taskTwo = createClassTaskThroughEndpoint($this, $context['user'], $context, ['title' => 'Tugas 2', 'task_date' => '2025-08-10']);

    $this->actingAs($context['user'])->putJson("/api/v1/class-tasks/{$taskOne->id}/scores/bulk", [
        'rows' => [['student_id' => $ali->id, 'score' => 60]],
    ])->assertOk();
    $this->actingAs($context['user'])->putJson("/api/v1/class-tasks/{$taskTwo->id}/scores/bulk", [
        'rows' => [['student_id' => $ali->id, 'score' => 100]],
    ])->assertOk();

    $this->actingAs($context['user'])->deleteJson("/api/v1/class-tasks/{$taskTwo->id}")->assertOk();

    $response = $this->actingAs($context['user'])->getJson(classTaskRecapQuery($context));
    $response->assertOk();

    $factor = classTaskRecapFactor($response->json('data.rows'), $ali->id, 'tugas');
    // Only taskOne's 60 counts now that taskTwo is soft-deleted.
    expect($factor['score'])->toEqual(60.0);
});

test('the recap Tugas factor for a late student averages only the tasks expected after their entry_date', function () {
    $context = setUpClassTaskContext();
    $taskBeforeEntry = createClassTaskThroughEndpoint($this, $context['user'], $context, ['title' => 'Sebelum Masuk', 'task_date' => '2025-08-01']);
    $taskAfterEntry = createClassTaskThroughEndpoint($this, $context['user'], $context, ['title' => 'Setelah Masuk', 'task_date' => '2025-09-10']);
    // Joins after taskBeforeEntry but before taskAfterEntry.
    $lateStudent = classTaskCreateStudentWithEntryDate($this, $context['user'], 'Zaid Terlambat', '2025-09-01');

    // A score on the task before entry would be rejected by the bulk
    // endpoint (see the dedicated 422 test) — only the expected task is scored.
    $this->actingAs($context['user'])->putJson("/api/v1/class-tasks/{$taskAfterEntry->id}/scores/bulk", [
        'rows' => [['student_id' => $lateStudent->id, 'score' => 90]],
    ])->assertOk();

    $response = $this->actingAs($context['user'])->getJson(classTaskRecapQuery($context));
    $response->assertOk();

    $factor = classTaskRecapFactor($response->json('data.rows'), $lateStudent->id, 'tugas');
    // Complete and equal to the one expected task's score — taskBeforeEntry
    // does not drag it down or count as unscored.
    expect($factor['score'])->toEqual(90.0);
    expect($factor['is_missing'])->toBeFalse();
    expect($factor['missing_reason'])->toBeNull();
});

test('the recap Tugas factor is null (Belum ada tugas) for a late student when every task predates their entry', function () {
    $context = setUpClassTaskContext();
    createClassTaskThroughEndpoint($this, $context['user'], $context, ['title' => 'Tugas Lama', 'task_date' => '2025-08-01']);
    // Joins after the only task that exists.
    $lateStudent = classTaskCreateStudentWithEntryDate($this, $context['user'], 'Zaid Terlambat', '2025-09-01');

    $response = $this->actingAs($context['user'])->getJson(classTaskRecapQuery($context));
    $response->assertOk();

    $factor = classTaskRecapFactor($response->json('data.rows'), $lateStudent->id, 'tugas');
    expect($factor['score'])->toBeNull();
    expect($factor['is_missing'])->toBeTrue();
    expect($factor['missing_reason'])->toBe('Belum ada tugas');
});

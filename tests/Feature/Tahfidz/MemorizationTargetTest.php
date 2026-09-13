<?php

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\GradingFactor;
use App\Models\MemorizationTarget;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\SubjectBook;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Akademik\AcademicSemesterService;
use App\Services\Akademik\GradingDefaultsInstaller;
use App\Services\Tahfidz\MemorizationTargetService;
use Database\Seeders\ClassLevelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;
use Database\Seeders\SubjectCategorySeeder;
use Database\Seeders\TahfizhSubjectBookSeeder;
use Illuminate\Validation\ValidationException;

/**
 * Seeds roles, the active school, its class levels, the grading defaults
 * (2 templates, 10 factors) and the "Tahfizh Al-Qur'an" subject book, and
 * creates an academic year with both semesters configured (Task 4's
 * seeders). One active ustadz is created for the target's teacher_id.
 *
 * @return array{user: User, school: School, academicYear: AcademicYear, classLevel: ClassLevel, teacher: Teacher, tahfizhBook: SubjectBook}
 */
function setUpMemorizationTargetContext(): array
{
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    (new ClassLevelSeeder)->run();

    $user = User::factory()->create(['name' => 'Admin Tahfidz']);
    $user->assignRole('super_admin');

    $school = School::where('is_active', true)->firstOrFail();
    app(GradingDefaultsInstaller::class)->installForSchool($school);
    (new SubjectCategorySeeder)->run();
    (new TahfizhSubjectBookSeeder)->run();

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
    $teacher = Teacher::factory()->create([
        'school_id' => $school->id,
        'full_name' => 'Ustadz Hafalan',
        'status' => Teacher::STATUS_ACTIVE,
    ]);
    $tahfizhBook = SubjectBook::where('school_id', $school->id)->where('title', "Tahfizh Al-Qur'an")->firstOrFail();

    return compact('user', 'school', 'academicYear', 'classLevel', 'teacher', 'tahfizhBook');
}

/**
 * Creates a student through the real POST /students endpoint.
 */
function targetCreateStudent($testCase, User $user, string $fullName, string $classLevelSlug = 'tamhidi', string $status = Student::STATUS_ACTIVE): Student
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

    $student = Student::findOrFail($response->json('data.id'));

    if ($status !== Student::STATUS_ACTIVE) {
        $student->forceFill(['status' => $status])->save();
    }

    return $student->fresh();
}

function targetStorePayload(array $context, Student $student, array $overrides = []): array
{
    return array_merge([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'student_id' => $student->id,
        'target_pages' => 40,
        'teacher_id' => $context['teacher']->id,
        'notes' => null,
    ], $overrides);
}

// ── CRUD ─────────────────────────────────────────────────────────────────

test('a Target Hafalan can be created with target_pages, listed and shows juz equivalent', function () {
    $context = setUpMemorizationTargetContext();
    $student = targetCreateStudent($this, $context['user'], 'Santri Hafalan Satu');

    $response = $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $student, ['target_pages' => 45]));

    $response->assertCreated()
        ->assertJsonPath('data.student.id', $student->id)
        ->assertJsonPath('data.teacher.id', $context['teacher']->id);
    expect($response->json('data.target_pages'))->toEqual(45.0);
    expect($response->json('data.target_juz'))->toEqual(2.25);

    $listResponse = $this->actingAs($context['user'])->getJson('/api/v1/memorization-targets?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
    ]));

    $listResponse->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.student.full_name', 'Santri Hafalan Satu')
        ->assertJsonPath('data.0.student.class_level.slug', 'tamhidi')
        ->assertJsonPath('data.0.teacher.full_name', $context['teacher']->full_name);
});

test('target_juz is converted to target_pages at 20 pages per juz, rounded to 1 decimal', function () {
    $context = setUpMemorizationTargetContext();
    $student = targetCreateStudent($this, $context['user'], 'Santri Juz');

    $response = $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $student, [
        'target_pages' => null,
        'target_juz' => 2.125,
    ]));

    $response->assertCreated();
    expect($response->json('data.target_pages'))->toEqual(42.5);
    expect($response->json('data.target_juz'))->toEqual(2.13);
});

test('when both target_pages and target_juz are sent, target_pages wins', function () {
    $context = setUpMemorizationTargetContext();
    $student = targetCreateStudent($this, $context['user'], 'Santri Both');

    $response = $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $student, [
        'target_pages' => 30,
        'target_juz' => 10,
    ]));

    $response->assertCreated();
    expect($response->json('data.target_pages'))->toEqual(30.0);
});

test('a Target Hafalan can be updated (target_juz recompute, teacher, notes)', function () {
    $context = setUpMemorizationTargetContext();
    $student = targetCreateStudent($this, $context['user'], 'Santri Update');
    $otherTeacher = Teacher::factory()->create(['school_id' => $context['school']->id, 'status' => Teacher::STATUS_ACTIVE]);

    $created = $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $student))->assertCreated();
    $targetId = $created->json('data.id');

    $response = $this->actingAs($context['user'])->putJson("/api/v1/memorization-targets/{$targetId}", [
        'target_juz' => 3,
        'teacher_id' => $otherTeacher->id,
        'notes' => 'Perbaikan tajwid',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.teacher.id', $otherTeacher->id)
        ->assertJsonPath('data.notes', 'Perbaikan tajwid');
    expect($response->json('data.target_pages'))->toEqual(60.0);
    expect($response->json('data.target_juz'))->toEqual(3.0);
});

test('deleting a Target Hafalan soft-deletes it and it no longer appears in the list', function () {
    $context = setUpMemorizationTargetContext();
    $student = targetCreateStudent($this, $context['user'], 'Santri Hapus');

    $created = $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $student))->assertCreated();
    $targetId = $created->json('data.id');

    $this->actingAs($context['user'])->deleteJson("/api/v1/memorization-targets/{$targetId}")->assertOk();

    $this->assertSoftDeleted('memorization_targets', ['id' => $targetId]);

    $listResponse = $this->actingAs($context['user'])->getJson('/api/v1/memorization-targets?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
    ]));
    $listResponse->assertOk()->assertJsonCount(0, 'data');
});

test('deleting a Target Hafalan is still allowed when the student already has Tahfizh grades that semester', function () {
    $context = setUpMemorizationTargetContext();
    $student = targetCreateStudent($this, $context['user'], 'Santri Sudah Dinilai');

    $created = $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $student))->assertCreated();
    $targetId = $created->json('data.id');

    $uasTahfizhFactor = GradingFactor::where('school_id', $context['school']->id)->where('code', 'uas_tahfizh')->firstOrFail();

    $grade = StudentGrade::create([
        'school_id' => $context['school']->id,
        'student_id' => $student->id,
        'subject_book_id' => $context['tahfizhBook']->id,
        'grading_factor_id' => $uasTahfizhFactor->id,
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'score' => 88,
        'created_by' => $context['user']->id,
        'updated_by' => $context['user']->id,
    ]);

    $this->actingAs($context['user'])->deleteJson("/api/v1/memorization-targets/{$targetId}")->assertOk();

    $this->assertSoftDeleted('memorization_targets', ['id' => $targetId]);
    $this->assertDatabaseHas('student_grades', ['id' => $grade->id, 'score' => 88]);
});

// ── Validation ───────────────────────────────────────────────────────────

test('creating a Target Hafalan requires either target_pages or target_juz', function () {
    $context = setUpMemorizationTargetContext();
    $student = targetCreateStudent($this, $context['user'], 'Santri Tanpa Target');

    $response = $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $student, ['target_pages' => null]));

    $response->assertStatus(422)->assertJsonValidationErrors(['target_pages', 'target_juz']);
});

test('target_pages above 604 (a full mushaf) is rejected', function () {
    $context = setUpMemorizationTargetContext();
    $student = targetCreateStudent($this, $context['user'], 'Santri Berlebih');

    $response = $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $student, ['target_pages' => 605]));

    $response->assertStatus(422)->assertJsonValidationErrors(['target_pages']);
});

test('target_juz above 30 is rejected', function () {
    $context = setUpMemorizationTargetContext();
    $student = targetCreateStudent($this, $context['user'], 'Santri Juz Berlebih');

    $response = $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $student, ['target_pages' => null, 'target_juz' => 31]));

    $response->assertStatus(422)->assertJsonValidationErrors(['target_juz']);
});

test('teacher_id must be an active teacher of the active school', function () {
    $context = setUpMemorizationTargetContext();
    $student = targetCreateStudent($this, $context['user'], 'Santri Guru Salah');
    $inactiveTeacher = Teacher::factory()->create(['school_id' => $context['school']->id, 'status' => Teacher::STATUS_INACTIVE]);

    $response = $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $student, ['teacher_id' => $inactiveTeacher->id]));

    $response->assertStatus(422)->assertJsonValidationErrors(['teacher_id']);
});

test('the student must belong to the active school and be status active', function () {
    $context = setUpMemorizationTargetContext();
    $inactiveStudent = targetCreateStudent($this, $context['user'], 'Santri Non Aktif', 'tamhidi', Student::STATUS_WITHDRAWN);

    $response = $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $inactiveStudent));

    $response->assertStatus(422)->assertJsonValidationErrors(['student_id']);
});

test('the semester akademik must be configured', function () {
    $context = setUpMemorizationTargetContext();
    $student = targetCreateStudent($this, $context['user'], 'Santri Semester Kosong');

    $unconfiguredYear = AcademicYear::factory()->create([
        'school_id' => $context['school']->id,
        'name' => '2024/2025',
        'start_date' => '2024-07-01',
        'end_date' => '2025-06-30',
        'is_active' => false,
    ]);

    $response = $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $student, [
        'academic_year_id' => $unconfiguredYear->id,
    ]));

    $response->assertStatus(422)->assertJsonValidationErrors(['semester'])
        ->assertJsonPath('errors.semester.0', 'Semester akademik belum dikonfigurasi.');
});

test('a student cannot have two non-deleted Target Hafalan in the same semester', function () {
    $context = setUpMemorizationTargetContext();
    $student = targetCreateStudent($this, $context['user'], 'Santri Duplikat');

    $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $student))->assertCreated();

    $response = $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $student));

    $response->assertStatus(422)->assertJsonValidationErrors(['student_id'])
        ->assertJsonPath('errors.student_id.0', 'Santri ini sudah memiliki Target Hafalan semester ini.');
});

test('after a Target Hafalan is deleted, a new one can be created for the same student and semester', function () {
    $context = setUpMemorizationTargetContext();
    $student = targetCreateStudent($this, $context['user'], 'Santri Ulang');

    $first = $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $student))->assertCreated();
    $this->actingAs($context['user'])->deleteJson('/api/v1/memorization-targets/'.$first->json('data.id'))->assertOk();

    $second = $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $student, ['target_pages' => 50]));

    $second->assertCreated();
    expect($second->json('data.target_pages'))->toEqual(50.0);
});

test('a concurrent insert that wins the race past assertNoExistingTarget() still gets the same 422, not a 500', function () {
    $context = setUpMemorizationTargetContext();
    $student = targetCreateStudent($this, $context['user'], 'Santri Race');

    // Simulates a second, concurrent request that committed its INSERT
    // between this request's assertNoExistingTarget() pre-check and its own
    // INSERT — the partial unique index (uniq_active_memorization_target_per_semester)
    // is what actually rejects the second insert; MemorizationTargetService's
    // private createTargetOrFailAsDuplicate() is what must turn that DB
    // exception into the same 422 the pre-check reports, instead of an
    // uncaught 500. Called directly (bypassing assertNoExistingTarget(), which
    // ran and passed for the "first" request before the race happened) so the
    // test exercises the real catch(UniqueConstraintViolationException) block
    // against a real unique-index violation, not a mocked one.
    MemorizationTarget::create([
        'school_id' => $context['school']->id,
        'student_id' => $student->id,
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'target_pages' => 30,
        'teacher_id' => $context['teacher']->id,
    ]);

    $service = app(MemorizationTargetService::class);
    $createTargetOrFailAsDuplicate = new ReflectionMethod($service, 'createTargetOrFailAsDuplicate');
    $createTargetOrFailAsDuplicate->setAccessible(true);

    $racingInsert = fn () => MemorizationTarget::create([
        'school_id' => $context['school']->id,
        'student_id' => $student->id,
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'target_pages' => 40,
        'teacher_id' => $context['teacher']->id,
    ]);

    try {
        $createTargetOrFailAsDuplicate->invoke($service, $racingInsert);
        $this->fail('Expected a ValidationException to be thrown.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['student_id' => [MemorizationTargetService::MESSAGE_DUPLICATE_TARGET]]);
    }

    // Only the pre-existing ("winning") row was persisted — the racing insert never committed.
    expect(MemorizationTarget::where('student_id', $student->id)->count())->toBe(1);
});

// ── List filters ─────────────────────────────────────────────────────────

test('the list can be filtered by class_level_id and searched by santri name', function () {
    $context = setUpMemorizationTargetContext();
    $studentA = targetCreateStudent($this, $context['user'], 'Ahmad Fulan', 'tamhidi');
    $studentB = targetCreateStudent($this, $context['user'], 'Budi Santoso', 'ibtida_1');

    $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $studentA))->assertCreated();
    $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $studentB))->assertCreated();

    $ibtida1 = ClassLevel::where('school_id', $context['school']->id)->where('slug', 'ibtida_1')->firstOrFail();

    $byClass = $this->actingAs($context['user'])->getJson('/api/v1/memorization-targets?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $ibtida1->id,
    ]));
    $byClass->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.student.full_name', 'Budi Santoso');

    $bySearch = $this->actingAs($context['user'])->getJson('/api/v1/memorization-targets?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'search' => 'ahmad',
    ]));
    $bySearch->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.student.full_name', 'Ahmad Fulan');
});

// ── ADR 0003: appears in gradable subjects, roster is target-driven ────────

test('a class only becomes gradable for Tahfizh once one of its santri has a Target Hafalan', function () {
    $context = setUpMemorizationTargetContext();
    $studentWithTarget = targetCreateStudent($this, $context['user'], 'Santri Bertarget');
    $studentWithoutTarget = targetCreateStudent($this, $context['user'], 'Santri Tanpa Target Dua');

    $beforeResponse = $this->actingAs($context['user'])->getJson('/api/v1/gradable-subjects?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
    ]));
    $beforeResponse->assertOk();
    expect(collect($beforeResponse->json('data'))->pluck('subject_book_id'))->not->toContain($context['tahfizhBook']->id);

    $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $studentWithTarget))->assertCreated();

    $afterResponse = $this->actingAs($context['user'])->getJson('/api/v1/gradable-subjects?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
    ]));
    $afterResponse->assertOk();
    $tahfizhPair = collect($afterResponse->json('data'))->firstWhere('subject_book_id', $context['tahfizhBook']->id);
    expect($tahfizhPair)->not->toBeNull();
    expect($tahfizhPair['is_gradable'])->toBeTrue();
    expect($tahfizhPair['grading_template']['code'])->toBe('tahfizh');

    // A santri in an akademik class (tamhidi) can be given a Tahfizh target (ADR 0003).
    expect($context['classLevel']->category ?? null)->not->toBe('tahfidz');
});

test('the grade grid for the Tahfizh pair only lists santri with a Target Hafalan that semester', function () {
    $context = setUpMemorizationTargetContext();
    $studentWithTarget = targetCreateStudent($this, $context['user'], 'Santri Grid Bertarget');
    $studentWithoutTarget = targetCreateStudent($this, $context['user'], 'Santri Grid Tanpa Target');

    $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $studentWithTarget))->assertCreated();

    $gridResponse = $this->actingAs($context['user'])->getJson('/api/v1/student-grades?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'subject_book_id' => $context['tahfizhBook']->id,
    ]));

    $gridResponse->assertOk();
    $studentIds = collect($gridResponse->json('data.students'))->pluck('id');
    expect($studentIds)->toContain($studentWithTarget->id);
    expect($studentIds)->not->toContain($studentWithoutTarget->id);
});

test('upserting a Tahfizh grade for a santri without a Target Hafalan is rejected', function () {
    $context = setUpMemorizationTargetContext();
    $studentWithTarget = targetCreateStudent($this, $context['user'], 'Santri Upsert Bertarget');
    $studentWithoutTarget = targetCreateStudent($this, $context['user'], 'Santri Upsert Tanpa Target');

    $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $studentWithTarget))->assertCreated();

    $response = $this->actingAs($context['user'])->putJson('/api/v1/student-grades/bulk', [
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'subject_book_id' => $context['tahfizhBook']->id,
        'rows' => [
            ['student_id' => $studentWithTarget->id, 'scores' => ['uas_tahfizh' => 90]],
            ['student_id' => $studentWithoutTarget->id, 'scores' => ['uas_tahfizh' => 80]],
        ],
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('errors.'.$studentWithoutTarget->id.'.0', 'Santri ini belum punya Target Hafalan semester ini.');
});

test('upserting a Tahfizh grade for a santri with a Target Hafalan succeeds', function () {
    $context = setUpMemorizationTargetContext();
    $studentWithTarget = targetCreateStudent($this, $context['user'], 'Santri Upsert Ok');
    $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $studentWithTarget))->assertCreated();

    $response = $this->actingAs($context['user'])->putJson('/api/v1/student-grades/bulk', [
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'subject_book_id' => $context['tahfizhBook']->id,
        'rows' => [
            ['student_id' => $studentWithTarget->id, 'scores' => ['uas_tahfizh' => 92]],
        ],
    ]);

    $response->assertOk();
    expect($response->json('data.0.score'))->toEqual(92.0);
});

test('the class recap for the Tahfizh pair only includes santri with a Target Hafalan', function () {
    $context = setUpMemorizationTargetContext();
    $studentWithTarget = targetCreateStudent($this, $context['user'], 'Santri Rekap Bertarget');
    $studentWithoutTarget = targetCreateStudent($this, $context['user'], 'Santri Rekap Tanpa Target');

    $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $studentWithTarget))->assertCreated();

    $recapResponse = $this->actingAs($context['user'])->getJson('/api/v1/grade-recaps/class?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'subject_book_id' => $context['tahfizhBook']->id,
    ]));

    $recapResponse->assertOk();
    $studentIds = collect($recapResponse->json('data.rows'))->pluck('student.id');
    expect($studentIds)->toContain($studentWithTarget->id);
    expect($studentIds)->not->toContain($studentWithoutTarget->id);
});

test('the per-santri recap only lists the Tahfizh subject for a santri who has a Target Hafalan', function () {
    $context = setUpMemorizationTargetContext();
    $studentWithTarget = targetCreateStudent($this, $context['user'], 'Santri Recap Santri Ya');
    $studentWithoutTarget = targetCreateStudent($this, $context['user'], 'Santri Recap Santri Tidak');

    $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $studentWithTarget))->assertCreated();

    $withTargetResponse = $this->actingAs($context['user'])->getJson('/api/v1/grade-recaps/student/'.$studentWithTarget->id.'?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
    ]));
    $withTargetResponse->assertOk();
    $titlesWithTarget = collect($withTargetResponse->json('data.subjects'))->pluck('subject_book.title');
    expect($titlesWithTarget)->toContain("Tahfizh Al-Qur'an");

    $withoutTargetResponse = $this->actingAs($context['user'])->getJson('/api/v1/grade-recaps/student/'.$studentWithoutTarget->id.'?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
    ]));
    $withoutTargetResponse->assertOk();
    $titlesWithoutTarget = collect($withoutTargetResponse->json('data.subjects'))->pluck('subject_book.title');
    expect($titlesWithoutTarget)->not->toContain("Tahfizh Al-Qur'an");
});

// ── Permissions ──────────────────────────────────────────────────────────

test('viewing the memorization target list requires view-memorization permission', function () {
    $context = setUpMemorizationTargetContext();
    $noPermissionUser = User::factory()->create();

    $response = $this->actingAs($noPermissionUser)->getJson('/api/v1/memorization-targets?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
    ]));

    $response->assertStatus(403);
});

test('creating a memorization target requires manage-memorization permission', function () {
    $context = setUpMemorizationTargetContext();
    $student = targetCreateStudent($this, $context['user'], 'Santri Izin');

    $viewOnlyUser = User::factory()->create();
    $viewOnlyUser->givePermissionTo('view-memorization');

    $response = $this->actingAs($viewOnlyUser)->postJson('/api/v1/memorization-targets', targetStorePayload($context, $student));

    $response->assertStatus(403);
});

// ── Tenancy ──────────────────────────────────────────────────────────────

test('a memorization target from another school 404s on update and delete', function () {
    $context = setUpMemorizationTargetContext();
    $student = targetCreateStudent($this, $context['user'], 'Santri Tenancy');
    $created = $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', targetStorePayload($context, $student))->assertCreated();
    $targetId = $created->json('data.id');

    $otherSchool = School::factory()->create(['is_active' => false]);
    $target = MemorizationTarget::findOrFail($targetId);
    $target->school_id = $otherSchool->id;
    $target->save();

    $this->actingAs($context['user'])->putJson("/api/v1/memorization-targets/{$targetId}", ['notes' => 'x'])->assertNotFound();
    $this->actingAs($context['user'])->deleteJson("/api/v1/memorization-targets/{$targetId}")->assertNotFound();
});

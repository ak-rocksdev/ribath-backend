<?php

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\GradingTemplate;
use App\Models\MemorizationLog;
use App\Models\MemorizationTarget;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\StudentTaskScore;
use App\Models\SubjectBook;
use App\Models\SubjectCategory;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Akademik\AcademicSemesterService;
use App\Services\Akademik\GradingDefaultsInstaller;
use App\Services\Akademik\StudentGradeService;
use Database\Seeders\ClassLevelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;
use Database\Seeders\SubjectCategorySeeder;
use Database\Seeders\TahfizhSubjectBookSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/*
 * Tahfizh untuk Pembimbing Tahfizh (role-ustadz ticket 07, ADR 0003–0005):
 * the santri bimbingan of an Akun Ustadz — the santri whose non-deleted
 * Target Hafalan of the Semester Akademik names his Ustadz as Pembimbing
 * Tahfizh — are the Tahfizh part of his Cakupan Mengajar. He reads their
 * Target Hafalan (never writes one), records, lists, changes and deletes
 * their Setoran and Murajaah as the Ustadz penyimak, sees their Progres
 * hafalan, and enters their UAS Tahfizh; the Kitab Tahfizh is offered to
 * him only in the classes of his santri bimbingan, and a Jadwal Mengajar
 * for the Kitab Tahfizh alone opens nothing (it used to show every santri
 * with a target to the scheduled Ustadz).
 *
 * Every record the rules depend on is created through the real endpoints:
 * accounts via grant-access, santri via POST /students, Target Hafalan and
 * their Pembimbing via POST /memorization-targets, the schedule via
 * /teaching-schedules.
 */

afterEach(function () {
    Carbon::setTestNow();
});

const PEMBIMBING_OUTSIDE_PAIR_MESSAGE = 'Kelas dan kitab ini di luar Cakupan Mengajar Anda.';

const PEMBIMBING_OUTSIDE_STUDENT_MESSAGE = 'Santri ini di luar Cakupan Mengajar Anda.';

/**
 * Semester 1 of 2025/2026 (dated 2025-07-01..2025-12-31, today 2025-09-15
 * WIB) with the school's Kitab Tahfizh. Tamhidi has Ali (Pembimbing:
 * Ustadz Ahmad), Umar (Pembimbing: Ustadz Bakar) and Hasan (no Target
 * Hafalan); Ibtida 1 has Zaid (Pembimbing: Ustadz Bakar). Ustadz Chalid
 * mentors nobody but holds the Jadwal Mengajar of the Kitab Tahfizh in
 * Tamhidi. Ahmad, Bakar and Chalid have an Akun Ustadz; a pengurus who
 * also teaches has roles pengurus_pesantren + ustadz.
 *
 * @return array<string, mixed>
 */
function setUpPembimbingTahfizhContext($testCase): array
{
    Carbon::setTestNow(Carbon::parse('2025-09-15 09:00:00', 'Asia/Jakarta'));

    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    (new ClassLevelSeeder)->run();

    $school = School::where('is_active', true)->firstOrFail();
    app(GradingDefaultsInstaller::class)->installForSchool($school);
    (new SubjectCategorySeeder)->run();
    (new TahfizhSubjectBookSeeder)->run();

    $superAdmin = User::factory()->create(['school_id' => $school->id, 'name' => 'Super Admin']);
    $superAdmin->assignRole('super_admin');

    $pengurus = User::factory()->create(['school_id' => $school->id, 'name' => 'Pengurus']);
    $pengurus->assignRole('pengurus_pesantren');

    $academicYear = AcademicYear::factory()->create([
        'school_id' => $school->id,
        'name' => '2025/2026',
        'start_date' => '2025-07-01',
        'end_date' => '2026-06-30',
        'is_active' => true,
        'active_semester' => 1,
    ]);
    app(AcademicSemesterService::class)->createSemestersForAcademicYear($academicYear);

    $testCase->actingAs($superAdmin)
        ->putJson("/api/v1/academic-years/{$academicYear->id}/semesters/1", [
            'start_date' => '2025-07-01',
            'end_date' => '2025-12-31',
        ])
        ->assertOk();

    $context = [
        'school' => $school,
        'superAdmin' => $superAdmin,
        'pengurus' => $pengurus,
        'academicYear' => $academicYear,
        'tamhidi' => ClassLevel::where('school_id', $school->id)->where('slug', 'tamhidi')->firstOrFail(),
        'ibtida' => ClassLevel::where('school_id', $school->id)->where('slug', 'ibtida_1')->firstOrFail(),
        'tahfizhBook' => SubjectBook::where('school_id', $school->id)->where('title', "Tahfizh Al-Qur'an")->firstOrFail(),
        'ustadzAhmad' => createPembimbingTeacher($school, 'Ustadz Ahmad'),
        'ustadzBakar' => createPembimbingTeacher($school, 'Ustadz Bakar'),
        'ustadzChalid' => createPembimbingTeacher($school, 'Ustadz Chalid'),
    ];

    $context['ahmadAccount'] = grantPembimbingAccess($testCase, $context, $context['ustadzAhmad'], 'ahmad@example.com');
    $context['bakarAccount'] = grantPembimbingAccess($testCase, $context, $context['ustadzBakar'], 'bakar@example.com');
    $context['chalidAccount'] = grantPembimbingAccess($testCase, $context, $context['ustadzChalid'], 'chalid@example.com');

    $context['ali'] = createPembimbingStudent($testCase, $context, 'Ali', 'tamhidi');
    $context['umar'] = createPembimbingStudent($testCase, $context, 'Umar', 'tamhidi');
    $context['hasan'] = createPembimbingStudent($testCase, $context, 'Hasan', 'tamhidi');
    $context['zaid'] = createPembimbingStudent($testCase, $context, 'Zaid', 'ibtida_1');

    $context['aliTargetId'] = createPembimbingTarget($testCase, $context, $context['ali'], $context['ustadzAhmad']);
    $context['umarTargetId'] = createPembimbingTarget($testCase, $context, $context['umar'], $context['ustadzBakar']);
    $context['zaidTargetId'] = createPembimbingTarget($testCase, $context, $context['zaid'], $context['ustadzBakar']);

    $context['timeSlot'] = TimeSlot::factory()->create(['school_id' => $school->id]);
    $context['chalidTahfizhScheduleId'] = createPembimbingSchedule($testCase, $context, $context['tahfizhBook'], $context['ustadzChalid'], 'friday');

    return $context;
}

/** A Jadwal Mengajar in Tamhidi, semester 1, through POST /teaching-schedules; returns its id. */
function createPembimbingSchedule($testCase, array $context, SubjectBook $subjectBook, Teacher $teacher, string $dayOfWeek): string
{
    return $testCase->actingAs($context['superAdmin'])
        ->postJson('/api/v1/teaching-schedules', [
            'academic_year_id' => $context['academicYear']->id,
            'semester' => 1,
            'day_of_week' => $dayOfWeek,
            'time_slot_id' => $context['timeSlot']->id,
            'class_level_ids' => [$context['tamhidi']->id],
            'subject_book_id' => $subjectBook->id,
            'teacher_id' => $teacher->id,
        ])
        ->assertCreated()
        ->json('data.id');
}

function createPembimbingTeacher(School $school, string $fullName): Teacher
{
    return Teacher::factory()->create([
        'school_id' => $school->id,
        'full_name' => $fullName,
        'status' => Teacher::STATUS_ACTIVE,
        'user_id' => null,
    ]);
}

/** Creates the Akun Ustadz through "Beri Akses" (POST /teachers/{teacher}/grant-access) and makes its first-login password change. */
function grantPembimbingAccess($testCase, array $context, Teacher $teacher, string $email): User
{
    $testCase->actingAs($context['superAdmin'])
        ->postJson("/api/v1/teachers/{$teacher->id}/grant-access", [
            'email' => $email,
            'password' => 'password123',
        ])
        ->assertCreated();

    return completeFirstLoginPasswordChange($testCase, User::where('email', $email)->firstOrFail(), 'password123');
}

/** A pengurus who also teaches: "Beri Akses" for his Ustadz, then roles ustadz + pengurus_pesantren. */
function createPembimbingMultiRoleAccount($testCase, array $context): User
{
    $pengurusYangMengajar = createPembimbingTeacher($context['school'], 'Ustadz Pengurus');
    $multiRoleAccount = grantPembimbingAccess($testCase, $context, $pengurusYangMengajar, 'pengurus.ustadz@example.com');
    $testCase->actingAs($context['superAdmin'])
        ->postJson("/api/v1/users/{$multiRoleAccount->id}/roles", ['roles' => ['ustadz', 'pengurus_pesantren']])
        ->assertOk();

    return $multiRoleAccount->fresh();
}

function createPembimbingStudent($testCase, array $context, string $fullName, string $classLevelSlug): Student
{
    $response = $testCase->actingAs($context['superAdmin'])
        ->postJson('/api/v1/students', [
            'full_name' => $fullName,
            'birth_date' => '2012-05-15',
            'gender' => 'L',
            'program' => 'regular',
            'entry_date' => '2025-07-01',
            'class_level' => $classLevelSlug,
            'address' => 'Jl. Contoh No. 1',
        ])
        ->assertCreated();

    return Student::findOrFail($response->json('data.id'));
}

/** A Target Hafalan and its Pembimbing Tahfizh, set by pengurus through POST /memorization-targets; returns its id. */
function createPembimbingTarget($testCase, array $context, Student $student, Teacher $pembimbing, int $semester = 1): string
{
    return $testCase->actingAs($context['pengurus'])
        ->postJson('/api/v1/memorization-targets', [
            'academic_year_id' => $context['academicYear']->id,
            'semester' => $semester,
            'student_id' => $student->id,
            'target_pages' => 40,
            'teacher_id' => $pembimbing->id,
        ])
        ->assertCreated()
        ->json('data.id');
}

/**
 * @return array<string, mixed>
 */
function pembimbingLogPayload(array $context, Student $student, Teacher $penyimak, array $overrides = []): array
{
    return array_merge([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'student_id' => $student->id,
        'teacher_id' => $penyimak->id,
        'log_date' => '2025-09-10',
        'type' => 'new',
        'start_page' => 1,
        'end_page' => 10,
        'quality_score' => 85,
    ], $overrides);
}

/** Records a Setoran through POST /memorization-logs as the given user; returns its id. */
function createPembimbingLog($testCase, User $user, array $context, Student $student, Teacher $penyimak): string
{
    return $testCase->actingAs($user)
        ->postJson('/api/v1/memorization-logs', pembimbingLogPayload($context, $student, $penyimak))
        ->assertCreated()
        ->json('data.id');
}

function pembimbingSemesterQuery(array $context, int $semester = 1, array $extra = []): string
{
    return http_build_query(array_merge([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => $semester,
    ], $extra));
}

function pembimbingClassSubjectQuery(array $context, ClassLevel $classLevel, int $semester = 1): string
{
    return pembimbingSemesterQuery($context, $semester, [
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $context['tahfizhBook']->id,
    ]);
}

/**
 * The classes (by slug, sorted) in which gradable-subjects offers the Kitab Tahfizh to the user.
 *
 * @return array<int, string>
 */
function pembimbingTahfizhClassSlugs($testCase, User $user, array $context, int $semester = 1): array
{
    return collect(
        $testCase->actingAs($user)
            ->getJson('/api/v1/gradable-subjects?'.pembimbingSemesterQuery($context, $semester))
            ->assertOk()
            ->json('data')
    )
        ->where('subject_book_id', $context['tahfizhBook']->id)
        ->pluck('class_level.slug')
        ->sort()
        ->values()
        ->all();
}

/**
 * The santri names of a response list, sorted.
 *
 * @return array<int, string>
 */
function pembimbingNames(array $items, string $namePath): array
{
    return collect($items)->pluck($namePath)->sort()->values()->all();
}

/**
 * @param  array<int, array{student_id: string, scores: array<string, mixed>}>  $rows
 * @return array<string, mixed>
 */
function pembimbingBulkPayload(array $context, ClassLevel $classLevel, array $rows): array
{
    return [
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $context['tahfizhBook']->id,
        'rows' => $rows,
    ];
}

// ── Pilihan kelas × kitab, grid UAS Tahfizh, rekap, Tugas ────────────────

test('the Kitab Tahfizh is offered to a Pembimbing Tahfizh only in the classes of his santri bimbingan', function () {
    $context = setUpPembimbingTahfizhContext($this);
    $multiRoleAccount = createPembimbingMultiRoleAccount($this, $context);

    expect(pembimbingTahfizhClassSlugs($this, $context['ahmadAccount'], $context))->toBe(['tamhidi'])
        ->and(pembimbingTahfizhClassSlugs($this, $context['bakarAccount'], $context))->toBe(['ibtida_1', 'tamhidi'])
        // A Jadwal Mengajar for the Kitab Tahfizh without santri bimbingan opens nothing.
        ->and(pembimbingTahfizhClassSlugs($this, $context['chalidAccount'], $context))->toBe([])
        ->and(pembimbingTahfizhClassSlugs($this, $context['pengurus'], $context))->toBe(['ibtida_1', 'tamhidi'])
        ->and(pembimbingTahfizhClassSlugs($this, $multiRoleAccount, $context))->toBe(['ibtida_1', 'tamhidi']);
});

test('the Tahfizh grid of a Pembimbing lists only his santri bimbingan, while pengurus see every santri with a Target Hafalan', function () {
    $context = setUpPembimbingTahfizhContext($this);
    $multiRoleAccount = createPembimbingMultiRoleAccount($this, $context);

    $gridNames = fn (User $user, ClassLevel $classLevel) => pembimbingNames(
        $this->actingAs($user)
            ->getJson('/api/v1/student-grades?'.pembimbingClassSubjectQuery($context, $classLevel))
            ->assertOk()
            ->json('data.students'),
        'full_name',
    );

    expect($gridNames($context['ahmadAccount'], $context['tamhidi']))->toBe(['Ali'])
        ->and($gridNames($context['bakarAccount'], $context['tamhidi']))->toBe(['Umar'])
        ->and($gridNames($context['bakarAccount'], $context['ibtida']))->toBe(['Zaid'])
        ->and($gridNames($context['pengurus'], $context['tamhidi']))->toBe(['Ali', 'Umar'])
        ->and($gridNames($multiRoleAccount, $context['tamhidi']))->toBe(['Ali', 'Umar']);

    // Ahmad mentors nobody in Ibtida 1.
    $this->actingAs($context['ahmadAccount'])
        ->getJson('/api/v1/student-grades?'.pembimbingClassSubjectQuery($context, $context['ibtida']))
        ->assertForbidden()
        ->assertJsonPath('message', PEMBIMBING_OUTSIDE_PAIR_MESSAGE);
});

test('a Pembimbing saves UAS Tahfizh for his santri bimbingan and is refused per santri for any other santri of the class', function () {
    $context = setUpPembimbingTahfizhContext($this);
    $ahmad = $context['ahmadAccount'];

    $this->actingAs($ahmad)
        ->putJson('/api/v1/student-grades/bulk', pembimbingBulkPayload($context, $context['tamhidi'], [
            ['student_id' => $context['ali']->id, 'scores' => ['uas_tahfizh' => 88]],
        ]))
        ->assertOk();

    $aliGrade = StudentGrade::where('student_id', $context['ali']->id)->sole();
    expect((float) $aliGrade->score)->toBe(88.0)
        ->and($aliGrade->created_by)->toBe($ahmad->id)
        ->and($aliGrade->updated_by)->toBe($ahmad->id);

    // Umar is Bakar's santri bimbingan in the same class; Hasan has no Target Hafalan.
    $this->actingAs($ahmad)
        ->putJson('/api/v1/student-grades/bulk', pembimbingBulkPayload($context, $context['tamhidi'], [
            ['student_id' => $context['ali']->id, 'scores' => ['uas_tahfizh' => 90]],
            ['student_id' => $context['umar']->id, 'scores' => ['uas_tahfizh' => 70]],
            ['student_id' => $context['hasan']->id, 'scores' => ['uas_tahfizh' => 60]],
        ]))
        ->assertUnprocessable()
        ->assertJsonPath("errors.{$context['umar']->id}.0", PEMBIMBING_OUTSIDE_STUDENT_MESSAGE)
        ->assertJsonPath("errors.{$context['hasan']->id}.0", StudentGradeService::MESSAGE_STUDENT_WITHOUT_TARGET)
        ->assertJsonMissingPath("errors.{$context['ali']->id}");

    // All or nothing: Ali keeps 88 and no grade exists for the others.
    expect((float) $aliGrade->fresh()->score)->toBe(88.0)
        ->and(StudentGrade::whereIn('student_id', [$context['umar']->id, $context['hasan']->id])->count())->toBe(0);

    $this->actingAs($ahmad)
        ->putJson('/api/v1/student-grades/bulk', pembimbingBulkPayload($context, $context['ibtida'], [
            ['student_id' => $context['zaid']->id, 'scores' => ['uas_tahfizh' => 70]],
        ]))
        ->assertForbidden()
        ->assertJsonPath('message', PEMBIMBING_OUTSIDE_PAIR_MESSAGE);

    // Pengurus still save for any santri with a Target Hafalan.
    $this->actingAs($context['pengurus'])
        ->putJson('/api/v1/student-grades/bulk', pembimbingBulkPayload($context, $context['tamhidi'], [
            ['student_id' => $context['umar']->id, 'scores' => ['uas_tahfizh' => 70]],
        ]))
        ->assertOk();
});

test('an Ustadz whose Jadwal Mengajar holds the Kitab Tahfizh but who mentors no santri sees no Tahfizh grid, recap or Setoran', function () {
    $context = setUpPembimbingTahfizhContext($this);
    $chalid = $context['chalidAccount'];
    createPembimbingLog($this, $context['pengurus'], $context, $context['ali'], $context['ustadzAhmad']);

    $this->actingAs($chalid)
        ->getJson('/api/v1/student-grades?'.pembimbingClassSubjectQuery($context, $context['tamhidi']))
        ->assertForbidden()
        ->assertJsonPath('message', PEMBIMBING_OUTSIDE_PAIR_MESSAGE);
    $this->actingAs($chalid)
        ->getJson('/api/v1/grade-recaps/class?'.pembimbingClassSubjectQuery($context, $context['tamhidi']))
        ->assertForbidden()
        ->assertJsonPath('message', PEMBIMBING_OUTSIDE_PAIR_MESSAGE);
    $this->actingAs($chalid)
        ->putJson('/api/v1/student-grades/bulk', pembimbingBulkPayload($context, $context['tamhidi'], [
            ['student_id' => $context['ali']->id, 'scores' => ['uas_tahfizh' => 50]],
        ]))
        ->assertForbidden();
    expect(StudentGrade::count())->toBe(0);

    $this->actingAs($chalid)
        ->getJson('/api/v1/memorization-logs?'.pembimbingSemesterQuery($context))
        ->assertOk()
        ->assertJsonCount(0, 'data');
    $this->actingAs($chalid)
        ->getJson('/api/v1/memorization-targets?'.pembimbingSemesterQuery($context))
        ->assertOk()
        ->assertJsonCount(0, 'data');
    $this->actingAs($chalid)
        ->getJson("/api/v1/students/{$context['ali']->id}/memorization-progress?".pembimbingSemesterQuery($context))
        ->assertNotFound();
});

test('the Tahfizh recap of a Pembimbing holds only his santri bimbingan and their Setoran factors', function () {
    $context = setUpPembimbingTahfizhContext($this);
    createPembimbingLog($this, $context['pengurus'], $context, $context['ali'], $context['ustadzAhmad']);
    createPembimbingLog($this, $context['pengurus'], $context, $context['umar'], $context['ustadzBakar']);

    $ahmadRecap = $this->actingAs($context['ahmadAccount'])
        ->getJson('/api/v1/grade-recaps/class?'.pembimbingClassSubjectQuery($context, $context['tamhidi']))
        ->assertOk()
        ->assertJsonPath('data.grading_template.code', 'tahfizh')
        ->assertJsonPath('data.summary.student_count', 1)
        ->assertJsonPath('data.rows.0.student.id', $context['ali']->id);
    $aliTargetFactor = collect($ahmadRecap->json('data.rows.0.factors'))->firstWhere('code', 'target_hafalan');
    expect($ahmadRecap->json('data.rows'))->toHaveCount(1)
        ->and($aliTargetFactor['score'])->not->toBeNull();

    $pengurusRecap = $this->actingAs($context['pengurus'])
        ->getJson('/api/v1/grade-recaps/class?'.pembimbingClassSubjectQuery($context, $context['tamhidi']))
        ->assertOk();
    expect(pembimbingNames($pengurusRecap->json('data.rows'), 'student.full_name'))->toBe(['Ali', 'Umar']);
});

test('a Tugas of the Kitab Tahfizh shows a Pembimbing only his santri bimbingan and refuses scores for other santri', function () {
    $context = setUpPembimbingTahfizhContext($this);
    $ahmad = $context['ahmadAccount'];

    $taskId = $this->actingAs($context['pengurus'])
        ->postJson('/api/v1/class-tasks', [
            'academic_year_id' => $context['academicYear']->id,
            'semester' => 1,
            'class_level_id' => $context['tamhidi']->id,
            'subject_book_id' => $context['tahfizhBook']->id,
            'title' => 'Hafalan Juz 30',
            'task_date' => '2025-08-01',
            'description' => null,
        ])
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($ahmad)
        ->getJson('/api/v1/class-tasks?'.pembimbingClassSubjectQuery($context, $context['tamhidi']))
        ->assertOk()
        ->assertJsonPath('data.0.id', $taskId)
        ->assertJsonPath('data.0.student_count', 1);
    $this->actingAs($ahmad)
        ->getJson("/api/v1/class-tasks/{$taskId}")
        ->assertOk()
        ->assertJsonPath('data.student_count', 1);
    $ahmadScores = $this->actingAs($ahmad)
        ->getJson("/api/v1/class-tasks/{$taskId}/scores")
        ->assertOk();
    expect(pembimbingNames($ahmadScores->json('data.students'), 'full_name'))->toBe(['Ali'])
        ->and(array_keys($ahmadScores->json('data.scores')))->toBe([$context['ali']->id]);

    $this->actingAs($ahmad)
        ->putJson("/api/v1/class-tasks/{$taskId}/scores/bulk", ['rows' => [
            ['student_id' => $context['ali']->id, 'score' => 80],
            ['student_id' => $context['umar']->id, 'score' => 70],
        ]])
        ->assertUnprocessable()
        ->assertJsonPath("errors.{$context['umar']->id}.0", PEMBIMBING_OUTSIDE_STUDENT_MESSAGE);
    expect(StudentTaskScore::count())->toBe(0);

    $this->actingAs($ahmad)
        ->putJson("/api/v1/class-tasks/{$taskId}/scores/bulk", ['rows' => [['student_id' => $context['ali']->id, 'score' => 80]]])
        ->assertOk();

    // The scheduled Ustadz without santri bimbingan does not find the Tugas.
    $this->actingAs($context['chalidAccount'])->getJson("/api/v1/class-tasks/{$taskId}")->assertNotFound();

    // Pengurus keep the whole class, as before.
    $pengurusScores = $this->actingAs($context['pengurus'])->getJson("/api/v1/class-tasks/{$taskId}/scores")->assertOk();
    expect(pembimbingNames($pengurusScores->json('data.students'), 'full_name'))->toBe(['Ali', 'Hasan', 'Umar']);
});

test('santri bimbingan follow the Semester Akademik of their Target Hafalan', function () {
    $context = setUpPembimbingTahfizhContext($this);
    $ahmad = $context['ahmadAccount'];
    // Ahmad mentors Hasan in semester 2 only.
    createPembimbingTarget($this, $context, $context['hasan'], $context['ustadzAhmad'], semester: 2);

    expect(pembimbingTahfizhClassSlugs($this, $ahmad, $context, semester: 2))->toBe(['tamhidi']);

    $semesterTwoGrid = $this->actingAs($ahmad)
        ->getJson('/api/v1/student-grades?'.pembimbingClassSubjectQuery($context, $context['tamhidi'], semester: 2))
        ->assertOk();
    expect(pembimbingNames($semesterTwoGrid->json('data.students'), 'full_name'))->toBe(['Hasan']);

    $semesterOneGrid = $this->actingAs($ahmad)
        ->getJson('/api/v1/student-grades?'.pembimbingClassSubjectQuery($context, $context['tamhidi']))
        ->assertOk();
    expect(pembimbingNames($semesterOneGrid->json('data.students'), 'full_name'))->toBe(['Ali']);

    $mentoredNames = fn (int $semester) => pembimbingNames(
        $this->actingAs($ahmad)->getJson('/api/v1/mentored-students?'.pembimbingSemesterQuery($context, $semester))->assertOk()->json('data'),
        'full_name',
    );
    expect($mentoredNames(1))->toBe(['Ali'])
        ->and($mentoredNames(2))->toBe(['Hasan']);

    $this->actingAs($ahmad)
        ->postJson('/api/v1/memorization-logs', pembimbingLogPayload($context, $context['hasan'], $context['ustadzAhmad']))
        ->assertForbidden()
        ->assertJsonPath('message', PEMBIMBING_OUTSIDE_STUDENT_MESSAGE);
    $this->actingAs($ahmad)
        ->getJson("/api/v1/students/{$context['hasan']->id}/memorization-progress?".pembimbingSemesterQuery($context))
        ->assertNotFound();
    $this->actingAs($ahmad)
        ->getJson("/api/v1/students/{$context['hasan']->id}/memorization-progress?".pembimbingSemesterQuery($context, 2))
        ->assertOk();
});

test('a deleted Target Hafalan takes the santri out of the scope of his Pembimbing', function () {
    $context = setUpPembimbingTahfizhContext($this);
    $ahmad = $context['ahmadAccount'];
    $aliLogId = createPembimbingLog($this, $context['pengurus'], $context, $context['ali'], $context['ustadzAhmad']);

    $this->actingAs($context['pengurus'])
        ->deleteJson("/api/v1/memorization-targets/{$context['aliTargetId']}")
        ->assertOk();

    expect(pembimbingTahfizhClassSlugs($this, $ahmad, $context))->toBe([]);
    // The pair stays gradable through Umar's target, but no longer for Ahmad.
    $this->actingAs($ahmad)
        ->getJson('/api/v1/student-grades?'.pembimbingClassSubjectQuery($context, $context['tamhidi']))
        ->assertForbidden();
    $this->actingAs($ahmad)
        ->getJson('/api/v1/mentored-students?'.pembimbingSemesterQuery($context))
        ->assertOk()
        ->assertJsonCount(0, 'data');
    $this->actingAs($ahmad)
        ->getJson('/api/v1/memorization-targets?'.pembimbingSemesterQuery($context))
        ->assertOk()
        ->assertJsonCount(0, 'data');
    $this->actingAs($ahmad)
        ->getJson('/api/v1/memorization-logs?'.pembimbingSemesterQuery($context))
        ->assertOk()
        ->assertJsonCount(0, 'data');
    $this->actingAs($ahmad)
        ->postJson('/api/v1/memorization-logs', pembimbingLogPayload($context, $context['ali'], $context['ustadzAhmad']))
        ->assertForbidden();
    $this->actingAs($ahmad)->putJson("/api/v1/memorization-logs/{$aliLogId}", ['quality_score' => 70])->assertNotFound();
    $this->actingAs($ahmad)
        ->getJson("/api/v1/students/{$context['ali']->id}/memorization-progress?".pembimbingSemesterQuery($context))
        ->assertNotFound();
});

// ── Log Setoran dan Progres hafalan ──────────────────────────────────────

test('a Pembimbing records a Setoran for his santri bimbingan as the Ustadz penyimak, whatever penyimak the request names', function () {
    $context = setUpPembimbingTahfizhContext($this);
    $ahmad = $context['ahmadAccount'];

    $logId = $this->actingAs($ahmad)
        ->postJson('/api/v1/memorization-logs', pembimbingLogPayload($context, $context['ali'], $context['ustadzBakar']))
        ->assertCreated()
        ->assertJsonPath('data.student.id', $context['ali']->id)
        ->assertJsonPath('data.teacher.id', $context['ustadzAhmad']->id)
        ->assertJsonPath('data.teacher.full_name', 'Ustadz Ahmad')
        ->json('data.id');

    $log = MemorizationLog::findOrFail($logId);
    expect($log->teacher_id)->toBe($context['ustadzAhmad']->id)
        ->and($log->created_by)->toBe($ahmad->id)
        ->and($log->updated_by)->toBe($ahmad->id);
});

test('a santri outside the bimbingan is refused with 403 on Log Setoran create and on the list filter', function () {
    $context = setUpPembimbingTahfizhContext($this);
    $ahmad = $context['ahmadAccount'];

    foreach ([$context['umar'], $context['hasan']] as $studentOutsideBimbingan) {
        $this->actingAs($ahmad)
            ->postJson('/api/v1/memorization-logs', pembimbingLogPayload($context, $studentOutsideBimbingan, $context['ustadzAhmad']))
            ->assertForbidden()
            ->assertJsonPath('message', PEMBIMBING_OUTSIDE_STUDENT_MESSAGE);
    }
    expect(MemorizationLog::count())->toBe(0);

    $this->actingAs($ahmad)
        ->getJson('/api/v1/memorization-logs?'.pembimbingSemesterQuery($context, 1, ['student_id' => $context['umar']->id]))
        ->assertForbidden()
        ->assertJsonPath('message', PEMBIMBING_OUTSIDE_STUDENT_MESSAGE);
    $this->actingAs($ahmad)
        ->getJson('/api/v1/memorization-logs?'.pembimbingSemesterQuery($context, 1, ['student_id' => $context['ali']->id]))
        ->assertOk();
});

test('the Log Setoran list of a Pembimbing holds only the logs of his santri bimbingan', function () {
    $context = setUpPembimbingTahfizhContext($this);
    // A substitute listened to Ali once; Umar and Zaid set to Bakar.
    createPembimbingLog($this, $context['pengurus'], $context, $context['ali'], $context['ustadzBakar']);
    createPembimbingLog($this, $context['pengurus'], $context, $context['umar'], $context['ustadzBakar']);
    createPembimbingLog($this, $context['pengurus'], $context, $context['zaid'], $context['ustadzBakar']);
    $multiRoleAccount = createPembimbingMultiRoleAccount($this, $context);

    $listedNames = fn (User $user) => pembimbingNames(
        $this->actingAs($user)->getJson('/api/v1/memorization-logs?'.pembimbingSemesterQuery($context))->assertOk()->json('data'),
        'student.full_name',
    );

    expect($listedNames($context['ahmadAccount']))->toBe(['Ali'])
        ->and($listedNames($context['bakarAccount']))->toBe(['Umar', 'Zaid'])
        ->and($listedNames($context['chalidAccount']))->toBe([])
        ->and($listedNames($context['pengurus']))->toBe(['Ali', 'Umar', 'Zaid'])
        ->and($listedNames($multiRoleAccount))->toBe(['Ali', 'Umar', 'Zaid']);
});

test('a log outside the bimbingan is not found on update and delete; inside it the stored penyimak is kept on update', function () {
    $context = setUpPembimbingTahfizhContext($this);
    $ahmad = $context['ahmadAccount'];
    $umarLogId = createPembimbingLog($this, $context['pengurus'], $context, $context['umar'], $context['ustadzBakar']);
    $aliLogId = createPembimbingLog($this, $context['pengurus'], $context, $context['ali'], $context['ustadzBakar']);

    // Not found even before the body is validated.
    $this->actingAs($ahmad)->putJson("/api/v1/memorization-logs/{$umarLogId}", ['quality_score' => 999])->assertNotFound();
    $this->actingAs($ahmad)->deleteJson("/api/v1/memorization-logs/{$umarLogId}")->assertNotFound();
    expect(MemorizationLog::find($umarLogId))->not->toBeNull()
        ->and(MemorizationLog::findOrFail($umarLogId)->quality_score)->toBe(85);

    // Bakar listened to Ali's Setoran: Ahmad's edit keeps him, with or without a teacher_id in the request.
    $this->actingAs($ahmad)
        ->putJson("/api/v1/memorization-logs/{$aliLogId}", ['quality_score' => 90])
        ->assertOk()
        ->assertJsonPath('data.quality_score', 90)
        ->assertJsonPath('data.teacher.id', $context['ustadzBakar']->id);
    $this->actingAs($ahmad)
        ->putJson("/api/v1/memorization-logs/{$aliLogId}", ['quality_score' => 92, 'teacher_id' => $context['ustadzAhmad']->id])
        ->assertOk()
        ->assertJsonPath('data.quality_score', 92)
        ->assertJsonPath('data.teacher.id', $context['ustadzBakar']->id);
    $aliLog = MemorizationLog::findOrFail($aliLogId);
    expect($aliLog->teacher_id)->toBe($context['ustadzBakar']->id)
        ->and($aliLog->created_by)->toBe($context['pengurus']->id)
        ->and($aliLog->updated_by)->toBe($ahmad->id);

    // Pengurus still change the penyimak.
    $this->actingAs($context['pengurus'])
        ->putJson("/api/v1/memorization-logs/{$aliLogId}", ['teacher_id' => $context['ustadzAhmad']->id])
        ->assertOk()
        ->assertJsonPath('data.teacher.id', $context['ustadzAhmad']->id);

    $this->actingAs($ahmad)->deleteJson("/api/v1/memorization-logs/{$aliLogId}")->assertOk();
    expect(MemorizationLog::find($aliLogId))->toBeNull()
        ->and(MemorizationLog::withTrashed()->findOrFail($aliLogId)->updated_by)->toBe($ahmad->id);
});

test('Progres hafalan opens for the santri bimbingan only', function () {
    $context = setUpPembimbingTahfizhContext($this);
    createPembimbingLog($this, $context['pengurus'], $context, $context['ali'], $context['ustadzAhmad']);
    $progressUrl = fn (Student $student) => "/api/v1/students/{$student->id}/memorization-progress?".pembimbingSemesterQuery($context);

    $aliProgress = $this->actingAs($context['ahmadAccount'])
        ->getJson($progressUrl($context['ali']))
        ->assertOk()
        ->assertJsonPath('data.new_count', 1);
    expect($aliProgress->json('data.target_pages'))->toEqual(40.0);
    $this->actingAs($context['ahmadAccount'])->getJson($progressUrl($context['umar']))->assertNotFound();
    $this->actingAs($context['ahmadAccount'])->getJson($progressUrl($context['hasan']))->assertNotFound();
    $this->actingAs($context['bakarAccount'])->getJson($progressUrl($context['umar']))->assertOk();
    $this->actingAs($context['pengurus'])->getJson($progressUrl($context['umar']))->assertOk();
    $this->actingAs($context['pengurus'])->getJson($progressUrl($context['hasan']))->assertOk();
});

// ── Target Hafalan dan santri bimbingan ──────────────────────────────────

test('the Target Hafalan list of a Pembimbing holds only his santri bimbingan and every write is refused', function () {
    $context = setUpPembimbingTahfizhContext($this);
    $ahmad = $context['ahmadAccount'];
    $multiRoleAccount = createPembimbingMultiRoleAccount($this, $context);

    $listedNames = fn (User $user) => pembimbingNames(
        $this->actingAs($user)->getJson('/api/v1/memorization-targets?'.pembimbingSemesterQuery($context))->assertOk()->json('data'),
        'student.full_name',
    );
    expect($listedNames($ahmad))->toBe(['Ali'])
        ->and($listedNames($context['bakarAccount']))->toBe(['Umar', 'Zaid'])
        ->and($listedNames($context['pengurus']))->toBe(['Ali', 'Umar', 'Zaid'])
        ->and($listedNames($multiRoleAccount))->toBe(['Ali', 'Umar', 'Zaid']);

    $this->actingAs($ahmad)
        ->postJson('/api/v1/memorization-targets', [
            'academic_year_id' => $context['academicYear']->id,
            'semester' => 1,
            'student_id' => $context['hasan']->id,
            'target_pages' => 20,
            'teacher_id' => $context['ustadzAhmad']->id,
        ])
        ->assertForbidden();
    $this->actingAs($ahmad)
        ->putJson("/api/v1/memorization-targets/{$context['aliTargetId']}", ['target_pages' => 80])
        ->assertForbidden();
    $this->actingAs($ahmad)
        ->putJson("/api/v1/memorization-targets/{$context['umarTargetId']}", ['teacher_id' => $context['ustadzAhmad']->id])
        ->assertForbidden();
    $this->actingAs($ahmad)
        ->deleteJson("/api/v1/memorization-targets/{$context['aliTargetId']}")
        ->assertForbidden();

    expect(MemorizationTarget::count())->toBe(3)
        ->and((float) MemorizationTarget::findOrFail($context['aliTargetId'])->target_pages)->toBe(40.0)
        ->and(MemorizationTarget::findOrFail($context['umarTargetId'])->teacher_id)->toBe($context['ustadzBakar']->id);
});

test('the santri bimbingan endpoint lists the santri whose Setoran the user may record', function () {
    $context = setUpPembimbingTahfizhContext($this);
    $multiRoleAccount = createPembimbingMultiRoleAccount($this, $context);
    $url = '/api/v1/mentored-students?'.pembimbingSemesterQuery($context);

    $this->actingAs($context['ahmadAccount'])
        ->getJson($url)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $context['ali']->id)
        ->assertJsonPath('data.0.full_name', 'Ali')
        ->assertJsonPath('data.0.is_active_student', true)
        ->assertJsonPath('data.0.class_level.slug', 'tamhidi');

    $bakarResponse = $this->actingAs($context['bakarAccount'])->getJson($url)->assertOk();
    expect(pembimbingNames($bakarResponse->json('data'), 'full_name'))->toBe(['Umar', 'Zaid']);

    foreach ([$context['pengurus'], $multiRoleAccount] as $unrestrictedUser) {
        $response = $this->actingAs($unrestrictedUser)->getJson($url)->assertOk();
        expect(pembimbingNames($response->json('data'), 'full_name'))->toBe(['Ali', 'Umar', 'Zaid']);
    }

    $this->actingAs($context['chalidAccount'])->getJson($url)->assertOk()->assertJsonCount(0, 'data');

    // An account with the ustadz role but no linked Ustadz mentors nobody.
    $unlinkedUstadz = User::factory()->create(['school_id' => $context['school']->id]);
    $unlinkedUstadz->assignRole('ustadz');
    $this->actingAs($unlinkedUstadz)->getJson($url)->assertOk()->assertJsonCount(0, 'data');

    $this->actingAs($context['ahmadAccount'])
        ->getJson('/api/v1/mentored-students')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['academic_year_id', 'semester']);
});

// ── Izin, validasi, tenancy ──────────────────────────────────────────────

test('a pengurus who also teaches records and reads Tahfidz without limits and keeps the penyimak he names', function () {
    $context = setUpPembimbingTahfizhContext($this);
    $multiRoleAccount = createPembimbingMultiRoleAccount($this, $context);

    $this->actingAs($multiRoleAccount)
        ->postJson('/api/v1/memorization-logs', pembimbingLogPayload($context, $context['umar'], $context['ustadzBakar']))
        ->assertCreated()
        ->assertJsonPath('data.teacher.id', $context['ustadzBakar']->id);
    $this->actingAs($multiRoleAccount)
        ->getJson("/api/v1/students/{$context['hasan']->id}/memorization-progress?".pembimbingSemesterQuery($context))
        ->assertOk();
    $this->actingAs($multiRoleAccount)
        ->putJson("/api/v1/memorization-targets/{$context['aliTargetId']}", ['target_pages' => 60])
        ->assertOk();
});

test('viewing memorization within the bimbingan does not allow recording it, and neither permission opens nothing', function () {
    $context = setUpPembimbingTahfizhContext($this);
    $aliLogId = createPembimbingLog($this, $context['pengurus'], $context, $context['ali'], $context['ustadzAhmad']);

    Role::create(['name' => 'penyimak_baca_saja', 'guard_name' => 'web'])->givePermissionTo('view-own-memorization');
    $viewOnlyAccount = grantPembimbingAccess($this, $context, createPembimbingTeacher($context['school'], 'Ustadz Baca Saja'), 'baca.saja@example.com');
    $viewOnlyAccount->syncRoles(['penyimak_baca_saja']);
    createPembimbingTarget($this, $context, $context['hasan'], $viewOnlyAccount->teacher);

    $this->actingAs($viewOnlyAccount)
        ->getJson('/api/v1/memorization-logs?'.pembimbingSemesterQuery($context))
        ->assertOk();
    $this->actingAs($viewOnlyAccount)
        ->postJson('/api/v1/memorization-logs', pembimbingLogPayload($context, $context['hasan'], $viewOnlyAccount->teacher))
        ->assertForbidden();

    $accountWithoutMemorization = User::factory()->create(['school_id' => $context['school']->id]);
    $semesterQuery = pembimbingSemesterQuery($context);
    foreach ([
        ['get', "/api/v1/memorization-logs?{$semesterQuery}", []],
        ['post', '/api/v1/memorization-logs', pembimbingLogPayload($context, $context['ali'], $context['ustadzAhmad'])],
        ['put', "/api/v1/memorization-logs/{$aliLogId}", ['quality_score' => 70]],
        ['delete', "/api/v1/memorization-logs/{$aliLogId}", []],
        ['get', "/api/v1/memorization-targets?{$semesterQuery}", []],
        ['get', "/api/v1/mentored-students?{$semesterQuery}", []],
        ['get', "/api/v1/students/{$context['ali']->id}/memorization-progress?{$semesterQuery}", []],
    ] as [$method, $url, $body]) {
        expect($this->actingAs($accountWithoutMemorization)->json($method, $url, $body)->status())
            ->toBe(403, "{$method} {$url} must refuse an account without memorization permissions");
    }
});

test('an Akun Ustadz gets the same validation and tenancy answers on Log Setoran as pengurus', function () {
    $context = setUpPembimbingTahfizhContext($this);

    foreach ([$context['ahmadAccount'], $context['pengurus']] as $user) {
        $this->actingAs($user)
            ->postJson('/api/v1/memorization-logs', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['academic_year_id', 'semester', 'student_id', 'teacher_id', 'log_date', 'type', 'quality_score']);
        $this->actingAs($user)
            ->postJson('/api/v1/memorization-logs', pembimbingLogPayload($context, $context['ali'], $context['ustadzAhmad'], ['log_date' => '2025-09-20']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['log_date']);
    }

    $aliLogId = createPembimbingLog($this, $context['pengurus'], $context, $context['ali'], $context['ustadzAhmad']);
    $aliLog = MemorizationLog::findOrFail($aliLogId);
    $aliLog->school_id = School::factory()->create(['is_active' => false])->id;
    $aliLog->save();

    $this->actingAs($context['ahmadAccount'])->putJson("/api/v1/memorization-logs/{$aliLogId}", ['quality_score' => 70])->assertNotFound();
    $this->actingAs($context['ahmadAccount'])->deleteJson("/api/v1/memorization-logs/{$aliLogId}")->assertNotFound();
});

// ── Profil ───────────────────────────────────────────────────────────────

test('the profile of an Akun Ustadz names his Ustadz, the Ustadz penyimak of his Setoran', function () {
    $context = setUpPembimbingTahfizhContext($this);

    $this->postJson('/api/v1/auth/login', ['email' => 'ahmad@example.com', 'password' => PASSWORD_CHOSEN_AT_FIRST_LOGIN])
        ->assertOk()
        ->assertJsonPath('data.user.teacher.id', $context['ustadzAhmad']->id)
        ->assertJsonPath('data.user.teacher.full_name', 'Ustadz Ahmad');

    $this->actingAs($context['ahmadAccount'])
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.teacher.id', $context['ustadzAhmad']->id)
        ->assertJsonPath('data.teacher.full_name', 'Ustadz Ahmad');

    $this->actingAs($context['pengurus'])
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.teacher', null);
});

test('an inactive santri bimbingan stays listed, marked, since a Setoran needs an active santri', function () {
    $context = setUpPembimbingTahfizhContext($this);

    $this->actingAs($context['superAdmin'])
        ->patchJson("/api/v1/students/{$context['umar']->id}/status", ['status' => Student::STATUS_WITHDRAWN])
        ->assertOk();

    $bakarList = collect($this->actingAs($context['bakarAccount'])
        ->getJson('/api/v1/mentored-students?'.pembimbingSemesterQuery($context))
        ->assertOk()
        ->json('data'))
        ->mapWithKeys(fn (array $mentoredStudent) => [$mentoredStudent['full_name'] => $mentoredStudent['is_active_student']])
        ->all();

    expect($bakarList)->toBe(['Umar' => false, 'Zaid' => true]);
});

test('the santri bimbingan endpoint stays within the active school', function () {
    $context = setUpPembimbingTahfizhContext($this);
    $otherSchool = School::factory()->create(['is_active' => false]);
    $otherSchoolYear = AcademicYear::factory()->create(['school_id' => $otherSchool->id, 'name' => '2025/2026']);
    $otherSchoolStudent = Student::factory()->create(['school_id' => $otherSchool->id, 'full_name' => 'Santri Pesantren Lain']);
    // A target of another school naming Ahmad cannot make its santri his santri bimbingan.
    MemorizationTarget::create([
        'school_id' => $otherSchool->id,
        'student_id' => $otherSchoolStudent->id,
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'target_pages' => 20,
        'teacher_id' => $context['ustadzAhmad']->id,
    ]);

    $ahmadNames = pembimbingNames(
        $this->actingAs($context['ahmadAccount'])->getJson('/api/v1/mentored-students?'.pembimbingSemesterQuery($context))->assertOk()->json('data'),
        'full_name',
    );
    $pengurusNames = pembimbingNames(
        $this->actingAs($context['pengurus'])->getJson('/api/v1/mentored-students?'.pembimbingSemesterQuery($context))->assertOk()->json('data'),
        'full_name',
    );
    expect($ahmadNames)->toBe(['Ali'])
        ->and($pengurusNames)->toBe(['Ali', 'Umar', 'Zaid']);

    foreach ([$context['ahmadAccount'], $context['pengurus']] as $user) {
        $this->actingAs($user)
            ->getJson('/api/v1/mentored-students?'.http_build_query(['academic_year_id' => $otherSchoolYear->id, 'semester' => 1]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['academic_year_id']);
    }
});

// ── Kitab Tahfizh = any kitab with the Tahfizh template ──────────────────

test('a second Kitab Tahfizh scheduled for an Ustadz who mentors no santri opens nothing to him', function () {
    $context = setUpPembimbingTahfizhContext($this);
    $tahfizhTemplate = GradingTemplate::where('school_id', $context['school']->id)->where('code', 'tahfizh')->firstOrFail();
    $juzAmma = SubjectBook::factory()->create([
        'school_id' => $context['school']->id,
        'subject_category_id' => SubjectCategory::factory()->create(['school_id' => $context['school']->id])->id,
        'grading_template_id' => $tahfizhTemplate->id,
        'title' => 'Tahfizh Juz Amma',
    ]);
    createPembimbingSchedule($this, $context, $juzAmma, $context['ustadzChalid'], 'thursday');
    $juzAmmaQuery = pembimbingSemesterQuery($context, 1, [
        'class_level_id' => $context['tamhidi']->id,
        'subject_book_id' => $juzAmma->id,
    ]);

    $listsJuzAmma = fn (User $user) => collect(
        $this->actingAs($user)->getJson('/api/v1/gradable-subjects?'.pembimbingSemesterQuery($context))->assertOk()->json('data')
    )->contains('subject_book_id', $juzAmma->id);
    expect($listsJuzAmma($context['chalidAccount']))->toBeFalse()
        ->and($listsJuzAmma($context['pengurus']))->toBeTrue()
        // Ahmad mentors Ali in Tamhidi, so every Kitab Tahfizh of Tamhidi counts his santri bimbingan.
        ->and($listsJuzAmma($context['ahmadAccount']))->toBeTrue();

    foreach ([
        $this->actingAs($context['chalidAccount'])->getJson("/api/v1/student-grades?{$juzAmmaQuery}"),
        $this->actingAs($context['chalidAccount'])->getJson("/api/v1/grade-recaps/class?{$juzAmmaQuery}"),
        $this->actingAs($context['chalidAccount'])->putJson('/api/v1/student-grades/bulk', [
            'academic_year_id' => $context['academicYear']->id,
            'semester' => 1,
            'class_level_id' => $context['tamhidi']->id,
            'subject_book_id' => $juzAmma->id,
            'rows' => [['student_id' => $context['ali']->id, 'scores' => ['uas_tahfizh' => 50]]],
        ]),
    ] as $refusal) {
        $refusal->assertForbidden()->assertJsonPath('message', PEMBIMBING_OUTSIDE_PAIR_MESSAGE);
    }
    expect(StudentGrade::count())->toBe(0);

    $ahmadGrid = $this->actingAs($context['ahmadAccount'])->getJson("/api/v1/student-grades?{$juzAmmaQuery}")->assertOk();
    expect(pembimbingNames($ahmadGrid->json('data.students'), 'full_name'))->toBe(['Ali']);

    $taskId = $this->actingAs($context['pengurus'])
        ->postJson('/api/v1/class-tasks', [
            'academic_year_id' => $context['academicYear']->id,
            'semester' => 1,
            'class_level_id' => $context['tamhidi']->id,
            'subject_book_id' => $juzAmma->id,
            'title' => 'Hafalan An-Naba',
            'task_date' => '2025-08-01',
            'description' => null,
        ])
        ->assertCreated()
        ->json('data.id');
    $this->actingAs($context['chalidAccount'])->getJson("/api/v1/class-tasks/{$taskId}/scores")->assertNotFound();
    $ahmadScores = $this->actingAs($context['ahmadAccount'])->getJson("/api/v1/class-tasks/{$taskId}/scores")->assertOk();
    expect(pembimbingNames($ahmadScores->json('data.students'), 'full_name'))->toBe(['Ali']);
});

// ── Absensi: a Jadwal Mengajar of the Kitab Tahfizh is like any other ────

test('the Ustadz holding the Kitab Tahfizh schedule records its Pertemuan and is alerted, while a Pembimbing who does not hold it cannot', function () {
    $context = setUpPembimbingTahfizhContext($this);
    $chalid = $context['chalidAccount'];
    $ahmad = $context['ahmadAccount'];
    $scheduleId = $context['chalidTahfizhScheduleId'];
    // A week after the schedule was created (2025-09-15): Friday 2025-09-19 went unrecorded.
    Carbon::setTestNow(Carbon::parse('2025-09-22 09:00:00', 'Asia/Jakarta'));
    $recordPertemuan = fn (User $user, string $sessionDate) => $this->actingAs($user)->postJson('/api/v1/class-sessions', [
        'teaching_schedule_id' => $scheduleId,
        'session_date' => $sessionDate,
        'attendances' => collect([$context['ali'], $context['umar'], $context['hasan']])
            ->map(fn (Student $tamhidiSantri) => ['student_id' => $tamhidiSantri->id, 'status' => 'present', 'notes' => null])
            ->all(),
    ]);
    $attendanceScheduleIds = fn (User $user) => collect(
        $this->actingAs($user)->getJson('/api/v1/attendance-schedules?'.pembimbingSemesterQuery($context))->assertOk()->json('data')
    )->pluck('id')->all();
    $alertedSchedules = fn (User $user) => collect(
        $this->actingAs($user)->getJson('/api/v1/attendance-alerts')->assertOk()->json('data.teachers')
    )->flatMap(fn (array $teacherGroup) => collect($teacherGroup['items'])->pluck('teaching_schedule_id'))->unique()->values()->all();

    // The holder: listed, alerted for his missed Friday, and records it.
    expect($attendanceScheduleIds($chalid))->toBe([$scheduleId])
        ->and($alertedSchedules($chalid))->toBe([$scheduleId]);
    $this->actingAs($chalid)
        ->getJson("/api/v1/teaching-schedules/{$scheduleId}/expected-students?session_date=2025-09-19")
        ->assertOk();
    $recordPertemuan($chalid, '2025-09-19')->assertCreated();
    $this->actingAs($chalid)
        ->getJson('/api/v1/attendance-recaps?'.pembimbingClassSubjectQuery($context, $context['tamhidi']))
        ->assertOk();

    // A Pembimbing of the class who does not hold the schedule.
    expect($attendanceScheduleIds($ahmad))->toBe([])
        ->and($alertedSchedules($ahmad))->toBe([]);
    $this->actingAs($ahmad)
        ->getJson("/api/v1/teaching-schedules/{$scheduleId}/expected-students?session_date=2025-09-19")
        ->assertNotFound();
    $recordPertemuan($ahmad, '2025-09-19')
        ->assertForbidden()
        ->assertJsonPath('message', PEMBIMBING_OUTSIDE_PAIR_MESSAGE);
    $this->actingAs($ahmad)
        ->getJson('/api/v1/attendance-recaps?'.pembimbingClassSubjectQuery($context, $context['tamhidi']))
        ->assertForbidden();
});

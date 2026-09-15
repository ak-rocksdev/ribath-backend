<?php

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\GradingTemplate;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\SubjectBook;
use App\Models\SubjectCategory;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Akademik\AcademicSemesterService;
use App\Services\Akademik\GradingDefaultsInstaller;
use Database\Seeders\ClassLevelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;
use Spatie\Permission\Models\Role;

/*
 * Cakupan Mengajar for Input Nilai and Adab & Keaktifan (role-ustadz
 * ticket 02, ADR 0004): an Akun Ustadz — only role `ustadz`, created
 * through "Beri Akses" — works on the Kelas × Kitab pairs of his own
 * Jadwal Mengajar in the chosen Semester Akademik; pengurus and a
 * pengurus who also teaches are never restricted.
 *
 * Every record the rules depend on is created through the real endpoints:
 * accounts via grant-access, schedules via /teaching-schedules, santri via
 * POST /students.
 */

const TEACHING_SCOPE_OUTSIDE_MESSAGE = 'Kelas dan kitab ini di luar Cakupan Mengajar Anda.';

/**
 * Tamhidi learns Safinatun Najah from Ustadz Ahmad and Ibtida 1 learns
 * Jurumiyah from Ustadz Bakar, both in semester 1; one santri per class.
 *
 * @return array<string, mixed>
 */
function setUpTeachingScopeContext($testCase): array
{
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    (new ClassLevelSeeder)->run();

    $school = School::where('is_active', true)->firstOrFail();
    app(GradingDefaultsInstaller::class)->installForSchool($school);

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

    $tamhidi = ClassLevel::where('school_id', $school->id)->where('slug', 'tamhidi')->firstOrFail();
    $ibtida = ClassLevel::where('school_id', $school->id)->where('slug', 'ibtida_1')->firstOrFail();

    $safinah = createTeachingScopeSubjectBook($school, 'Safinatun Najah');
    $jurumiyah = createTeachingScopeSubjectBook($school, 'Jurumiyah');

    $ustadzAhmad = Teacher::factory()->create(['school_id' => $school->id, 'full_name' => 'Ustadz Ahmad', 'user_id' => null]);
    $ustadzBakar = Teacher::factory()->create(['school_id' => $school->id, 'full_name' => 'Ustadz Bakar', 'user_id' => null]);

    $context = [
        'school' => $school,
        'superAdmin' => $superAdmin,
        'pengurus' => $pengurus,
        'academicYear' => $academicYear,
        'timeSlot' => TimeSlot::factory()->create(['school_id' => $school->id]),
        'tamhidi' => $tamhidi,
        'ibtida' => $ibtida,
        'safinah' => $safinah,
        'jurumiyah' => $jurumiyah,
        'ustadzAhmad' => $ustadzAhmad,
        'ustadzBakar' => $ustadzBakar,
    ];

    $context['ahmadAccount'] = grantTeachingScopeAccess($testCase, $context, $ustadzAhmad, 'ahmad@example.com');
    $context['bakarAccount'] = grantTeachingScopeAccess($testCase, $context, $ustadzBakar, 'bakar@example.com');

    $context['ahmadSafinahScheduleId'] = createTeachingScopeSchedule($testCase, $context, $tamhidi, $safinah, $ustadzAhmad, 'monday');
    createTeachingScopeSchedule($testCase, $context, $ibtida, $jurumiyah, $ustadzBakar, 'monday');

    $context['tamhidiSantri'] = createTeachingScopeStudent($testCase, $context, 'Ali', 'tamhidi');
    $context['ibtidaSantri'] = createTeachingScopeStudent($testCase, $context, 'Zaid', 'ibtida_1');

    return $context;
}

function createTeachingScopeSubjectBook(School $school, string $title): SubjectBook
{
    $teoriKitabTemplate = GradingTemplate::where('school_id', $school->id)->where('code', 'teori_kitab')->firstOrFail();

    return SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => SubjectCategory::factory()->create(['school_id' => $school->id])->id,
        'grading_template_id' => $teoriKitabTemplate->id,
        'title' => $title,
    ]);
}

/** Creates the Akun Ustadz through "Beri Akses" (POST /teachers/{teacher}/grant-access). */
function grantTeachingScopeAccess($testCase, array $context, Teacher $teacher, string $email): User
{
    $testCase->actingAs($context['superAdmin'])
        ->postJson("/api/v1/teachers/{$teacher->id}/grant-access", [
            'email' => $email,
            'password' => 'password123',
        ])
        ->assertCreated();

    return User::where('email', $email)->firstOrFail();
}

/** Creates a Jadwal Mengajar through POST /teaching-schedules; returns its id. */
function createTeachingScopeSchedule($testCase, array $context, ClassLevel $classLevel, SubjectBook $subjectBook, Teacher $teacher, string $dayOfWeek, int $semester = 1): string
{
    $response = $testCase->actingAs($context['superAdmin'])
        ->postJson('/api/v1/teaching-schedules', [
            'academic_year_id' => $context['academicYear']->id,
            'semester' => $semester,
            'day_of_week' => $dayOfWeek,
            'time_slot_id' => $context['timeSlot']->id,
            'class_level_id' => $classLevel->id,
            'subject_book_id' => $subjectBook->id,
            'teacher_id' => $teacher->id,
        ])
        ->assertCreated();

    return $response->json('data.id');
}

function createTeachingScopeStudent($testCase, array $context, string $fullName, string $classLevelSlug): Student
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

function teachingScopeGradableSubjectsUrl(array $context, int $semester = 1): string
{
    return '/api/v1/gradable-subjects?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => $semester,
    ]);
}

function teachingScopeGridUrl(array $context, ClassLevel $classLevel, SubjectBook $subjectBook, int $semester = 1): string
{
    return '/api/v1/student-grades?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => $semester,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
    ]);
}

/**
 * @param  array<int, array{student_id: string, scores: array<string, mixed>}>  $rows
 * @return array<string, mixed>
 */
function teachingScopeBulkPayload(array $context, ClassLevel $classLevel, SubjectBook $subjectBook, array $rows, int $semester = 1): array
{
    return [
        'academic_year_id' => $context['academicYear']->id,
        'semester' => $semester,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'rows' => $rows,
    ];
}

/**
 * The "<class_level_id>|<subject_book_id>" keys of a gradable-subjects response, sorted.
 *
 * @return array<int, string>
 */
function teachingScopePairKeys($response): array
{
    return collect($response->json('data'))
        ->map(fn (array $pair) => $pair['class_level_id'].'|'.$pair['subject_book_id'])
        ->sort()
        ->values()
        ->all();
}

function teachingScopePairKey(ClassLevel $classLevel, SubjectBook $subjectBook): string
{
    return $classLevel->id.'|'.$subjectBook->id;
}

// ── Pilihan kelas × kitab ────────────────────────────────────────────────

test('an Akun Ustadz lists only the class and kitab pairs of his Cakupan Mengajar', function () {
    $context = setUpTeachingScopeContext($this);

    $ahmadResponse = $this->actingAs($context['ahmadAccount'])
        ->getJson(teachingScopeGradableSubjectsUrl($context))
        ->assertOk();
    expect(teachingScopePairKeys($ahmadResponse))->toBe([teachingScopePairKey($context['tamhidi'], $context['safinah'])]);

    $bakarResponse = $this->actingAs($context['bakarAccount'])
        ->getJson(teachingScopeGradableSubjectsUrl($context))
        ->assertOk();
    expect(teachingScopePairKeys($bakarResponse))->toBe([teachingScopePairKey($context['ibtida'], $context['jurumiyah'])]);
});

test('pengurus and a pengurus who also teaches list every gradable pair', function () {
    $context = setUpTeachingScopeContext($this);

    $pengurusYangMengajar = Teacher::factory()->create(['school_id' => $context['school']->id, 'full_name' => 'Ustadz Pengurus', 'user_id' => null]);
    $multiRoleAccount = grantTeachingScopeAccess($this, $context, $pengurusYangMengajar, 'pengurus.ustadz@example.com');
    $this->actingAs($context['superAdmin'])
        ->postJson("/api/v1/users/{$multiRoleAccount->id}/roles", ['roles' => ['ustadz', 'pengurus_pesantren']])
        ->assertOk();
    createTeachingScopeSchedule($this, $context, $context['tamhidi'], $context['jurumiyah'], $pengurusYangMengajar, 'tuesday');

    $everyPair = collect([
        teachingScopePairKey($context['tamhidi'], $context['safinah']),
        teachingScopePairKey($context['ibtida'], $context['jurumiyah']),
        teachingScopePairKey($context['tamhidi'], $context['jurumiyah']),
    ])->sort()->values()->all();

    foreach ([$context['pengurus'], $multiRoleAccount] as $unrestrictedUser) {
        $response = $this->actingAs($unrestrictedUser)
            ->getJson(teachingScopeGradableSubjectsUrl($context))
            ->assertOk();

        expect(teachingScopePairKeys($response))->toBe($everyPair);
    }
});

test('the Cakupan Mengajar follows the chosen Semester Akademik', function () {
    $context = setUpTeachingScopeContext($this);

    // Ustadz Ahmad teaches Jurumiyah to Ibtida 1 only in semester 2.
    createTeachingScopeSchedule($this, $context, $context['ibtida'], $context['jurumiyah'], $context['ustadzAhmad'], 'tuesday', semester: 2);

    $semesterOneResponse = $this->actingAs($context['ahmadAccount'])
        ->getJson(teachingScopeGradableSubjectsUrl($context, semester: 1))
        ->assertOk();
    expect(teachingScopePairKeys($semesterOneResponse))->toBe([teachingScopePairKey($context['tamhidi'], $context['safinah'])]);

    $semesterTwoResponse = $this->actingAs($context['ahmadAccount'])
        ->getJson(teachingScopeGradableSubjectsUrl($context, semester: 2))
        ->assertOk();
    expect(teachingScopePairKeys($semesterTwoResponse))->toBe([teachingScopePairKey($context['ibtida'], $context['jurumiyah'])]);

    $this->actingAs($context['ahmadAccount'])
        ->getJson(teachingScopeGridUrl($context, $context['ibtida'], $context['jurumiyah'], semester: 1))
        ->assertForbidden()
        ->assertJsonPath('message', TEACHING_SCOPE_OUTSIDE_MESSAGE);
    $this->actingAs($context['ahmadAccount'])
        ->getJson(teachingScopeGridUrl($context, $context['ibtida'], $context['jurumiyah'], semester: 2))
        ->assertOk();
});

test('a user holding the ustadz role without a linked Ustadz has an empty Cakupan Mengajar', function () {
    $context = setUpTeachingScopeContext($this);

    $unlinkedAccount = User::factory()->create(['school_id' => $context['school']->id]);
    $unlinkedAccount->assignRole('ustadz');

    $this->actingAs($unlinkedAccount)
        ->getJson(teachingScopeGradableSubjectsUrl($context))
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($unlinkedAccount)
        ->getJson(teachingScopeGridUrl($context, $context['tamhidi'], $context['safinah']))
        ->assertForbidden()
        ->assertJsonPath('message', TEACHING_SCOPE_OUTSIDE_MESSAGE);
});

// ── Grid nilai dan simpan massal ─────────────────────────────────────────

test('an Akun Ustadz opens the grid and saves UTS, UAS, Adab and Keaktifan for his own pair', function () {
    $context = setUpTeachingScopeContext($this);
    $ali = $context['tamhidiSantri'];

    $this->actingAs($context['ahmadAccount'])
        ->getJson(teachingScopeGridUrl($context, $context['tamhidi'], $context['safinah']))
        ->assertOk()
        ->assertJsonCount(1, 'data.students')
        ->assertJsonPath('data.students.0.id', $ali->id);

    $response = $this->actingAs($context['ahmadAccount'])
        ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['tamhidi'], $context['safinah'], [
            ['student_id' => $ali->id, 'scores' => ['uts' => 80, 'uas' => 90, 'adab' => 3, 'keaktifan' => 4]],
        ]))
        ->assertOk()
        ->assertJsonCount(4, 'data');

    $savedRowsByCode = collect($response->json('data'))->keyBy('code');
    expect($savedRowsByCode['uts']['score'])->toEqual(80.0)
        ->and($savedRowsByCode['adab']['scale_level'])->toBe(3)
        ->and($savedRowsByCode['keaktifan']['scale_level'])->toBe(4);

    // The audit columns name the Akun Ustadz that filled the grid.
    $storedGrades = StudentGrade::where('student_id', $ali->id)->get();
    expect($storedGrades)->toHaveCount(4)
        ->and($storedGrades->pluck('created_by')->unique()->all())->toBe([$context['ahmadAccount']->id])
        ->and($storedGrades->pluck('updated_by')->unique()->all())->toBe([$context['ahmadAccount']->id]);
});

test('a pair outside the Cakupan Mengajar is rejected with 403 on the grid and on bulk save', function () {
    $context = setUpTeachingScopeContext($this);

    $this->actingAs($context['ahmadAccount'])
        ->getJson(teachingScopeGridUrl($context, $context['ibtida'], $context['jurumiyah']))
        ->assertForbidden()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', TEACHING_SCOPE_OUTSIDE_MESSAGE);

    $this->actingAs($context['ahmadAccount'])
        ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['ibtida'], $context['jurumiyah'], [
            ['student_id' => $context['ibtidaSantri']->id, 'scores' => ['uts' => 80]],
        ]))
        ->assertForbidden()
        ->assertJsonPath('message', TEACHING_SCOPE_OUTSIDE_MESSAGE);

    expect(StudentGrade::count())->toBe(0);
});

test('an unscheduled pair is outside the Cakupan Mengajar for an Akun Ustadz but only unscheduled for pengurus', function () {
    $context = setUpTeachingScopeContext($this);

    // Nobody teaches Jurumiyah to Tamhidi.
    $this->actingAs($context['ahmadAccount'])
        ->getJson(teachingScopeGridUrl($context, $context['tamhidi'], $context['jurumiyah']))
        ->assertForbidden()
        ->assertJsonPath('message', TEACHING_SCOPE_OUTSIDE_MESSAGE);

    $this->actingAs($context['pengurus'])
        ->getJson(teachingScopeGridUrl($context, $context['tamhidi'], $context['jurumiyah']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['subject_book_id']);
});

test('pengurus and a pengurus who also teaches save grades in any scheduled pair', function () {
    $context = setUpTeachingScopeContext($this);

    $pengurusYangMengajar = Teacher::factory()->create(['school_id' => $context['school']->id, 'full_name' => 'Ustadz Pengurus', 'user_id' => null]);
    $multiRoleAccount = grantTeachingScopeAccess($this, $context, $pengurusYangMengajar, 'pengurus.ustadz@example.com');
    $this->actingAs($context['superAdmin'])
        ->postJson("/api/v1/users/{$multiRoleAccount->id}/roles", ['roles' => ['ustadz', 'pengurus_pesantren']])
        ->assertOk();

    foreach ([75 => $context['pengurus'], 85 => $multiRoleAccount] as $utsScore => $unrestrictedUser) {
        $this->actingAs($unrestrictedUser)
            ->getJson(teachingScopeGridUrl($context, $context['ibtida'], $context['jurumiyah']))
            ->assertOk();

        $this->actingAs($unrestrictedUser)
            ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['ibtida'], $context['jurumiyah'], [
                ['student_id' => $context['ibtidaSantri']->id, 'scores' => ['uts' => $utsScore]],
            ]))
            ->assertOk();
    }

    expect(StudentGrade::where('student_id', $context['ibtidaSantri']->id)->value('updated_by'))->toBe($multiRoleAccount->id);
});

// ── Jadwal nonaktif ──────────────────────────────────────────────────────

test('a deactivated schedule keeps its pair in the Cakupan Mengajar of the ustadz who held it', function () {
    $context = setUpTeachingScopeContext($this);

    // Tamhidi learns Awamil twice a week: Tuesday with Ustadz Ahmad, Thursday
    // with Ustadz Bakar. Pengurus then removes Ahmad's Tuesday lesson.
    $awamil = createTeachingScopeSubjectBook($context['school'], 'Awamil');
    $ahmadAwamilScheduleId = createTeachingScopeSchedule($this, $context, $context['tamhidi'], $awamil, $context['ustadzAhmad'], 'tuesday');
    createTeachingScopeSchedule($this, $context, $context['tamhidi'], $awamil, $context['ustadzBakar'], 'thursday');

    $this->actingAs($context['pengurus'])
        ->deleteJson("/api/v1/teaching-schedules/{$ahmadAwamilScheduleId}")
        ->assertOk();

    $response = $this->actingAs($context['ahmadAccount'])
        ->getJson(teachingScopeGradableSubjectsUrl($context))
        ->assertOk();
    expect(teachingScopePairKeys($response))->toContain(teachingScopePairKey($context['tamhidi'], $awamil));

    $this->actingAs($context['ahmadAccount'])
        ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['tamhidi'], $awamil, [
            ['student_id' => $context['tamhidiSantri']->id, 'scores' => ['uas' => 88]],
        ]))
        ->assertOk();
});

test('a pair whose every schedule is deactivated is no longer gradable, for the ustadz as for pengurus', function () {
    $context = setUpTeachingScopeContext($this);

    // Penilaian rule: the gradable pairs come from active schedules. The
    // pair stays inside Ahmad's Cakupan Mengajar, so he gets the same
    // "not scheduled" answer as pengurus instead of a 403.
    $this->actingAs($context['pengurus'])
        ->deleteJson("/api/v1/teaching-schedules/{$context['ahmadSafinahScheduleId']}")
        ->assertOk();

    $this->actingAs($context['ahmadAccount'])
        ->getJson(teachingScopeGradableSubjectsUrl($context))
        ->assertOk()
        ->assertJsonCount(0, 'data');

    foreach ([$context['ahmadAccount'], $context['pengurus']] as $user) {
        $this->actingAs($user)
            ->getJson(teachingScopeGridUrl($context, $context['tamhidi'], $context['safinah']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_book_id']);
    }
});

// ── Izin, validasi, tenancy ──────────────────────────────────────────────

test('viewing grades within the Cakupan Mengajar does not allow saving them', function () {
    $context = setUpTeachingScopeContext($this);

    $viewOnlyRole = Role::firstOrCreate(['name' => 'ustadz_baca_saja', 'guard_name' => 'web']);
    $viewOnlyRole->syncPermissions(['view-own-grades']);
    $context['ahmadAccount']->syncRoles([$viewOnlyRole]);

    $this->actingAs($context['ahmadAccount'])
        ->getJson(teachingScopeGridUrl($context, $context['tamhidi'], $context['safinah']))
        ->assertOk();

    $this->actingAs($context['ahmadAccount'])
        ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['tamhidi'], $context['safinah'], [
            ['student_id' => $context['tamhidiSantri']->id, 'scores' => ['uts' => 80]],
        ]))
        ->assertForbidden();

    expect(StudentGrade::count())->toBe(0);
});

test('an account without grade permissions of either kind gets 403', function () {
    $context = setUpTeachingScopeContext($this);

    $accountWithoutGradePermissions = User::factory()->create(['school_id' => $context['school']->id]);

    $this->actingAs($accountWithoutGradePermissions)
        ->getJson(teachingScopeGradableSubjectsUrl($context))
        ->assertForbidden();
    $this->actingAs($accountWithoutGradePermissions)
        ->getJson(teachingScopeGridUrl($context, $context['tamhidi'], $context['safinah']))
        ->assertForbidden();
    $this->actingAs($accountWithoutGradePermissions)
        ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['tamhidi'], $context['safinah'], [
            ['student_id' => $context['tamhidiSantri']->id, 'scores' => ['uts' => 80]],
        ]))
        ->assertForbidden();
});

test('an Akun Ustadz gets the same validation and tenancy answers as pengurus', function () {
    $context = setUpTeachingScopeContext($this);

    $this->actingAs($context['ahmadAccount'])
        ->getJson('/api/v1/gradable-subjects')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['academic_year_id', 'semester']);

    $this->actingAs($context['ahmadAccount'])
        ->putJson('/api/v1/student-grades/bulk', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['academic_year_id', 'class_level_id', 'subject_book_id', 'rows']);

    $otherSchool = School::factory()->create();
    $otherSchoolClassLevel = ClassLevel::factory()->create(['school_id' => $otherSchool->id]);

    $this->actingAs($context['ahmadAccount'])
        ->getJson(teachingScopeGridUrl($context, $otherSchoolClassLevel, $context['safinah']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['class_level_id']);
});

test('a santri of another class is rejected per santri inside the Akun Ustadz own pair', function () {
    $context = setUpTeachingScopeContext($this);

    $this->actingAs($context['ahmadAccount'])
        ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['tamhidi'], $context['safinah'], [
            ['student_id' => $context['tamhidiSantri']->id, 'scores' => ['uts' => 80]],
            ['student_id' => $context['ibtidaSantri']->id, 'scores' => ['uts' => 70]],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$context['ibtidaSantri']->id]);

    expect(StudentGrade::count())->toBe(0);
});

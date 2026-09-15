<?php

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\ClassSession;
use App\Models\ClassTask;
use App\Models\GradingTemplate;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\StudentTaskScore;
use App\Models\SubjectBook;
use App\Models\SubjectCategory;
use App\Models\Teacher;
use App\Models\TeachingSchedule;
use App\Models\TeachingScheduleTeacherHistory;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Akademik\AcademicSemesterService;
use App\Services\Akademik\GradingDefaultsInstaller;
use Carbon\Carbon;
use Database\Seeders\ClassLevelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;
use Spatie\Permission\Models\Role;

/*
 * Cakupan Mengajar for Input Nilai and Adab & Keaktifan (role-ustadz
 * ticket 02, ADR 0004): an Akun Ustadz — only role `ustadz`, created
 * through "Beri Akses" — works on the Kelas × Kitab pairs of his own
 * Jadwal Mengajar in the chosen Semester Akademik; pengurus and a
 * pengurus who also teaches are never restricted. Ticket 03 (ADR 0005):
 * the riwayat pengajar keeps a pair in the Cakupan Mengajar of the Ustadz
 * who held it earlier in the same semester. Ticket 04: the Tugas of a pair
 * inside the Cakupan Mengajar are listed, created, changed, deleted and
 * scored like pengurus does; a Tugas of another pair is not found (404).
 * Ticket 05: Absensi Pertemuan (schedule list, record, edit, cancel,
 * Rekap Kehadiran) follows the same Cakupan Mengajar; the Alert Pertemuan
 * Bolong of an Akun Ustadz holds only the schedules he currently holds;
 * libur massal stays with pengurus.
 *
 * Every record the rules depend on is created through the real endpoints:
 * accounts via grant-access, schedules via /teaching-schedules, santri via
 * POST /students.
 */

afterEach(function () {
    Carbon::setTestNow();
});

const TEACHING_SCOPE_OUTSIDE_MESSAGE = 'Kelas dan kitab ini di luar Cakupan Mengajar Anda.';

/**
 * Tamhidi learns Safinatun Najah from Ustadz Ahmad on Monday and Ibtida 1
 * learns Jurumiyah from Ustadz Bakar on Tuesday, both in semester 1 at the
 * same time slot; one santri per class.
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
    $context['bakarJurumiyahScheduleId'] = createTeachingScopeSchedule($testCase, $context, $ibtida, $jurumiyah, $ustadzBakar, 'tuesday');

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

/**
 * The school's Tahfizh kitab (template `tahfizh`), taught to Tamhidi by
 * Ustadz Ahmad through a Jadwal Mengajar, with a Target Hafalan for the
 * Tamhidi santri whose Pembimbing Tahfizh is Ustadz Bakar.
 *
 * @return array{tahfizhBook: SubjectBook, scheduleId: string}
 */
function setUpTeachingScopeTahfizhSchedule($testCase, array $context): array
{
    $tahfizhTemplate = GradingTemplate::where('school_id', $context['school']->id)->where('code', 'tahfizh')->firstOrFail();
    $tahfizhBook = SubjectBook::factory()->create([
        'school_id' => $context['school']->id,
        'subject_category_id' => SubjectCategory::factory()->create(['school_id' => $context['school']->id])->id,
        'grading_template_id' => $tahfizhTemplate->id,
        'title' => "Tahfizh Al-Qur'an",
    ]);

    $scheduleId = createTeachingScopeSchedule($testCase, $context, $context['tamhidi'], $tahfizhBook, $context['ustadzAhmad'], 'friday');

    $testCase->actingAs($context['superAdmin'])
        ->postJson('/api/v1/memorization-targets', [
            'academic_year_id' => $context['academicYear']->id,
            'semester' => 1,
            'student_id' => $context['tamhidiSantri']->id,
            'target_pages' => 40,
            'teacher_id' => $context['ustadzBakar']->id,
        ])
        ->assertCreated();

    return ['tahfizhBook' => $tahfizhBook, 'scheduleId' => $scheduleId];
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

/**
 * The pair of a gradable-subjects response for (class, kitab), or null when not listed.
 *
 * @return array<string, mixed>|null
 */
function teachingScopeFindPair($response, ClassLevel $classLevel, SubjectBook $subjectBook): ?array
{
    return collect($response->json('data'))
        ->first(fn (array $pair) => $pair['class_level_id'] === $classLevel->id && $pair['subject_book_id'] === $subjectBook->id);
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
    $awamilPair = teachingScopeFindPair($response, $context['tamhidi'], $awamil);
    expect($awamilPair)->not->toBeNull()
        ->and($awamilPair['is_schedule_stopped'])->toBeFalse();

    $this->actingAs($context['ahmadAccount'])
        ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['tamhidi'], $awamil, [
            ['student_id' => $context['tamhidiSantri']->id, 'scores' => ['uas' => 88]],
        ]))
        ->assertOk();
});

test('a pair whose only schedule is deleted after grades were saved stays gradable for its ustadz and for pengurus', function () {
    $context = setUpTeachingScopeContext($this);

    $this->actingAs($context['ahmadAccount'])
        ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['tamhidi'], $context['safinah'], [
            ['student_id' => $context['tamhidiSantri']->id, 'scores' => ['uts' => 70]],
        ]))
        ->assertOk();

    // Pengurus stops the kitab mid-semester ("Hapus" deactivates the row).
    $this->actingAs($context['pengurus'])
        ->deleteJson("/api/v1/teaching-schedules/{$context['ahmadSafinahScheduleId']}")
        ->assertOk();

    foreach ([80 => $context['ahmadAccount'], 90 => $context['pengurus']] as $uasScore => $user) {
        $pair = teachingScopeFindPair(
            $this->actingAs($user)->getJson(teachingScopeGradableSubjectsUrl($context))->assertOk(),
            $context['tamhidi'],
            $context['safinah'],
        );
        expect($pair)->not->toBeNull()
            ->and($pair['is_schedule_stopped'])->toBeTrue()
            ->and(collect($pair['teachers'])->pluck('full_name')->all())->toBe(['Ustadz Ahmad']);

        $this->actingAs($user)
            ->getJson(teachingScopeGridUrl($context, $context['tamhidi'], $context['safinah']))
            ->assertOk()
            ->assertJsonPath('data.grades.'.$context['tamhidiSantri']->id.'.uts.score', 70);

        $this->actingAs($user)
            ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['tamhidi'], $context['safinah'], [
                ['student_id' => $context['tamhidiSantri']->id, 'scores' => ['uas' => $uasScore]],
            ]))
            ->assertOk();
    }

    // The stopped kitab stays on the santri's recap, the source of the Rapor.
    $recapSubjectTitles = collect(
        $this->actingAs($context['pengurus'])
            ->getJson("/api/v1/grade-recaps/student/{$context['tamhidiSantri']->id}?".http_build_query([
                'academic_year_id' => $context['academicYear']->id,
                'semester' => 1,
            ]))
            ->assertOk()
            ->json('data.subjects')
    )->pluck('subject_book.title')->all();
    expect($recapSubjectTitles)->toContain('Safinatun Najah');
});

test('a Tugas alone keeps a stopped pair gradable', function () {
    $context = setUpTeachingScopeContext($this);

    $this->actingAs($context['superAdmin'])
        ->postJson('/api/v1/class-tasks', [
            'academic_year_id' => $context['academicYear']->id,
            'semester' => 1,
            'class_level_id' => $context['tamhidi']->id,
            'subject_book_id' => $context['safinah']->id,
            'title' => 'Hafalan Bab Thaharah',
            'task_date' => '2025-08-01',
        ])
        ->assertCreated();

    $this->actingAs($context['pengurus'])
        ->deleteJson("/api/v1/teaching-schedules/{$context['ahmadSafinahScheduleId']}")
        ->assertOk();

    $this->actingAs($context['ahmadAccount'])
        ->getJson(teachingScopeGridUrl($context, $context['tamhidi'], $context['safinah']))
        ->assertOk();
});

test('a recorded Pertemuan alone keeps a stopped pair gradable', function () {
    Carbon::setTestNow('2025-09-10 10:00:00');
    $context = setUpTeachingScopeContext($this);

    $this->actingAs($context['superAdmin'])
        ->putJson("/api/v1/academic-years/{$context['academicYear']->id}/semesters/1", [
            'start_date' => '2025-07-01',
            'end_date' => '2025-12-31',
        ])
        ->assertOk();
    $this->actingAs($context['superAdmin'])
        ->postJson('/api/v1/class-sessions', [
            'teaching_schedule_id' => $context['ahmadSafinahScheduleId'],
            'session_date' => '2025-09-08',
            'attendances' => [['student_id' => $context['tamhidiSantri']->id, 'status' => 'present', 'notes' => null]],
        ])
        ->assertCreated();

    $this->actingAs($context['pengurus'])
        ->deleteJson("/api/v1/teaching-schedules/{$context['ahmadSafinahScheduleId']}")
        ->assertOk();

    $this->actingAs($context['pengurus'])
        ->getJson(teachingScopeGridUrl($context, $context['tamhidi'], $context['safinah']))
        ->assertOk();
});

test('a pair whose only schedule was deleted after a libur massal is gone for everyone', function () {
    Carbon::setTestNow('2025-09-10 10:00:00');
    $context = setUpTeachingScopeContext($this);

    // Libur massal cancels every active schedule in range (two Mondays for
    // Ahmad's Safinah); the schedule is then deleted as a mistake.
    $this->actingAs($context['superAdmin'])
        ->putJson("/api/v1/academic-years/{$context['academicYear']->id}/semesters/1", [
            'start_date' => '2025-07-01',
            'end_date' => '2025-12-31',
        ])
        ->assertOk();
    $this->actingAs($context['pengurus'])
        ->postJson('/api/v1/class-sessions/cancel-range', [
            'start_date' => '2025-09-01',
            'end_date' => '2025-09-08',
            'reason' => 'Libur Maulid Nabi',
        ])
        ->assertOk();
    expect(ClassSession::where('teaching_schedule_id', $context['ahmadSafinahScheduleId'])->where('status', 'cancelled')->count())->toBe(2);

    $this->actingAs($context['pengurus'])
        ->deleteJson("/api/v1/teaching-schedules/{$context['ahmadSafinahScheduleId']}")
        ->assertOk();

    foreach ([$context['ahmadAccount'], $context['pengurus']] as $user) {
        expect(teachingScopeFindPair(
            $this->actingAs($user)->getJson(teachingScopeGradableSubjectsUrl($context))->assertOk(),
            $context['tamhidi'],
            $context['safinah'],
        ))->toBeNull();

        $this->actingAs($user)
            ->getJson(teachingScopeGridUrl($context, $context['tamhidi'], $context['safinah']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_book_id']);
    }
});

test('a pair whose only data is a cleared score is gone for everyone once its schedule is deleted', function () {
    $context = setUpTeachingScopeContext($this);
    $bulkUrl = '/api/v1/student-grades/bulk';
    $ali = $context['tamhidiSantri'];

    $this->actingAs($context['ahmadAccount'])
        ->putJson($bulkUrl, teachingScopeBulkPayload($context, $context['tamhidi'], $context['safinah'], [
            ['student_id' => $ali->id, 'scores' => ['uts' => 70, 'adab' => 3]],
        ]))
        ->assertOk();
    // Both cells cleared: the rows stay, with score and level NULL.
    $this->actingAs($context['ahmadAccount'])
        ->putJson($bulkUrl, teachingScopeBulkPayload($context, $context['tamhidi'], $context['safinah'], [
            ['student_id' => $ali->id, 'scores' => ['uts' => null, 'adab' => null]],
        ]))
        ->assertOk();
    expect(StudentGrade::where('student_id', $ali->id)->count())->toBe(2)
        ->and(StudentGrade::where('student_id', $ali->id)->whereNotNull('score')->count())->toBe(0);

    $this->actingAs($context['pengurus'])
        ->deleteJson("/api/v1/teaching-schedules/{$context['ahmadSafinahScheduleId']}")
        ->assertOk();

    foreach ([$context['ahmadAccount'], $context['pengurus']] as $user) {
        expect(teachingScopeFindPair(
            $this->actingAs($user)->getJson(teachingScopeGradableSubjectsUrl($context))->assertOk(),
            $context['tamhidi'],
            $context['safinah'],
        ))->toBeNull();

        $this->actingAs($user)
            ->putJson($bulkUrl, teachingScopeBulkPayload($context, $context['tamhidi'], $context['safinah'], [
                ['student_id' => $ali->id, 'scores' => ['uts' => 80]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_book_id']);
    }
});

test('a pair whose only schedule is deleted before any data is gone for everyone', function () {
    $context = setUpTeachingScopeContext($this);

    // Created by mistake and deleted before anything was recorded.
    $this->actingAs($context['pengurus'])
        ->deleteJson("/api/v1/teaching-schedules/{$context['ahmadSafinahScheduleId']}")
        ->assertOk();

    foreach ([$context['ahmadAccount'], $context['pengurus']] as $user) {
        $pair = teachingScopeFindPair(
            $this->actingAs($user)->getJson(teachingScopeGradableSubjectsUrl($context))->assertOk(),
            $context['tamhidi'],
            $context['safinah'],
        );
        expect($pair)->toBeNull();

        // Inside Ahmad's Cakupan Mengajar (scope is checked first), so he
        // gets the same "not scheduled" answer as pengurus.
        $this->actingAs($user)
            ->getJson(teachingScopeGridUrl($context, $context['tamhidi'], $context['safinah']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_book_id']);
        $this->actingAs($user)
            ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['tamhidi'], $context['safinah'], [
                ['student_id' => $context['tamhidiSantri']->id, 'scores' => ['uts' => 80]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_book_id']);
    }

    expect(StudentGrade::count())->toBe(0);
});

test('schedules and data of another semester never keep a pair alive', function () {
    $context = setUpTeachingScopeContext($this);

    // Semester 2: Ahmad teaches Safinah to Tamhidi, grades are saved, then
    // that schedule is deleted too — semester 2 keeps the pair (stopped).
    $semesterTwoScheduleId = createTeachingScopeSchedule($this, $context, $context['tamhidi'], $context['safinah'], $context['ustadzAhmad'], 'wednesday', semester: 2);
    $this->actingAs($context['ahmadAccount'])
        ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['tamhidi'], $context['safinah'], [
            ['student_id' => $context['tamhidiSantri']->id, 'scores' => ['uts' => 75]],
        ], semester: 2))
        ->assertOk();
    $this->actingAs($context['pengurus'])
        ->deleteJson("/api/v1/teaching-schedules/{$semesterTwoScheduleId}")
        ->assertOk();

    // Semester 1: the pair's only schedule is deleted before any semester-1 data.
    $this->actingAs($context['pengurus'])
        ->deleteJson("/api/v1/teaching-schedules/{$context['ahmadSafinahScheduleId']}")
        ->assertOk();

    foreach ([$context['ahmadAccount'], $context['pengurus']] as $user) {
        $semesterOnePair = teachingScopeFindPair(
            $this->actingAs($user)->getJson(teachingScopeGradableSubjectsUrl($context, semester: 1))->assertOk(),
            $context['tamhidi'],
            $context['safinah'],
        );
        expect($semesterOnePair)->toBeNull();

        $semesterTwoPair = teachingScopeFindPair(
            $this->actingAs($user)->getJson(teachingScopeGradableSubjectsUrl($context, semester: 2))->assertOk(),
            $context['tamhidi'],
            $context['safinah'],
        );
        expect($semesterTwoPair['is_schedule_stopped'])->toBeTrue();
    }

    $this->actingAs($context['pengurus'])
        ->getJson(teachingScopeGridUrl($context, $context['tamhidi'], $context['safinah'], semester: 1))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['subject_book_id']);
});

test('a deleted Tahfizh schedule without data leaves the Tahfizh kitab gradable through its Target Hafalan', function () {
    $context = setUpTeachingScopeContext($this);
    ['tahfizhBook' => $tahfizhBook, 'scheduleId' => $tahfizhScheduleId] = setUpTeachingScopeTahfizhSchedule($this, $context);

    $this->actingAs($context['pengurus'])
        ->deleteJson("/api/v1/teaching-schedules/{$tahfizhScheduleId}")
        ->assertOk();

    // Pengurus, and Ahmad — whose deleted schedule keeps the pair in his
    // Cakupan Mengajar — list the pair, load the grid and save UAS Tahfizh.
    foreach ([90 => $context['pengurus'], 95 => $context['ahmadAccount']] as $uasTahfizhScore => $user) {
        $pair = teachingScopeFindPair(
            $this->actingAs($user)->getJson(teachingScopeGradableSubjectsUrl($context))->assertOk(),
            $context['tamhidi'],
            $tahfizhBook,
        );
        expect($pair)->not->toBeNull()
            ->and($pair['is_schedule_stopped'])->toBeFalse();

        $this->actingAs($user)
            ->getJson(teachingScopeGridUrl($context, $context['tamhidi'], $tahfizhBook))
            ->assertOk()
            ->assertJsonPath('data.students.0.id', $context['tamhidiSantri']->id);

        $this->actingAs($user)
            ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['tamhidi'], $tahfizhBook, [
                ['student_id' => $context['tamhidiSantri']->id, 'scores' => ['uas_tahfizh' => $uasTahfizhScore]],
            ]))
            ->assertOk();
    }
});

test('a deleted Tahfizh schedule with data is listed as the Target Hafalan pair, not as stopped', function () {
    $context = setUpTeachingScopeContext($this);
    ['tahfizhBook' => $tahfizhBook, 'scheduleId' => $tahfizhScheduleId] = setUpTeachingScopeTahfizhSchedule($this, $context);

    $this->actingAs($context['pengurus'])
        ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['tamhidi'], $tahfizhBook, [
            ['student_id' => $context['tamhidiSantri']->id, 'scores' => ['uas_tahfizh' => 80]],
        ]))
        ->assertOk();
    $this->actingAs($context['pengurus'])
        ->deleteJson("/api/v1/teaching-schedules/{$tahfizhScheduleId}")
        ->assertOk();

    $pair = teachingScopeFindPair(
        $this->actingAs($context['pengurus'])->getJson(teachingScopeGradableSubjectsUrl($context))->assertOk(),
        $context['tamhidi'],
        $tahfizhBook,
    );
    expect($pair['is_schedule_stopped'])->toBeFalse()
        ->and($pair['teachers'])->toBe([]);
});

// ── Riwayat pengajar (ADR 0005) ──────────────────────────────────────────

/** Changes a Jadwal Mengajar through the schedule edit (PUT /teaching-schedules/{id}), as pengurus. */
function editTeachingScopeSchedule($testCase, array $context, string $scheduleId, array $changes): void
{
    $testCase->actingAs($context['pengurus'])
        ->putJson("/api/v1/teaching-schedules/{$scheduleId}", $changes)
        ->assertOk();
}

/** The bulk "ganti ustadz" (POST /teaching-schedules/replace-teacher) for semester 1. */
function replaceTeachingScopeTeacher($testCase, array $context, Teacher $sourceTeacher, Teacher $targetTeacher): void
{
    $testCase->actingAs($context['pengurus'])
        ->postJson('/api/v1/teaching-schedules/replace-teacher', [
            'source_teacher_id' => $sourceTeacher->id,
            'target_teacher_id' => $targetTeacher->id,
            'academic_year_id' => $context['academicYear']->id,
            'semester' => 1,
        ])
        ->assertOk();
}

test('a schedule moved from Ustadz Ahmad to Ustadz Bakar through the edit lets both save grades that semester', function () {
    $context = setUpTeachingScopeContext($this);
    $ali = $context['tamhidiSantri'];

    editTeachingScopeSchedule($this, $context, $context['ahmadSafinahScheduleId'], ['teacher_id' => $context['ustadzBakar']->id]);

    // Ahmad keeps Tamhidi × Safinah through the riwayat pengajar; Bakar holds it now.
    foreach ([$context['ahmadAccount'], $context['bakarAccount']] as $ustadzAccount) {
        $safinahPair = teachingScopeFindPair(
            $this->actingAs($ustadzAccount)->getJson(teachingScopeGradableSubjectsUrl($context))->assertOk(),
            $context['tamhidi'],
            $context['safinah'],
        );
        expect($safinahPair)->not->toBeNull()
            ->and($safinahPair['is_schedule_stopped'])->toBeFalse()
            ->and(collect($safinahPair['teachers'])->pluck('full_name')->all())->toBe(['Ustadz Bakar']);

        $this->actingAs($ustadzAccount)
            ->getJson(teachingScopeGridUrl($context, $context['tamhidi'], $context['safinah']))
            ->assertOk();
    }

    $this->actingAs($context['ahmadAccount'])
        ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['tamhidi'], $context['safinah'], [
            ['student_id' => $ali->id, 'scores' => ['uts' => 70]],
        ]))
        ->assertOk();
    $this->actingAs($context['bakarAccount'])
        ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['tamhidi'], $context['safinah'], [
            ['student_id' => $ali->id, 'scores' => ['uas' => 85]],
        ]))
        ->assertOk();

    // Who wrote what stays in the audit columns.
    $gradeWritersByFactorCode = StudentGrade::where('student_id', $ali->id)->with('gradingFactor:id,code')->get()
        ->mapWithKeys(fn (StudentGrade $grade) => [$grade->gradingFactor->code => $grade->created_by])
        ->all();
    expect($gradeWritersByFactorCode)->toBe([
        'uts' => $context['ahmadAccount']->id,
        'uas' => $context['bakarAccount']->id,
    ]);

    // Pengurus and a pengurus who also teaches are not restricted either way.
    $pengurusYangMengajar = Teacher::factory()->create(['school_id' => $context['school']->id, 'full_name' => 'Ustadz Pengurus', 'user_id' => null]);
    $multiRoleAccount = grantTeachingScopeAccess($this, $context, $pengurusYangMengajar, 'pengurus.ustadz@example.com');
    $this->actingAs($context['superAdmin'])
        ->postJson("/api/v1/users/{$multiRoleAccount->id}/roles", ['roles' => ['ustadz', 'pengurus_pesantren']])
        ->assertOk();
    foreach ([$context['pengurus'], $multiRoleAccount] as $unrestrictedUser) {
        $this->actingAs($unrestrictedUser)
            ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['tamhidi'], $context['safinah'], [
                ['student_id' => $ali->id, 'scores' => ['adab' => 3]],
            ]))
            ->assertOk();
    }
});

test('the riwayat pengajar of one semester does not open the pair in another semester', function () {
    $context = setUpTeachingScopeContext($this);

    // Semester 2: Bakar alone teaches Safinah to Tamhidi.
    createTeachingScopeSchedule($this, $context, $context['tamhidi'], $context['safinah'], $context['ustadzBakar'], 'wednesday', semester: 2);
    editTeachingScopeSchedule($this, $context, $context['ahmadSafinahScheduleId'], ['teacher_id' => $context['ustadzBakar']->id]);

    expect(teachingScopeFindPair(
        $this->actingAs($context['ahmadAccount'])->getJson(teachingScopeGradableSubjectsUrl($context, semester: 2))->assertOk(),
        $context['tamhidi'],
        $context['safinah'],
    ))->toBeNull();

    $this->actingAs($context['ahmadAccount'])
        ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['tamhidi'], $context['safinah'], [
            ['student_id' => $context['tamhidiSantri']->id, 'scores' => ['uts' => 70]],
        ], semester: 2))
        ->assertForbidden()
        ->assertJsonPath('message', TEACHING_SCOPE_OUTSIDE_MESSAGE);
});

test('a schedule moved through the bulk ganti ustadz lets both save grades that semester and leaves the other semester as it was', function () {
    $context = setUpTeachingScopeContext($this);
    $ali = $context['tamhidiSantri'];

    // Ahmad also teaches Safinah to Tamhidi in semester 2; only semester 1 moves to Bakar.
    createTeachingScopeSchedule($this, $context, $context['tamhidi'], $context['safinah'], $context['ustadzAhmad'], 'wednesday', semester: 2);
    replaceTeachingScopeTeacher($this, $context, $context['ustadzAhmad'], $context['ustadzBakar']);

    foreach ([70 => $context['ahmadAccount'], 80 => $context['bakarAccount']] as $utsScore => $ustadzAccount) {
        $this->actingAs($ustadzAccount)
            ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['tamhidi'], $context['safinah'], [
                ['student_id' => $ali->id, 'scores' => ['uts' => $utsScore]],
            ]))
            ->assertOk();
    }
    expect(StudentGrade::where('student_id', $ali->id)->where('semester', 1)->value('updated_by'))->toBe($context['bakarAccount']->id);

    // Semester 2 still belongs to Ahmad alone.
    $this->actingAs($context['ahmadAccount'])
        ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['tamhidi'], $context['safinah'], [
            ['student_id' => $ali->id, 'scores' => ['uts' => 90]],
        ], semester: 2))
        ->assertOk();
    $this->actingAs($context['bakarAccount'])
        ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['tamhidi'], $context['safinah'], [
            ['student_id' => $ali->id, 'scores' => ['uts' => 60]],
        ], semester: 2))
        ->assertForbidden()
        ->assertJsonPath('message', TEACHING_SCOPE_OUTSIDE_MESSAGE);
});

test('a schedule moved to another class keeps the old class with recorded grades for its Ustadz, marked stopped', function () {
    $context = setUpTeachingScopeContext($this);

    $this->actingAs($context['ahmadAccount'])
        ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['tamhidi'], $context['safinah'], [
            ['student_id' => $context['tamhidiSantri']->id, 'scores' => ['uts' => 70]],
        ]))
        ->assertOk();

    // Pengurus moves Ahmad's Safinah lesson from Tamhidi to Ibtida 1.
    editTeachingScopeSchedule($this, $context, $context['ahmadSafinahScheduleId'], ['class_level_id' => $context['ibtida']->id]);

    $ahmadResponse = $this->actingAs($context['ahmadAccount'])->getJson(teachingScopeGradableSubjectsUrl($context))->assertOk();
    expect(teachingScopePairKeys($ahmadResponse))->toBe(collect([
        teachingScopePairKey($context['tamhidi'], $context['safinah']),
        teachingScopePairKey($context['ibtida'], $context['safinah']),
    ])->sort()->values()->all());

    foreach ([80 => $context['ahmadAccount'], 90 => $context['pengurus']] as $uasScore => $user) {
        $oldClassPair = teachingScopeFindPair(
            $this->actingAs($user)->getJson(teachingScopeGradableSubjectsUrl($context))->assertOk(),
            $context['tamhidi'],
            $context['safinah'],
        );
        expect($oldClassPair['is_schedule_stopped'])->toBeTrue()
            ->and(collect($oldClassPair['teachers'])->pluck('full_name')->all())->toBe(['Ustadz Ahmad']);

        $this->actingAs($user)
            ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['tamhidi'], $context['safinah'], [
                ['student_id' => $context['tamhidiSantri']->id, 'scores' => ['uas' => $uasScore]],
            ]))
            ->assertOk();
    }
});

test('a schedule moved to another kitab before any data leaves the old pair ungradable for everyone', function () {
    $context = setUpTeachingScopeContext($this);

    // Safinah was entered by mistake; pengurus corrects the kitab to Jurumiyah.
    editTeachingScopeSchedule($this, $context, $context['ahmadSafinahScheduleId'], ['subject_book_id' => $context['jurumiyah']->id]);

    foreach ([$context['ahmadAccount'], $context['pengurus']] as $user) {
        expect(teachingScopeFindPair(
            $this->actingAs($user)->getJson(teachingScopeGradableSubjectsUrl($context))->assertOk(),
            $context['tamhidi'],
            $context['safinah'],
        ))->toBeNull();

        // Still inside Ahmad's Cakupan Mengajar, so he gets the same answer as pengurus.
        $this->actingAs($user)
            ->getJson(teachingScopeGridUrl($context, $context['tamhidi'], $context['safinah']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_book_id']);
    }

    $this->actingAs($context['ahmadAccount'])
        ->getJson(teachingScopeGridUrl($context, $context['tamhidi'], $context['jurumiyah']))
        ->assertOk();
});

test('the riwayat pengajar of another school widens nothing', function () {
    $context = setUpTeachingScopeContext($this);

    // Only reachable through a direct insert: the API records the riwayat
    // pengajar for the active school alone. This entry names Ahmad and
    // Ibtida 1 × Jurumiyah in the active semester, but another school.
    $otherSchool = School::factory()->inactive()->create();
    $otherSchoolSchedule = TeachingSchedule::factory()->create([
        'school_id' => $otherSchool->id,
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'time_slot_id' => $context['timeSlot']->id,
        'class_level_id' => $context['ibtida']->id,
        'subject_book_id' => $context['jurumiyah']->id,
        'teacher_id' => $context['ustadzBakar']->id,
    ]);
    TeachingScheduleTeacherHistory::create([
        'school_id' => $otherSchool->id,
        'teaching_schedule_id' => $otherSchoolSchedule->id,
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'previous_teacher_id' => $context['ustadzAhmad']->id,
        'previous_class_level_id' => $context['ibtida']->id,
        'previous_subject_book_id' => $context['jurumiyah']->id,
    ]);

    expect(teachingScopePairKeys(
        $this->actingAs($context['ahmadAccount'])->getJson(teachingScopeGradableSubjectsUrl($context))->assertOk()
    ))->toBe([teachingScopePairKey($context['tamhidi'], $context['safinah'])]);

    $this->actingAs($context['ahmadAccount'])
        ->getJson(teachingScopeGridUrl($context, $context['ibtida'], $context['jurumiyah']))
        ->assertForbidden()
        ->assertJsonPath('message', TEACHING_SCOPE_OUTSIDE_MESSAGE);
});

test('a finalized Rapor ends the access of the former Ustadz as it does for everyone', function () {
    Carbon::setTestNow('2025-09-10 10:00:00');
    $context = setUpTeachingScopeContext($this);
    $ali = $context['tamhidiSantri'];

    replaceTeachingScopeTeacher($this, $context, $context['ustadzAhmad'], $context['ustadzBakar']);

    // Bakar completes every factor of Safinah, Ali's only kitab: a held
    // Pertemuan (Absensi), a scored Tugas, then the manual factors.
    $this->actingAs($context['superAdmin'])
        ->putJson("/api/v1/academic-years/{$context['academicYear']->id}/semesters/1", [
            'start_date' => '2025-07-01',
            'end_date' => '2025-12-31',
        ])
        ->assertOk();
    $this->actingAs($context['superAdmin'])
        ->postJson('/api/v1/class-sessions', [
            'teaching_schedule_id' => $context['ahmadSafinahScheduleId'],
            'session_date' => '2025-09-08',
            'attendances' => [['student_id' => $ali->id, 'status' => 'present', 'notes' => null]],
        ])
        ->assertCreated();
    $taskId = $this->actingAs($context['superAdmin'])
        ->postJson('/api/v1/class-tasks', [
            'academic_year_id' => $context['academicYear']->id,
            'semester' => 1,
            'class_level_id' => $context['tamhidi']->id,
            'subject_book_id' => $context['safinah']->id,
            'title' => 'Hafalan Bab Thaharah',
            'task_date' => '2025-08-01',
        ])
        ->assertCreated()
        ->json('data.id');
    $this->actingAs($context['superAdmin'])
        ->putJson("/api/v1/class-tasks/{$taskId}/scores/bulk", ['rows' => [['student_id' => $ali->id, 'score' => 75]]])
        ->assertOk();
    $this->actingAs($context['bakarAccount'])
        ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['tamhidi'], $context['safinah'], [
            ['student_id' => $ali->id, 'scores' => ['uts' => 80, 'uas' => 85, 'keaktifan' => 3, 'adab' => 4]],
        ]))
        ->assertOk();

    $this->actingAs($context['pengurus'])
        ->postJson('/api/v1/report-cards/finalize', [
            'student_id' => $ali->id,
            'academic_year_id' => $context['academicYear']->id,
            'semester' => 1,
        ])
        ->assertOk();

    foreach ([$context['ahmadAccount'], $context['bakarAccount']] as $ustadzAccount) {
        $this->actingAs($ustadzAccount)
            ->putJson('/api/v1/student-grades/bulk', teachingScopeBulkPayload($context, $context['tamhidi'], $context['safinah'], [
                ['student_id' => $ali->id, 'scores' => ['uts' => 60]],
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Rapor santri ini sudah final untuk semester tersebut.');
    }
    expect(StudentGrade::where('student_id', $ali->id)->whereHas('gradingFactor', fn ($query) => $query->where('code', 'uts'))->value('score'))->toEqual(80);
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

// ── Tugas (ticket 04) ────────────────────────────────────────────────────

function teachingScopeTasksUrl(array $context, ClassLevel $classLevel, SubjectBook $subjectBook, int $semester = 1): string
{
    return '/api/v1/class-tasks?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => $semester,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
    ]);
}

/**
 * @return array<string, mixed>
 */
function teachingScopeTaskPayload(array $context, ClassLevel $classLevel, SubjectBook $subjectBook, int $semester = 1): array
{
    return [
        'academic_year_id' => $context['academicYear']->id,
        'semester' => $semester,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'title' => 'Hafalan Bab 1',
        'task_date' => '2025-08-01',
        'description' => null,
    ];
}

/** Creates a Tugas through POST /class-tasks as the given user; returns its id. */
function createTeachingScopeTask($testCase, User $user, array $context, ClassLevel $classLevel, SubjectBook $subjectBook, int $semester = 1): string
{
    return $testCase->actingAs($user)
        ->postJson('/api/v1/class-tasks', teachingScopeTaskPayload($context, $classLevel, $subjectBook, $semester))
        ->assertCreated()
        ->json('data.id');
}

test('an Akun Ustadz creates, lists, updates, scores and deletes the Tugas of his own pair under his own account', function () {
    $context = setUpTeachingScopeContext($this);
    $ahmad = $context['ahmadAccount'];
    $ali = $context['tamhidiSantri'];

    // Pengurus gave a Tugas to Ahmad's class and already scored it.
    $pengurusTaskId = createTeachingScopeTask($this, $context['pengurus'], $context, $context['tamhidi'], $context['safinah']);
    $this->actingAs($context['pengurus'])
        ->putJson("/api/v1/class-tasks/{$pengurusTaskId}/scores/bulk", ['rows' => [['student_id' => $ali->id, 'score' => 70]]])
        ->assertOk();

    $ownTaskId = $this->actingAs($ahmad)
        ->postJson('/api/v1/class-tasks', teachingScopeTaskPayload($context, $context['tamhidi'], $context['safinah']))
        ->assertCreated()
        ->assertJsonPath('data.created_by', $ahmad->id)
        ->json('data.id');

    $listedTaskIds = collect(
        $this->actingAs($ahmad)
            ->getJson(teachingScopeTasksUrl($context, $context['tamhidi'], $context['safinah']))
            ->assertOk()
            ->json('data')
    )->pluck('id')->sort()->values()->all();
    expect($listedTaskIds)->toBe(collect([$pengurusTaskId, $ownTaskId])->sort()->values()->all());

    $this->actingAs($ahmad)
        ->getJson("/api/v1/class-tasks/{$pengurusTaskId}")
        ->assertOk()
        ->assertJsonPath('data.title', 'Hafalan Bab 1');
    $this->actingAs($ahmad)
        ->putJson("/api/v1/class-tasks/{$pengurusTaskId}", ['title' => 'Hafalan Bab 1 (Revisi)'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Hafalan Bab 1 (Revisi)')
        ->assertJsonPath('data.updated_by', $ahmad->id);
    $this->actingAs($ahmad)
        ->getJson("/api/v1/class-tasks/{$pengurusTaskId}/scores")
        ->assertOk()
        ->assertJsonCount(1, 'data.students')
        ->assertJsonPath("data.scores.{$ali->id}.score", 70);
    $this->actingAs($ahmad)
        ->putJson("/api/v1/class-tasks/{$pengurusTaskId}/scores/bulk", ['rows' => [['student_id' => $ali->id, 'score' => 85]]])
        ->assertOk();
    $this->actingAs($ahmad)
        ->putJson("/api/v1/class-tasks/{$ownTaskId}/scores/bulk", ['rows' => [['student_id' => $ali->id, 'score' => 90]]])
        ->assertOk();

    // The audit columns name the Akun Ustadz for everything he wrote.
    $pengurusTask = ClassTask::findOrFail($pengurusTaskId);
    expect($pengurusTask->created_by)->toBe($context['pengurus']->id)
        ->and($pengurusTask->updated_by)->toBe($ahmad->id);
    $rescoredScore = StudentTaskScore::where('class_task_id', $pengurusTaskId)->where('student_id', $ali->id)->sole();
    expect((float) $rescoredScore->score)->toBe(85.0)
        ->and($rescoredScore->created_by)->toBe($context['pengurus']->id)
        ->and($rescoredScore->updated_by)->toBe($ahmad->id);
    $ownTaskScore = StudentTaskScore::where('class_task_id', $ownTaskId)->where('student_id', $ali->id)->sole();
    expect($ownTaskScore->created_by)->toBe($ahmad->id)
        ->and($ownTaskScore->updated_by)->toBe($ahmad->id);

    $this->actingAs($ahmad)->deleteJson("/api/v1/class-tasks/{$ownTaskId}")->assertOk();
    expect(ClassTask::find($ownTaskId))->toBeNull()
        ->and(ClassTask::withTrashed()->findOrFail($ownTaskId)->updated_by)->toBe($ahmad->id);
});

test('a Tugas outside the Cakupan Mengajar is not found for an Akun Ustadz, even before its body is validated', function () {
    $context = setUpTeachingScopeContext($this);
    $zaid = $context['ibtidaSantri'];

    // Ibtida 1 × Jurumiyah is Bakar's pair.
    $bakarTaskId = createTeachingScopeTask($this, $context['bakarAccount'], $context, $context['ibtida'], $context['jurumiyah']);
    $this->actingAs($context['bakarAccount'])
        ->putJson("/api/v1/class-tasks/{$bakarTaskId}/scores/bulk", ['rows' => [['student_id' => $zaid->id, 'score' => 75]]])
        ->assertOk();

    $ahmad = $context['ahmadAccount'];
    $this->actingAs($ahmad)->getJson("/api/v1/class-tasks/{$bakarTaskId}")->assertNotFound();
    $this->actingAs($ahmad)->putJson("/api/v1/class-tasks/{$bakarTaskId}", ['title' => 'Diubah Ahmad'])->assertNotFound();
    $this->actingAs($ahmad)->putJson("/api/v1/class-tasks/{$bakarTaskId}", ['title' => ''])->assertNotFound();
    $this->actingAs($ahmad)->deleteJson("/api/v1/class-tasks/{$bakarTaskId}")->assertNotFound();
    $this->actingAs($ahmad)->getJson("/api/v1/class-tasks/{$bakarTaskId}/scores")->assertNotFound();
    $this->actingAs($ahmad)
        ->putJson("/api/v1/class-tasks/{$bakarTaskId}/scores/bulk", ['rows' => [['student_id' => $zaid->id, 'score' => 10]]])
        ->assertNotFound();
    $this->actingAs($ahmad)->putJson("/api/v1/class-tasks/{$bakarTaskId}/scores/bulk", ['rows' => []])->assertNotFound();

    $bakarTask = ClassTask::findOrFail($bakarTaskId);
    expect($bakarTask->title)->toBe('Hafalan Bab 1')
        ->and($bakarTask->updated_by)->toBe($context['bakarAccount']->id)
        ->and((float) StudentTaskScore::where('class_task_id', $bakarTaskId)->sole()->score)->toBe(75.0);

    $this->actingAs($context['bakarAccount'])->getJson("/api/v1/class-tasks/{$bakarTaskId}")->assertOk();
});

test('a pair outside the Cakupan Mengajar is rejected with 403 on the Tugas list and on create', function () {
    $context = setUpTeachingScopeContext($this);

    // Bakar's pair, then a pair nobody teaches.
    foreach ([[$context['ibtida'], $context['jurumiyah']], [$context['tamhidi'], $context['jurumiyah']]] as [$classLevel, $subjectBook]) {
        $this->actingAs($context['ahmadAccount'])
            ->getJson(teachingScopeTasksUrl($context, $classLevel, $subjectBook))
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', TEACHING_SCOPE_OUTSIDE_MESSAGE);

        $this->actingAs($context['ahmadAccount'])
            ->postJson('/api/v1/class-tasks', teachingScopeTaskPayload($context, $classLevel, $subjectBook))
            ->assertForbidden()
            ->assertJsonPath('message', TEACHING_SCOPE_OUTSIDE_MESSAGE);
    }

    expect(ClassTask::count())->toBe(0);
});

test('pengurus and a pengurus who also teaches manage the Tugas of every pair', function () {
    $context = setUpTeachingScopeContext($this);
    $zaid = $context['ibtidaSantri'];

    $pengurusYangMengajar = Teacher::factory()->create(['school_id' => $context['school']->id, 'full_name' => 'Ustadz Pengurus', 'user_id' => null]);
    $multiRoleAccount = grantTeachingScopeAccess($this, $context, $pengurusYangMengajar, 'pengurus.ustadz@example.com');
    $this->actingAs($context['superAdmin'])
        ->postJson("/api/v1/users/{$multiRoleAccount->id}/roles", ['roles' => ['ustadz', 'pengurus_pesantren']])
        ->assertOk();
    createTeachingScopeSchedule($this, $context, $context['tamhidi'], $context['jurumiyah'], $pengurusYangMengajar, 'wednesday');

    // Ibtida 1 × Jurumiyah is Bakar's pair, outside both users' own teaching.
    foreach ([$context['pengurus'], $multiRoleAccount] as $unrestrictedUser) {
        $taskId = createTeachingScopeTask($this, $unrestrictedUser, $context, $context['ibtida'], $context['jurumiyah']);

        $this->actingAs($unrestrictedUser)
            ->getJson(teachingScopeTasksUrl($context, $context['ibtida'], $context['jurumiyah']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $taskId);
        $this->actingAs($unrestrictedUser)->getJson("/api/v1/class-tasks/{$taskId}")->assertOk();
        $this->actingAs($unrestrictedUser)->putJson("/api/v1/class-tasks/{$taskId}", ['title' => 'Revisi'])->assertOk();
        $this->actingAs($unrestrictedUser)->getJson("/api/v1/class-tasks/{$taskId}/scores")->assertOk();
        $this->actingAs($unrestrictedUser)
            ->putJson("/api/v1/class-tasks/{$taskId}/scores/bulk", ['rows' => [['student_id' => $zaid->id, 'score' => 80]]])
            ->assertOk();
        $this->actingAs($unrestrictedUser)->deleteJson("/api/v1/class-tasks/{$taskId}")->assertOk();

        expect(ClassTask::withTrashed()->findOrFail($taskId)->updated_by)->toBe($unrestrictedUser->id);
    }
});

test('the Semester Akademik of the Tugas itself decides whether it is in the Cakupan Mengajar', function () {
    $context = setUpTeachingScopeContext($this);

    // Ahmad teaches Jurumiyah to Ibtida 1 only in semester 2; in semester 1 it is Bakar's.
    createTeachingScopeSchedule($this, $context, $context['ibtida'], $context['jurumiyah'], $context['ustadzAhmad'], 'wednesday', semester: 2);
    $semesterOneTaskId = createTeachingScopeTask($this, $context['pengurus'], $context, $context['ibtida'], $context['jurumiyah'], semester: 1);
    $semesterTwoTaskId = createTeachingScopeTask($this, $context['pengurus'], $context, $context['ibtida'], $context['jurumiyah'], semester: 2);

    $this->actingAs($context['ahmadAccount'])->getJson("/api/v1/class-tasks/{$semesterTwoTaskId}")->assertOk();
    $this->actingAs($context['ahmadAccount'])
        ->putJson("/api/v1/class-tasks/{$semesterTwoTaskId}", ['title' => 'Revisi Semester 2'])
        ->assertOk();

    $this->actingAs($context['ahmadAccount'])->getJson("/api/v1/class-tasks/{$semesterOneTaskId}")->assertNotFound();
    $this->actingAs($context['ahmadAccount'])
        ->putJson("/api/v1/class-tasks/{$semesterOneTaskId}", ['title' => 'Revisi Semester 1'])
        ->assertNotFound();
});

test('the Tugas of a schedule moved through the bulk ganti ustadz stay open to the former and the new Ustadz', function () {
    $context = setUpTeachingScopeContext($this);
    $ali = $context['tamhidiSantri'];

    $taskId = createTeachingScopeTask($this, $context['ahmadAccount'], $context, $context['tamhidi'], $context['safinah']);
    replaceTeachingScopeTeacher($this, $context, $context['ustadzAhmad'], $context['ustadzBakar']);

    $this->actingAs($context['bakarAccount'])
        ->putJson("/api/v1/class-tasks/{$taskId}/scores/bulk", ['rows' => [['student_id' => $ali->id, 'score' => 80]]])
        ->assertOk();
    $this->actingAs($context['ahmadAccount'])
        ->putJson("/api/v1/class-tasks/{$taskId}/scores/bulk", ['rows' => [['student_id' => $ali->id, 'score' => 82]]])
        ->assertOk();
    $this->actingAs($context['ahmadAccount'])
        ->getJson(teachingScopeTasksUrl($context, $context['tamhidi'], $context['safinah']))
        ->assertOk()
        ->assertJsonPath('data.0.id', $taskId);

    $score = StudentTaskScore::where('class_task_id', $taskId)->sole();
    expect($score->created_by)->toBe($context['bakarAccount']->id)
        ->and($score->updated_by)->toBe($context['ahmadAccount']->id);
});

test('viewing grades within the Cakupan Mengajar does not allow managing Tugas', function () {
    $context = setUpTeachingScopeContext($this);
    $ali = $context['tamhidiSantri'];
    $taskId = createTeachingScopeTask($this, $context['pengurus'], $context, $context['tamhidi'], $context['safinah']);

    $viewOnlyRole = Role::firstOrCreate(['name' => 'ustadz_baca_saja', 'guard_name' => 'web']);
    $viewOnlyRole->syncPermissions(['view-own-grades']);
    $context['ahmadAccount']->syncRoles([$viewOnlyRole]);
    $ahmad = $context['ahmadAccount'];

    $this->actingAs($ahmad)->getJson(teachingScopeTasksUrl($context, $context['tamhidi'], $context['safinah']))->assertOk();
    $this->actingAs($ahmad)->getJson("/api/v1/class-tasks/{$taskId}")->assertOk();
    $this->actingAs($ahmad)->getJson("/api/v1/class-tasks/{$taskId}/scores")->assertOk();

    $this->actingAs($ahmad)
        ->postJson('/api/v1/class-tasks', teachingScopeTaskPayload($context, $context['tamhidi'], $context['safinah']))
        ->assertForbidden();
    $this->actingAs($ahmad)->putJson("/api/v1/class-tasks/{$taskId}", ['title' => 'Revisi'])->assertForbidden();
    $this->actingAs($ahmad)->deleteJson("/api/v1/class-tasks/{$taskId}")->assertForbidden();
    $this->actingAs($ahmad)
        ->putJson("/api/v1/class-tasks/{$taskId}/scores/bulk", ['rows' => [['student_id' => $ali->id, 'score' => 80]]])
        ->assertForbidden();

    expect(ClassTask::count())->toBe(1)
        ->and(ClassTask::findOrFail($taskId)->title)->toBe('Hafalan Bab 1')
        ->and(StudentTaskScore::count())->toBe(0);
});

test('an Akun Ustadz gets the same validation and tenancy answers on Tugas as pengurus', function () {
    $context = setUpTeachingScopeContext($this);
    $ahmad = $context['ahmadAccount'];
    $taskId = createTeachingScopeTask($this, $ahmad, $context, $context['tamhidi'], $context['safinah']);

    $this->actingAs($ahmad)
        ->postJson('/api/v1/class-tasks', array_merge(teachingScopeTaskPayload($context, $context['tamhidi'], $context['safinah']), ['title' => '']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title']);
    $this->actingAs($ahmad)
        ->putJson("/api/v1/class-tasks/{$taskId}", ['task_date' => 'bukan-tanggal'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['task_date']);
    $this->actingAs($ahmad)
        ->putJson("/api/v1/class-tasks/{$taskId}/scores/bulk", ['rows' => [['student_id' => $context['ibtidaSantri']->id, 'score' => 80]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$context['ibtidaSantri']->id]);

    $otherSchool = School::factory()->create();
    $otherSchoolTask = ClassTask::create([
        'school_id' => $otherSchool->id,
        'class_level_id' => ClassLevel::factory()->create(['school_id' => $otherSchool->id])->id,
        'subject_book_id' => SubjectBook::factory()->create([
            'school_id' => $otherSchool->id,
            'subject_category_id' => SubjectCategory::factory()->create(['school_id' => $otherSchool->id])->id,
        ])->id,
        'academic_year_id' => AcademicYear::factory()->create(['school_id' => $otherSchool->id])->id,
        'semester' => 1,
        'title' => 'Tugas Sekolah Lain',
        'task_date' => '2025-08-01',
    ]);

    $this->actingAs($ahmad)->getJson("/api/v1/class-tasks/{$otherSchoolTask->id}")->assertNotFound();
    $this->actingAs($ahmad)->putJson("/api/v1/class-tasks/{$otherSchoolTask->id}", ['title' => 'X'])->assertNotFound();
    $this->actingAs($ahmad)->deleteJson("/api/v1/class-tasks/{$otherSchoolTask->id}")->assertNotFound();
    $this->actingAs($ahmad)->getJson("/api/v1/class-tasks/{$otherSchoolTask->id}/scores")->assertNotFound();
    $this->actingAs($ahmad)->putJson("/api/v1/class-tasks/{$otherSchoolTask->id}/scores/bulk", ['rows' => []])->assertNotFound();
});

// ── Absensi Pertemuan dan Alert Pertemuan Bolong (ticket 05) ─────────────

/**
 * The Cakupan Mengajar context with semester 1 dated 2025-07-01..2025-12-31.
 * The schedules are created while "now" is Monday 2025-09-01, so the Alert
 * Pertemuan Bolong starts there; "today" is then Wednesday 2025-09-10 (WIB),
 * which puts Ahmad's missed Mondays at 2025-09-01 and 2025-09-08 and Bakar's
 * missed Tuesdays at 2025-09-02 and 2025-09-09.
 *
 * @return array<string, mixed>
 */
function setUpTeachingScopeAttendanceContext($testCase): array
{
    Carbon::setTestNow('2025-09-01 01:00:00');
    $context = setUpTeachingScopeContext($testCase);

    $testCase->actingAs($context['superAdmin'])
        ->putJson("/api/v1/academic-years/{$context['academicYear']->id}/semesters/1", [
            'start_date' => '2025-07-01',
            'end_date' => '2025-12-31',
        ])
        ->assertOk();

    Carbon::setTestNow('2025-09-10 03:00:00');

    return $context;
}

/** A pengurus who also teaches: "Beri Akses" for his Ustadz, then roles ustadz + pengurus_pesantren. */
function createTeachingScopeMultiRoleAccount($testCase, array $context): User
{
    $pengurusYangMengajar = Teacher::factory()->create(['school_id' => $context['school']->id, 'full_name' => 'Ustadz Pengurus', 'user_id' => null]);
    $multiRoleAccount = grantTeachingScopeAccess($testCase, $context, $pengurusYangMengajar, 'pengurus.ustadz@example.com');
    $testCase->actingAs($context['superAdmin'])
        ->postJson("/api/v1/users/{$multiRoleAccount->id}/roles", ['roles' => ['ustadz', 'pengurus_pesantren']])
        ->assertOk();

    return $multiRoleAccount->fresh();
}

/** Records a held Pertemuan through POST /class-sessions with one santri's status. */
function recordTeachingScopeSession($testCase, User $user, string $scheduleId, string $sessionDate, Student $student, string $status = 'present')
{
    return $testCase->actingAs($user)->postJson('/api/v1/class-sessions', [
        'teaching_schedule_id' => $scheduleId,
        'session_date' => $sessionDate,
        'attendances' => [['student_id' => $student->id, 'status' => $status, 'notes' => null]],
    ]);
}

function teachingScopeAttendanceSchedulesUrl(array $context, int $semester = 1): string
{
    return '/api/v1/attendance-schedules?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => $semester,
    ]);
}

function teachingScopeClassSessionsUrl(array $context, array $filters = []): string
{
    return '/api/v1/class-sessions?'.http_build_query(array_merge([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
    ], $filters));
}

function teachingScopeAttendanceRecapUrl(array $context, ClassLevel $classLevel, SubjectBook $subjectBook): string
{
    return '/api/v1/attendance-recaps?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
    ]);
}

/**
 * The schedule ids of an attendance-schedules response, sorted.
 *
 * @return array<int, string>
 */
function teachingScopeScheduleIds($response): array
{
    return collect($response->json('data'))->pluck('id')->sort()->values()->all();
}

/**
 * The Alert Pertemuan Bolong the user sees, as Ustadz name => the
 * "<teaching_schedule_id>|<session_date>" of each missed Pertemuan.
 *
 * @return array<string, array<int, string>>
 */
function teachingScopeMissingSessionsByUstadz($testCase, User $user): array
{
    $response = $testCase->actingAs($user)->getJson('/api/v1/attendance-alerts')->assertOk();

    return collect($response->json('data.teachers'))
        ->mapWithKeys(fn (array $teacherGroup) => [
            $teacherGroup['teacher']['full_name'] => collect($teacherGroup['items'])
                ->map(fn (array $item) => $item['teaching_schedule_id'].'|'.$item['session_date'])
                ->all(),
        ])
        ->all();
}

/** Moves Ahmad's Tamhidi × Safinah schedule to Ustadz Bakar through the edit or the bulk ganti ustadz. */
function moveTeachingScopeScheduleToBakar($testCase, array $context, string $throughPath): void
{
    if ($throughPath === 'the schedule edit') {
        editTeachingScopeSchedule($testCase, $context, $context['ahmadSafinahScheduleId'], ['teacher_id' => $context['ustadzBakar']->id]);

        return;
    }

    // Bakar teaches Tuesday at the same slot; the Monday schedule moves without a conflict.
    replaceTeachingScopeTeacher($testCase, $context, $context['ustadzAhmad'], $context['ustadzBakar']);
}

test('the Absensi Pertemuan schedule list holds only the active schedules of the Cakupan Mengajar', function () {
    $context = setUpTeachingScopeAttendanceContext($this);
    $ahmadScheduleId = $context['ahmadSafinahScheduleId'];
    $bakarScheduleId = $context['bakarJurumiyahScheduleId'];

    $ahmadResponse = $this->actingAs($context['ahmadAccount'])
        ->getJson(teachingScopeAttendanceSchedulesUrl($context))
        ->assertOk();
    expect(teachingScopeScheduleIds($ahmadResponse))->toBe([$ahmadScheduleId])
        ->and($ahmadResponse->json('data.0.teacher.full_name'))->toBe('Ustadz Ahmad')
        ->and($ahmadResponse->json('data.0.class_level.id'))->toBe($context['tamhidi']->id)
        ->and($ahmadResponse->json('data.0.subject_book.title'))->toBe('Safinatun Najah')
        ->and($ahmadResponse->json('data.0.time_slot.id'))->toBe($context['timeSlot']->id);

    expect(teachingScopeScheduleIds($this->actingAs($context['bakarAccount'])->getJson(teachingScopeAttendanceSchedulesUrl($context))->assertOk()))
        ->toBe([$bakarScheduleId]);

    $everySchedule = collect([$ahmadScheduleId, $bakarScheduleId])->sort()->values()->all();
    foreach ([$context['pengurus'], createTeachingScopeMultiRoleAccount($this, $context)] as $unrestrictedUser) {
        expect(teachingScopeScheduleIds($this->actingAs($unrestrictedUser)->getJson(teachingScopeAttendanceSchedulesUrl($context))->assertOk()))
            ->toBe($everySchedule);
    }

    // One schedule by id (the ?schedule= link of the alert): inside the scope only.
    $this->actingAs($context['ahmadAccount'])
        ->getJson("/api/v1/attendance-schedules/{$ahmadScheduleId}")
        ->assertOk()
        ->assertJsonPath('data.id', $ahmadScheduleId)
        ->assertJsonPath('data.academic_year_id', $context['academicYear']->id)
        ->assertJsonPath('data.semester', 1);
    $this->actingAs($context['ahmadAccount'])->getJson("/api/v1/attendance-schedules/{$bakarScheduleId}")->assertNotFound();
    $this->actingAs($context['pengurus'])->getJson("/api/v1/attendance-schedules/{$bakarScheduleId}")->assertOk();

    // Another Semester Akademik: Ahmad has no schedule there.
    expect($this->actingAs($context['ahmadAccount'])->getJson(teachingScopeAttendanceSchedulesUrl($context, semester: 2))->assertOk()->json('data'))
        ->toBe([]);

    // A deleted (deactivated) schedule leaves the list, as it did for pengurus.
    $this->actingAs($context['pengurus'])->deleteJson("/api/v1/teaching-schedules/{$ahmadScheduleId}")->assertOk();
    expect($this->actingAs($context['ahmadAccount'])->getJson(teachingScopeAttendanceSchedulesUrl($context))->assertOk()->json('data'))->toBe([])
        ->and(teachingScopeScheduleIds($this->actingAs($context['pengurus'])->getJson(teachingScopeAttendanceSchedulesUrl($context))->assertOk()))
        ->toBe([$bakarScheduleId]);
});

test('an Akun Ustadz records, edits and cancels the Pertemuan of his own schedule under his own account', function () {
    $context = setUpTeachingScopeAttendanceContext($this);
    $ahmad = $context['ahmadAccount'];
    $ali = $context['tamhidiSantri'];
    $scheduleId = $context['ahmadSafinahScheduleId'];

    $this->actingAs($ahmad)
        ->getJson("/api/v1/teaching-schedules/{$scheduleId}/expected-students?session_date=2025-09-08")
        ->assertOk()
        ->assertJsonPath('data.students.0.id', $ali->id);

    $sessionId = recordTeachingScopeSession($this, $ahmad, $scheduleId, '2025-09-08', $ali)
        ->assertCreated()
        ->assertJsonPath('data.class_session.teacher.full_name', 'Ustadz Ahmad')
        ->json('data.class_session.id');

    $this->actingAs($ahmad)
        ->getJson(teachingScopeClassSessionsUrl($context, ['teaching_schedule_id' => $scheduleId]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $sessionId);
    $this->actingAs($ahmad)->getJson("/api/v1/class-sessions/{$sessionId}")->assertOk()->assertJsonPath('data.attendances.0.status', 'present');

    $this->actingAs($ahmad)
        ->putJson("/api/v1/class-sessions/{$sessionId}/attendances", [
            'attendances' => [['student_id' => $ali->id, 'status' => 'sick', 'notes' => 'Demam']],
        ])
        ->assertOk()
        ->assertJsonPath('data.attendances.0.updated_by', $ahmad->id);

    $cancelledSessionId = $this->actingAs($ahmad)
        ->postJson('/api/v1/class-sessions/cancel', [
            'teaching_schedule_id' => $scheduleId,
            'session_date' => '2025-09-01',
            'reason' => 'Ustadz sakit',
        ])
        ->assertCreated()
        ->assertJsonPath('data.class_session.status', 'cancelled')
        ->assertJsonPath('data.class_session.cancel_reason', 'Ustadz sakit')
        ->json('data.class_session.id');

    // The reason stays required for an Akun Ustadz.
    $this->actingAs($ahmad)
        ->postJson('/api/v1/class-sessions/cancel', [
            'teaching_schedule_id' => $scheduleId,
            'session_date' => '2025-09-08',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);

    $heldSession = ClassSession::findOrFail($sessionId);
    $cancelledSession = ClassSession::findOrFail($cancelledSessionId);
    expect($heldSession->teacher_id)->toBe($context['ustadzAhmad']->id)
        ->and($heldSession->created_by)->toBe($ahmad->id)
        ->and($heldSession->updated_by)->toBe($ahmad->id)
        ->and($heldSession->status)->toBe(ClassSession::STATUS_HELD)
        ->and($cancelledSession->created_by)->toBe($ahmad->id)
        ->and($cancelledSession->teacher_id)->toBe($context['ustadzAhmad']->id);

    $this->actingAs($ahmad)
        ->getJson(teachingScopeAttendanceRecapUrl($context, $context['tamhidi'], $context['safinah']))
        ->assertOk()
        ->assertJsonPath('data.held_session_count', 1)
        ->assertJsonPath('data.cancelled_session_count', 1)
        ->assertJsonPath('data.rows.0.student.id', $ali->id)
        ->assertJsonPath('data.rows.0.sick_count', 1);
});

test('a Pertemuan outside the Cakupan Mengajar is not found and a schedule outside it is refused with 403', function () {
    $context = setUpTeachingScopeAttendanceContext($this);
    $ahmad = $context['ahmadAccount'];
    $zaid = $context['ibtidaSantri'];
    $bakarScheduleId = $context['bakarJurumiyahScheduleId'];

    $bakarSessionId = recordTeachingScopeSession($this, $context['bakarAccount'], $bakarScheduleId, '2025-09-09', $zaid)
        ->assertCreated()
        ->json('data.class_session.id');

    // Records bound to the route: not found, even before the body or the query is validated.
    $this->actingAs($ahmad)->getJson("/api/v1/class-sessions/{$bakarSessionId}")->assertNotFound();
    $this->actingAs($ahmad)
        ->putJson("/api/v1/class-sessions/{$bakarSessionId}/attendances", [
            'attendances' => [['student_id' => $zaid->id, 'status' => 'absent', 'notes' => null]],
        ])
        ->assertNotFound();
    $this->actingAs($ahmad)->putJson("/api/v1/class-sessions/{$bakarSessionId}/attendances", ['attendances' => []])->assertNotFound();
    $this->actingAs($ahmad)
        ->getJson("/api/v1/teaching-schedules/{$bakarScheduleId}/expected-students?session_date=2025-09-09")
        ->assertNotFound();
    $this->actingAs($ahmad)->getJson("/api/v1/teaching-schedules/{$bakarScheduleId}/expected-students")->assertNotFound();

    // A schedule or pair chosen in the body or the query: 403 with the scope message.
    $refusals = [
        recordTeachingScopeSession($this, $ahmad, $bakarScheduleId, '2025-09-02', $zaid),
        $this->actingAs($ahmad)->postJson('/api/v1/class-sessions/cancel', [
            'teaching_schedule_id' => $bakarScheduleId,
            'session_date' => '2025-09-09',
            'reason' => 'Libur',
        ]),
        $this->actingAs($ahmad)->getJson(teachingScopeClassSessionsUrl($context, ['teaching_schedule_id' => $bakarScheduleId])),
        $this->actingAs($ahmad)->getJson(teachingScopeAttendanceRecapUrl($context, $context['ibtida'], $context['jurumiyah'])),
        // A pair nobody teaches is outside the Cakupan Mengajar too.
        $this->actingAs($ahmad)->getJson(teachingScopeAttendanceRecapUrl($context, $context['tamhidi'], $context['jurumiyah'])),
    ];
    foreach ($refusals as $refusal) {
        $refusal->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', TEACHING_SCOPE_OUTSIDE_MESSAGE);
    }

    // The unfiltered list leaves out the Pertemuan of other pairs.
    $this->actingAs($ahmad)->getJson(teachingScopeClassSessionsUrl($context))->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($ahmad)->getJson(teachingScopeClassSessionsUrl($context, ['class_level_id' => $context['ibtida']->id]))->assertOk()->assertJsonCount(0, 'data');

    // Nothing changed: Bakar's Pertemuan is the only one, as he recorded it.
    $bakarSession = ClassSession::with('attendances')->sole();
    expect($bakarSession->id)->toBe($bakarSessionId)
        ->and($bakarSession->status)->toBe(ClassSession::STATUS_HELD)
        ->and($bakarSession->updated_by)->toBe($context['bakarAccount']->id)
        ->and($bakarSession->attendances->sole()->status)->toBe('present');

    $this->actingAs($context['bakarAccount'])->getJson("/api/v1/class-sessions/{$bakarSessionId}")->assertOk();
});

test('a former Ustadz records the Pertemuan of a schedule moved away from him under the current Ustadz, as the recorder', function (string $throughPath) {
    $context = setUpTeachingScopeAttendanceContext($this);
    $ali = $context['tamhidiSantri'];
    $movedScheduleId = $context['ahmadSafinahScheduleId'];

    moveTeachingScopeScheduleToBakar($this, $context, $throughPath);

    // The riwayat pengajar keeps the pair in Ahmad's Cakupan Mengajar: the schedule, now Bakar's, is listed.
    $ahmadResponse = $this->actingAs($context['ahmadAccount'])->getJson(teachingScopeAttendanceSchedulesUrl($context))->assertOk();
    expect(teachingScopeScheduleIds($ahmadResponse))->toBe([$movedScheduleId])
        ->and($ahmadResponse->json('data.0.teacher.full_name'))->toBe('Ustadz Bakar');

    $sessionId = recordTeachingScopeSession($this, $context['ahmadAccount'], $movedScheduleId, '2025-09-08', $ali)
        ->assertCreated()
        ->assertJsonPath('data.class_session.teacher.full_name', 'Ustadz Bakar')
        ->assertJsonPath('data.class_session.created_by', $context['ahmadAccount']->id)
        ->json('data.class_session.id');

    $this->actingAs($context['bakarAccount'])
        ->putJson("/api/v1/class-sessions/{$sessionId}/attendances", [
            'attendances' => [['student_id' => $ali->id, 'status' => 'excused', 'notes' => null]],
        ])
        ->assertOk();

    $session = ClassSession::findOrFail($sessionId);
    expect($session->teacher_id)->toBe($context['ustadzBakar']->id)
        ->and($session->created_by)->toBe($context['ahmadAccount']->id)
        ->and($session->updated_by)->toBe($context['bakarAccount']->id);
})->with(['the schedule edit', 'the bulk ganti ustadz']);

test('the Alert Pertemuan Bolong of an Akun Ustadz holds only the schedules he holds now, while pengurus see every Ustadz', function (string $throughPath) {
    $context = setUpTeachingScopeAttendanceContext($this);
    $ahmadScheduleId = $context['ahmadSafinahScheduleId'];
    $bakarScheduleId = $context['bakarJurumiyahScheduleId'];
    $multiRoleAccount = createTeachingScopeMultiRoleAccount($this, $context);

    $ahmadMissing = ["{$ahmadScheduleId}|2025-09-01", "{$ahmadScheduleId}|2025-09-08"];
    $bakarMissing = ["{$bakarScheduleId}|2025-09-02", "{$bakarScheduleId}|2025-09-09"];

    expect(teachingScopeMissingSessionsByUstadz($this, $context['ahmadAccount']))->toBe(['Ustadz Ahmad' => $ahmadMissing])
        ->and(teachingScopeMissingSessionsByUstadz($this, $context['bakarAccount']))->toBe(['Ustadz Bakar' => $bakarMissing]);
    foreach ([$context['pengurus'], $multiRoleAccount] as $unrestrictedUser) {
        expect(teachingScopeMissingSessionsByUstadz($this, $unrestrictedUser))
            ->toBe(['Ustadz Ahmad' => $ahmadMissing, 'Ustadz Bakar' => $bakarMissing]);
    }

    moveTeachingScopeScheduleToBakar($this, $context, $throughPath);

    // Only the Ustadz who holds the schedule now is alerted — never the former one.
    $bakarNowMissing = collect([...$ahmadMissing, ...$bakarMissing])->sortBy(fn (string $key) => explode('|', $key)[1])->values()->all();
    expect(teachingScopeMissingSessionsByUstadz($this, $context['ahmadAccount']))->toBe([])
        ->and(teachingScopeMissingSessionsByUstadz($this, $context['bakarAccount']))->toBe(['Ustadz Bakar' => $bakarNowMissing]);
    foreach ([$context['pengurus'], $multiRoleAccount] as $unrestrictedUser) {
        expect(teachingScopeMissingSessionsByUstadz($this, $unrestrictedUser))->toBe(['Ustadz Bakar' => $bakarNowMissing]);
    }

    $this->actingAs($context['ahmadAccount'])
        ->getJson('/api/v1/attendance-alerts')
        ->assertOk()
        ->assertJsonPath('data.configured', true)
        ->assertJsonPath('data.total_missing', 0);
})->with(['the schedule edit', 'the bulk ganti ustadz']);

test('a user holding the ustadz role without a linked Ustadz has no Alert Pertemuan Bolong and no schedules', function () {
    $context = setUpTeachingScopeAttendanceContext($this);

    $accountWithoutUstadz = User::factory()->create(['school_id' => $context['school']->id]);
    $accountWithoutUstadz->assignRole('ustadz');

    $this->actingAs($accountWithoutUstadz)
        ->getJson('/api/v1/attendance-alerts')
        ->assertOk()
        ->assertJsonPath('data.total_missing', 0)
        ->assertJsonPath('data.teachers', []);
    $this->actingAs($accountWithoutUstadz)->getJson(teachingScopeAttendanceSchedulesUrl($context))->assertOk()->assertJsonPath('data', []);
    recordTeachingScopeSession($this, $accountWithoutUstadz, $context['ahmadSafinahScheduleId'], '2025-09-08', $context['tamhidiSantri'])
        ->assertForbidden()
        ->assertJsonPath('message', TEACHING_SCOPE_OUTSIDE_MESSAGE);
});

test('the 14-day limit and the date rules apply to an Akun Ustadz exactly as to pengurus', function () {
    $context = setUpTeachingScopeAttendanceContext($this);

    // Today is Wednesday 2025-09-10 (WIB); the edit window reaches back to 2025-08-27.
    $cases = [
        'Akun Ustadz' => [$context['ahmadAccount'], $context['ahmadSafinahScheduleId'], $context['tamhidiSantri'], ['future' => '2025-09-15', 'old' => '2025-08-25', 'recent' => '2025-09-01']],
        'pengurus' => [$context['pengurus'], $context['bakarJurumiyahScheduleId'], $context['ibtidaSantri'], ['future' => '2025-09-16', 'old' => '2025-08-26', 'recent' => '2025-09-02']],
    ];

    foreach ($cases as [$user, $scheduleId, $santri, $dates]) {
        recordTeachingScopeSession($this, $user, $scheduleId, $dates['future'], $santri)
            ->assertUnprocessable()
            ->assertJsonPath('errors.session_date.0', 'Pertemuan tidak boleh dicatat untuk tanggal mendatang.');

        // A missed Pertemuan older than 14 days may still be recorded, but not changed afterwards.
        $oldSessionId = recordTeachingScopeSession($this, $user, $scheduleId, $dates['old'], $santri)->assertCreated()->json('data.class_session.id');
        $this->actingAs($user)
            ->putJson("/api/v1/class-sessions/{$oldSessionId}/attendances", [
                'attendances' => [['student_id' => $santri->id, 'status' => 'absent', 'notes' => null]],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.session_date.0', 'Perubahan absensi hanya boleh sampai 14 hari ke belakang.');
        $this->actingAs($user)
            ->postJson('/api/v1/class-sessions/cancel', ['teaching_schedule_id' => $scheduleId, 'session_date' => $dates['old'], 'reason' => 'Libur'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.session_date.0', 'Perubahan absensi hanya boleh sampai 14 hari ke belakang.');

        // Inside the window the change is saved.
        $recentSessionId = recordTeachingScopeSession($this, $user, $scheduleId, $dates['recent'], $santri)->assertCreated()->json('data.class_session.id');
        $this->actingAs($user)
            ->putJson("/api/v1/class-sessions/{$recentSessionId}/attendances", [
                'attendances' => [['student_id' => $santri->id, 'status' => 'absent', 'notes' => null]],
            ])
            ->assertOk();

        expect(ClassSession::findOrFail($oldSessionId)->status)->toBe(ClassSession::STATUS_HELD);
    }
});

test('libur massal is refused for an Akun Ustadz and stays with pengurus, including a pengurus who teaches', function () {
    $context = setUpTeachingScopeAttendanceContext($this);
    $cancelRangePayload = ['start_date' => '2025-09-01', 'end_date' => '2025-09-09', 'reason' => 'Libur Maulid Nabi'];

    $this->actingAs($context['ahmadAccount'])->postJson('/api/v1/class-sessions/cancel-range', $cancelRangePayload)->assertForbidden();
    expect(ClassSession::count())->toBe(0);

    $this->actingAs(createTeachingScopeMultiRoleAccount($this, $context))
        ->postJson('/api/v1/class-sessions/cancel-range', $cancelRangePayload)
        ->assertOk()
        ->assertJsonPath('data.created', 4);
});

test('viewing attendance within the Cakupan Mengajar does not allow recording it', function () {
    $context = setUpTeachingScopeAttendanceContext($this);
    $ali = $context['tamhidiSantri'];
    $scheduleId = $context['ahmadSafinahScheduleId'];
    $sessionId = recordTeachingScopeSession($this, $context['pengurus'], $scheduleId, '2025-09-08', $ali)->assertCreated()->json('data.class_session.id');

    $viewOnlyRole = Role::firstOrCreate(['name' => 'ustadz_absensi_baca_saja', 'guard_name' => 'web']);
    $viewOnlyRole->syncPermissions(['view-own-attendance']);
    $viewOnlyAccount = $context['ahmadAccount'];
    $viewOnlyAccount->syncRoles([$viewOnlyRole]);

    $this->actingAs($viewOnlyAccount)->getJson(teachingScopeAttendanceSchedulesUrl($context))->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($viewOnlyAccount)->getJson("/api/v1/attendance-schedules/{$scheduleId}")->assertOk();
    $this->actingAs($viewOnlyAccount)->getJson(teachingScopeClassSessionsUrl($context))->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($viewOnlyAccount)->getJson("/api/v1/class-sessions/{$sessionId}")->assertOk();
    $this->actingAs($viewOnlyAccount)->getJson("/api/v1/teaching-schedules/{$scheduleId}/expected-students?session_date=2025-09-01")->assertOk();
    $this->actingAs($viewOnlyAccount)->getJson(teachingScopeAttendanceRecapUrl($context, $context['tamhidi'], $context['safinah']))->assertOk();
    $this->actingAs($viewOnlyAccount)->getJson('/api/v1/attendance-alerts')->assertOk();

    recordTeachingScopeSession($this, $viewOnlyAccount, $scheduleId, '2025-09-01', $ali)->assertForbidden();
    $this->actingAs($viewOnlyAccount)
        ->putJson("/api/v1/class-sessions/{$sessionId}/attendances", ['attendances' => [['student_id' => $ali->id, 'status' => 'absent', 'notes' => null]]])
        ->assertForbidden();
    $this->actingAs($viewOnlyAccount)
        ->postJson('/api/v1/class-sessions/cancel', ['teaching_schedule_id' => $scheduleId, 'session_date' => '2025-09-01', 'reason' => 'Libur'])
        ->assertForbidden();
    expect(ClassSession::count())->toBe(1);

    $accountWithoutAttendancePermissions = User::factory()->create(['school_id' => $context['school']->id]);
    foreach ([
        teachingScopeAttendanceSchedulesUrl($context),
        "/api/v1/attendance-schedules/{$scheduleId}",
        teachingScopeClassSessionsUrl($context),
        "/api/v1/class-sessions/{$sessionId}",
        "/api/v1/teaching-schedules/{$scheduleId}/expected-students?session_date=2025-09-01",
        teachingScopeAttendanceRecapUrl($context, $context['tamhidi'], $context['safinah']),
        '/api/v1/attendance-alerts',
    ] as $readUrl) {
        $this->actingAs($accountWithoutAttendancePermissions)->getJson($readUrl)->assertForbidden();
    }
    recordTeachingScopeSession($this, $accountWithoutAttendancePermissions, $scheduleId, '2025-09-01', $ali)->assertForbidden();
});

test('pengurus and a pengurus who also teaches record and read the Pertemuan of every schedule', function () {
    $context = setUpTeachingScopeAttendanceContext($this);
    $multiRoleAccount = createTeachingScopeMultiRoleAccount($this, $context);

    $bakarSessionId = recordTeachingScopeSession($this, $multiRoleAccount, $context['bakarJurumiyahScheduleId'], '2025-09-09', $context['ibtidaSantri'])
        ->assertCreated()
        ->assertJsonPath('data.class_session.teacher.full_name', 'Ustadz Bakar')
        ->assertJsonPath('data.class_session.created_by', $multiRoleAccount->id)
        ->json('data.class_session.id');
    recordTeachingScopeSession($this, $context['pengurus'], $context['ahmadSafinahScheduleId'], '2025-09-08', $context['tamhidiSantri'])->assertCreated();

    foreach ([$context['pengurus'], $multiRoleAccount] as $unrestrictedUser) {
        $this->actingAs($unrestrictedUser)->getJson(teachingScopeClassSessionsUrl($context))->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($unrestrictedUser)->getJson("/api/v1/class-sessions/{$bakarSessionId}")->assertOk();
        $this->actingAs($unrestrictedUser)->getJson(teachingScopeAttendanceRecapUrl($context, $context['ibtida'], $context['jurumiyah']))->assertOk();
    }
});

test('an Akun Ustadz gets the same validation and tenancy answers on Absensi as pengurus', function () {
    $context = setUpTeachingScopeAttendanceContext($this);

    foreach ([$context['ahmadAccount'], $context['pengurus']] as $user) {
        $this->actingAs($user)
            ->getJson('/api/v1/attendance-schedules')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['academic_year_id', 'semester']);
        $this->actingAs($user)
            ->postJson('/api/v1/class-sessions', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['teaching_schedule_id', 'session_date', 'attendances']);
    }

    // A santri of another class is rejected per santri inside Ahmad's own schedule.
    recordTeachingScopeSession($this, $context['ahmadAccount'], $context['ahmadSafinahScheduleId'], '2025-09-08', $context['ibtidaSantri'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$context['ibtidaSantri']->id]);

    $otherSchool = School::factory()->create();
    $otherAcademicYear = AcademicYear::factory()->create(['school_id' => $otherSchool->id]);
    $otherClassLevel = ClassLevel::factory()->create(['school_id' => $otherSchool->id]);
    $otherSubjectBook = SubjectBook::factory()->create([
        'school_id' => $otherSchool->id,
        'subject_category_id' => SubjectCategory::factory()->create(['school_id' => $otherSchool->id])->id,
    ]);
    $otherTeacher = Teacher::factory()->create(['school_id' => $otherSchool->id]);
    $otherSchedule = TeachingSchedule::factory()->create([
        'school_id' => $otherSchool->id,
        'academic_year_id' => $otherAcademicYear->id,
        'semester' => 1,
        'day_of_week' => 'monday',
        'time_slot_id' => TimeSlot::factory()->create(['school_id' => $otherSchool->id])->id,
        'class_level_id' => $otherClassLevel->id,
        'subject_book_id' => $otherSubjectBook->id,
        'teacher_id' => $otherTeacher->id,
    ]);

    foreach ([$context['ahmadAccount'], $context['pengurus']] as $user) {
        $this->actingAs($user)->getJson("/api/v1/attendance-schedules/{$otherSchedule->id}")->assertNotFound();
        recordTeachingScopeSession($this, $user, $otherSchedule->id, '2025-09-08', $context['tamhidiSantri'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['teaching_schedule_id']);
        $this->actingAs($user)
            ->getJson('/api/v1/attendance-schedules?'.http_build_query(['academic_year_id' => $otherAcademicYear->id, 'semester' => 1]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['academic_year_id']);
    }

    expect(ClassSession::count())->toBe(0);
});

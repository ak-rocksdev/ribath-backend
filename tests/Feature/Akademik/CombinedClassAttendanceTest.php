<?php

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\ClassSession;
use App\Models\GradingTemplate;
use App\Models\School;
use App\Models\StudentAttendance;
use App\Models\SubjectBook;
use App\Models\SubjectCategory;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Akademik\AcademicSemesterService;
use App\Services\Akademik\GradingDefaultsInstaller;
use Carbon\Carbon;
use Database\Seeders\ClassLevelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;

/*
 * Kelas gabungan, the teaching side (ADR 0006, ticket 02): one Jadwal
 * Mengajar holding two Kelas gives its Ustadz both Kelas × Kitab pairs in
 * his Cakupan Mengajar, is absen once for the santri of both Kelas, and
 * still reports a Rekap Kehadiran per Kelas. Nilai stay per Kelas.
 *
 * Everything is created through the real endpoints: the Akun Ustadz through
 * "Beri Akses", the schedules through /teaching-schedules, the santri
 * through POST /students, the Pertemuan through /class-sessions. A
 * single-class schedule is carried along as the regression case.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Semester 1 of 2025/2026 (2025-07-01 … 2025-12-31) with:
 *
 * - a combined Monday schedule: Takmilah for Ibtida 2 + Tsanawiyah 1, by
 *   Ustadz Ali, who has an Akun Ustadz;
 * - a single-class Tuesday schedule: Jurumiyah for Tamhidi, by Ustadz Umar,
 *   who has one too;
 * - one santri per Kelas: Ahmad (Ibtida 2), Bilal (Tsanawiyah 1), Cecep
 *   (Tamhidi — outside the combined schedule).
 *
 * @return array<string, mixed>
 */
function setUpCombinedClassAttendanceContext($testCase): array
{
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    (new ClassLevelSeeder)->run();

    $school = School::where('is_active', true)->firstOrFail();
    app(GradingDefaultsInstaller::class)->installForSchool($school);

    $superAdmin = User::factory()->create(['school_id' => $school->id, 'name' => 'Super Admin Gabungan']);
    $superAdmin->assignRole('super_admin');

    $pengurus = User::factory()->create(['school_id' => $school->id, 'name' => 'Pengurus Gabungan']);
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
    app(AcademicSemesterService::class)->updateSemester($academicYear, 1, [
        'start_date' => '2025-07-01',
        'end_date' => '2025-12-31',
    ]);

    $context = [
        'school' => $school,
        'superAdmin' => $superAdmin,
        'pengurus' => $pengurus,
        'academicYear' => $academicYear,
        'ishaSlot' => TimeSlot::factory()->create(['school_id' => $school->id, 'sort_order' => 1]),
        'subuhSlot' => TimeSlot::factory()->create(['school_id' => $school->id, 'sort_order' => 2]),
        'ibtida2' => ClassLevel::where('school_id', $school->id)->where('slug', 'ibtida_2')->firstOrFail(),
        'tsanawiyah1' => ClassLevel::where('school_id', $school->id)->where('slug', 'tsanawiyah_1')->firstOrFail(),
        'tamhidi' => ClassLevel::where('school_id', $school->id)->where('slug', 'tamhidi')->firstOrFail(),
        'takmilah' => combinedClassSubjectBook($school, 'Takmilah'),
        'jurumiyah' => combinedClassSubjectBook($school, 'Jurumiyah'),
        'ustadzAli' => Teacher::factory()->create(['school_id' => $school->id, 'full_name' => 'Ustadz Ali', 'user_id' => null]),
        'ustadzUmar' => Teacher::factory()->create(['school_id' => $school->id, 'full_name' => 'Ustadz Umar', 'user_id' => null]),
    ];

    $context['aliAccount'] = grantTeacherAccess($testCase, $superAdmin, $context['ustadzAli'], 'ali@example.com');
    $context['umarAccount'] = grantTeacherAccess($testCase, $superAdmin, $context['ustadzUmar'], 'umar@example.com');

    $context['combinedScheduleId'] = combinedClassCreateSchedule($testCase, $context, [
        'day_of_week' => 'monday',
        'time_slot_id' => $context['ishaSlot']->id,
        'class_level_ids' => [$context['ibtida2']->id, $context['tsanawiyah1']->id],
        'subject_book_id' => $context['takmilah']->id,
        'teacher_id' => $context['ustadzAli']->id,
    ]);

    $context['singleScheduleId'] = combinedClassCreateSchedule($testCase, $context, [
        'day_of_week' => 'tuesday',
        'time_slot_id' => $context['subuhSlot']->id,
        'class_level_ids' => [$context['tamhidi']->id],
        'subject_book_id' => $context['jurumiyah']->id,
        'teacher_id' => $context['ustadzUmar']->id,
    ]);

    $context['ahmad'] = createSantriThroughEndpoint($testCase, $superAdmin, 'Ahmad', 'ibtida_2');
    $context['bilal'] = createSantriThroughEndpoint($testCase, $superAdmin, 'Bilal', 'tsanawiyah_1');
    $context['cecep'] = createSantriThroughEndpoint($testCase, $superAdmin, 'Cecep', 'tamhidi');

    return $context;
}

function combinedClassSubjectBook(School $school, string $title): SubjectBook
{
    return SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => SubjectCategory::factory()->create(['school_id' => $school->id])->id,
        'grading_template_id' => GradingTemplate::where('school_id', $school->id)
            ->where('code', GradingTemplate::CODE_TEORI_KITAB)
            ->value('id'),
        'title' => $title,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function combinedClassCreateSchedule($testCase, array $context, array $attributes): string
{
    return createTeachingScheduleThroughEndpoint($testCase, $context['pengurus'], array_merge([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
    ], $attributes));
}

/**
 * The Kelas the stored Pertemuan covers, by label, in the Kelas master
 * order — the Pertemuan's own set, not its schedule's (ADR 0006).
 *
 * @return array<int, string>
 */
function combinedClassSessionClassLevelLabels(string $sessionId): array
{
    return ClassSession::findOrFail($sessionId)->classLevels->pluck('label')->all();
}

function combinedClassRecapUrl(array $context, ClassLevel $classLevel, SubjectBook $subjectBook): string
{
    return '/api/v1/attendance-recaps?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
    ]);
}

// ── Cakupan Mengajar ───────────────────────────────────────────────────────

test('a combined schedule puts one Kelas × Kitab pair per Kelas in the Cakupan Mengajar of its Ustadz', function () {
    $context = setUpCombinedClassAttendanceContext($this);

    $pairs = $this->actingAs($context['aliAccount'])
        ->getJson('/api/v1/gradable-subjects?'.http_build_query([
            'academic_year_id' => $context['academicYear']->id,
            'semester' => 1,
        ]))
        ->assertOk()
        ->json('data');

    expect(collect($pairs)->map(fn (array $pair) => [$pair['class_level']['label'], $pair['subject_book']['title']])->all())
        ->toEqual([['Ibtida 2', 'Takmilah'], ['Tsanawiyah 1', 'Takmilah']]);
});

test('the Ustadz of a combined schedule may open the grade grid of either Kelas', function () {
    $context = setUpCombinedClassAttendanceContext($this);

    foreach ([[$context['ibtida2'], $context['ahmad']], [$context['tsanawiyah1'], $context['bilal']]] as [$classLevel, $santri]) {
        $this->actingAs($context['aliAccount'])
            ->getJson('/api/v1/student-grades?'.http_build_query([
                'academic_year_id' => $context['academicYear']->id,
                'semester' => 1,
                'class_level_id' => $classLevel->id,
                'subject_book_id' => $context['takmilah']->id,
            ]))
            ->assertOk()
            ->assertJsonPath('data.class_level.id', $classLevel->id)
            ->assertJsonPath('data.students.0.id', $santri->id);
    }
});

// ── Roster absensi ─────────────────────────────────────────────────────────

test('the expected santri of a combined Pertemuan are every Kelas of the schedule, grouped per Kelas', function () {
    $context = setUpCombinedClassAttendanceContext($this);

    $response = $this->actingAs($context['aliAccount'])
        ->getJson("/api/v1/teaching-schedules/{$context['combinedScheduleId']}/expected-students?session_date=2025-09-01")
        ->assertOk();

    expect(collect($response->json('data.class_levels'))->pluck('label')->all())->toEqual(['Ibtida 2', 'Tsanawiyah 1']);
    expect(collect($response->json('data.students'))->map(fn (array $student) => [$student['full_name'], $student['class_level_id']])->all())
        ->toEqual([
            ['Ahmad', $context['ibtida2']->id],
            ['Bilal', $context['tsanawiyah1']->id],
        ]);
});

test('a single-class schedule still lists only its own Kelas and santri', function () {
    $context = setUpCombinedClassAttendanceContext($this);

    $response = $this->actingAs($context['umarAccount'])
        ->getJson("/api/v1/teaching-schedules/{$context['singleScheduleId']}/expected-students?session_date=2025-09-02")
        ->assertOk();

    expect(collect($response->json('data.class_levels'))->pluck('label')->all())->toEqual(['Tamhidi']);
    expect(collect($response->json('data.students'))->pluck('full_name')->all())->toEqual(['Cecep']);
});

// ── Satu pertemuan, satu penyimpanan ───────────────────────────────────────

test('a combined Pertemuan is recorded once and every Absensi row keeps the Kelas of its santri', function () {
    $context = setUpCombinedClassAttendanceContext($this);

    $response = recordClassSessionThroughEndpoint($this, $context['aliAccount'], $context['combinedScheduleId'], '2025-09-01', [
        $context['ahmad']->id => 'present',
        $context['bilal']->id => 'absent',
    ])->assertCreated();

    expect(ClassSession::where('teaching_schedule_id', $context['combinedScheduleId'])->count())->toBe(1);

    // The Pertemuan keeps one snapshot Kelas — its Kelas utama — and covers
    // every Kelas it was held for.
    expect($response->json('data.class_session.class_level.label'))->toBe('Ibtida 2')
        ->and(combinedClassSessionClassLevelLabels($response->json('data.class_session.id')))
        ->toEqual(['Ibtida 2', 'Tsanawiyah 1']);

    $classLevelIdByStudentId = StudentAttendance::query()
        ->pluck('class_level_id', 'student_id');

    expect($classLevelIdByStudentId[$context['ahmad']->id])->toBe($context['ibtida2']->id)
        ->and($classLevelIdByStudentId[$context['bilal']->id])->toBe($context['tsanawiyah1']->id);
});

test('an Absensi row of a santri outside every Kelas of the schedule is rejected', function () {
    $context = setUpCombinedClassAttendanceContext($this);

    recordClassSessionThroughEndpoint($this, $context['aliAccount'], $context['combinedScheduleId'], '2025-09-01', [
        $context['ahmad']->id => 'present',
        $context['bilal']->id => 'present',
        $context['cecep']->id => 'present',
    ])->assertUnprocessable()->assertJsonValidationErrors([$context['cecep']->id]);

    expect(ClassSession::count())->toBe(0);
});

test('a combined Pertemuan is incomplete until every active santri of both Kelas has a status', function () {
    $context = setUpCombinedClassAttendanceContext($this);

    recordClassSessionThroughEndpoint($this, $context['aliAccount'], $context['combinedScheduleId'], '2025-09-01', [
        $context['ahmad']->id => 'present',
    ])->assertUnprocessable()->assertJsonValidationErrors([$context['bilal']->id]);
});

test('a santri of the second Kelas of a combined Pertemuan can be edited afterwards', function () {
    // Inside the 14-day attendance edit window of the 2025-09-01 Pertemuan.
    Carbon::setTestNow('2025-09-10 10:00:00');
    $context = setUpCombinedClassAttendanceContext($this);

    $sessionId = recordClassSessionThroughEndpoint($this, $context['aliAccount'], $context['combinedScheduleId'], '2025-09-01', [
        $context['ahmad']->id => 'present',
        $context['bilal']->id => 'present',
    ])->assertCreated()->json('data.class_session.id');

    $this->actingAs($context['aliAccount'])
        ->putJson("/api/v1/class-sessions/{$sessionId}/attendances", [
            'attendances' => [['student_id' => $context['bilal']->id, 'status' => 'sick', 'notes' => 'Demam']],
        ])
        ->assertOk()
        ->assertJsonPath('data.attendances.0.status', 'sick');

    expect(StudentAttendance::where('student_id', $context['bilal']->id)->value('class_level_id'))
        ->toBe($context['tsanawiyah1']->id);
});

// ── Rekap kehadiran per Kelas ──────────────────────────────────────────────

test('the Rekap Kehadiran of each Kelas of a combined schedule counts only its own santri', function () {
    $context = setUpCombinedClassAttendanceContext($this);

    recordClassSessionThroughEndpoint($this, $context['aliAccount'], $context['combinedScheduleId'], '2025-09-01', [
        $context['ahmad']->id => 'present',
        $context['bilal']->id => 'absent',
    ])->assertCreated();

    $ibtidaRecap = $this->actingAs($context['aliAccount'])
        ->getJson(combinedClassRecapUrl($context, $context['ibtida2'], $context['takmilah']))
        ->assertOk()
        ->json('data');

    expect($ibtidaRecap['held_session_count'])->toBe(1)
        ->and(collect($ibtidaRecap['rows'])->pluck('student.full_name')->all())->toEqual(['Ahmad'])
        ->and($ibtidaRecap['rows'][0]['present_count'])->toBe(1)
        ->and($ibtidaRecap['rows'][0]['score'])->toEqual(100);

    $tsanawiyahRecap = $this->actingAs($context['aliAccount'])
        ->getJson(combinedClassRecapUrl($context, $context['tsanawiyah1'], $context['takmilah']))
        ->assertOk()
        ->json('data');

    expect($tsanawiyahRecap['held_session_count'])->toBe(1)
        ->and(collect($tsanawiyahRecap['rows'])->pluck('student.full_name')->all())->toEqual(['Bilal'])
        ->and($tsanawiyahRecap['rows'][0]['absent_count'])->toBe(1)
        ->and($tsanawiyahRecap['rows'][0]['score'])->toEqual(0);
});

test('a Kelas taken off a combined schedule keeps the Rekap Kehadiran it was recorded for', function () {
    $context = setUpCombinedClassAttendanceContext($this);

    recordClassSessionThroughEndpoint($this, $context['aliAccount'], $context['combinedScheduleId'], '2025-09-01', [
        $context['ahmad']->id => 'present',
        $context['bilal']->id => 'present',
    ])->assertCreated();

    $this->actingAs($context['pengurus'])
        ->putJson("/api/v1/teaching-schedules/{$context['combinedScheduleId']}", [
            'class_level_ids' => [$context['ibtida2']->id],
        ])
        ->assertOk();

    $recap = $this->actingAs($context['aliAccount'])
        ->getJson(combinedClassRecapUrl($context, $context['tsanawiyah1'], $context['takmilah']))
        ->assertOk()
        ->json('data');

    expect($recap['held_session_count'])->toBe(1)
        ->and(collect($recap['rows'])->pluck('student.full_name')->all())->toEqual(['Bilal'])
        ->and($recap['rows'][0]['present_count'])->toBe(1);
});

test('a deactivated combined schedule keeps both pairs gradable when only Absensi was recorded', function () {
    $context = setUpCombinedClassAttendanceContext($this);

    recordClassSessionThroughEndpoint($this, $context['aliAccount'], $context['combinedScheduleId'], '2025-09-01', [
        $context['ahmad']->id => 'present',
        $context['bilal']->id => 'present',
    ])->assertCreated();

    $this->actingAs($context['pengurus'])
        ->deleteJson("/api/v1/teaching-schedules/{$context['combinedScheduleId']}")
        ->assertOk();

    $pairs = $this->actingAs($context['pengurus'])
        ->getJson('/api/v1/gradable-subjects?'.http_build_query([
            'academic_year_id' => $context['academicYear']->id,
            'semester' => 1,
        ]))
        ->assertOk()
        ->json('data');

    $takmilahPairs = collect($pairs)->where('subject_book.title', 'Takmilah');

    expect($takmilahPairs->pluck('class_level.label')->all())->toEqual(['Ibtida 2', 'Tsanawiyah 1'])
        ->and($takmilahPairs->pluck('is_schedule_stopped')->all())->toEqual([true, true]);
});

// ── Peringatan pertemuan belum diabsen ─────────────────────────────────────

test('an unrecorded combined Pertemuan raises one alert naming every Kelas', function () {
    // The schedule cannot be bolong before it existed: create it on the first
    // Monday of September, then look back from the tenth.
    Carbon::setTestNow('2025-09-01 08:00:00');
    $context = setUpCombinedClassAttendanceContext($this);
    Carbon::setTestNow('2025-09-10 10:00:00');

    $items = $this->actingAs($context['aliAccount'])
        ->getJson('/api/v1/attendance-alerts?'.http_build_query([
            'academic_year_id' => $context['academicYear']->id,
            'semester' => 1,
        ]))
        ->assertOk()
        ->json('data.teachers.0.items');

    $itemsOfFirstMonday = collect($items)->where('session_date', '2025-09-01');

    expect($itemsOfFirstMonday)->toHaveCount(1)
        ->and(collect($itemsOfFirstMonday->first()['class_levels'])->pluck('label')->all())
        ->toEqual(['Ibtida 2', 'Tsanawiyah 1']);
});

// ── Regresi jadwal berkelas tunggal ────────────────────────────────────────

test('a single-class Pertemuan is recorded and recapped exactly as before', function () {
    $context = setUpCombinedClassAttendanceContext($this);

    $response = recordClassSessionThroughEndpoint($this, $context['umarAccount'], $context['singleScheduleId'], '2025-09-02', [
        $context['cecep']->id => 'present',
    ])->assertCreated();

    expect(combinedClassSessionClassLevelLabels($response->json('data.class_session.id')))->toEqual(['Tamhidi']);

    expect(StudentAttendance::where('student_id', $context['cecep']->id)->value('class_level_id'))
        ->toBe($context['tamhidi']->id);

    $recap = $this->actingAs($context['umarAccount'])
        ->getJson(combinedClassRecapUrl($context, $context['tamhidi'], $context['jurumiyah']))
        ->assertOk()
        ->json('data');

    expect($recap['held_session_count'])->toBe(1)
        ->and($recap['cancelled_session_count'])->toBe(0)
        ->and(collect($recap['rows'])->pluck('student.full_name')->all())->toEqual(['Cecep'])
        ->and($recap['rows'][0]['present_count'])->toBe(1);
});

test('the Absensi Pertemuan schedule list of an Akun Ustadz holds his combined schedule once', function () {
    $context = setUpCombinedClassAttendanceContext($this);

    $schedules = $this->actingAs($context['aliAccount'])
        ->getJson('/api/v1/attendance-schedules?'.http_build_query([
            'academic_year_id' => $context['academicYear']->id,
            'semester' => 1,
        ]))
        ->assertOk()
        ->json('data');

    expect($schedules)->toHaveCount(1)
        ->and(collect($schedules[0]['class_levels'])->pluck('label')->all())->toEqual(['Ibtida 2', 'Tsanawiyah 1']);
});

test('a Pertemuan covers the Kelas its schedule held when it was recorded, not one added later', function () {
    // Inside the 14-day attendance edit window of the 2025-09-01 Pertemuan.
    Carbon::setTestNow('2025-09-10 10:00:00');
    $context = setUpCombinedClassAttendanceContext($this);

    $sessionId = recordClassSessionThroughEndpoint($this, $context['aliAccount'], $context['combinedScheduleId'], '2025-09-01', [
        $context['ahmad']->id => 'present',
        $context['bilal']->id => 'present',
    ])->assertCreated()->json('data.class_session.id');

    // Tamhidi joins the combined schedule afterwards (its own schedule sits
    // in another slot, so nothing conflicts).
    $this->actingAs($context['pengurus'])
        ->putJson("/api/v1/teaching-schedules/{$context['combinedScheduleId']}", [
            'class_level_ids' => [$context['ibtida2']->id, $context['tsanawiyah1']->id, $context['tamhidi']->id],
        ])
        ->assertOk();

    // The Pertemuan already held keeps the two Kelas it was held for.
    $response = $this->actingAs($context['aliAccount'])
        ->getJson("/api/v1/teaching-schedules/{$context['combinedScheduleId']}/expected-students?session_date=2025-09-01")
        ->assertOk();

    expect(collect($response->json('data.class_levels'))->pluck('label')->all())->toEqual(['Ibtida 2', 'Tsanawiyah 1'])
        ->and(collect($response->json('data.students'))->pluck('full_name')->all())->toEqual(['Ahmad', 'Bilal']);

    $this->actingAs($context['aliAccount'])
        ->putJson("/api/v1/class-sessions/{$sessionId}/attendances", [
            'attendances' => [['student_id' => $context['cecep']->id, 'status' => 'present', 'notes' => null]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$context['cecep']->id]);

    // A Pertemuan recorded from now on covers all three Kelas.
    $laterResponse = $this->actingAs($context['aliAccount'])
        ->getJson("/api/v1/teaching-schedules/{$context['combinedScheduleId']}/expected-students?session_date=2025-09-08")
        ->assertOk();

    expect(collect($laterResponse->json('data.class_levels'))->pluck('label')->all())
        ->toEqual(['Tamhidi', 'Ibtida 2', 'Tsanawiyah 1']);
});

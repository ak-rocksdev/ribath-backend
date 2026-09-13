<?php

use App\Models\AcademicSemester;
use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\GradingFactor;
use App\Models\School;
use App\Models\Student;
use App\Models\SubjectBook;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Akademik\AcademicSemesterService;
use App\Services\Akademik\FactorScores\FactorScoreContext;
use App\Services\Akademik\FactorScores\MemorizationFactorScoreProvider;
use App\Services\Akademik\GradingDefaultsInstaller;
use Database\Seeders\ClassLevelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;
use Database\Seeders\SubjectCategorySeeder;
use Database\Seeders\TahfizhSubjectBookSeeder;

/**
 * Task 15: the three automatic Tahfizh factors (target_hafalan,
 * kualitas_setoran, murajaah) plus the manual UAS Tahfizh, end to end
 * through the real endpoints that feed them — Target Hafalan, Log Setoran
 * dan Murajaah, the grade grid, and the class recap.
 *
 * @return array{user: User, school: School, academicYear: AcademicYear, classLevel: ClassLevel, teacher: Teacher, tahfizhBook: SubjectBook}
 */
function setUpMemorizationFactorRecapContext(): array
{
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    (new ClassLevelSeeder)->run();

    $user = User::factory()->create(['name' => 'Admin Rekap Tahfidz']);
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
        'full_name' => 'Ustadz Rekap Tahfidz',
        'status' => Teacher::STATUS_ACTIVE,
    ]);
    $tahfizhBook = SubjectBook::where('school_id', $school->id)->where('title', "Tahfizh Al-Qur'an")->firstOrFail();

    return compact('user', 'school', 'academicYear', 'classLevel', 'teacher', 'tahfizhBook');
}

function factorCreateStudent($testCase, User $user, string $fullName): Student
{
    $response = $testCase->actingAs($user)->postJson('/api/v1/students', [
        'full_name' => $fullName,
        'birth_date' => '2012-05-15',
        'gender' => 'L',
        'program' => 'regular',
        'entry_date' => '2025-07-01',
        'class_level' => 'tamhidi',
        'address' => 'Jl. Contoh No. 1',
    ]);

    $response->assertCreated();

    return Student::findOrFail($response->json('data.id'));
}

function factorCreateTarget($testCase, array $context, Student $student, float $targetPages): void
{
    $testCase->actingAs($context['user'])->postJson('/api/v1/memorization-targets', [
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'student_id' => $student->id,
        'target_pages' => $targetPages,
        'teacher_id' => $context['teacher']->id,
    ])->assertCreated();
}

function factorCreateLog($testCase, array $context, Student $student, string $type, float $pages, int $qualityScore, string $logDate = '2025-09-10'): void
{
    $testCase->actingAs($context['user'])->postJson('/api/v1/memorization-logs', [
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'student_id' => $student->id,
        'teacher_id' => $context['teacher']->id,
        'log_date' => $logDate,
        'type' => $type,
        'pages' => $pages,
        'quality_score' => $qualityScore,
    ])->assertCreated();
}

function factorSaveUasTahfizh($testCase, array $context, Student $student, float $score): void
{
    $testCase->actingAs($context['user'])->putJson('/api/v1/student-grades/bulk', [
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'subject_book_id' => $context['tahfizhBook']->id,
        'rows' => [
            ['student_id' => $student->id, 'scores' => ['uas_tahfizh' => $score]],
        ],
    ])->assertOk();
}

function factorRecapRowFor($testCase, array $context, Student $student): array
{
    $response = $testCase->actingAs($context['user'])->getJson('/api/v1/grade-recaps/class?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'subject_book_id' => $context['tahfizhBook']->id,
    ]));

    $response->assertOk();

    return collect($response->json('data.rows'))->firstWhere('student.id', $student->id);
}

function factorFromRow(array $row, string $code): array
{
    return collect($row['factors'])->firstWhere('code', $code);
}

// ── Full flow: target + logs + UAS → weighted 20/20/20/40 final score ────

test('the class recap computes the weighted final score from target achievement, submission quality, review quality and UAS Tahfizh', function () {
    $context = setUpMemorizationFactorRecapContext();
    $student = factorCreateStudent($this, $context['user'], 'Santri Rekap Lengkap');

    factorCreateTarget($this, $context, $student, 40);
    factorCreateLog($this, $context, $student, 'new', 10, 80);
    factorCreateLog($this, $context, $student, 'new', 10, 90);
    factorCreateLog($this, $context, $student, 'review', 5, 70);
    factorCreateLog($this, $context, $student, 'review', 5, 90);
    factorSaveUasTahfizh($this, $context, $student, 95);

    $row = factorRecapRowFor($this, $context, $student);

    // target_hafalan: 20 halaman Setoran ÷ 40 target × 100 = 50.
    expect(factorFromRow($row, 'target_hafalan'))->toMatchArray([
        'score' => 50.0, 'source' => 'hafalan', 'is_missing' => false, 'missing_reason' => null,
    ]);
    // kualitas_setoran: rata-rata 80 dan 90 = 85.
    expect(factorFromRow($row, 'kualitas_setoran'))->toMatchArray([
        'score' => 85.0, 'source' => 'hafalan', 'is_missing' => false,
    ]);
    // murajaah: rata-rata 70 dan 90 = 80.
    expect(factorFromRow($row, 'murajaah'))->toMatchArray([
        'score' => 80.0, 'source' => 'hafalan', 'is_missing' => false,
    ]);
    expect(factorFromRow($row, 'uas_tahfizh'))->toMatchArray([
        'score' => 95.0, 'source' => 'manual', 'is_missing' => false,
    ]);

    // (50×20 + 85×20 + 80×20 + 95×40) / 100 = 81.00.
    expect($row['final_score'])->toEqual(81.0);
    expect($row['is_complete'])->toBeTrue();
    expect($row['missing_factor_codes'])->toBe([]);
});

test('achievement is capped at 100 when total Setoran halaman exceeds the target', function () {
    $context = setUpMemorizationFactorRecapContext();
    $student = factorCreateStudent($this, $context['user'], 'Santri Rekap Lebih Target');

    factorCreateTarget($this, $context, $student, 20);
    factorCreateLog($this, $context, $student, 'new', 30, 80);
    factorCreateLog($this, $context, $student, 'new', 20, 80);

    $row = factorRecapRowFor($this, $context, $student);

    expect(factorFromRow($row, 'target_hafalan')['score'])->toEqual(100.0);
});

test('murajaah is NULL with a reason when the santri has no Murajaah log yet, and the final score stays NULL', function () {
    $context = setUpMemorizationFactorRecapContext();
    $student = factorCreateStudent($this, $context['user'], 'Santri Rekap Tanpa Murajaah');

    factorCreateTarget($this, $context, $student, 40);
    factorCreateLog($this, $context, $student, 'new', 20, 80);
    factorSaveUasTahfizh($this, $context, $student, 90);

    $row = factorRecapRowFor($this, $context, $student);

    expect(factorFromRow($row, 'murajaah'))->toMatchArray([
        'score' => null, 'is_missing' => true, 'missing_reason' => 'Belum ada murajaah',
    ]);
    expect($row['final_score'])->toBeNull();
    expect($row['missing_factor_codes'])->toContain('murajaah');
    expect($row['is_complete'])->toBeFalse();
});

// ── Provider-level: scenarios the Tahfizh roster (ADR 0003) never produces ─
//
// listGradedStudents() only rosters santri who already have a Target
// Hafalan, so a "no target" santri can never reach the class recap through
// the HTTP roster — the provider must still handle it correctly (story 72),
// tested directly against a hand-built FactorScoreContext.

test('the provider scores NULL with "Target belum diset" for a santri who has no Target Hafalan', function () {
    $context = setUpMemorizationFactorRecapContext();
    $student = factorCreateStudent($this, $context['user'], 'Santri Tanpa Target Provider');

    $academicSemester = AcademicSemester::findByPair($context['academicYear']->id, 1);
    $factorScoreContext = new FactorScoreContext(
        academicSemester: $academicSemester,
        academicYearId: $context['academicYear']->id,
        semester: 1,
        classLevelId: $context['classLevel']->id,
        subjectBookId: $context['tahfizhBook']->id,
        students: collect([$student]),
    );

    $targetHafalanFactor = GradingFactor::where('school_id', $context['school']->id)->where('code', 'target_hafalan')->firstOrFail();

    $scores = app(MemorizationFactorScoreProvider::class)->scoresFor($targetHafalanFactor, $factorScoreContext);

    expect($scores[$student->id]->score)->toBeNull();
    expect($scores[$student->id]->missingReason)->toBe('Target belum diset');
});

test('the provider scores NULL with "Belum ada setoran" for a santri with a target but no Setoran log', function () {
    $context = setUpMemorizationFactorRecapContext();
    $student = factorCreateStudent($this, $context['user'], 'Santri Provider Tanpa Setoran');
    factorCreateTarget($this, $context, $student, 40);

    $academicSemester = AcademicSemester::findByPair($context['academicYear']->id, 1);
    $factorScoreContext = new FactorScoreContext(
        academicSemester: $academicSemester,
        academicYearId: $context['academicYear']->id,
        semester: 1,
        classLevelId: $context['classLevel']->id,
        subjectBookId: $context['tahfizhBook']->id,
        students: collect([$student->fresh()]),
    );

    $kualitasSetoranFactor = GradingFactor::where('school_id', $context['school']->id)->where('code', 'kualitas_setoran')->firstOrFail();

    $scores = app(MemorizationFactorScoreProvider::class)->scoresFor($kualitasSetoranFactor, $factorScoreContext);

    expect($scores[$student->id]->score)->toBeNull();
    expect($scores[$student->id]->missingReason)->toBe('Belum ada setoran');
});

test('an auto_from_log factor with an unrecognized code scores NULL with a generic reason instead of throwing', function () {
    $context = setUpMemorizationFactorRecapContext();
    $student = factorCreateStudent($this, $context['user'], 'Santri Provider Kode Asing');

    $academicSemester = AcademicSemester::findByPair($context['academicYear']->id, 1);
    $factorScoreContext = new FactorScoreContext(
        academicSemester: $academicSemester,
        academicYearId: $context['academicYear']->id,
        semester: 1,
        classLevelId: $context['classLevel']->id,
        subjectBookId: $context['tahfizhBook']->id,
        students: collect([$student]),
    );

    $unknownFactor = (new GradingFactor)->forceFill([
        'school_id' => $context['school']->id,
        'code' => 'unrecognized_hafalan_code',
        'input_type' => GradingFactor::INPUT_TYPE_AUTO_FROM_LOG,
    ]);

    $scores = app(MemorizationFactorScoreProvider::class)->scoresFor($unknownFactor, $factorScoreContext);

    expect($scores[$student->id]->score)->toBeNull();
    expect($scores[$student->id]->missingReason)->toBe('Faktor hafalan tidak dikenal');
});

<?php

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\GradingFactor;
use App\Models\GradingTemplate;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\SubjectBook;
use App\Models\SubjectCategory;
use App\Models\Teacher;
use App\Models\TeachingSchedule;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Akademik\AcademicSemesterService;
use App\Services\Akademik\FactorScores\FactorScore;
use App\Services\Akademik\FactorScores\FactorScoreContext;
use App\Services\Akademik\FactorScores\FactorScoreProvider;
use App\Services\Akademik\FactorScores\FactorScoreProviderRegistry;
use App\Services\Akademik\GradingDefaultsInstaller;
use App\Services\Akademik\StudentGradeService;
use Database\Seeders\ClassLevelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seeds roles, the active school, class levels and grading defaults,
 * creates an academic year with both semesters (and their weight rows),
 * and schedules one teori_kitab kitab for the "tamhidi" class in
 * semester 1.
 *
 * @return array{user: User, school: School, academicYear: AcademicYear, classLevel: ClassLevel, subjectBook: SubjectBook, teacher: Teacher, factorsByCode: Collection, templatesByCode: Collection}
 */
function setUpGradeRecapContext(): array
{
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    (new ClassLevelSeeder)->run();

    $user = User::factory()->create(['name' => 'Pengurus Rekap']);
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

    $classLevel = ClassLevel::where('school_id', $school->id)->where('slug', 'tamhidi')->firstOrFail();
    $templatesByCode = GradingTemplate::where('school_id', $school->id)->get()->keyBy('code');

    $subjectBook = recapCreateSubjectBook($school, 'Safinatun Najah', $templatesByCode[GradingTemplate::CODE_TEORI_KITAB]->id);
    $teacher = Teacher::factory()->create(['school_id' => $school->id, 'full_name' => 'Ustadz Ahmad']);
    recapScheduleSubjectBook($school, $academicYear, 1, $classLevel, $subjectBook, $teacher);

    $factorsByCode = GradingFactor::where('school_id', $school->id)->get()->keyBy('code');

    return compact('user', 'school', 'academicYear', 'classLevel', 'subjectBook', 'teacher', 'factorsByCode', 'templatesByCode');
}

function recapCreateSubjectBook(School $school, string $title, ?string $gradingTemplateId): SubjectBook
{
    return SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => SubjectCategory::factory()->create(['school_id' => $school->id])->id,
        'grading_template_id' => $gradingTemplateId,
        'title' => $title,
    ]);
}

function recapScheduleSubjectBook(School $school, AcademicYear $academicYear, int $semester, ClassLevel $classLevel, SubjectBook $subjectBook, Teacher $teacher): TeachingSchedule
{
    return TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'semester' => $semester,
        'time_slot_id' => TimeSlot::factory()->create(['school_id' => $school->id])->id,
        'class_level_id' => $classLevel->id,
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
        'is_active' => true,
    ]);
}

/**
 * Creates a student through the real POST /students endpoint.
 */
function recapCreateStudent($testCase, User $user, string $fullName, string $entryDate = '2025-07-01', string $classLevelSlug = 'tamhidi'): Student
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

function recapQuery(array $context, array $overrides = []): string
{
    return '/api/v1/grade-recaps/class?'.http_build_query(array_merge([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'subject_book_id' => $context['subjectBook']->id,
    ], $overrides));
}

/**
 * Saves percent-scale manual scores (UTS/UAS) through the real bulk endpoint.
 *
 * @param  array<string, array<string, float|int|null>>  $scoresByStudentId
 */
function recapSaveManualScores($testCase, array $context, array $scoresByStudentId): void
{
    $testCase->actingAs($context['user'])->putJson('/api/v1/student-grades/bulk', [
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'subject_book_id' => $context['subjectBook']->id,
        'rows' => collect($scoresByStudentId)
            ->map(fn (array $scores, string $studentId) => ['student_id' => $studentId, 'scores' => $scores])
            ->values()
            ->all(),
    ])->assertOk();
}

/**
 * Stores a level_1_4 factor score (Adab/Keaktifan) directly, bypassing the
 * bulk endpoint's level→score conversion (Task 7) — useful when a test only
 * cares about the recap math and wants an exact score. See the dedicated
 * "saved through the real bulk endpoint" test below for the conversion
 * itself.
 */
function recapStoreLevelScore(array $context, Student $student, string $factorCode, int $scaleLevel, float $score): void
{
    StudentGrade::create([
        'school_id' => $context['school']->id,
        'student_id' => $student->id,
        'subject_book_id' => $context['subjectBook']->id,
        'grading_factor_id' => $context['factorsByCode'][$factorCode]->id,
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'scale_level' => $scaleLevel,
        'score' => $score,
    ]);
}

/**
 * Replaces a template's weights for a semester through the real endpoint.
 *
 * @param  array<string, array{0: float|int, 1: bool}>  $weightsByCode  code => [weight, is_active]
 */
function recapReplaceWeights($testCase, array $context, string $templateCode, int $semester, array $weightsByCode): void
{
    $testCase->actingAs($context['user'])->putJson('/api/v1/grading-template-factors', [
        'academic_year_id' => $context['academicYear']->id,
        'semester' => $semester,
        'grading_template_id' => $context['templatesByCode'][$templateCode]->id,
        'factors' => collect($weightsByCode)
            ->map(fn (array $weight, string $code) => [
                'grading_factor_id' => $context['factorsByCode'][$code]->id,
                'weight' => $weight[0],
                'is_active' => $weight[1],
            ])
            ->values()
            ->all(),
    ])->assertOk();
}

/**
 * @return array<string, float|null> code => normalized_weight of a recap row or header
 */
function recapNormalizedWeights(array $factors): array
{
    return collect($factors)->mapWithKeys(fn (array $factor) => [$factor['code'] => $factor['normalized_weight']])->all();
}

function recapRowFor(array $rows, Student $student): array
{
    return collect($rows)->firstWhere('student.id', $student->id);
}

function recapFactor(array $row, string $code): array
{
    return collect($row['factors'])->firstWhere('code', $code);
}

// ── Happy path + NULL propagation ────────────────────────────────────────

test('class recap lists every student and factor with a NULL final score while factors are empty', function () {
    $context = setUpGradeRecapContext();
    $ali = recapCreateStudent($this, $context['user'], 'Ali');
    $zaid = recapCreateStudent($this, $context['user'], 'Zaid');
    $zaid->update(['status' => Student::STATUS_WITHDRAWN]);

    recapSaveManualScores($this, $context, [$ali->id => ['uts' => 80, 'uas' => 90]]);

    $response = $this->actingAs($context['user'])->getJson(recapQuery($context));

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.academic_year_id', $context['academicYear']->id)
        ->assertJsonPath('data.semester', 1)
        ->assertJsonPath('data.class_level.label', 'Tamhidi')
        ->assertJsonPath('data.subject_book.title', 'Safinatun Najah')
        ->assertJsonPath('data.grading_template.code', 'teori_kitab')
        ->assertJsonPath('data.uts_enabled', true)
        ->assertJsonPath('data.midterm_exam_date', null)
        ->assertJsonPath('data.summary.student_count', 2)
        ->assertJsonPath('data.summary.complete_count', 0);

    $header = $response->json('data.factors');
    expect(collect($header)->pluck('code')->all())->toBe(['uts', 'uas', 'tugas', 'keaktifan', 'adab', 'absensi']);
    expect(collect($header)->pluck('weight')->all())->toEqual([20, 30, 20, 10, 10, 10]);
    expect(collect($header)->pluck('source')->all())->toBe(['manual', 'manual', 'tugas', 'manual', 'manual', 'absensi']);
    expect(collect($header)->pluck('is_active')->unique()->all())->toBe([true]);
    expect(recapNormalizedWeights($header))->toEqual(['uts' => 20, 'uas' => 30, 'tugas' => 20, 'keaktifan' => 10, 'adab' => 10, 'absensi' => 10]);

    $rows = $response->json('data.rows');
    expect(collect($rows)->pluck('student.full_name')->all())->toBe(['Ali', 'Zaid']);

    $aliRow = recapRowFor($rows, $ali);
    expect($aliRow['student']['is_active_student'])->toBeTrue();
    expect($aliRow['student']['entry_date'])->toBe('2025-07-01');
    expect($aliRow['midterm_excluded'])->toBeFalse();
    expect($aliRow['final_score'])->toBeNull();
    expect($aliRow['is_complete'])->toBeFalse();
    expect($aliRow['missing_factor_codes'])->toBe(['tugas', 'keaktifan', 'adab', 'absensi']);

    expect(recapFactor($aliRow, 'uts'))->toMatchArray([
        'code' => 'uts',
        'name' => 'UTS',
        'score' => 80,
        'source' => 'manual',
        'weight' => 20,
        'normalized_weight' => 20,
        'is_active' => true,
        'is_missing' => false,
        'missing_reason' => null,
    ]);
    // No provider is registered for Tugas/Absensi yet: NULL, never 0.
    expect(recapFactor($aliRow, 'tugas'))->toMatchArray(['score' => null, 'source' => 'tugas', 'is_active' => true, 'is_missing' => true]);
    expect(recapFactor($aliRow, 'absensi'))->toMatchArray(['score' => null, 'source' => 'absensi', 'is_missing' => true]);

    $zaidRow = recapRowFor($rows, $zaid);
    expect($zaidRow['student']['is_active_student'])->toBeFalse();
    expect($zaidRow['missing_factor_codes'])->toBe(['uts', 'uas', 'tugas', 'keaktifan', 'adab', 'absensi']);
});

test('class recap computes the final score once every counted factor is filled', function () {
    $context = setUpGradeRecapContext();
    $ali = recapCreateStudent($this, $context['user'], 'Ali');
    $umar = recapCreateStudent($this, $context['user'], 'Umar');

    // Only manual factors count this semester: Tugas and Absensi are switched off.
    recapReplaceWeights($this, $context, 'teori_kitab', 1, [
        'uts' => [30, true],
        'uas' => [40, true],
        'tugas' => [20, false],
        'keaktifan' => [15, true],
        'adab' => [15, true],
        'absensi' => [10, false],
    ]);

    recapSaveManualScores($this, $context, [
        $ali->id => ['uts' => 80, 'uas' => 90],
        $umar->id => ['uts' => 0, 'uas' => 100],
    ]);
    recapStoreLevelScore($context, $ali, 'keaktifan', 3, 85);
    recapStoreLevelScore($context, $ali, 'adab', 4, 100);
    recapStoreLevelScore($context, $umar, 'keaktifan', 1, 60);
    recapStoreLevelScore($context, $umar, 'adab', 1, 60);

    $response = $this->actingAs($context['user'])->getJson(recapQuery($context))->assertOk();
    $rows = $response->json('data.rows');

    $aliRow = recapRowFor($rows, $ali);
    // 80×30% + 90×40% + 85×15% + 100×15% = 24 + 36 + 12.75 + 15
    expect($aliRow['final_score'])->toEqual(87.75);
    expect($aliRow['is_complete'])->toBeTrue();
    expect($aliRow['missing_factor_codes'])->toBe([]);

    // An inactive factor is shown, never counted and never missing.
    expect(recapFactor($aliRow, 'tugas'))->toMatchArray([
        'weight' => 20,
        'normalized_weight' => null,
        'is_active' => false,
        'is_missing' => false,
    ]);

    // An explicit 0 is a real score: 0 + 40 + 9 + 9.
    expect(recapRowFor($rows, $umar)['final_score'])->toEqual(58);

    $response->assertJsonPath('data.summary.complete_count', 2);
    expect(collect($response->json('data.factors'))->firstWhere('code', 'absensi')['is_active'])->toBeFalse();
});

test('a level saved through the real bulk endpoint (Task 7) shows up in the class recap with its converted score', function () {
    $context = setUpGradeRecapContext();
    $ali = recapCreateStudent($this, $context['user'], 'Ali');

    // Adab & Keaktifan (Task 7): the bulk endpoint now stores the level's
    // converted score, and the recap reads it from student_grades.score
    // like any other manual factor — no recap-side change was needed.
    recapSaveManualScores($this, $context, [$ali->id => ['adab' => 3, 'keaktifan' => 4]]);

    $response = $this->actingAs($context['user'])->getJson(recapQuery($context))->assertOk();
    $aliRow = recapRowFor($response->json('data.rows'), $ali);

    expect(recapFactor($aliRow, 'adab'))->toMatchArray(['score' => 85.0, 'source' => 'manual', 'is_missing' => false]);
    expect(recapFactor($aliRow, 'keaktifan'))->toMatchArray(['score' => 100.0, 'source' => 'manual', 'is_missing' => false]);
});

// ── Normalization (spec §4.2) ────────────────────────────────────────────

test('switching UTS off normalizes 20/30/20/10/10/10 to 37.5/25/12.5/12.5/12.5', function () {
    $context = setUpGradeRecapContext();
    $ali = recapCreateStudent($this, $context['user'], 'Ali');
    recapSaveManualScores($this, $context, [$ali->id => ['uts' => 80, 'uas' => 90]]);

    $this->actingAs($context['user'])
        ->putJson("/api/v1/academic-years/{$context['academicYear']->id}/semesters/1", ['uts_enabled' => false])
        ->assertOk();

    $response = $this->actingAs($context['user'])->getJson(recapQuery($context))->assertOk();

    $response->assertJsonPath('data.uts_enabled', false);
    $expectedWeights = ['uts' => null, 'uas' => 37.5, 'tugas' => 25, 'keaktifan' => 12.5, 'adab' => 12.5, 'absensi' => 12.5];
    expect(recapNormalizedWeights($response->json('data.factors')))->toEqual($expectedWeights);

    $aliRow = recapRowFor($response->json('data.rows'), $ali);
    expect($aliRow['midterm_excluded'])->toBeTrue();
    expect(recapNormalizedWeights($aliRow['factors']))->toEqual($expectedWeights);
    // The stored UTS is still shown but no longer counts or goes missing.
    expect(recapFactor($aliRow, 'uts'))->toMatchArray(['score' => 80, 'weight' => 20, 'is_active' => false, 'is_missing' => false]);
    expect($aliRow['missing_factor_codes'])->toBe(['tugas', 'keaktifan', 'adab', 'absensi']);
});

test('a student who entered after the midterm date is normalized the same way and Tahfizh is unaffected', function () {
    $context = setUpGradeRecapContext();
    $ali = recapCreateStudent($this, $context['user'], 'Ali', '2025-07-01');
    $late = recapCreateStudent($this, $context['user'], 'Hasan Pindahan', '2025-11-03');

    $this->actingAs($context['user'])
        ->putJson("/api/v1/academic-years/{$context['academicYear']->id}/semesters/1", ['midterm_exam_date' => '2025-10-01'])
        ->assertOk();

    $response = $this->actingAs($context['user'])->getJson(recapQuery($context))->assertOk();

    $response->assertJsonPath('data.uts_enabled', true)
        ->assertJsonPath('data.midterm_exam_date', '2025-10-01');
    // The header describes the semester: UTS still counts for the class.
    expect(recapFactor(['factors' => $response->json('data.factors')], 'uts')['normalized_weight'])->toEqual(20);

    $rows = $response->json('data.rows');
    $aliRow = recapRowFor($rows, $ali);
    $lateRow = recapRowFor($rows, $late);

    expect($aliRow['midterm_excluded'])->toBeFalse();
    expect(recapFactor($aliRow, 'uts')['normalized_weight'])->toEqual(20);

    expect($lateRow['midterm_excluded'])->toBeTrue();
    expect(recapNormalizedWeights($lateRow['factors']))
        ->toEqual(['uts' => null, 'uas' => 37.5, 'tugas' => 25, 'keaktifan' => 12.5, 'adab' => 12.5, 'absensi' => 12.5]);
    expect($lateRow['missing_factor_codes'])->not->toContain('uts');

    // Tahfizh has no midterm factor, so the late student keeps 20/20/20/40.
    $tahfizhBook = recapCreateSubjectBook($context['school'], "Tahfizh Al-Qur'an", $context['templatesByCode'][GradingTemplate::CODE_TAHFIZH]->id);
    recapScheduleSubjectBook($context['school'], $context['academicYear'], 1, $context['classLevel'], $tahfizhBook, $context['teacher']);

    $tahfizhResponse = $this->actingAs($context['user'])
        ->getJson(recapQuery($context, ['subject_book_id' => $tahfizhBook->id]))
        ->assertOk()
        ->assertJsonPath('data.grading_template.code', 'tahfizh');

    $lateTahfizhRow = recapRowFor($tahfizhResponse->json('data.rows'), $late);
    expect($lateTahfizhRow['midterm_excluded'])->toBeFalse();
    expect(recapNormalizedWeights($lateTahfizhRow['factors']))
        ->toEqual(['target_hafalan' => 20, 'kualitas_setoran' => 20, 'murajaah' => 20, 'uas_tahfizh' => 40]);
    expect(collect($lateTahfizhRow['factors'])->pluck('source')->all())->toBe(['hafalan', 'hafalan', 'hafalan', 'manual']);
});

test('class recap uses the weights of the requested semester only', function () {
    $context = setUpGradeRecapContext();
    recapCreateStudent($this, $context['user'], 'Ali');

    recapReplaceWeights($this, $context, 'teori_kitab', 2, [
        'uts' => [50, true],
        'uas' => [10, true],
        'tugas' => [10, true],
        'keaktifan' => [10, true],
        'adab' => [10, true],
        'absensi' => [10, true],
    ]);

    $response = $this->actingAs($context['user'])->getJson(recapQuery($context))->assertOk();

    expect(collect($response->json('data.factors'))->pluck('weight')->all())->toEqual([20, 30, 20, 10, 10, 10]);
});

// ── Factor score providers (extension point for Tugas / Absensi / Tahfizh) ─

test('a registered factor score provider feeds its factor into the recap', function () {
    $context = setUpGradeRecapContext();
    $ali = recapCreateStudent($this, $context['user'], 'Ali');
    $zaid = recapCreateStudent($this, $context['user'], 'Zaid');

    recapSaveManualScores($this, $context, [$ali->id => ['uts' => 80, 'uas' => 90], $zaid->id => ['uts' => 70, 'uas' => 70]]);
    recapStoreLevelScore($context, $ali, 'keaktifan', 3, 85);
    recapStoreLevelScore($context, $ali, 'adab', 4, 100);

    $fakeProvider = new class($ali->id) implements FactorScoreProvider
    {
        public ?FactorScoreContext $receivedContext = null;

        public function __construct(private string $aliId) {}

        public function supports(GradingFactor $factor): bool
        {
            return in_array($factor->code, ['tugas', 'absensi'], true);
        }

        public function scoresFor(GradingFactor $factor, FactorScoreContext $context): array
        {
            $this->receivedContext = $context;

            return $factor->code === 'tugas'
                ? [$this->aliId => new FactorScore(70.0)]
                : [$this->aliId => new FactorScore(75.0), 'unknown-student' => new FactorScore(10.0)];
        }
    };
    $this->app->instance(FactorScoreProviderRegistry::class, new FactorScoreProviderRegistry([$fakeProvider]));

    $response = $this->actingAs($context['user'])->getJson(recapQuery($context))->assertOk();
    $rows = $response->json('data.rows');

    $aliRow = recapRowFor($rows, $ali);
    // 16 + 27 + 14 + 8.5 + 10 + 7.5
    expect($aliRow['final_score'])->toEqual(83);
    expect(recapFactor($aliRow, 'tugas'))->toMatchArray(['score' => 70, 'source' => 'tugas', 'is_missing' => false]);

    // Students the provider leaves out stay empty.
    expect(recapFactor(recapRowFor($rows, $zaid), 'absensi'))->toMatchArray(['score' => null, 'is_missing' => true]);
    expect(collect($rows)->pluck('student.id'))->not->toContain('unknown-student');

    expect($fakeProvider->receivedContext->academicYearId)->toBe($context['academicYear']->id);
    expect($fakeProvider->receivedContext->semester)->toBe(1);
    expect($fakeProvider->receivedContext->classLevelId)->toBe($context['classLevel']->id);
    expect($fakeProvider->receivedContext->subjectBookId)->toBe($context['subjectBook']->id);
    expect($fakeProvider->receivedContext->students->pluck('id')->sort()->values()->all())
        ->toBe(collect([$ali->id, $zaid->id])->sort()->values()->all());
});

test('a provider missing reason is passed through to the recap', function () {
    $context = setUpGradeRecapContext();
    $ali = recapCreateStudent($this, $context['user'], 'Ali');

    $this->app->instance(FactorScoreProviderRegistry::class, new FactorScoreProviderRegistry([
        new class implements FactorScoreProvider
        {
            public function supports(GradingFactor $factor): bool
            {
                return $factor->code === 'absensi';
            }

            public function scoresFor(GradingFactor $factor, FactorScoreContext $context): array
            {
                return $context->students
                    ->mapWithKeys(fn (Student $student) => [$student->id => new FactorScore(null, 'Belum ada pertemuan tercatat.')])
                    ->all();
            }
        },
    ]));

    $response = $this->actingAs($context['user'])->getJson(recapQuery($context))->assertOk();

    expect(recapFactor(recapRowFor($response->json('data.rows'), $ali), 'absensi'))
        ->toMatchArray(['score' => null, 'is_missing' => true, 'missing_reason' => 'Belum ada pertemuan tercatat.']);
});

// ── Rejections ───────────────────────────────────────────────────────────

test('class recap rejects a semester without academic semester configuration', function () {
    $context = setUpGradeRecapContext();

    $unconfiguredYear = AcademicYear::factory()->create(['school_id' => $context['school']->id]);
    recapScheduleSubjectBook($context['school'], $unconfiguredYear, 1, $context['classLevel'], $context['subjectBook'], $context['teacher']);

    $this->actingAs($context['user'])
        ->getJson(recapQuery($context, ['academic_year_id' => $unconfiguredYear->id]))
        ->assertUnprocessable()
        ->assertJsonPath('message', StudentGradeService::MESSAGE_SEMESTER_NOT_CONFIGURED)
        ->assertJsonValidationErrors(['semester']);
});

test('class recap rejects a pair that is not scheduled and a kitab without a template', function () {
    $context = setUpGradeRecapContext();

    $this->actingAs($context['user'])
        ->getJson(recapQuery($context, ['semester' => 2]))
        ->assertUnprocessable()
        ->assertJsonPath('message', StudentGradeService::MESSAGE_PAIR_NOT_SCHEDULED);

    $untemplatedBook = recapCreateSubjectBook($context['school'], 'Kitab Tanpa Template', null);
    recapScheduleSubjectBook($context['school'], $context['academicYear'], 1, $context['classLevel'], $untemplatedBook, $context['teacher']);

    $this->actingAs($context['user'])
        ->getJson(recapQuery($context, ['subject_book_id' => $untemplatedBook->id]))
        ->assertUnprocessable()
        ->assertJsonPath('message', StudentGradeService::MESSAGE_BOOK_WITHOUT_TEMPLATE);
});

test('class recap requires all query params', function () {
    $context = setUpGradeRecapContext();

    $this->actingAs($context['user'])
        ->getJson('/api/v1/grade-recaps/class?semester=3')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['academic_year_id', 'semester', 'class_level_id', 'subject_book_id']);
});

// ── Permissions ──────────────────────────────────────────────────────────

test('class recap requires view-grades permission', function () {
    $context = setUpGradeRecapContext();

    $this->actingAs(User::factory()->create())
        ->getJson(recapQuery($context))
        ->assertForbidden();
});

test('pengurus_pesantren can view the class recap', function () {
    $context = setUpGradeRecapContext();
    $pengurus = User::factory()->create();
    $pengurus->assignRole('pengurus_pesantren');

    $this->actingAs($pengurus)->getJson(recapQuery($context))->assertOk();
});

// ── Tenancy ──────────────────────────────────────────────────────────────

test('class recap rejects another schools class level and kitab', function () {
    $context = setUpGradeRecapContext();

    $otherSchool = School::factory()->create();
    $otherClassLevel = ClassLevel::factory()->create(['school_id' => $otherSchool->id]);
    $otherBook = recapCreateSubjectBook($otherSchool, 'Kitab Sekolah Lain', null);

    $this->actingAs($context['user'])
        ->getJson(recapQuery($context, ['class_level_id' => $otherClassLevel->id, 'subject_book_id' => $otherBook->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['class_level_id', 'subject_book_id']);
});

test('class recap never lists another schools students or their grades', function () {
    $context = setUpGradeRecapContext();
    $ali = recapCreateStudent($this, $context['user'], 'Ali');

    $otherSchool = School::factory()->create();
    $foreignStudent = Student::factory()->create([
        'school_id' => $otherSchool->id,
        'class_level_id' => $context['classLevel']->id,
    ]);
    StudentGrade::create([
        'school_id' => $otherSchool->id,
        'student_id' => $foreignStudent->id,
        'subject_book_id' => $context['subjectBook']->id,
        'grading_factor_id' => $context['factorsByCode']['uts']->id,
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'score' => 99,
    ]);

    $response = $this->actingAs($context['user'])->getJson(recapQuery($context))->assertOk();

    expect(collect($response->json('data.rows'))->pluck('student.id')->all())->toBe([$ali->id]);
    expect(recapFactor($response->json('data.rows.0'), 'uts')['score'])->toBeNull();
});

// ── Rekap per santri (Task 9) ─────────────────────────────────────────────

function studentRecapQuery(array $context, Student $student, array $overrides = []): string
{
    return "/api/v1/grade-recaps/student/{$student->id}?".http_build_query(array_merge([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
    ], $overrides));
}

test('student recap lists only the books scheduled for the students class, with the class recaps factor breakdown', function () {
    $context = setUpGradeRecapContext();
    $ali = recapCreateStudent($this, $context['user'], 'Ali');
    recapSaveManualScores($this, $context, [$ali->id => ['uts' => 80, 'uas' => 90]]);

    // A book without a grading template, scheduled for the same class: shown but not gradable.
    $untemplatedBook = recapCreateSubjectBook($context['school'], 'Kitab Tanpa Template', null);
    recapScheduleSubjectBook($context['school'], $context['academicYear'], 1, $context['classLevel'], $untemplatedBook, $context['teacher']);

    // A book scheduled for a DIFFERENT class: must not appear for Ali.
    $otherClassLevel = ClassLevel::where('school_id', $context['school']->id)->where('slug', 'ibtida_1')->firstOrFail();
    $otherClassBook = recapCreateSubjectBook($context['school'], 'Kitab Kelas Lain', $context['templatesByCode'][GradingTemplate::CODE_TEORI_KITAB]->id);
    recapScheduleSubjectBook($context['school'], $context['academicYear'], 1, $otherClassLevel, $otherClassBook, $context['teacher']);

    $response = $this->actingAs($context['user'])->getJson(studentRecapQuery($context, $ali));

    $response->assertOk()
        ->assertJsonPath('data.student.id', $ali->id)
        ->assertJsonPath('data.student.full_name', 'Ali')
        ->assertJsonPath('data.student.class_level.label', 'Tamhidi')
        ->assertJsonPath('data.student.entry_date', '2025-07-01')
        ->assertJsonPath('data.student.is_active_student', true)
        ->assertJsonPath('data.academic_year_id', $context['academicYear']->id)
        ->assertJsonPath('data.semester', 1)
        ->assertJsonPath('data.uts_enabled', true);

    $subjects = $response->json('data.subjects');
    // GradableSubjectService::listForSemester orders pairs by subject_book title.
    expect(collect($subjects)->pluck('subject_book.title')->all())->toBe(['Kitab Tanpa Template', 'Safinatun Najah']);

    $gradableSubject = collect($subjects)->firstWhere('subject_book.title', 'Safinatun Najah');
    expect($gradableSubject['is_gradable'])->toBeTrue();
    expect($gradableSubject['grading_template']['code'])->toBe('teori_kitab');
    expect(collect($gradableSubject['factors'])->pluck('code')->all())->toBe(['uts', 'uas', 'tugas', 'keaktifan', 'adab', 'absensi']);
    expect(collect($gradableSubject['factors'])->firstWhere('code', 'uts')['score'])->toBe(80);
    expect($gradableSubject['missing_factor_codes'])->toBe(['tugas', 'keaktifan', 'adab', 'absensi']);
    expect($gradableSubject['is_complete'])->toBeFalse();
    expect($gradableSubject['midterm_excluded'])->toBeFalse();

    $untemplatedSubject = collect($subjects)->firstWhere('subject_book.title', 'Kitab Tanpa Template');
    expect($untemplatedSubject['is_gradable'])->toBeFalse();
    expect($untemplatedSubject['grading_template'])->toBeNull();
    expect($untemplatedSubject['factors'])->toBe([]);
    expect($untemplatedSubject['final_score'])->toBeNull();
    expect($untemplatedSubject['is_complete'])->toBeFalse();
});

test('student recap is_complete requires every gradable subject to be complete', function () {
    $context = setUpGradeRecapContext();
    $ali = recapCreateStudent($this, $context['user'], 'Ali');

    $incomplete = $this->actingAs($context['user'])->getJson(studentRecapQuery($context, $ali))->assertOk();
    expect($incomplete->json('data.is_complete'))->toBeFalse();

    recapReplaceWeights($this, $context, 'teori_kitab', 1, [
        'uts' => [50, true],
        'uas' => [50, true],
        'tugas' => [0, false],
        'keaktifan' => [0, false],
        'adab' => [0, false],
        'absensi' => [0, false],
    ]);
    recapSaveManualScores($this, $context, [$ali->id => ['uts' => 80, 'uas' => 90]]);

    $complete = $this->actingAs($context['user'])->getJson(studentRecapQuery($context, $ali))->assertOk();
    expect($complete->json('data.is_complete'))->toBeTrue();
    expect($complete->json('data.subjects.0.is_complete'))->toBeTrue();

    // A second, still-incomplete gradable book flips the overall flag back to false.
    $secondBook = recapCreateSubjectBook($context['school'], 'Kitab Kedua', $context['templatesByCode'][GradingTemplate::CODE_TEORI_KITAB]->id);
    recapScheduleSubjectBook($context['school'], $context['academicYear'], 1, $context['classLevel'], $secondBook, $context['teacher']);

    $mixed = $this->actingAs($context['user'])->getJson(studentRecapQuery($context, $ali))->assertOk();
    expect($mixed->json('data.is_complete'))->toBeFalse();
});

test('student recap is_complete is false and subjects is empty when the student has no gradable subjects', function () {
    $context = setUpGradeRecapContext();
    $student = recapCreateStudent($this, $context['user'], 'Kosong', '2025-07-01', 'ibtida_1');

    $response = $this->actingAs($context['user'])->getJson(studentRecapQuery($context, $student))->assertOk();

    expect($response->json('data.subjects'))->toBe([]);
    expect($response->json('data.is_complete'))->toBeFalse();
});

test('student recap rejects a student without a class', function () {
    $context = setUpGradeRecapContext();
    $student = Student::factory()->create(['school_id' => $context['school']->id]);

    $this->actingAs($context['user'])
        ->getJson(studentRecapQuery($context, $student))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Santri belum memiliki kelas.');
});

test('student recap requires academic_year_id and semester', function () {
    $context = setUpGradeRecapContext();
    $ali = recapCreateStudent($this, $context['user'], 'Ali');

    $this->actingAs($context['user'])
        ->getJson("/api/v1/grade-recaps/student/{$ali->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['academic_year_id', 'semester']);
});

test('student recap requires view-grades permission', function () {
    $context = setUpGradeRecapContext();
    $ali = recapCreateStudent($this, $context['user'], 'Ali');

    $this->actingAs(User::factory()->create())
        ->getJson(studentRecapQuery($context, $ali))
        ->assertForbidden();
});

test('student recap rejects a student from another school', function () {
    $context = setUpGradeRecapContext();

    $otherSchool = School::factory()->create();
    $foreignStudent = Student::factory()->create(['school_id' => $otherSchool->id]);

    $this->actingAs($context['user'])
        ->getJson(studentRecapQuery($context, $foreignStudent))
        ->assertNotFound();
});

test('student recap query count does not scale with the number of kitab (no N+1)', function () {
    $context = setUpGradeRecapContext();

    // One santri with only the context's single scheduled kitab.
    $zaid = recapCreateStudent($this, $context['user'], 'Zaid');

    DB::enableQueryLog();
    $this->actingAs($context['user'])->getJson(studentRecapQuery($context, $zaid))->assertOk();
    $queryCountWithOneKitab = count(DB::getQueryLog());
    DB::flushQueryLog();
    DB::disableQueryLog();

    // A second santri whose class has four more teori_kitab kitab scheduled
    // (same template as the original, so grading_template_factors batches
    // into one query regardless of how many kitab share it).
    for ($i = 1; $i <= 4; $i++) {
        $extraBook = recapCreateSubjectBook($context['school'], "Kitab Tambahan {$i}", $context['templatesByCode'][GradingTemplate::CODE_TEORI_KITAB]->id);
        recapScheduleSubjectBook($context['school'], $context['academicYear'], 1, $context['classLevel'], $extraBook, $context['teacher']);
    }
    $ali = recapCreateStudent($this, $context['user'], 'Ali');

    DB::enableQueryLog();
    $response = $this->actingAs($context['user'])->getJson(studentRecapQuery($context, $ali))->assertOk();
    $queryCountWithFiveKitab = count(DB::getQueryLog());
    DB::flushQueryLog();
    DB::disableQueryLog();

    expect($response->json('data.subjects'))->toHaveCount(5);

    // Each extra kitab still needs its own real, kitab-specific queries
    // (its student_grades manual scores, and the Tugas factor score
    // provider's own per-kitab lookup — batching those is out of scope
    // here) — currently 3 per extra kitab. What must NOT come back is the
    // fixed N+1 (a SubjectBook fetch + a redundant isGradablePair() check +
    // a GradingTemplateFactor query + an AcademicSemester re-fetch per
    // kitab, ~8-9 queries each — 4 extra kitab would add 32+). The bound
    // below leaves headroom above the current 3/kitab while staying far
    // below that regression.
    expect($queryCountWithFiveKitab - $queryCountWithOneKitab)->toBeLessThanOrEqual(24);
});

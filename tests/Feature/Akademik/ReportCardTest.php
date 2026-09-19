<?php

use App\Exceptions\FinalizedReportCardException;
use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\GradingTemplate;
use App\Models\MemorizationLog;
use App\Models\MemorizationTarget;
use App\Models\ReportCard;
use App\Models\ReportCardEntry;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\StudentGrade;
use App\Models\SubjectBook;
use App\Models\SubjectCategory;
use App\Models\Teacher;
use App\Models\TeachingSchedule;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Akademik\AcademicSemesterService;
use App\Services\Akademik\FinalizedReportCardGuard;
use App\Services\Akademik\GradingDefaultsInstaller;
use App\Services\Akademik\ReportCardService;
use Database\Seeders\ClassLevelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;
use Database\Seeders\SubjectCategorySeeder;
use Database\Seeders\TahfizhSubjectBookSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Task 16: Rapor — finalization snapshot (ADR 0001) and cancellation.
 *
 * "Today" is frozen at Wednesday 2025-09-10. The teori_kitab schedule meets
 * on Mondays (2025-09-08, 2025-09-01 are recordable past dates).
 *
 * A "complete" teori_kitab santri (default weights 20/30/20/10/10/10):
 *   UTS 80, UAS 90, Tugas 70, Keaktifan level 3 (85), Adab level 4 (100),
 *   Absensi 100 (present at the one recorded Pertemuan)
 *   → 80×.2 + 90×.3 + 70×.2 + 85×.1 + 100×.1 + 100×.1 = 85.50
 * A "complete" Tahfizh santri (20/20/20/40):
 *   target 40 halaman, Setoran 10+10 halaman (kualitas 80, 90), Murajaah
 *   (70, 90), UAS Tahfizh 95 → 50×.2 + 85×.2 + 80×.2 + 95×.4 = 81.00
 */
beforeEach(function () {
    Carbon::setTestNow('2025-09-10 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @return array{user: User, pengurus: User, school: School, academicYear: AcademicYear, classLevel: ClassLevel, subjectBook: SubjectBook, tahfizhBook: SubjectBook, teacher: Teacher, schedule: TeachingSchedule}
 */
function setUpReportCardContext(): array
{
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    (new ClassLevelSeeder)->run();

    $user = User::factory()->create(['name' => 'Admin Rapor']);
    $user->assignRole('super_admin');

    $pengurus = User::factory()->create(['name' => 'Pengurus Rapor']);
    $pengurus->assignRole('pengurus_pesantren');

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
    $teoriKitabTemplateId = GradingTemplate::where('school_id', $school->id)
        ->where('code', GradingTemplate::CODE_TEORI_KITAB)
        ->value('id');

    $subjectBook = SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => SubjectCategory::factory()->create(['school_id' => $school->id])->id,
        'grading_template_id' => $teoriKitabTemplateId,
        'title' => 'Safinatun Najah',
    ]);
    $teacher = Teacher::factory()->create([
        'school_id' => $school->id,
        'full_name' => 'Ustadz Rapor',
        'status' => Teacher::STATUS_ACTIVE,
    ]);
    $schedule = TeachingSchedule::factory()->create([
        'school_id' => $school->id,
        'academic_year_id' => $academicYear->id,
        'semester' => 1,
        'day_of_week' => 'monday',
        'time_slot_id' => TimeSlot::factory()->create(['school_id' => $school->id])->id,
        'class_level_ids' => [$classLevel->id],
        'subject_book_id' => $subjectBook->id,
        'teacher_id' => $teacher->id,
        'is_active' => true,
    ]);
    $tahfizhBook = SubjectBook::where('school_id', $school->id)->where('title', "Tahfizh Al-Qur'an")->firstOrFail();

    return compact('user', 'pengurus', 'school', 'academicYear', 'classLevel', 'subjectBook', 'tahfizhBook', 'teacher', 'schedule');
}

/**
 * Creates a student through the real POST /students endpoint.
 */
function raporCreateStudent($testCase, User $user, string $fullName, string $classLevelSlug = 'tamhidi'): Student
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
 * @param  array<string, int|float|null>  $scores  factor code => score (percent) or level (level_1_4)
 */
function raporSaveTeoriGrades($testCase, array $context, Student $student, array $scores, ?User $actingUser = null)
{
    return $testCase->actingAs($actingUser ?? $context['user'])->putJson('/api/v1/student-grades/bulk', [
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'subject_book_id' => $context['subjectBook']->id,
        'rows' => [['student_id' => $student->id, 'scores' => $scores]],
    ]);
}

function raporCreateTask($testCase, array $context, string $title = 'Tugas Bab 1'): string
{
    $response = $testCase->actingAs($context['user'])->postJson('/api/v1/class-tasks', [
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'subject_book_id' => $context['subjectBook']->id,
        'title' => $title,
        'task_date' => '2025-08-01',
        'description' => null,
    ]);

    $response->assertCreated();

    return $response->json('data.id');
}

function raporScoreTask($testCase, array $context, string $taskId, Student $student, float $score)
{
    return $testCase->actingAs($context['user'])->putJson("/api/v1/class-tasks/{$taskId}/scores/bulk", [
        'rows' => [['student_id' => $student->id, 'score' => $score]],
    ]);
}

/**
 * Records a held Pertemuan of the Monday schedule through the real endpoint.
 *
 * @param  array<string, string>  $statusByStudentId
 */
function raporRecordSession($testCase, array $context, string $sessionDate, array $statusByStudentId): string
{
    $response = $testCase->actingAs($context['user'])->postJson('/api/v1/class-sessions', [
        'teaching_schedule_id' => $context['schedule']->id,
        'session_date' => $sessionDate,
        'attendances' => collect($statusByStudentId)
            ->map(fn (string $status, string $studentId) => ['student_id' => $studentId, 'status' => $status, 'notes' => null])
            ->values()
            ->all(),
    ]);

    $response->assertCreated();

    return $response->json('data.class_session.id');
}

/**
 * Every manual teori_kitab factor plus the one Tugas score — Absensi comes
 * from the Pertemuan recorded separately.
 */
function raporCompleteTeoriScores($testCase, array $context, Student $student, string $taskId): void
{
    raporSaveTeoriGrades($testCase, $context, $student, ['uts' => 80, 'uas' => 90, 'keaktifan' => 3, 'adab' => 4])->assertOk();
    raporScoreTask($testCase, $context, $taskId, $student, 70)->assertOk();
}

function raporCompleteTahfizh($testCase, array $context, Student $student): void
{
    $testCase->actingAs($context['user'])->postJson('/api/v1/memorization-targets', [
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'student_id' => $student->id,
        'target_pages' => 40,
        'teacher_id' => $context['teacher']->id,
    ])->assertCreated();

    foreach ([['new', 10, 80], ['new', 10, 90], ['review', 5, 70], ['review', 5, 90]] as [$type, $pages, $quality]) {
        raporCreateLog($testCase, $context, $student, $type, $pages, $quality)->assertCreated();
    }

    $testCase->actingAs($context['user'])->putJson('/api/v1/student-grades/bulk', [
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'subject_book_id' => $context['tahfizhBook']->id,
        'rows' => [['student_id' => $student->id, 'scores' => ['uas_tahfizh' => 95]]],
    ])->assertOk();
}

function raporCreateLog($testCase, array $context, Student $student, string $type = 'new', float $pages = 2, int $quality = 85)
{
    return $testCase->actingAs($context['user'])->postJson('/api/v1/memorization-logs', [
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'student_id' => $student->id,
        'teacher_id' => $context['teacher']->id,
        'log_date' => '2025-09-01',
        'type' => $type,
        'pages' => $pages,
        'quality_score' => $quality,
    ]);
}

function raporFinalize($testCase, array $context, Student $student, ?User $actingUser = null)
{
    return $testCase->actingAs($actingUser ?? $context['user'])->postJson('/api/v1/report-cards/finalize', [
        'student_id' => $student->id,
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
    ]);
}

/**
 * Two teori-only santri, both complete (Pertemuan 2025-09-08, both present);
 * Ali is finalized.
 *
 * @return array{0: Student, 1: Student, 2: string, 3: string} [ali, budi, taskId, sessionId]
 */
function raporTwoCompleteSantriWithAliFinal($testCase, array $context): array
{
    $ali = raporCreateStudent($testCase, $context['user'], 'Ali');
    $budi = raporCreateStudent($testCase, $context['user'], 'Budi');
    $taskId = raporCreateTask($testCase, $context);
    $sessionId = raporRecordSession($testCase, $context, '2025-09-08', [$ali->id => 'present', $budi->id => 'present']);
    raporCompleteTeoriScores($testCase, $context, $ali, $taskId);
    raporCompleteTeoriScores($testCase, $context, $budi, $taskId);

    raporFinalize($testCase, $context, $ali)->assertOk();

    return [$ali, $budi, $taskId, $sessionId];
}

function raporStudentRecap($testCase, array $context, Student $student): array
{
    return $testCase->actingAs($context['user'])
        ->getJson("/api/v1/grade-recaps/student/{$student->id}?academic_year_id={$context['academicYear']->id}&semester=1")
        ->assertOk()
        ->json('data');
}

function raporClassRecapRows($testCase, array $context): array
{
    return $testCase->actingAs($context['user'])->getJson('/api/v1/grade-recaps/class?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'subject_book_id' => $context['subjectBook']->id,
    ]))->assertOk()->json('data.rows');
}

function raporListQuery(array $context, array $overrides = []): string
{
    return '/api/v1/report-cards?'.http_build_query(array_merge([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
    ], $overrides));
}

function raporFactor(array $factors, string $code): array
{
    return collect($factors)->firstWhere('code', $code);
}

function raporReportCardOf(Student $student): ReportCard
{
    return ReportCard::where('student_id', $student->id)->firstOrFail();
}

// ── POST /report-cards/finalize ───────────────────────────────────────────

test('finalizing a complete santri stores a snapshot of every gradable kitab, Tahfizh included', function () {
    $context = setUpReportCardContext();
    $ali = raporCreateStudent($this, $context['user'], 'Ali');
    raporCompleteTahfizh($this, $context, $ali);
    $taskId = raporCreateTask($this, $context);
    raporRecordSession($this, $context, '2025-09-08', [$ali->id => 'present']);
    raporCompleteTeoriScores($this, $context, $ali, $taskId);

    $response = raporFinalize($this, $context, $ali)->assertOk();

    $reportCard = raporReportCardOf($ali);
    expect($reportCard->status)->toBe(ReportCard::STATUS_FINAL)
        ->and($reportCard->school_id)->toBe($context['school']->id)
        ->and($reportCard->academic_year_id)->toBe($context['academicYear']->id)
        ->and($reportCard->semester)->toBe(1)
        ->and($reportCard->class_level_id)->toBe($context['classLevel']->id)
        ->and($reportCard->finalized_by)->toBe($context['user']->id)
        ->and($reportCard->finalized_at->toDateTimeString())->toBe('2025-09-10 10:00:00')
        ->and($reportCard->created_by)->toBe($context['user']->id)
        ->and($reportCard->updated_by)->toBe($context['user']->id);

    $entriesByBook = ReportCardEntry::where('report_card_id', $reportCard->id)->get()->keyBy('subject_book_id');
    expect($entriesByBook)->toHaveCount(2);

    $teoriEntry = $entriesByBook[$context['subjectBook']->id];
    expect((float) $teoriEntry->final_score)->toBe(85.5)
        ->and($teoriEntry->school_id)->toBe($context['school']->id)
        ->and($teoriEntry->breakdown['final_score'])->toBe(85.5)
        ->and($teoriEntry->breakdown['midterm_excluded'])->toBeFalse()
        ->and($teoriEntry->breakdown['grading_template']['code'])->toBe(GradingTemplate::CODE_TEORI_KITAB)
        ->and($teoriEntry->breakdown['subject_book'])->toBe(['id' => $context['subjectBook']->id, 'title' => 'Safinatun Najah'])
        ->and(collect($teoriEntry->breakdown['factors'])->pluck('code')->all())->toBe(['uts', 'uas', 'tugas', 'keaktifan', 'adab', 'absensi']);
    expect(raporFactor($teoriEntry->breakdown['factors'], 'uts'))->toMatchArray([
        'score' => 80, 'source' => 'manual', 'weight' => 20, 'normalized_weight' => 20,
        'is_active' => true, 'is_missing' => false, 'missing_reason' => null, 'is_midterm_exam' => true,
    ]);
    expect(raporFactor($teoriEntry->breakdown['factors'], 'tugas'))->toMatchArray(['score' => 70, 'source' => 'tugas']);
    expect(raporFactor($teoriEntry->breakdown['factors'], 'absensi'))->toMatchArray(['score' => 100, 'source' => 'absensi']);
    expect(raporFactor($teoriEntry->breakdown['factors'], 'keaktifan'))->toMatchArray(['score' => 85]);

    $tahfizhEntry = $entriesByBook[$context['tahfizhBook']->id];
    expect((float) $tahfizhEntry->final_score)->toBe(81.0)
        ->and($tahfizhEntry->breakdown['grading_template']['code'])->toBe(GradingTemplate::CODE_TAHFIZH);
    expect(raporFactor($tahfizhEntry->breakdown['factors'], 'target_hafalan'))->toMatchArray([
        'score' => 50, 'source' => 'hafalan', 'weight' => 20, 'normalized_weight' => 20, 'is_active' => true,
    ]);
    expect(raporFactor($tahfizhEntry->breakdown['factors'], 'uas_tahfizh'))->toMatchArray(['score' => 95, 'normalized_weight' => 40]);

    $response->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $reportCard->id)
        ->assertJsonPath('data.status', 'final')
        ->assertJsonPath('data.student.id', $ali->id)
        ->assertJsonPath('data.class_level.id', $context['classLevel']->id)
        ->assertJsonPath('data.finalized_by.name', 'Admin Rapor')
        ->assertJsonCount(2, 'data.subjects');
    $responseSubjects = collect($response->json('data.subjects'))->keyBy('subject_book.id');
    expect($responseSubjects[$context['subjectBook']->id]['final_score'])->toBe(85.5)
        ->and($responseSubjects[$context['tahfizhBook']->id]['final_score'])->toEqual(81);
});

test('finalizing an incomplete santri is rejected with the list of incomplete kitab', function () {
    $context = setUpReportCardContext();
    $ali = raporCreateStudent($this, $context['user'], 'Ali');
    raporSaveTeoriGrades($this, $context, $ali, ['uts' => 80])->assertOk();

    $response = raporFinalize($this, $context, $ali);

    $response->assertUnprocessable()
        ->assertJsonPath('message', 'Rapor belum bisa difinalkan: masih ada nilai kosong.')
        ->assertJsonPath('errors.student_id.0', 'Rapor belum bisa difinalkan: masih ada nilai kosong.')
        ->assertJsonCount(1, 'incomplete_subjects')
        ->assertJsonPath('incomplete_subjects.0.subject_book', ['id' => $context['subjectBook']->id, 'title' => 'Safinatun Najah'])
        ->assertJsonPath('incomplete_subjects.0.missing_factor_codes', ['uas', 'tugas', 'keaktifan', 'adab', 'absensi']);
    expect(ReportCard::count())->toBe(0);
});

test('a santri whose class has no gradable kitab cannot be finalized', function () {
    $context = setUpReportCardContext();
    $ali = raporCreateStudent($this, $context['user'], 'Ali', 'ibtida_1');

    raporFinalize($this, $context, $ali)
        ->assertUnprocessable()
        ->assertJsonPath('message', ReportCardService::MESSAGE_NO_GRADABLE_SUBJECTS)
        ->assertJsonPath('incomplete_subjects', []);
    expect(ReportCard::count())->toBe(0);
});

test('finalizing an already final rapor is rejected', function () {
    $context = setUpReportCardContext();
    [$ali] = raporTwoCompleteSantriWithAliFinal($this, $context);

    raporFinalize($this, $context, $ali)
        ->assertUnprocessable()
        ->assertJsonPath('errors.student_id.0', 'Rapor santri ini sudah final untuk semester tersebut.');
    expect(ReportCard::count())->toBe(1);
});

test('finalize validates the body and scopes every id to the active school', function () {
    $context = setUpReportCardContext();

    $this->actingAs($context['user'])->postJson('/api/v1/report-cards/finalize', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['student_id', 'academic_year_id', 'semester']);

    $otherSchool = School::factory()->create(['is_active' => false]);
    $foreignStudent = Student::factory()->create(['school_id' => $otherSchool->id]);
    $foreignYear = AcademicYear::factory()->create(['school_id' => $otherSchool->id, 'name' => 'Asing 2025/2026']);

    $this->actingAs($context['user'])->postJson('/api/v1/report-cards/finalize', [
        'student_id' => $foreignStudent->id,
        'academic_year_id' => $foreignYear->id,
        'semester' => 3,
    ])->assertUnprocessable()->assertJsonValidationErrors(['student_id', 'academic_year_id', 'semester']);
});

test('finalize requires manage-grades permission, pengurus_pesantren may finalize', function () {
    $context = setUpReportCardContext();
    $ali = raporCreateStudent($this, $context['user'], 'Ali');

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('view-grades');
    raporFinalize($this, $context, $ali, $viewer)->assertForbidden();

    $taskId = raporCreateTask($this, $context);
    raporRecordSession($this, $context, '2025-09-08', [$ali->id => 'present']);
    raporCompleteTeoriScores($this, $context, $ali, $taskId);

    raporFinalize($this, $context, $ali, $context['pengurus'])->assertOk()->assertJsonPath('data.finalized_by.name', 'Pengurus Rapor');
});

// ── Reading after final: snapshot, not live (ADR 0001, spec US90) ─────────

test('after finalization a grade change is rejected and both recaps read the snapshot', function () {
    $context = setUpReportCardContext();
    [$ali, $budi] = raporTwoCompleteSantriWithAliFinal($this, $context);

    // A per-santri write for the finalized santri is rejected, all-or-nothing.
    $response = $this->actingAs($context['user'])->putJson('/api/v1/student-grades/bulk', [
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'subject_book_id' => $context['subjectBook']->id,
        'rows' => [
            ['student_id' => $budi->id, 'scores' => ['uts' => 60]],
            ['student_id' => $ali->id, 'scores' => ['uts' => 10]],
        ],
    ]);
    $response->assertUnprocessable()
        ->assertJsonPath('message', 'Rapor santri ini sudah final untuk semester tersebut.')
        ->assertJsonPath("errors.{$ali->id}.0", 'Rapor santri ini sudah final untuk semester tersebut.')
        ->assertJsonMissingPath("errors.{$budi->id}");
    expect(StudentGrade::where('student_id', $ali->id)->whereHas('gradingFactor', fn ($q) => $q->where('code', 'uts'))->value('score'))->toEqual(80)
        ->and(StudentGrade::where('student_id', $budi->id)->whereHas('gradingFactor', fn ($q) => $q->where('code', 'uts'))->value('score'))->toEqual(80);

    // A class-level change stays allowed (a new Pertemuan, Ali absent) — the
    // live value moves, the finalized rapor does not.
    raporRecordSession($this, $context, '2025-09-01', [$ali->id => 'absent', $budi->id => 'present']);

    $recap = raporStudentRecap($this, $context, $ali);
    expect($recap['is_finalized'])->toBeTrue()
        ->and($recap['report_card_id'])->toBe(raporReportCardOf($ali)->id)
        ->and($recap['finalized_at'])->toBe(raporReportCardOf($ali)->finalized_at->toJSON())
        ->and($recap['finalized_by_name'])->toBe('Admin Rapor')
        ->and($recap['is_complete'])->toBeTrue()
        ->and($recap['subjects'])->toHaveCount(1)
        ->and($recap['subjects'][0]['final_score'])->toBe(85.5)
        ->and(raporFactor($recap['subjects'][0]['factors'], 'absensi')['score'])->toEqual(100);

    // The per-santri recap equals the stored snapshot exactly.
    $show = $this->actingAs($context['user'])->getJson('/api/v1/report-cards/'.raporReportCardOf($ali)->id)->assertOk()->json('data');
    expect($recap['subjects'])->toBe($show['subjects']);

    // The class recap substitutes the snapshot row for Ali only.
    $rows = collect(raporClassRecapRows($this, $context))->keyBy('student.id');
    expect($rows[$ali->id]['is_finalized'])->toBeTrue()
        ->and($rows[$ali->id]['is_snapshot'])->toBeTrue()
        ->and($rows[$ali->id]['final_score'])->toBe(85.5)
        ->and($rows[$ali->id]['factors'])->toBe($show['subjects'][0]['factors'])
        ->and($rows[$ali->id]['student']['full_name'])->toBe('Ali')
        ->and($rows[$budi->id]['is_finalized'])->toBeFalse()
        ->and($rows[$budi->id]['is_snapshot'])->toBeFalse()
        ->and($rows[$budi->id]['final_score'])->toBe(85.5);

    // Budi's live recap is unaffected and not flagged.
    $budiRecap = raporStudentRecap($this, $context, $budi);
    expect($budiRecap['is_finalized'])->toBeFalse()
        ->and($budiRecap['report_card_id'])->toBeNull()
        ->and($budiRecap['finalized_at'])->toBeNull();
});

test('the class recap shows the live row flagged when the finalized snapshot has no entry for that kitab', function () {
    $context = setUpReportCardContext();
    [$ali] = raporTwoCompleteSantriWithAliFinal($this, $context);

    // A second kitab scheduled for the class after Ali was finalized.
    $lateBook = SubjectBook::factory()->create([
        'school_id' => $context['school']->id,
        'subject_category_id' => SubjectCategory::factory()->create(['school_id' => $context['school']->id])->id,
        'grading_template_id' => GradingTemplate::where('school_id', $context['school']->id)->where('code', GradingTemplate::CODE_TEORI_KITAB)->value('id'),
        'title' => 'Kitab Susulan',
    ]);
    TeachingSchedule::factory()->create([
        'school_id' => $context['school']->id,
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'day_of_week' => 'tuesday',
        'time_slot_id' => TimeSlot::factory()->create(['school_id' => $context['school']->id])->id,
        'class_level_ids' => [$context['classLevel']->id],
        'subject_book_id' => $lateBook->id,
        'teacher_id' => $context['teacher']->id,
        'is_active' => true,
    ]);

    $rows = $this->actingAs($context['user'])->getJson('/api/v1/grade-recaps/class?'.http_build_query([
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'subject_book_id' => $lateBook->id,
    ]))->assertOk()->json('data.rows');

    $aliRow = collect($rows)->firstWhere('student.id', $ali->id);
    expect($aliRow['is_finalized'])->toBeTrue()
        ->and($aliRow['is_snapshot'])->toBeFalse()
        ->and($aliRow['final_score'])->toBeNull();

    // The per-santri recap still shows only what was finalized.
    expect(collect(raporStudentRecap($this, $context, $ali)['subjects'])->pluck('subject_book.title')->all())->toBe(['Safinatun Najah']);
});

// ── POST /report-cards/{reportCard}/unfinalize ────────────────────────────

test('only super_admin can unfinalize, with a reason; the recap then goes live again and re-finalizing overwrites the snapshot', function () {
    $context = setUpReportCardContext();
    [$ali, $budi] = raporTwoCompleteSantriWithAliFinal($this, $context);
    raporRecordSession($this, $context, '2025-09-01', [$ali->id => 'absent', $budi->id => 'present']);
    $reportCard = raporReportCardOf($ali);

    $this->actingAs($context['pengurus'])
        ->postJson("/api/v1/report-cards/{$reportCard->id}/unfinalize", ['reason' => 'Salah input'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Hanya super_admin yang dapat membatalkan finalisasi rapor.');

    $this->actingAs($context['user'])
        ->postJson("/api/v1/report-cards/{$reportCard->id}/unfinalize", ['reason' => '   '])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);
    $this->actingAs($context['user'])
        ->postJson("/api/v1/report-cards/{$reportCard->id}/unfinalize", ['reason' => str_repeat('a', 1001)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);
    expect($reportCard->fresh()->status)->toBe(ReportCard::STATUS_FINAL);

    Carbon::setTestNow('2025-09-10 11:30:00');
    $this->actingAs($context['user'])
        ->postJson("/api/v1/report-cards/{$reportCard->id}/unfinalize", ['reason' => 'Absensi 1 September terlambat dicatat.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.unfinalize_reason', 'Absensi 1 September terlambat dicatat.')
        ->assertJsonPath('data.unfinalized_by.name', 'Admin Rapor')
        ->assertJsonPath('data.subjects', []);

    $reportCard->refresh();
    expect($reportCard->status)->toBe(ReportCard::STATUS_DRAFT)
        ->and($reportCard->unfinalized_by)->toBe($context['user']->id)
        ->and($reportCard->unfinalized_at->toDateTimeString())->toBe('2025-09-10 11:30:00')
        ->and($reportCard->entries()->count())->toBe(1);

    // Live again: the late absent Pertemuan now counts (absensi 50 → 80.50).
    $recap = raporStudentRecap($this, $context, $ali);
    expect($recap['is_finalized'])->toBeFalse()
        ->and($recap['report_card_id'])->toBe($reportCard->id)
        ->and($recap['subjects'][0]['final_score'])->toBe(80.5);
    expect(collect(raporClassRecapRows($this, $context))->firstWhere('student.id', $ali->id)['is_finalized'])->toBeFalse();

    // Writes are allowed again; re-finalizing overwrites the entries.
    raporSaveTeoriGrades($this, $context, $ali, ['uts' => 100])->assertOk();
    raporFinalize($this, $context, $ali)->assertOk()->assertJsonPath('data.subjects.0.final_score', 84.5);

    $reportCard->refresh();
    expect($reportCard->status)->toBe(ReportCard::STATUS_FINAL)
        ->and($reportCard->unfinalize_reason)->toBe('Absensi 1 September terlambat dicatat.')
        ->and(ReportCardEntry::where('report_card_id', $reportCard->id)->count())->toBe(1)
        ->and((float) ReportCardEntry::where('report_card_id', $reportCard->id)->value('final_score'))->toBe(84.5);
});

test('unfinalizing a draft rapor is rejected', function () {
    $context = setUpReportCardContext();
    [$ali] = raporTwoCompleteSantriWithAliFinal($this, $context);
    $reportCard = raporReportCardOf($ali);

    $this->actingAs($context['user'])->postJson("/api/v1/report-cards/{$reportCard->id}/unfinalize", ['reason' => 'Pertama'])->assertOk();

    $this->actingAs($context['user'])
        ->postJson("/api/v1/report-cards/{$reportCard->id}/unfinalize", ['reason' => 'Kedua'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.report_card.0', ReportCardService::MESSAGE_NOT_FINAL);
});

test('unfinalize requires manage-grades permission and 404s for another schools rapor before validating', function () {
    $context = setUpReportCardContext();
    [$ali] = raporTwoCompleteSantriWithAliFinal($this, $context);
    $reportCard = raporReportCardOf($ali);

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('view-grades');
    $this->actingAs($viewer)->postJson("/api/v1/report-cards/{$reportCard->id}/unfinalize", ['reason' => 'x'])->assertForbidden();

    $otherSchool = School::factory()->create(['is_active' => false]);
    $reportCard->school_id = $otherSchool->id;
    $reportCard->save();

    $this->actingAs($context['user'])->postJson("/api/v1/report-cards/{$reportCard->id}/unfinalize", [])->assertNotFound();
});

// ── GET /report-cards (class list) ────────────────────────────────────────

test('the class list shows every santri with status, completeness and counts', function () {
    $context = setUpReportCardContext();
    $ali = raporCreateStudent($this, $context['user'], 'Ali');
    $budi = raporCreateStudent($this, $context['user'], 'Budi');
    $citra = raporCreateStudent($this, $context['user'], 'Citra');
    $dewi = raporCreateStudent($this, $context['user'], 'Dewi');
    $dewi->update(['status' => Student::STATUS_WITHDRAWN]);
    raporCreateStudent($this, $context['user'], 'Santri Kelas Lain', 'ibtida_1');

    $taskId = raporCreateTask($this, $context);
    raporRecordSession($this, $context, '2025-09-08', [$ali->id => 'present', $budi->id => 'present', $citra->id => 'present']);
    raporCompleteTeoriScores($this, $context, $ali, $taskId);
    raporCompleteTeoriScores($this, $context, $budi, $taskId);
    raporSaveTeoriGrades($this, $context, $citra, ['uts' => 70])->assertOk();

    raporFinalize($this, $context, $ali)->assertOk();
    raporFinalize($this, $context, $budi)->assertOk();
    $this->actingAs($context['user'])
        ->postJson('/api/v1/report-cards/'.raporReportCardOf($budi)->id.'/unfinalize', ['reason' => 'Koreksi'])
        ->assertOk();

    $response = $this->actingAs($context['user'])->getJson(raporListQuery($context))->assertOk();

    $response->assertJsonPath('data.class_level.id', $context['classLevel']->id)
        ->assertJsonPath('data.summary', ['student_count' => 4, 'final_count' => 1, 'complete_count' => 2]);
    $rows = collect($response->json('data.rows'));
    expect($rows->pluck('student.full_name')->all())->toBe(['Ali', 'Budi', 'Citra', 'Dewi']);

    expect($rows[0])->toMatchArray([
        'report_card_id' => raporReportCardOf($ali)->id,
        'status' => 'final',
        'is_complete' => true,
        'gradable_subject_count' => 1,
        'final_subject_count' => 1,
        'incomplete_subject_count' => 0,
        'finalized_at' => raporReportCardOf($ali)->finalized_at->toJSON(),
        'finalized_by_name' => 'Admin Rapor',
    ]);
    expect($rows[1])->toMatchArray([
        'report_card_id' => raporReportCardOf($budi)->id,
        'status' => 'draft',
        'is_complete' => true,
        'final_subject_count' => 1,
        'incomplete_subject_count' => 0,
        'finalized_at' => null,
    ]);
    expect($rows[2])->toMatchArray([
        'report_card_id' => null,
        'status' => 'none',
        'is_complete' => false,
        'gradable_subject_count' => 1,
        'final_subject_count' => 0,
        'incomplete_subject_count' => 1,
    ]);
    expect($rows[3]['student']['is_active_student'])->toBeFalse()
        ->and($rows[3]['status'])->toBe('none');
});

test('the class list query count does not scale with the number of santri', function () {
    $context = setUpReportCardContext();
    $first = raporCreateStudent($this, $context['user'], 'Santri 1');

    DB::enableQueryLog();
    $this->actingAs($context['user'])->getJson(raporListQuery($context))->assertOk();
    $queriesForOneSantri = count(DB::getQueryLog());
    DB::flushQueryLog();

    foreach (range(2, 6) as $number) {
        raporCreateStudent($this, $context['user'], "Santri {$number}");
    }

    DB::flushQueryLog();
    $this->actingAs($context['user'])->getJson(raporListQuery($context))->assertOk()->assertJsonCount(6, 'data.rows');
    $queriesForSixSantri = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queriesForSixSantri - $queriesForOneSantri)->toBeLessThanOrEqual(2);
});

test('the class list validates its query and scopes it to the active school', function () {
    $context = setUpReportCardContext();

    $this->actingAs($context['user'])->getJson('/api/v1/report-cards')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['academic_year_id', 'semester', 'class_level_id']);

    $otherSchool = School::factory()->create(['is_active' => false]);
    $foreignClass = ClassLevel::factory()->create(['school_id' => $otherSchool->id]);

    $this->actingAs($context['user'])->getJson(raporListQuery($context, ['class_level_id' => $foreignClass->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['class_level_id']);
});

test('the class list and detail require view-grades permission; pengurus_pesantren may read', function () {
    $context = setUpReportCardContext();
    [$ali] = raporTwoCompleteSantriWithAliFinal($this, $context);
    $reportCard = raporReportCardOf($ali);

    $stranger = User::factory()->create();
    $this->actingAs($stranger)->getJson(raporListQuery($context))->assertForbidden();
    $this->actingAs($stranger)->getJson("/api/v1/report-cards/{$reportCard->id}")->assertForbidden();

    $this->actingAs($context['pengurus'])->getJson(raporListQuery($context))->assertOk();
    $this->actingAs($context['pengurus'])->getJson("/api/v1/report-cards/{$reportCard->id}")->assertOk();
});

// ── GET /report-cards/{reportCard} ────────────────────────────────────────

test('the detail renders the snapshot per kitab, and no subjects while draft', function () {
    $context = setUpReportCardContext();
    [$ali] = raporTwoCompleteSantriWithAliFinal($this, $context);
    $reportCard = raporReportCardOf($ali);

    $response = $this->actingAs($context['user'])->getJson("/api/v1/report-cards/{$reportCard->id}")->assertOk();

    $response->assertJsonPath('data.status', 'final')
        ->assertJsonPath('data.is_finalized', true)
        ->assertJsonPath('data.student.full_name', 'Ali')
        ->assertJsonPath('data.academic_year.id', $context['academicYear']->id)
        ->assertJsonPath('data.academic_year.name', '2025/2026')
        ->assertJsonPath('data.semester', 1)
        ->assertJsonPath('data.class_level.label', 'Tamhidi')
        ->assertJsonPath('data.uts_enabled', true)
        ->assertJsonPath('data.subjects.0.subject_book.title', 'Safinatun Najah')
        ->assertJsonPath('data.subjects.0.grading_template.code', 'teori_kitab')
        ->assertJsonPath('data.subjects.0.is_gradable', true)
        ->assertJsonPath('data.subjects.0.is_complete', true)
        ->assertJsonPath('data.subjects.0.missing_factor_codes', [])
        ->assertJsonPath('data.subjects.0.final_score', 85.5)
        ->assertJsonCount(6, 'data.subjects.0.factors');

    $this->actingAs($context['user'])->postJson("/api/v1/report-cards/{$reportCard->id}/unfinalize", ['reason' => 'Koreksi'])->assertOk();

    $this->actingAs($context['user'])->getJson("/api/v1/report-cards/{$reportCard->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.is_finalized', false)
        ->assertJsonPath('data.subjects', []);
});

test('the detail 404s for another schools rapor', function () {
    $context = setUpReportCardContext();
    [$ali] = raporTwoCompleteSantriWithAliFinal($this, $context);
    $reportCard = raporReportCardOf($ali);

    $otherSchool = School::factory()->create(['is_active' => false]);
    $reportCard->school_id = $otherSchool->id;
    $reportCard->save();

    $this->actingAs($context['user'])->getJson("/api/v1/report-cards/{$reportCard->id}")->assertNotFound();
});

// ── Write guard on per-santri writes (spec US91) ──────────────────────────

test('task scores of a finalized santri are rejected while other santri can still be scored', function () {
    $context = setUpReportCardContext();
    [$ali, $budi, $taskId] = raporTwoCompleteSantriWithAliFinal($this, $context);

    raporScoreTask($this, $context, $taskId, $ali, 10)
        ->assertUnprocessable()
        ->assertJsonPath("errors.{$ali->id}.0", 'Rapor santri ini sudah final untuk semester tersebut.');
    raporScoreTask($this, $context, $taskId, $budi, 60)->assertOk();
});

test('attendance edits that change an existing row of a finalized santri are rejected', function () {
    $context = setUpReportCardContext();
    [$ali, $budi, , $sessionId] = raporTwoCompleteSantriWithAliFinal($this, $context);

    $this->actingAs($context['user'])->putJson("/api/v1/class-sessions/{$sessionId}/attendances", [
        'attendances' => [['student_id' => $ali->id, 'status' => 'absent', 'notes' => null]],
    ])->assertUnprocessable()->assertJsonPath("errors.{$ali->id}.0", 'Rapor santri ini sudah final untuk semester tersebut.');

    $this->actingAs($context['user'])->putJson("/api/v1/class-sessions/{$sessionId}/attendances", [
        'attendances' => [['student_id' => $ali->id, 'status' => 'present', 'notes' => 'Catatan baru']],
    ])->assertUnprocessable();

    // An unchanged row of the finalized santri alongside a real change of another santri is fine.
    $this->actingAs($context['user'])->putJson("/api/v1/class-sessions/{$sessionId}/attendances", [
        'attendances' => [
            ['student_id' => $ali->id, 'status' => 'present', 'notes' => null],
            ['student_id' => $budi->id, 'status' => 'sick', 'notes' => null],
        ],
    ])->assertOk();

    expect(StudentAttendance::where('class_session_id', $sessionId)->where('student_id', $ali->id)->value('status'))->toBe('present')
        ->and(StudentAttendance::where('class_session_id', $sessionId)->where('student_id', $budi->id)->value('status'))->toBe('sick');
});

test('memorization log create, update and delete are rejected for a finalized santri', function () {
    $context = setUpReportCardContext();
    $ali = raporCreateStudent($this, $context['user'], 'Ali');
    raporCompleteTahfizh($this, $context, $ali);
    $taskId = raporCreateTask($this, $context);
    raporRecordSession($this, $context, '2025-09-08', [$ali->id => 'present']);
    raporCompleteTeoriScores($this, $context, $ali, $taskId);
    raporFinalize($this, $context, $ali)->assertOk();

    raporCreateLog($this, $context, $ali)
        ->assertUnprocessable()
        ->assertJsonPath('errors.student_id.0', 'Rapor santri ini sudah final untuk semester tersebut.');

    $log = MemorizationLog::where('student_id', $ali->id)->firstOrFail();
    $this->actingAs($context['user'])->putJson("/api/v1/memorization-logs/{$log->id}", ['quality_score' => 40])
        ->assertUnprocessable()
        ->assertJsonPath('errors.student_id.0', 'Rapor santri ini sudah final untuk semester tersebut.');
    $this->actingAs($context['user'])->deleteJson("/api/v1/memorization-logs/{$log->id}")->assertUnprocessable();

    expect(MemorizationLog::where('student_id', $ali->id)->count())->toBe(4)
        ->and($log->fresh()->quality_score)->toBe(80);
});

test('memorization target create, update and delete are rejected for a finalized santri', function () {
    $context = setUpReportCardContext();
    $ali = raporCreateStudent($this, $context['user'], 'Ali');
    $budi = raporCreateStudent($this, $context['user'], 'Budi');
    raporCompleteTahfizh($this, $context, $ali);
    $taskId = raporCreateTask($this, $context);
    raporRecordSession($this, $context, '2025-09-08', [$ali->id => 'present', $budi->id => 'present']);
    raporCompleteTeoriScores($this, $context, $ali, $taskId);
    raporCompleteTeoriScores($this, $context, $budi, $taskId);
    raporFinalize($this, $context, $ali)->assertOk();
    raporFinalize($this, $context, $budi)->assertOk();

    $target = MemorizationTarget::where('student_id', $ali->id)->firstOrFail();
    $this->actingAs($context['user'])->putJson("/api/v1/memorization-targets/{$target->id}", ['target_pages' => 10])
        ->assertUnprocessable()
        ->assertJsonPath('errors.student_id.0', 'Rapor santri ini sudah final untuk semester tersebut.');
    $this->actingAs($context['user'])->deleteJson("/api/v1/memorization-targets/{$target->id}")->assertUnprocessable();
    expect((float) $target->fresh()->target_pages)->toBe(40.0);

    // Budi's finalized rapor has no Tahfizh; adding a target now would change it.
    $this->actingAs($context['user'])->postJson('/api/v1/memorization-targets', [
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'student_id' => $budi->id,
        'target_pages' => 20,
        'teacher_id' => $context['teacher']->id,
    ])->assertUnprocessable()->assertJsonPath('errors.student_id.0', 'Rapor santri ini sudah final untuk semester tersebut.');
});

test('class-level actions stay allowed after a santri is finalized', function () {
    $context = setUpReportCardContext();
    [$ali, $budi, $taskId] = raporTwoCompleteSantriWithAliFinal($this, $context);

    // A new Pertemuan with every expected santri, then cancelling it.
    raporRecordSession($this, $context, '2025-09-01', [$ali->id => 'present', $budi->id => 'present']);
    $this->actingAs($context['user'])->postJson('/api/v1/class-sessions/cancel', [
        'teaching_schedule_id' => $context['schedule']->id,
        'session_date' => '2025-09-01',
        'reason' => 'Libur',
    ])->assertOk();

    // Tugas create / update / delete.
    $newTaskId = raporCreateTask($this, $context, 'Tugas Bab 2');
    $this->actingAs($context['user'])->putJson("/api/v1/class-tasks/{$taskId}", ['title' => 'Tugas Bab 1 (revisi)'])->assertOk();
    $this->actingAs($context['user'])->deleteJson("/api/v1/class-tasks/{$newTaskId}")->assertOk();

    expect(raporReportCardOf($ali)->status)->toBe(ReportCard::STATUS_FINAL);
});

test('the guard is scoped to the santri, academic year and semester, with one query for many santri', function () {
    $context = setUpReportCardContext();
    [$ali, $budi] = raporTwoCompleteSantriWithAliFinal($this, $context);
    $guard = app(FinalizedReportCardGuard::class);
    $academicYearId = $context['academicYear']->id;

    $guard->assertEditable($budi->id, $academicYearId, 1);
    $guard->assertEditable($ali->id, $academicYearId, 2);
    expect(fn () => $guard->assertEditable($ali->id, $academicYearId, 1))->toThrow(FinalizedReportCardException::class);

    DB::enableQueryLog();
    expect($guard->finalizedStudentIds([$ali->id, $budi->id], $academicYearId, 1))->toBe([$ali->id]);
    $reportCardQueries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'report_cards'));
    expect($reportCardQueries)->toHaveCount(1);
    DB::disableQueryLog();

    // A draft rapor does not lock anything.
    $this->actingAs($context['user'])->postJson('/api/v1/report-cards/'.raporReportCardOf($ali)->id.'/unfinalize', ['reason' => 'Koreksi'])->assertOk();
    $guard->assertEditable($ali->id, $academicYearId, 1);
});

test('the unique rapor per santri and semester is enforced by the database', function () {
    $context = setUpReportCardContext();
    [$ali] = raporTwoCompleteSantriWithAliFinal($this, $context);

    expect(fn () => ReportCard::create([
        'school_id' => $context['school']->id,
        'student_id' => $ali->id,
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'status' => ReportCard::STATUS_DRAFT,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('a new report card defaults to draft', function () {
    $context = setUpReportCardContext();
    $ali = raporCreateStudent($this, $context['user'], 'Ali');

    $reportCard = ReportCard::create([
        'school_id' => $context['school']->id,
        'student_id' => $ali->id,
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
    ]);

    expect($reportCard->fresh()->status)->toBe(ReportCard::STATUS_DRAFT);
});

test('a finalized santri keeps reading the snapshot after leaving the class', function () {
    $context = setUpReportCardContext();
    [$ali] = raporTwoCompleteSantriWithAliFinal($this, $context);

    Student::whereKey($ali->id)->update(['class_level_id' => null]);

    $recap = raporStudentRecap($this, $context, $ali->fresh());
    expect($recap['is_finalized'])->toBeTrue()
        ->and($recap['subjects'][0]['final_score'])->toBe(85.5);
});

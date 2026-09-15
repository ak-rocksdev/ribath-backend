<?php

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\GradingTemplate;
use App\Models\ReportCard;
use App\Models\ReportCardEntry;
use App\Models\School;
use App\Models\Student;
use App\Models\SubjectBook;
use App\Models\SubjectCategory;
use App\Models\User;
use App\Services\Akademik\ReportCardPdfPresenter;
use Database\Seeders\ClassLevelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;

/*
 * Task 17: Ekspor Rapor PDF. The PDF is rendered from the frozen snapshot
 * (Task 16, ADR 0001) only, so these tests build the ReportCard/Entry rows
 * directly instead of replaying the whole grading pipeline that ReportCardTest
 * already covers.
 */

/**
 * @return array{user: User, school: School, academicYear: AcademicYear, classLevel: ClassLevel, subjectBook: SubjectBook}
 */
function setUpReportCardPdfContext(): array
{
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    (new ClassLevelSeeder)->run();

    $user = User::factory()->create(['name' => 'Admin Rapor Pdf']);
    $user->assignRole('super_admin');

    $school = School::where('is_active', true)->firstOrFail();
    $classLevel = ClassLevel::where('school_id', $school->id)->where('slug', 'tamhidi')->firstOrFail();

    $academicYear = AcademicYear::factory()->create([
        'school_id' => $school->id,
        'name' => '2025/2026',
        'is_active' => true,
        'active_semester' => 1,
    ]);

    $template = GradingTemplate::create([
        'school_id' => $school->id,
        'code' => GradingTemplate::CODE_TEORI_KITAB,
        'name' => 'Teori/Kitab',
        'is_active' => true,
    ]);

    $subjectBook = SubjectBook::factory()->create([
        'school_id' => $school->id,
        'subject_category_id' => SubjectCategory::factory()->create(['school_id' => $school->id])->id,
        'grading_template_id' => $template->id,
        'title' => 'Safinatun Najah',
    ]);

    return compact('user', 'school', 'academicYear', 'classLevel', 'subjectBook');
}

/** Creates a student through the real POST /students endpoint (global constraint 10). */
function reportCardPdfCreateStudent($testCase, User $user, string $fullName, string $classLevelSlug = 'tamhidi'): Student
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
 * Directly crafts a final ReportCard + one ReportCardEntry, matching the
 * snapshot shape written by ReportCardService::finalize() /
 * ReportCardSnapshot::breakdownFromSubjectRow().
 */
function makeFinalReportCard(array $context, Student $student, ?float $missingFactorScore = 60.0): ReportCard
{
    $reportCard = ReportCard::create([
        'school_id' => $context['school']->id,
        'student_id' => $student->id,
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'status' => ReportCard::STATUS_FINAL,
        'finalized_at' => '2025-09-10 09:15:00',
        'finalized_by' => $context['user']->id,
        'created_by' => $context['user']->id,
        'updated_by' => $context['user']->id,
    ]);

    ReportCardEntry::create([
        'school_id' => $context['school']->id,
        'report_card_id' => $reportCard->id,
        'subject_book_id' => $context['subjectBook']->id,
        'final_score' => 85.5,
        'breakdown' => [
            'subject_book' => ['id' => $context['subjectBook']->id, 'title' => $context['subjectBook']->title],
            'grading_template' => ['id' => 'x', 'code' => 'teori_kitab', 'name' => 'Teori/Kitab'],
            'is_gradable' => true,
            'factors' => [
                ['code' => 'uts', 'name' => 'UTS', 'score' => 80.0, 'source' => 'manual', 'is_midterm_exam' => true, 'weight' => 20.0, 'normalized_weight' => 20.0, 'is_active' => true, 'is_missing' => false, 'missing_reason' => null],
                ['code' => 'uas', 'name' => 'UAS', 'score' => 90.0, 'source' => 'manual', 'is_midterm_exam' => false, 'weight' => 30.0, 'normalized_weight' => 30.0, 'is_active' => true, 'is_missing' => false, 'missing_reason' => null],
                ['code' => 'absensi', 'name' => 'Absensi', 'score' => $missingFactorScore, 'source' => 'absensi', 'is_midterm_exam' => false, 'weight' => 10.0, 'normalized_weight' => $missingFactorScore === null ? null : 10.0, 'is_active' => $missingFactorScore !== null, 'is_missing' => $missingFactorScore === null, 'missing_reason' => $missingFactorScore === null ? 'Belum ada pertemuan' : null],
            ],
            'final_score' => 85.5,
            'missing_factor_codes' => [],
            'is_complete' => true,
            'midterm_excluded' => false,
        ],
        'created_by' => $context['user']->id,
        'updated_by' => $context['user']->id,
    ]);

    return $reportCard->fresh();
}

test('unauthenticated request returns 401', function () {
    $context = setUpReportCardPdfContext();
    $student = reportCardPdfCreateStudent($this, $context['user'], 'Ahmad Fadli');
    $reportCard = makeFinalReportCard($context, $student);

    // reportCardPdfCreateStudent() authenticated via the real /students
    // endpoint (global constraint 10) — drop that session before asserting
    // a truly unauthenticated request.
    $this->app['auth']->forgetGuards();

    $response = $this->getJson("/api/v1/report-cards/{$reportCard->id}/pdf");

    $response->assertUnauthorized();
});

test('user without view-grades permission returns 403', function () {
    $context = setUpReportCardPdfContext();
    $student = reportCardPdfCreateStudent($this, $context['user'], 'Ahmad Fadli');
    $reportCard = makeFinalReportCard($context, $student);
    $noPermissionUser = User::factory()->create();

    $response = $this->actingAs($noPermissionUser)->get("/api/v1/report-cards/{$reportCard->id}/pdf");

    $response->assertForbidden();
});

test('non-existent report card returns 404', function () {
    setUpReportCardPdfContext();
    $user = User::factory()->create();
    $user->givePermissionTo('view-grades');
    $missingUuid = '00000000-0000-0000-0000-000000000000';

    $response = $this->actingAs($user)->get("/api/v1/report-cards/{$missingUuid}/pdf");

    $response->assertNotFound();
});

test('a report card belonging to another school returns 404', function () {
    $context = setUpReportCardPdfContext();
    $student = reportCardPdfCreateStudent($this, $context['user'], 'Ahmad Fadli');
    $reportCard = makeFinalReportCard($context, $student);

    $otherSchool = School::factory()->create(['is_active' => false]);
    $reportCard->update(['school_id' => $otherSchool->id]);

    $response = $this->actingAs($context['user'])->get("/api/v1/report-cards/{$reportCard->id}/pdf");

    $response->assertNotFound();
});

test('a draft report card returns 422 Rapor belum final', function () {
    $context = setUpReportCardPdfContext();
    $student = reportCardPdfCreateStudent($this, $context['user'], 'Ahmad Fadli');
    $reportCard = makeFinalReportCard($context, $student);
    $reportCard->update(['status' => ReportCard::STATUS_DRAFT]);

    $response = $this->actingAs($context['user'])->getJson("/api/v1/report-cards/{$reportCard->id}/pdf");

    $response->assertUnprocessable();
    $response->assertJsonPath('errors.report_card.0', ReportCardPdfPresenter::MESSAGE_NOT_FINAL);
});

test('a final report card returns 200 with an attached PDF and the canonical filename', function () {
    $context = setUpReportCardPdfContext();
    $student = reportCardPdfCreateStudent($this, $context['user'], 'Ahmad Fadli');
    $reportCard = makeFinalReportCard($context, $student);

    $response = $this->actingAs($context['user'])->get("/api/v1/report-cards/{$reportCard->id}/pdf");

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('attachment');
    expect($response->headers->get('Content-Disposition'))->toContain('rapor-ahmad-fadli-2025-2026-semester-1.pdf');
});

test('a report card with a null factor score (should not happen post-finalize, but defensively) still exports 200', function () {
    $context = setUpReportCardPdfContext();
    $student = reportCardPdfCreateStudent($this, $context['user'], 'Budi Santoso');
    $reportCard = makeFinalReportCard($context, $student, missingFactorScore: null);

    $response = $this->actingAs($context['user'])->get("/api/v1/report-cards/{$reportCard->id}/pdf");

    // The exact "—" rendering for a null factor is asserted at the
    // presenter level (ReportCardPdfPresenterTest), which is testable
    // without Chromium.
    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});

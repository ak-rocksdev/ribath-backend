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
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/*
 * Task 17: shapes the data for pdf.report-card. These tests never touch
 * Browsershot/Chromium — they assert the presenter's returned array
 * directly, which is what the Blade renders verbatim.
 */

function presenterSetUpContext(): array
{
    (new RolePermissionSeeder)->run();
    (new SchoolSeeder)->run();
    (new ClassLevelSeeder)->run();

    $user = User::factory()->create(['name' => 'Kepala Sekolah']);
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

function presenterCreateStudent($testCase, User $user, string $fullName): Student
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

function presenterMakeReportCard(array $context, Student $student, string $status, ?float $secondFactorScore = 90.0): ReportCard
{
    $reportCard = ReportCard::create([
        'school_id' => $context['school']->id,
        'student_id' => $student->id,
        'academic_year_id' => $context['academicYear']->id,
        'semester' => 1,
        'class_level_id' => $context['classLevel']->id,
        'status' => $status,
        'finalized_at' => $status === ReportCard::STATUS_FINAL ? Carbon::parse('2025-09-10 09:15:00', 'UTC') : null,
        'finalized_by' => $status === ReportCard::STATUS_FINAL ? $context['user']->id : null,
        'created_by' => $context['user']->id,
        'updated_by' => $context['user']->id,
    ]);

    if ($status === ReportCard::STATUS_FINAL) {
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
                    ['code' => 'uas', 'name' => 'UAS', 'score' => $secondFactorScore, 'source' => 'manual', 'is_midterm_exam' => false, 'weight' => 30.0, 'normalized_weight' => $secondFactorScore === null ? null : 30.0, 'is_active' => $secondFactorScore !== null, 'is_missing' => $secondFactorScore === null, 'missing_reason' => $secondFactorScore === null ? 'Belum dinilai' : null],
                ],
                'final_score' => 85.5,
                'missing_factor_codes' => [],
                'is_complete' => true,
                'midterm_excluded' => false,
                'uts_enabled' => true,
            ],
            'created_by' => $context['user']->id,
            'updated_by' => $context['user']->id,
        ]);
    }

    return $reportCard->fresh();
}

test('present() formats scores and weights with an Indonesian comma and 2 decimals', function () {
    $context = presenterSetUpContext();
    $student = presenterCreateStudent($this, $context['user'], 'Ahmad Fadli');
    $reportCard = presenterMakeReportCard($context, $student, ReportCard::STATUS_FINAL);

    $viewModel = app(ReportCardPdfPresenter::class)->present($reportCard);

    expect($viewModel['title'])->toBe('LAPORAN HASIL BELAJAR');
    expect($viewModel['student_name'])->toBe('Ahmad Fadli');
    expect($viewModel['class_label'])->not->toBeNull();
    expect($viewModel['academic_year_name'])->toBe('2025/2026');
    expect($viewModel['semester'])->toBe(1);

    $subject = $viewModel['subjects'][0];
    expect($subject['title'])->toBe('Safinatun Najah');
    expect($subject['template_name'])->toBe('Teori/Kitab');
    expect($subject['final_score_label'])->toBe('85,50');

    $utsFactor = collect($subject['factors'])->firstWhere('name', 'UTS');
    expect($utsFactor['score_label'])->toBe('80,00');
    expect($utsFactor['normalized_weight_label'])->toBe('20,00%');

    expect($viewModel['summary'][0])->toBe(['title' => 'Safinatun Najah', 'final_score_label' => '85,50']);
});

test('present() renders a null factor score and weight as an em dash, never 0', function () {
    $context = presenterSetUpContext();
    $student = presenterCreateStudent($this, $context['user'], 'Budi Santoso');
    $reportCard = presenterMakeReportCard($context, $student, ReportCard::STATUS_FINAL, secondFactorScore: null);

    $viewModel = app(ReportCardPdfPresenter::class)->present($reportCard);

    $uasFactor = collect($viewModel['subjects'][0]['factors'])->firstWhere('name', 'UAS');
    expect($uasFactor['score_label'])->toBe('—');
    expect($uasFactor['normalized_weight_label'])->toBe('—');
});

test('present() formats finalized_at in WIB with the Indonesian locale', function () {
    $context = presenterSetUpContext();
    $student = presenterCreateStudent($this, $context['user'], 'Citra Dewi');
    $reportCard = presenterMakeReportCard($context, $student, ReportCard::STATUS_FINAL);

    $viewModel = app(ReportCardPdfPresenter::class)->present($reportCard);

    $expectedLabel = Carbon::parse('2025-09-10 09:15:00', 'UTC')
        ->timezone('Asia/Jakarta')
        ->locale('id')
        ->isoFormat('D MMMM Y [pukul] HH.mm').' WIB';

    expect($viewModel['finalized_label'])->toBe($expectedLabel);
    expect($viewModel['finalized_label'])->toContain('September');
    expect($viewModel['finalized_label'])->toContain('WIB');
    expect($viewModel['finalized_by_name'])->toBe('Kepala Sekolah');
});

test('present() throws a 422 ValidationException keyed report_card for a draft rapor', function () {
    $context = presenterSetUpContext();
    $student = presenterCreateStudent($this, $context['user'], 'Dewi Lestari');
    $reportCard = presenterMakeReportCard($context, $student, ReportCard::STATUS_DRAFT);

    try {
        app(ReportCardPdfPresenter::class)->present($reportCard);
        expect(false)->toBeTrue('Expected a ValidationException to be thrown.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['report_card' => [ReportCardPdfPresenter::MESSAGE_NOT_FINAL]]);
        expect($exception->status)->toBe(422);
    }
});

test('filename() slugifies the student name and academic year, and includes the semester', function () {
    $context = presenterSetUpContext();
    $student = presenterCreateStudent($this, $context['user'], 'Ahmad Fadli');
    $reportCard = presenterMakeReportCard($context, $student, ReportCard::STATUS_FINAL);

    expect(app(ReportCardPdfPresenter::class)->filename($reportCard))
        ->toBe('rapor-ahmad-fadli-2025-2026-semester-1.pdf');
});

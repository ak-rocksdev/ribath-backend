<?php

namespace App\Services\Akademik;

use App\Models\ReportCard;
use App\Models\School;
use App\Services\SchoolLogoResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shapes a final Rapor (ReportCardService::show()) into a PDF-ready view
 * model for `pdf.report-card`: everything the Blade needs is already a
 * formatted string (Indonesian comma, 2 decimals; "—" for null), so the
 * view stays pure markup and this class is testable without Chromium
 * (Task 17). It never recomputes a score — only `show()`'s frozen snapshot
 * is read.
 */
class ReportCardPdfPresenter
{
    public const MESSAGE_NOT_FINAL = 'Rapor belum final.';

    public function __construct(
        private ReportCardService $reportCardService,
        private SchoolLogoResolver $schoolLogoResolver,
    ) {}

    /**
     * @throws ValidationException MESSAGE_NOT_FINAL (422) when the rapor is a draft
     */
    private function assertFinal(ReportCard $reportCard): void
    {
        if (! $reportCard->isFinal()) {
            throw ValidationException::withMessages(['report_card' => self::MESSAGE_NOT_FINAL]);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException MESSAGE_NOT_FINAL (422) when the rapor is a draft
     */
    public function present(ReportCard $reportCard): array
    {
        $this->assertFinal($reportCard);

        $shown = $this->reportCardService->show($reportCard);
        $school = School::activeOrFail();

        $subjects = collect($shown['subjects'])
            ->map(fn (array $subject) => $this->presentSubject($subject))
            ->values()
            ->all();

        return [
            'title' => 'LAPORAN HASIL BELAJAR',
            'school' => [
                'name' => $school->name,
                'address' => $school->address,
            ],
            'logo_data_uri' => $this->schoolLogoResolver->dataUri($school),
            'student_name' => $shown['student']['full_name'] ?? '-',
            'class_label' => $shown['class_level']['label'] ?? '-',
            'academic_year_name' => $shown['academic_year']['name'] ?? '-',
            'semester' => $shown['semester'],
            'subjects' => $subjects,
            'summary' => collect($subjects)
                ->map(fn (array $subject) => [
                    'title' => $subject['title'],
                    'final_score_label' => $subject['final_score_label'],
                ])
                ->values()
                ->all(),
            'finalized_label' => $this->formatFinalizedAt($reportCard->finalized_at),
            'finalized_by_name' => $shown['finalized_by']['name'] ?? '-',
            'generated_at' => Carbon::now('Asia/Jakarta'),
        ];
    }

    /**
     * Canonical filename: rapor-<slug nama santri>-<slug tahun ajaran>-semester-<n>.pdf
     */
    public function filename(ReportCard $reportCard): string
    {
        $reportCard->loadMissing(['student:id,full_name', 'academicYear:id,name']);

        $studentSlug = Str::slug($reportCard->student?->full_name ?? 'santri');
        // Str::slug() drops "/" outright (e.g. "2025/2026" → "20252026") instead of
        // treating it as a separator, so normalise it to "-" first (same fix as
        // TeachingScheduleService::buildTeacherExportFilename — keep both in sync).
        $academicYearSlug = Str::slug(str_replace('/', '-', $reportCard->academicYear?->name ?? 'ta'));

        return "rapor-{$studentSlug}-{$academicYearSlug}-semester-{$reportCard->semester}.pdf";
    }

    /**
     * @param  array<string, mixed>  $subject  one of show()'s `subjects` rows
     * @return array<string, mixed>
     */
    private function presentSubject(array $subject): array
    {
        return [
            'title' => $subject['subject_book']['title'] ?? '-',
            'template_name' => $subject['grading_template']['name'] ?? null,
            'factors' => collect($subject['factors'])
                ->map(fn (array $factor) => [
                    'name' => $factor['name'],
                    'score_label' => $this->formatNumber($factor['score']),
                    'normalized_weight_label' => $this->formatWeight($factor['normalized_weight']),
                ])
                ->values()
                ->all(),
            'final_score_label' => $this->formatNumber($subject['final_score']),
        ];
    }

    /** 82.5 → "82,50"; null → "—" (never rendered as 0). */
    private function formatNumber(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 2, ',', '.');
    }

    /** 20.0 → "20,00%"; null (an inactive factor) → "—". */
    private function formatWeight(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 2, ',', '.').'%';
    }

    private function formatFinalizedAt(?Carbon $finalizedAt): string
    {
        if ($finalizedAt === null) {
            return '-';
        }

        return $finalizedAt->copy()->timezone('Asia/Jakarta')->locale('id')->isoFormat('D MMMM Y [pukul] HH.mm').' WIB';
    }
}

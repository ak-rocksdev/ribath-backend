<?php

namespace App\Http\Controllers\Api\Akademik;

use App\Http\Controllers\Controller;
use App\Models\ReportCard;
use App\Services\Akademik\ReportCardPdfPresenter;
use App\Traits\EnsuresActiveSchoolTenancy;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

class ReportCardPdfController extends Controller
{
    use EnsuresActiveSchoolTenancy;

    public function __construct(
        private ReportCardPdfPresenter $reportCardPdfPresenter,
    ) {}

    /**
     * GET /report-cards/{reportCard}/pdf — renders the frozen snapshot of a
     * final Rapor (Task 16, ADR 0001) as a downloadable PDF. 422 for a draft
     * rapor (ReportCardPdfPresenter::MESSAGE_NOT_FINAL); nothing is ever
     * recomputed.
     */
    public function show(ReportCard $reportCard): PdfBuilder
    {
        $this->ensureBelongsToActiveSchool($reportCard);

        $viewModel = $this->reportCardPdfPresenter->present($reportCard);

        // See TeachingScheduleExportController for why this env propagation
        // to the puppeteer child process is necessary under PHP-FPM.
        if ($cacheDir = config('services.browsershot.puppeteer_cache_dir')) {
            putenv("PUPPETEER_CACHE_DIR={$cacheDir}");
            $_ENV['PUPPETEER_CACHE_DIR'] = $cacheDir;
            $_SERVER['PUPPETEER_CACHE_DIR'] = $cacheDir;
        }

        return Pdf::view('pdf.report-card', $viewModel)
            ->format(Format::A4)
            ->portrait()
            ->withBrowsershot(fn ($browsershot) => $browsershot->noSandbox())
            ->download($this->reportCardPdfPresenter->filename($reportCard));
    }
}

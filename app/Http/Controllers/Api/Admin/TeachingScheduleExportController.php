<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExportTeacherSchedulePdfRequest;
use App\Models\Teacher;
use App\Services\TeachingScheduleService;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

class TeachingScheduleExportController extends Controller
{
    public function __construct(
        private TeachingScheduleService $teachingScheduleService,
    ) {}

    public function teacher(
        ExportTeacherSchedulePdfRequest $request,
        Teacher $teacher,
    ): PdfBuilder {
        $orientation = $request->orientation();

        $viewModel = $this->teachingScheduleService->buildTeacherExportViewModel(
            teacher: $teacher,
            academicYearId: $request->validated('academic_year_id'),
            semester: (int) $request->validated('semester'),
        );

        // Tell puppeteer's resolver where to find its bundled Chromium cache. Has to be
        // set on the PHP process env (not on Browsershot's launch-options env, which only
        // affects the child Chrome process) because puppeteer reads it from process.env
        // when *resolving the binary path* before launch.
        //
        // PHP-FPM commonly has variables_order="GPCS" (no E), so $_ENV is empty and Symfony
        // Process's getDefaultEnv() ends up not propagating putenv() values to the child
        // Node subprocess reliably. Setting all three guarantees the env reaches puppeteer.
        if ($cacheDir = config('services.browsershot.puppeteer_cache_dir')) {
            putenv("PUPPETEER_CACHE_DIR={$cacheDir}");
            $_ENV['PUPPETEER_CACHE_DIR'] = $cacheDir;
            $_SERVER['PUPPETEER_CACHE_DIR'] = $cacheDir;
        }

        $pdf = Pdf::view('pdf.teaching-schedule-teacher', [
                ...$viewModel,
                'orientation' => $orientation,
            ])
            ->format(Format::A4)
            // --no-sandbox required on Ubuntu 23.10+ where AppArmor disables unprivileged
            // user namespaces. Safe because we only render trusted Blade templates.
            ->withBrowsershot(fn ($browsershot) => $browsershot->noSandbox());

        if ($orientation === 'landscape') {
            $pdf->landscape();
        }

        return $pdf->inline(
            $this->teachingScheduleService->buildTeacherExportFilename($viewModel),
        );
    }
}

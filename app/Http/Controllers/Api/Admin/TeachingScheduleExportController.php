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

        $pdf = Pdf::view('pdf.teaching-schedule-teacher', [
                ...$viewModel,
                'orientation' => $orientation,
            ])
            ->format(Format::A4)
            ->withBrowsershot(function ($browsershot) {
                // --no-sandbox required on Ubuntu 23.10+ where AppArmor disables unprivileged
                // user namespaces. Safe because we only render trusted Blade templates.
                $browsershot->noSandbox();

                // Pass PUPPETEER_CACHE_DIR through to the Node child process. PHP-FPM
                // workers don't share ak_rocks's HOME, so puppeteer needs an explicit
                // shared cache path or it tries to download Chromium per request.
                if ($cacheDir = env('PUPPETEER_CACHE_DIR')) {
                    $browsershot->setEnvironmentOptions(['PUPPETEER_CACHE_DIR' => $cacheDir]);
                }
            });

        if ($orientation === 'landscape') {
            $pdf->landscape();
        }

        return $pdf->inline(
            $this->teachingScheduleService->buildTeacherExportFilename($viewModel),
        );
    }
}

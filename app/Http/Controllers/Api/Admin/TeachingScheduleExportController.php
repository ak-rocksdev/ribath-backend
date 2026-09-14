<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExportTeacherSchedulePdfRequest;
use App\Models\Teacher;
use App\Services\TeachingScheduleService;
use App\Support\BrowsershotEnvironment;
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

        BrowsershotEnvironment::preparePuppeteerCache();

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

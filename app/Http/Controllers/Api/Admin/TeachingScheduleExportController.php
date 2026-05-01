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
            // Required on Ubuntu 23.10+ where AppArmor disables unprivileged user namespaces.
            // Safe here because we only render our own trusted Blade templates with vetted data.
            ->withBrowsershot(fn ($browsershot) => $browsershot->noSandbox());

        if ($orientation === 'landscape') {
            $pdf->landscape();
        }

        return $pdf->inline(
            $this->teachingScheduleService->buildTeacherExportFilename($viewModel),
        );
    }
}

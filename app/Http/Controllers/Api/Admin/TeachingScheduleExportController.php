<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExportTeacherSchedulePdfRequest;
use App\Models\Teacher;
use App\Services\TeachingScheduleService;
use Illuminate\Http\Response;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Facades\Pdf;

class TeachingScheduleExportController extends Controller
{
    public function __construct(
        private TeachingScheduleService $teachingScheduleService,
    ) {}

    public function teacher(
        ExportTeacherSchedulePdfRequest $request,
        Teacher $teacher,
    ): Response {
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
            ->name($this->buildFilename($viewModel));

        if ($orientation === 'landscape') {
            $pdf->landscape();
        }

        return $pdf->inline();
    }

    private function buildFilename(array $viewModel): string
    {
        $teacherCode = $viewModel['teacher']['code'];
        $semester = $viewModel['semester'];
        $year = str_replace('/', '-', $viewModel['academic_year']['name'] ?? 'TA');

        return "Jadwal-{$teacherCode}-Sem{$semester}-{$year}.pdf";
    }
}

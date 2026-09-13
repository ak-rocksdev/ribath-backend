<?php

namespace App\Http\Controllers\Api\Akademik;

use App\Http\Controllers\Controller;
use App\Http\Requests\Akademik\FinalizeReportCardRequest;
use App\Http\Requests\Akademik\ListReportCardsRequest;
use App\Http\Requests\Akademik\UnfinalizeReportCardRequest;
use App\Models\ReportCard;
use App\Models\Student;
use App\Services\Akademik\ReportCardService;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Http\JsonResponse;

class ReportCardController extends Controller
{
    use EnsuresActiveSchoolTenancy;

    public function __construct(
        private ReportCardService $reportCardService,
    ) {}

    public function index(ListReportCardsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $list = $this->reportCardService->listForClass(
            $data['academic_year_id'],
            (int) $data['semester'],
            $data['class_level_id'],
        );

        return $this->successResponse($list, 'Daftar rapor berhasil diambil');
    }

    public function show(ReportCard $reportCard): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($reportCard);

        return $this->successResponse($this->reportCardService->show($reportCard), 'Rapor berhasil diambil');
    }

    public function finalize(FinalizeReportCardRequest $request): JsonResponse
    {
        $data = $request->validated();

        $reportCard = $this->reportCardService->finalize(
            Student::findOrFail($data['student_id']),
            $data['academic_year_id'],
            (int) $data['semester'],
        );

        return $this->successResponse($reportCard, 'Rapor berhasil difinalkan');
    }

    public function unfinalize(UnfinalizeReportCardRequest $request, ReportCard $reportCard): JsonResponse
    {
        $result = $this->reportCardService->unfinalize($reportCard, $request->validated()['reason']);

        return $this->successResponse($result, 'Finalisasi rapor berhasil dibatalkan');
    }
}

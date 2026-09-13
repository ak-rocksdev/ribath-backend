<?php

namespace App\Http\Controllers\Api\Akademik;

use App\Http\Controllers\Controller;
use App\Http\Requests\Akademik\ShowClassGradeRecapRequest;
use App\Services\Akademik\GradeRecapService;
use Illuminate\Http\JsonResponse;

class GradeRecapController extends Controller
{
    public function __construct(
        private GradeRecapService $gradeRecapService,
    ) {}

    public function classSubject(ShowClassGradeRecapRequest $request): JsonResponse
    {
        $data = $request->validated();

        $recap = $this->gradeRecapService->recapForClassSubject(
            $data['academic_year_id'],
            (int) $data['semester'],
            $data['class_level_id'],
            $data['subject_book_id'],
        );

        return $this->successResponse($recap, 'Rekap nilai berhasil diambil');
    }
}

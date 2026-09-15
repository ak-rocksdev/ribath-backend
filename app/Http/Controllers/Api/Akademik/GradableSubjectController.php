<?php

namespace App\Http\Controllers\Api\Akademik;

use App\Http\Controllers\Controller;
use App\Http\Requests\Akademik\ListGradableSubjectsRequest;
use App\Services\Akademik\GradableSubjectService;
use Illuminate\Http\JsonResponse;

class GradableSubjectController extends Controller
{
    public function __construct(
        private GradableSubjectService $gradableSubjectService,
    ) {}

    public function index(ListGradableSubjectsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $gradableSubjects = $this->gradableSubjectService->listForCurrentUser(
            $data['academic_year_id'],
            (int) $data['semester'],
            $data['class_level_id'] ?? null,
        );

        return $this->successResponse($gradableSubjects, 'Daftar kelas dan kitab yang dapat dinilai berhasil diambil');
    }
}

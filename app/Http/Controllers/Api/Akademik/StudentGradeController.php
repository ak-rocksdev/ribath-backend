<?php

namespace App\Http\Controllers\Api\Akademik;

use App\Http\Controllers\Controller;
use App\Http\Requests\Akademik\BulkUpsertStudentGradesRequest;
use App\Http\Requests\Akademik\ShowStudentGradeGridRequest;
use App\Services\Akademik\StudentGradeService;
use Illuminate\Http\JsonResponse;

class StudentGradeController extends Controller
{
    public function __construct(
        private StudentGradeService $studentGradeService,
    ) {}

    public function index(ShowStudentGradeGridRequest $request): JsonResponse
    {
        $data = $request->validated();

        $grid = $this->studentGradeService->getGrid(
            $data['academic_year_id'],
            (int) $data['semester'],
            $data['class_level_id'],
            $data['subject_book_id'],
        );

        return $this->successResponse($grid, 'Grid nilai berhasil diambil');
    }

    public function bulkUpsert(BulkUpsertStudentGradesRequest $request): JsonResponse
    {
        $data = $request->validated();

        $savedGrades = $this->studentGradeService->upsertGrid(
            $data['academic_year_id'],
            (int) $data['semester'],
            $data['class_level_id'],
            $data['subject_book_id'],
            $data['rows'],
        );

        return $this->successResponse($savedGrades, 'Nilai berhasil disimpan');
    }
}

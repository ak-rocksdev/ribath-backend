<?php

namespace App\Http\Controllers\Api\Akademik;

use App\Http\Controllers\Controller;
use App\Http\Requests\Akademik\ShowClassGradeRecapRequest;
use App\Http\Requests\Akademik\ShowStudentGradeRecapRequest;
use App\Models\Student;
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

    public function student(ShowStudentGradeRecapRequest $request, Student $student): JsonResponse
    {
        $data = $request->validated();

        $recap = $this->gradeRecapService->recapForStudent(
            $student,
            $data['academic_year_id'],
            (int) $data['semester'],
        );

        return $this->successResponse($recap, 'Rekap nilai santri berhasil diambil');
    }
}

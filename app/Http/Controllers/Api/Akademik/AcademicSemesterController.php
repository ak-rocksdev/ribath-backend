<?php

namespace App\Http\Controllers\Api\Akademik;

use App\Http\Controllers\Controller;
use App\Http\Requests\Akademik\UpdateAcademicSemesterRequest;
use App\Models\AcademicYear;
use App\Services\Akademik\AcademicSemesterService;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Http\JsonResponse;

class AcademicSemesterController extends Controller
{
    use EnsuresActiveSchoolTenancy;

    public function __construct(
        private AcademicSemesterService $academicSemesterService,
    ) {}

    public function index(AcademicYear $academicYear): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($academicYear);

        $semesters = $this->academicSemesterService->listForAcademicYear($academicYear);

        return $this->successResponse($semesters, 'Semester akademik berhasil diambil');
    }

    public function update(UpdateAcademicSemesterRequest $request, AcademicYear $academicYear, string $semester): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($academicYear);

        $updatedSemester = $this->academicSemesterService->updateSemester(
            $academicYear,
            (int) $semester,
            $request->validated()
        );

        return $this->successResponse($updatedSemester, 'Semester akademik berhasil diperbarui');
    }
}

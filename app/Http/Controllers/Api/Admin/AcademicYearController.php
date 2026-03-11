<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\HasDependentsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAcademicYearRequest;
use App\Http\Requests\Admin\UpdateAcademicYearRequest;
use App\Models\AcademicYear;
use App\Services\AcademicYearService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicYearController extends Controller
{
    public function __construct(
        private AcademicYearService $academicYearService
    ) {}

    public function index(): JsonResponse
    {
        $academicYears = $this->academicYearService->listAll();

        return $this->successResponse($academicYears, 'Academic years retrieved');
    }

    public function store(StoreAcademicYearRequest $request): JsonResponse
    {
        $academicYear = $this->academicYearService->createAcademicYear($request->validated());

        return $this->successResponse($academicYear, 'Academic year created', 201);
    }

    public function show(AcademicYear $academicYear): JsonResponse
    {
        return $this->successResponse($academicYear, 'Academic year retrieved');
    }

    public function update(UpdateAcademicYearRequest $request, AcademicYear $academicYear): JsonResponse
    {
        $updatedAcademicYear = $this->academicYearService->updateAcademicYear($academicYear, $request->validated());

        return $this->successResponse($updatedAcademicYear, 'Academic year updated');
    }

    public function destroy(AcademicYear $academicYear): JsonResponse
    {
        try {
            $this->academicYearService->deleteAcademicYear($academicYear);
        } catch (HasDependentsException $e) {
            return $this->errorResponse($e->getMessage(), code: 422);
        }

        return $this->successResponse(null, 'Academic year deleted');
    }

    public function activate(AcademicYear $academicYear): JsonResponse
    {
        $activatedAcademicYear = $this->academicYearService->activate($academicYear);

        return $this->successResponse($activatedAcademicYear, 'Academic year activated');
    }

    public function active(): JsonResponse
    {
        $activeYear = $this->academicYearService->getActive();

        if (! $activeYear) {
            return $this->errorResponse('No active academic year found', code: 404);
        }

        return $this->successResponse($activeYear, 'Active academic year retrieved');
    }

    public function switchSemester(Request $request, AcademicYear $academicYear): JsonResponse
    {
        $request->validate([
            'semester' => ['required', 'integer', 'in:1,2'],
        ]);

        $updatedAcademicYear = $this->academicYearService->switchSemester(
            $academicYear,
            $request->integer('semester')
        );

        return $this->successResponse($updatedAcademicYear, 'Semester switched');
    }
}

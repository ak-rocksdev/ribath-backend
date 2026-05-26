<?php

namespace App\Http\Controllers\Api\Keuangan;

use App\Http\Controllers\Controller;
use App\Http\Requests\Keuangan\ManualSnapshotAssignmentRequest;
use App\Http\Requests\Keuangan\SetManualAssignmentRequest;
use App\Http\Resources\Keuangan\StudentFeeAssignmentResource;
use App\Models\AcademicYear;
use App\Models\Student;
use App\Services\Keuangan\StudentFeeAssignmentService;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Http\JsonResponse;

class StudentFeeAssignmentController extends Controller
{
    use EnsuresActiveSchoolTenancy;

    public function __construct(
        private StudentFeeAssignmentService $assignmentService,
    ) {}

    public function index(Student $student): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($student);

        $assignments = $this->assignmentService->listForStudent($student);

        return $this->successResponse(
            StudentFeeAssignmentResource::collection($assignments),
            'Student fee assignments retrieved'
        );
    }

    public function snapshot(ManualSnapshotAssignmentRequest $request, Student $student): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($student);

        $sourceAy = AcademicYear::findOrFail($request->validated()['academic_year_id']);

        $result = $this->assignmentService->manualSnapshotByAcademicYear($student, $sourceAy);

        return $this->successResponse([
            'created_count' => $result['created_count'],
            'skipped_count' => $result['skipped_count'],
            'assignments' => StudentFeeAssignmentResource::collection(
                $this->assignmentService->listForStudent($student)
            ),
        ], 'Snapshot completed', 201);
    }

    public function manual(SetManualAssignmentRequest $request, Student $student): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($student);

        $validated = $request->validated();
        $sourceAy = AcademicYear::findOrFail($validated['source_academic_year_id']);

        $result = $this->assignmentService->setManualEntries($student, $sourceAy, $validated['items']);

        return $this->successResponse([
            'created_count' => $result['created_count'],
            'skipped_count' => $result['skipped_count'],
            'assignments' => StudentFeeAssignmentResource::collection(
                $this->assignmentService->listForStudent($student)
            ),
        ], 'Manual assignments created', 201);
    }
}

<?php

namespace App\Http\Controllers\Api\Keuangan;

use App\Http\Controllers\Controller;
use App\Http\Requests\Keuangan\StoreFeeExceptionRequest;
use App\Http\Requests\Keuangan\UpdateFeeExceptionRequest;
use App\Http\Resources\Keuangan\StudentFeeExceptionResource;
use App\Models\Student;
use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeException;
use App\Services\Keuangan\StudentFeeExceptionService;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Http\JsonResponse;

class StudentFeeExceptionController extends Controller
{
    use EnsuresActiveSchoolTenancy;

    public function __construct(
        private StudentFeeExceptionService $exceptionService,
    ) {}

    public function store(StoreFeeExceptionRequest $request, Student $student, StudentFeeAssignment $assignment): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($student);
        $this->ensureAssignmentBelongsToStudent($assignment, $student);

        $exception = $this->exceptionService->create($assignment, $request->validated(), $request->user()->id);

        return $this->successResponse(new StudentFeeExceptionResource($exception), 'Pengecualian ditambahkan', 201);
    }

    public function update(
        UpdateFeeExceptionRequest $request,
        Student $student,
        StudentFeeAssignment $assignment,
        StudentFeeException $exception,
    ): JsonResponse {
        $this->ensureBelongsToActiveSchool($student);
        $this->ensureAssignmentBelongsToStudent($assignment, $student);
        $this->ensureExceptionBelongsToAssignment($exception, $assignment);

        // Attach the route-bound assignment so the service's cap re-check +
        // the observer don't lazy-load it (symmetry with the create path).
        $exception->setRelation('assignment', $assignment);
        $updated = $this->exceptionService->update($exception, $request->validated());

        return $this->successResponse(new StudentFeeExceptionResource($updated), 'Pengecualian diperbarui');
    }

    public function destroy(
        Student $student,
        StudentFeeAssignment $assignment,
        StudentFeeException $exception,
    ): JsonResponse {
        $this->ensureBelongsToActiveSchool($student);
        $this->ensureAssignmentBelongsToStudent($assignment, $student);
        $this->ensureExceptionBelongsToAssignment($exception, $assignment);

        $exception->setRelation('assignment', $assignment);
        $this->exceptionService->delete($exception);

        return $this->successResponse(null, 'Pengecualian dihapus');
    }

    private function ensureAssignmentBelongsToStudent(StudentFeeAssignment $assignment, Student $student): void
    {
        abort_unless($assignment->student_id === $student->id, 404);
    }

    private function ensureExceptionBelongsToAssignment(StudentFeeException $exception, StudentFeeAssignment $assignment): void
    {
        abort_unless($exception->student_fee_assignment_id === $assignment->id, 404);
    }
}

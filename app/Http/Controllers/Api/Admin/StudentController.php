<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStudentRequest;
use App\Http\Requests\Admin\UpdateStudentRequest;
use App\Http\Requests\Admin\UpdateStudentStatusRequest;
use App\Models\Student;
use App\Services\StudentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function __construct(
        private StudentService $studentService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $students = $this->studentService->listStudents($request->all());

        return $this->paginatedResponse($students, 'Students retrieved');
    }

    public function store(StoreStudentRequest $request): JsonResponse
    {
        $student = $this->studentService->createStudent($request->validated());

        return $this->successResponse($student, 'Student created', 201);
    }

    public function show(Student $student): JsonResponse
    {
        $student->load(['guardian', 'registration']);

        return $this->successResponse($student, 'Student retrieved');
    }

    public function update(UpdateStudentRequest $request, Student $student): JsonResponse
    {
        $updatedStudent = $this->studentService->updateStudent($student, $request->validated());

        return $this->successResponse($updatedStudent, 'Student updated');
    }

    public function destroy(Student $student): JsonResponse
    {
        $student->delete();

        return $this->successResponse(null, 'Student deleted');
    }

    public function updateStatus(UpdateStudentStatusRequest $request, Student $student): JsonResponse
    {
        $updatedStudent = $this->studentService->updateStudentStatus(
            $student,
            $request->validated()['status']
        );

        return $this->successResponse($updatedStudent, 'Student status updated');
    }
}

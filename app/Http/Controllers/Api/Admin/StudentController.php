<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStudentRequest;
use App\Http\Requests\Admin\UpdateStudentRequest;
use App\Http\Requests\Admin\UpdateStudentStatusRequest;
use App\Models\Student;
use App\Services\StudentCompletionService;
use App\Services\StudentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    public function __construct(
        private StudentService $studentService,
        private StudentCompletionService $completionService,
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
        $student->load([
            'guardian',
            'registration',
            'parents',
            'health',
            'educationHistory',
            'religiousProfile',
            'additionalInfo',
            'documents',
        ]);

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

    public function uploadDocument(Request $request, Student $student): JsonResponse
    {
        $allowedTypes = ['foto', 'akta_kelahiran', 'kartu_keluarga', 'ijazah'];

        $request->validate([
            'document_type' => ['required', 'string', Rule::in($allowedTypes)],
            'file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,pdf,webp'],
        ]);

        $document = $this->completionService->uploadDocument(
            $student,
            $request->input('document_type'),
            $request->file('file'),
        );

        return $this->successResponse($document, 'Document uploaded');
    }

    public function deleteDocument(Student $student, string $documentType): JsonResponse
    {
        $deleted = $this->completionService->deleteDocument($student, $documentType);

        if (! $deleted) {
            return $this->errorResponse('Document not found', 404);
        }

        return $this->successResponse(null, 'Document deleted');
    }
}

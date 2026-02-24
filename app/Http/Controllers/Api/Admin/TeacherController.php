<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GrantTeacherAccessRequest;
use App\Http\Requests\Admin\StoreTeacherRequest;
use App\Http\Requests\Admin\UpdateTeacherRequest;
use App\Http\Requests\Admin\UpdateTeacherStatusRequest;
use App\Models\Teacher;
use App\Services\TeacherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherController extends Controller
{
    public function __construct(
        private TeacherService $teacherService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $teachers = $this->teacherService->listTeachers($request->all());

        return $this->paginatedResponse($teachers, 'Teachers retrieved');
    }

    public function store(StoreTeacherRequest $request): JsonResponse
    {
        $teacher = $this->teacherService->createTeacher($request->validated());

        return $this->successResponse($teacher, 'Teacher created', 201);
    }

    public function show(Teacher $teacher): JsonResponse
    {
        $teacher->load(['school', 'user']);

        return $this->successResponse($teacher, 'Teacher retrieved');
    }

    public function update(UpdateTeacherRequest $request, Teacher $teacher): JsonResponse
    {
        $updatedTeacher = $this->teacherService->updateTeacher($teacher, $request->validated());

        return $this->successResponse($updatedTeacher, 'Teacher updated');
    }

    public function destroy(Request $request, Teacher $teacher): JsonResponse
    {
        $cascadeUser = filter_var($request->query('cascade_user', false), FILTER_VALIDATE_BOOLEAN);

        $this->teacherService->deleteWithCascade($teacher, $cascadeUser);

        return $this->successResponse(null, 'Teacher deleted');
    }

    public function relationships(Teacher $teacher): JsonResponse
    {
        $relationships = $this->teacherService->getRelationships($teacher);

        return $this->successResponse($relationships, 'Teacher relationships retrieved');
    }

    public function updateStatus(UpdateTeacherStatusRequest $request, Teacher $teacher): JsonResponse
    {
        $updatedTeacher = $this->teacherService->updateTeacherStatus(
            $teacher,
            $request->validated()['status']
        );

        return $this->successResponse($updatedTeacher, 'Teacher status updated');
    }

    public function grantAccess(GrantTeacherAccessRequest $request, Teacher $teacher): JsonResponse
    {
        if ($teacher->user_id !== null) {
            return $this->errorResponse('Teacher already has system access', null, 422);
        }

        $result = $this->teacherService->grantAccess(
            $teacher,
            $request->validated()['email'],
            $request->validated()['password']
        );

        return $this->successResponse($result, 'System access granted', 201);
    }
}

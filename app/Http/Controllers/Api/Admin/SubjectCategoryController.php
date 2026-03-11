<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\HasDependentsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSubjectCategoryRequest;
use App\Http\Requests\Admin\UpdateSubjectCategoryRequest;
use App\Models\SubjectCategory;
use App\Services\SubjectCategoryService;
use Illuminate\Http\JsonResponse;

class SubjectCategoryController extends Controller
{
    public function __construct(
        private SubjectCategoryService $subjectCategoryService
    ) {}

    public function index(): JsonResponse
    {
        $categories = $this->subjectCategoryService->listForAdmin();

        return $this->successResponse($categories, 'Subject categories retrieved');
    }

    public function store(StoreSubjectCategoryRequest $request): JsonResponse
    {
        $category = $this->subjectCategoryService->createCategory($request->validated());

        return $this->successResponse($category, 'Subject category created', 201);
    }

    public function show(SubjectCategory $subjectCategory): JsonResponse
    {
        return $this->successResponse($subjectCategory, 'Subject category retrieved');
    }

    public function update(UpdateSubjectCategoryRequest $request, SubjectCategory $subjectCategory): JsonResponse
    {
        $updatedCategory = $this->subjectCategoryService->updateCategory($subjectCategory, $request->validated());

        return $this->successResponse($updatedCategory, 'Subject category updated');
    }

    public function destroy(SubjectCategory $subjectCategory): JsonResponse
    {
        try {
            $this->subjectCategoryService->deleteCategory($subjectCategory);
        } catch (HasDependentsException $e) {
            return $this->errorResponse($e->getMessage(), code: 422);
        }

        return $this->successResponse(null, 'Subject category deleted');
    }
}

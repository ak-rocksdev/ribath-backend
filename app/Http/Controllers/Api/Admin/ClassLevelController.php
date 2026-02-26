<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReorderClassLevelsRequest;
use App\Http\Requests\Admin\StoreClassLevelRequest;
use App\Http\Requests\Admin\UpdateClassLevelRequest;
use App\Http\Requests\Admin\UpdateClassLevelStatusRequest;
use App\Models\ClassLevel;
use App\Services\ClassLevelService;
use Illuminate\Http\JsonResponse;

class ClassLevelController extends Controller
{
    public function __construct(
        private ClassLevelService $classLevelService
    ) {}

    public function index(): JsonResponse
    {
        $classLevels = ClassLevel::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'slug', 'label', 'category', 'sort_order']);

        return $this->successResponse($classLevels, 'Class levels retrieved');
    }

    public function adminIndex(): JsonResponse
    {
        $classLevels = $this->classLevelService->listAllClassLevels();

        return $this->successResponse($classLevels, 'All class levels retrieved');
    }

    public function store(StoreClassLevelRequest $request): JsonResponse
    {
        $classLevel = $this->classLevelService->createClassLevel($request->validated());

        return $this->successResponse($classLevel, 'Class level created', 201);
    }

    public function update(UpdateClassLevelRequest $request, ClassLevel $classLevel): JsonResponse
    {
        $updatedClassLevel = $this->classLevelService->updateClassLevel($classLevel, $request->validated());

        return $this->successResponse($updatedClassLevel, 'Class level updated');
    }

    public function destroy(ClassLevel $classLevel): JsonResponse
    {
        $deleted = $this->classLevelService->deleteClassLevel($classLevel);

        if (! $deleted) {
            return $this->errorResponse(
                'Cannot delete class level that has students assigned to it',
                null,
                422
            );
        }

        return $this->successResponse(null, 'Class level deleted');
    }

    public function updateStatus(UpdateClassLevelStatusRequest $request, ClassLevel $classLevel): JsonResponse
    {
        $updatedClassLevel = $this->classLevelService->toggleStatus($classLevel, $request->boolean('is_active'));

        return $this->successResponse($updatedClassLevel, 'Class level status updated');
    }

    public function reorder(ReorderClassLevelsRequest $request): JsonResponse
    {
        $this->classLevelService->reorderClassLevels($request->input('ordered_ids'));

        return $this->successResponse(null, 'Class levels reordered');
    }
}

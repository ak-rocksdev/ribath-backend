<?php

namespace App\Http\Controllers\Api\Akademik;

use App\Http\Controllers\Controller;
use App\Http\Requests\Akademik\BulkUpsertClassTaskScoresRequest;
use App\Http\Requests\Akademik\ListClassTasksRequest;
use App\Http\Requests\Akademik\StoreClassTaskRequest;
use App\Http\Requests\Akademik\UpdateClassTaskRequest;
use App\Models\ClassTask;
use App\Services\Akademik\ClassTaskService;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Http\JsonResponse;

class ClassTaskController extends Controller
{
    use EnsuresActiveSchoolTenancy;

    public function __construct(
        private ClassTaskService $classTaskService,
    ) {}

    public function index(ListClassTasksRequest $request): JsonResponse
    {
        $data = $request->validated();

        $tasks = $this->classTaskService->list(
            $data['academic_year_id'],
            (int) $data['semester'],
            $data['class_level_id'],
            $data['subject_book_id'],
        );

        return $this->successResponse($tasks, 'Daftar tugas berhasil diambil');
    }

    public function store(StoreClassTaskRequest $request): JsonResponse
    {
        $task = $this->classTaskService->create($request->validated());

        return $this->successResponse($task, 'Tugas berhasil dibuat', 201);
    }

    public function show(ClassTask $classTask): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($classTask);

        return $this->successResponse($this->classTaskService->present($classTask), 'Tugas berhasil diambil');
    }

    public function update(UpdateClassTaskRequest $request, ClassTask $classTask): JsonResponse
    {
        $task = $this->classTaskService->update($classTask, $request->validated());

        return $this->successResponse($task, 'Tugas berhasil diperbarui');
    }

    public function destroy(ClassTask $classTask): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($classTask);
        $this->classTaskService->delete($classTask);

        return $this->successResponse(null, 'Tugas berhasil dihapus');
    }

    public function scores(ClassTask $classTask): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($classTask);

        return $this->successResponse($this->classTaskService->getScores($classTask), 'Nilai tugas berhasil diambil');
    }

    public function bulkUpsertScores(BulkUpsertClassTaskScoresRequest $request, ClassTask $classTask): JsonResponse
    {
        $savedScores = $this->classTaskService->upsertScores($classTask, $request->validated()['rows']);

        return $this->successResponse($savedScores, 'Nilai tugas berhasil disimpan');
    }
}

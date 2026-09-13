<?php

namespace App\Http\Controllers\Api\Tahfidz;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tahfidz\ListMemorizationTargetsRequest;
use App\Http\Requests\Tahfidz\StoreMemorizationTargetRequest;
use App\Http\Requests\Tahfidz\UpdateMemorizationTargetRequest;
use App\Models\MemorizationTarget;
use App\Services\Tahfidz\MemorizationTargetService;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Http\JsonResponse;

class MemorizationTargetController extends Controller
{
    use EnsuresActiveSchoolTenancy;

    public function __construct(
        private MemorizationTargetService $memorizationTargetService,
    ) {}

    public function index(ListMemorizationTargetsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $targets = $this->memorizationTargetService->list(
            $data['academic_year_id'],
            (int) $data['semester'],
            $data['class_level_id'] ?? null,
            $data['search'] ?? null,
        );

        return $this->paginatedResponse($targets, 'Daftar Target Hafalan berhasil diambil');
    }

    public function store(StoreMemorizationTargetRequest $request): JsonResponse
    {
        $target = $this->memorizationTargetService->create($request->validated());

        return $this->successResponse($target, 'Target Hafalan berhasil dibuat', 201);
    }

    public function update(UpdateMemorizationTargetRequest $request, MemorizationTarget $memorizationTarget): JsonResponse
    {
        $target = $this->memorizationTargetService->update($memorizationTarget, $request->validated());

        return $this->successResponse($target, 'Target Hafalan berhasil diperbarui');
    }

    public function destroy(MemorizationTarget $memorizationTarget): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($memorizationTarget);
        $this->memorizationTargetService->delete($memorizationTarget);

        return $this->successResponse(null, 'Target Hafalan berhasil dihapus');
    }
}

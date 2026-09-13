<?php

namespace App\Http\Controllers\Api\Tahfidz;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tahfidz\ListMemorizationLogsRequest;
use App\Http\Requests\Tahfidz\ShowMemorizationProgressRequest;
use App\Http\Requests\Tahfidz\StoreMemorizationLogRequest;
use App\Http\Requests\Tahfidz\UpdateMemorizationLogRequest;
use App\Models\MemorizationLog;
use App\Models\Student;
use App\Services\Tahfidz\MemorizationLogService;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Http\JsonResponse;

class MemorizationLogController extends Controller
{
    use EnsuresActiveSchoolTenancy;

    public function __construct(
        private MemorizationLogService $memorizationLogService,
    ) {}

    public function index(ListMemorizationLogsRequest $request): JsonResponse
    {
        $logs = $this->memorizationLogService->list($request->validated());

        return $this->paginatedResponse($logs, 'Daftar Log Setoran berhasil diambil');
    }

    public function store(StoreMemorizationLogRequest $request): JsonResponse
    {
        $log = $this->memorizationLogService->create($request->validated());

        return $this->successResponse($log, 'Log Setoran berhasil dibuat', 201);
    }

    public function update(UpdateMemorizationLogRequest $request, MemorizationLog $memorizationLog): JsonResponse
    {
        $log = $this->memorizationLogService->update($memorizationLog, $request->validated());

        return $this->successResponse($log, 'Log Setoran berhasil diperbarui');
    }

    public function destroy(MemorizationLog $memorizationLog): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($memorizationLog);
        $this->memorizationLogService->delete($memorizationLog);

        return $this->successResponse(null, 'Log Setoran berhasil dihapus');
    }

    public function progress(ShowMemorizationProgressRequest $request, Student $student): JsonResponse
    {
        $data = $request->validated();

        $progress = $this->memorizationLogService->progressForStudent(
            $student,
            $data['academic_year_id'],
            (int) $data['semester'],
        );

        return $this->successResponse($progress, 'Progres hafalan santri berhasil diambil');
    }
}

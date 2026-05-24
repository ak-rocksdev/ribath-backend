<?php

namespace App\Http\Controllers\Api\Keuangan;

use App\Http\Controllers\Controller;
use App\Http\Requests\Keuangan\StoreFeeScheduleRequest;
use App\Http\Requests\Keuangan\UpdateFeeScheduleRequest;
use App\Models\FeeSchedule;
use App\Services\Keuangan\FeeScheduleService;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeeScheduleController extends Controller
{
    use EnsuresActiveSchoolTenancy;

    public function __construct(
        private FeeScheduleService $feeScheduleService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['academic_year_id', 'fee_type_id', 'per_page']);
        $schedules = $this->feeScheduleService->listSchedules($filters);

        return $this->paginatedResponse($schedules, 'Fee schedules retrieved');
    }

    public function store(StoreFeeScheduleRequest $request): JsonResponse
    {
        $schedule = $this->feeScheduleService->createSchedule($request->validated());

        return $this->successResponse($schedule, 'Fee schedule created', 201);
    }

    public function update(UpdateFeeScheduleRequest $request, FeeSchedule $feeSchedule): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($feeSchedule);

        $schedule = $this->feeScheduleService->updateSchedule($feeSchedule, $request->validated());

        return $this->successResponse($schedule, 'Fee schedule updated');
    }

    public function destroy(FeeSchedule $feeSchedule): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($feeSchedule);
        $this->feeScheduleService->deleteSchedule($feeSchedule);

        return $this->successResponse(null, 'Fee schedule deleted');
    }
}

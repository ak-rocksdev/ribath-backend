<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\HasDependentsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTimeSlotRequest;
use App\Http\Requests\Admin\UpdateTimeSlotRequest;
use App\Models\TimeSlot;
use App\Services\TimeSlotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimeSlotController extends Controller
{
    public function __construct(
        private TimeSlotService $timeSlotService
    ) {}

    public function index(): JsonResponse
    {
        $timeSlots = $this->timeSlotService->listForAdmin();

        return $this->successResponse($timeSlots, 'Time slots retrieved');
    }

    public function store(StoreTimeSlotRequest $request): JsonResponse
    {
        $timeSlot = $this->timeSlotService->createTimeSlot($request->validated());

        return $this->successResponse($timeSlot, 'Time slot created', 201);
    }

    public function show(TimeSlot $timeSlot): JsonResponse
    {
        return $this->successResponse($timeSlot, 'Time slot retrieved');
    }

    public function update(UpdateTimeSlotRequest $request, TimeSlot $timeSlot): JsonResponse
    {
        $updatedTimeSlot = $this->timeSlotService->updateTimeSlot($timeSlot, $request->validated());

        return $this->successResponse($updatedTimeSlot, 'Time slot updated');
    }

    public function destroy(TimeSlot $timeSlot): JsonResponse
    {
        try {
            $this->timeSlotService->deleteTimeSlot($timeSlot);
        } catch (HasDependentsException $e) {
            return $this->errorResponse($e->getMessage(), code: 422);
        }

        return $this->successResponse(null, 'Time slot deleted');
    }

    public function reorder(Request $request): JsonResponse
    {
        $request->validate([
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['required', 'uuid', 'exists:time_slots,id'],
        ]);

        $this->timeSlotService->reorder($request->input('ordered_ids'));

        return $this->successResponse(null, 'Time slots reordered');
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTeachingScheduleRequest;
use App\Http\Requests\Admin\UpdateTeachingScheduleRequest;
use App\Models\TeachingSchedule;
use App\Services\TeachingScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeachingScheduleController extends Controller
{
    public function __construct(
        private TeachingScheduleService $teachingScheduleService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'academic_year_id', 'semester', 'class_level_id', 'day_of_week', 'teacher_id',
        ]);

        $schedules = $this->teachingScheduleService->listSchedules($filters);

        return $this->successResponse($schedules, 'Teaching schedules retrieved');
    }

    public function store(StoreTeachingScheduleRequest $request): JsonResponse
    {
        $schedule = $this->teachingScheduleService->createSchedule($request->validated());

        return $this->successResponse($schedule, 'Teaching schedule created', 201);
    }

    public function show(TeachingSchedule $teachingSchedule): JsonResponse
    {
        $teachingSchedule->load([
            'subjectBook:id,title,subject_category_id,sessions_per_week',
            'subjectBook.subjectCategory:id,name,color',
            'teacher:id,full_name,code',
            'timeSlot:id,code,label,type,start_time,end_time,sort_order',
            'classLevel:id,slug,label,category',
            'academicYear:id,name',
        ]);

        return $this->successResponse($teachingSchedule, 'Teaching schedule retrieved');
    }

    public function update(UpdateTeachingScheduleRequest $request, TeachingSchedule $teachingSchedule): JsonResponse
    {
        $updatedSchedule = $this->teachingScheduleService->updateSchedule(
            $teachingSchedule,
            $request->validated()
        );

        return $this->successResponse($updatedSchedule, 'Teaching schedule updated');
    }

    public function destroy(TeachingSchedule $teachingSchedule): JsonResponse
    {
        $this->teachingScheduleService->deleteSchedule($teachingSchedule);

        return $this->successResponse(null, 'Teaching schedule deleted');
    }
}

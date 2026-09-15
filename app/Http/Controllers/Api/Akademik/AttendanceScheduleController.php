<?php

namespace App\Http\Controllers\Api\Akademik;

use App\Http\Controllers\Controller;
use App\Http\Requests\Akademik\ListAttendanceSchedulesRequest;
use App\Models\TeachingSchedule;
use App\Services\Akademik\ClassSessionService;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Http\JsonResponse;

/**
 * The Jadwal Mengajar of the Absensi Pertemuan page, readable with the
 * attendance permissions rather than the pesantren-wide `view-schedules`.
 */
class AttendanceScheduleController extends Controller
{
    use EnsuresActiveSchoolTenancy;

    public function __construct(
        private ClassSessionService $classSessionService,
    ) {}

    public function index(ListAttendanceSchedulesRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->successResponse(
            $this->classSessionService->listSchedulesForAttendance($data['academic_year_id'], (int) $data['semester']),
            'Daftar jadwal absensi berhasil diambil'
        );
    }

    public function show(TeachingSchedule $teachingSchedule): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($teachingSchedule);

        return $this->successResponse(
            $this->classSessionService->showScheduleForAttendance($teachingSchedule),
            'Jadwal absensi berhasil diambil'
        );
    }
}

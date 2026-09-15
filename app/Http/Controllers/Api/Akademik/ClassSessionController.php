<?php

namespace App\Http\Controllers\Api\Akademik;

use App\Http\Controllers\Controller;
use App\Http\Requests\Akademik\CancelClassSessionRangeRequest;
use App\Http\Requests\Akademik\CancelClassSessionRequest;
use App\Http\Requests\Akademik\ListClassSessionsRequest;
use App\Http\Requests\Akademik\ShowExpectedStudentsRequest;
use App\Http\Requests\Akademik\StoreClassSessionRequest;
use App\Http\Requests\Akademik\UpdateClassSessionAttendancesRequest;
use App\Models\ClassSession;
use App\Models\TeachingSchedule;
use App\Services\Akademik\ClassSessionService;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Http\JsonResponse;

class ClassSessionController extends Controller
{
    use EnsuresActiveSchoolTenancy;

    public function __construct(
        private ClassSessionService $classSessionService,
    ) {}

    public function index(ListClassSessionsRequest $request): JsonResponse
    {
        return $this->successResponse(
            $this->classSessionService->listSessions($request->validated()),
            'Daftar pertemuan berhasil diambil'
        );
    }

    public function store(StoreClassSessionRequest $request): JsonResponse
    {
        $data = $request->validated();

        $result = $this->classSessionService->recordSession(
            TeachingSchedule::findOrFail($data['teaching_schedule_id']),
            $data['session_date'],
            $data['attendances'],
        );

        return $this->successResponse($result, 'Pertemuan berhasil dicatat', 201);
    }

    public function show(ClassSession $classSession): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($classSession);

        return $this->successResponse($this->classSessionService->getSession($classSession), 'Pertemuan berhasil diambil');
    }

    public function updateAttendances(UpdateClassSessionAttendancesRequest $request, ClassSession $classSession): JsonResponse
    {
        $result = $this->classSessionService->updateAttendances($classSession, $request->validated()['attendances']);

        return $this->successResponse($result, 'Absensi berhasil disimpan');
    }

    public function cancel(CancelClassSessionRequest $request): JsonResponse
    {
        $data = $request->validated();

        $cancellation = $this->classSessionService->cancelSession(
            TeachingSchedule::findOrFail($data['teaching_schedule_id']),
            $data['session_date'],
            $data['reason'],
        );

        return $this->successResponse(
            $cancellation['result'],
            'Pertemuan berhasil dibatalkan',
            $cancellation['created'] ? 201 : 200
        );
    }

    public function cancelRange(CancelClassSessionRangeRequest $request): JsonResponse
    {
        $data = $request->validated();

        $result = $this->classSessionService->cancelDateRange($data['start_date'], $data['end_date'], $data['reason']);

        return $this->successResponse($result, 'Libur massal berhasil diproses');
    }

    public function expectedStudents(ShowExpectedStudentsRequest $request, TeachingSchedule $teachingSchedule): JsonResponse
    {
        return $this->successResponse(
            $this->classSessionService->presentExpectedStudents($teachingSchedule, $request->validated()['session_date']),
            'Daftar santri pertemuan berhasil diambil'
        );
    }
}

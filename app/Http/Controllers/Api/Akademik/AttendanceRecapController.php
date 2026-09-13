<?php

namespace App\Http\Controllers\Api\Akademik;

use App\Http\Controllers\Controller;
use App\Http\Requests\Akademik\ShowAttendanceRecapRequest;
use App\Services\Akademik\AttendanceRecapService;
use Illuminate\Http\JsonResponse;

class AttendanceRecapController extends Controller
{
    public function __construct(
        private AttendanceRecapService $attendanceRecapService,
    ) {}

    public function index(ShowAttendanceRecapRequest $request): JsonResponse
    {
        $data = $request->validated();

        $recap = $this->attendanceRecapService->recapForClassSubject(
            $data['academic_year_id'],
            (int) $data['semester'],
            $data['class_level_id'],
            $data['subject_book_id'],
        );

        return $this->successResponse($recap, 'Rekap kehadiran berhasil diambil');
    }
}

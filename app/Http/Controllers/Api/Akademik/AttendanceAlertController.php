<?php

namespace App\Http\Controllers\Api\Akademik;

use App\Http\Controllers\Controller;
use App\Http\Requests\Akademik\ListAttendanceAlertsRequest;
use App\Services\Akademik\MissingSessionFinder;
use Illuminate\Http\JsonResponse;

class AttendanceAlertController extends Controller
{
    public function __construct(
        private MissingSessionFinder $missingSessionFinder,
    ) {}

    public function index(ListAttendanceAlertsRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->successResponse(
            $this->missingSessionFinder->findForSemester(
                $data['academic_year_id'] ?? null,
                isset($data['semester']) ? (int) $data['semester'] : null,
            ),
            'Daftar pertemuan bolong berhasil diambil'
        );
    }
}

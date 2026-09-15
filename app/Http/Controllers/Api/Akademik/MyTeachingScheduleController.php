<?php

namespace App\Http\Controllers\Api\Akademik;

use App\Http\Controllers\Controller;
use App\Http\Requests\Akademik\ListMyTeachingSchedulesRequest;
use App\Services\Akademik\MyTeachingScheduleService;
use Illuminate\Http\JsonResponse;

/**
 * Jadwal Saya: the Jadwal Mengajar the user's own Ustadz holds now.
 */
class MyTeachingScheduleController extends Controller
{
    public function __construct(
        private MyTeachingScheduleService $myTeachingScheduleService,
    ) {}

    public function index(ListMyTeachingSchedulesRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->successResponse(
            $this->myTeachingScheduleService->listForCurrentUser(
                $data['academic_year_id'] ?? null,
                isset($data['semester']) ? (int) $data['semester'] : null,
            ),
            'Jadwal mengajar Anda berhasil diambil'
        );
    }
}

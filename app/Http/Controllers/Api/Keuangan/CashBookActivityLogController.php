<?php

namespace App\Http\Controllers\Api\Keuangan;

use App\Http\Controllers\Controller;
use App\Services\Keuangan\CashBookActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashBookActivityLogController extends Controller
{
    public function __construct(
        private CashBookActivityLogService $activityLogService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'subject_type', 'subject_id', 'actor_id', 'start_date', 'end_date', 'per_page',
        ]);

        $logs = $this->activityLogService->listActivityLogs($filters);

        return $this->paginatedResponse($logs, 'Cash book activity logs retrieved');
    }
}

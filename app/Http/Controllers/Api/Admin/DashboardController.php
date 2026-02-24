<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(
        private DashboardService $dashboardService
    ) {}

    public function stats(): JsonResponse
    {
        $stats = $this->dashboardService->getStats();

        return $this->successResponse($stats, 'Dashboard stats retrieved');
    }
}

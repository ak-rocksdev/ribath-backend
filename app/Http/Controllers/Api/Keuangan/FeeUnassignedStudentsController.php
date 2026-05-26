<?php

namespace App\Http\Controllers\Api\Keuangan;

use App\Http\Controllers\Controller;
use App\Services\Keuangan\StudentFeeAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeeUnassignedStudentsController extends Controller
{
    public function __construct(
        private StudentFeeAssignmentService $assignmentService,
    ) {}

    public function count(): JsonResponse
    {
        return $this->successResponse(
            ['count' => $this->assignmentService->countUnassignedStudents()],
            'Unassigned students count retrieved'
        );
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 25), 100);
        $paginated = $this->assignmentService->listUnassignedStudents($perPage);

        return response()->json([
            'success' => true,
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
            'message' => 'Unassigned students retrieved',
        ]);
    }
}

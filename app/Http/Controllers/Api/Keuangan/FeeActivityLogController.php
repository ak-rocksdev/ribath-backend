<?php

namespace App\Http\Controllers\Api\Keuangan;

use App\Http\Controllers\Controller;
use App\Http\Resources\Keuangan\FeeActivityLogResource;
use App\Models\FeeActivityLog;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeeActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $school = School::activeOrFail();
        $perPage = min((int) $request->input('per_page', 25), 100);

        $query = FeeActivityLog::query()
            ->with('actor')
            ->where('school_id', $school->id)
            ->orderByDesc('created_at');

        if ($subjectType = $request->input('subject_type')) {
            $query->where('subject_type', $subjectType);
        }
        if ($subjectId = $request->input('subject_id')) {
            $query->where('subject_id', $subjectId);
        }
        if ($action = $request->input('action')) {
            $query->where('action', $action);
        }
        if ($actorId = $request->input('actor_id')) {
            $query->where('actor_id', $actorId);
        }
        if ($startDate = $request->input('start_date')) {
            $query->where('created_at', '>=', $startDate);
        }
        if ($endDate = $request->input('end_date')) {
            $query->where('created_at', '<=', $endDate);
        }

        $paginated = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => FeeActivityLogResource::collection($paginated->items()),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
            'message' => 'Fee activity logs retrieved',
        ]);
    }
}

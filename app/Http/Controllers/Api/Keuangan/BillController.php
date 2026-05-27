<?php

namespace App\Http\Controllers\Api\Keuangan;

use App\Http\Controllers\Controller;
use App\Http\Requests\Keuangan\GenerateBillsRequest;
use App\Http\Resources\Keuangan\BillResource;
use App\Models\Bill;
use App\Models\FeeActivityLog;
use App\Models\School;
use App\Services\Keuangan\BillGeneratorService;
use App\Traits\EnsuresActiveSchoolTenancy;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillController extends Controller
{
    use EnsuresActiveSchoolTenancy;

    public function __construct(
        private BillGeneratorService $generator,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $school = School::activeOrFail();
        $perPage = min((int) $request->input('per_page', 25), 100);

        $query = Bill::query()
            ->with(['student', 'assignment.feeType'])
            ->where('school_id', $school->id)
            ->orderByDesc('billing_period_start')
            ->orderBy('student_id');

        $this->applyFilters($query, $request);

        $paginated = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => BillResource::collection($paginated->items()),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
            'message' => 'Bills retrieved',
        ]);
    }

    public function show(Bill $bill): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($bill);
        $bill->load(['student', 'assignment.feeType', 'payments']);

        return $this->successResponse(new BillResource($bill), 'Bill retrieved');
    }

    public function generate(GenerateBillsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $stats = $this->generator->generate(
            $validated['period'] ?? null,
            $validated['cadence'] ?? 'all',
            FeeActivityLog::ACTOR_USER,
            $request->user()?->id,
        );

        return $this->successResponse($stats, 'Bill generator completed', 201);
    }

    public function arrearsSummary(): JsonResponse
    {
        $school = School::activeOrFail();
        $today = Carbon::now('Asia/Jakarta')->toDateString();

        $buckets = collect(array_keys(self::arrearsBucketKeys()))->mapWithKeys(function ($bucket) use ($school, $today) {
            $range = self::arrearsBucketRange($bucket, $today);

            // Single selectRaw query per bucket — earlier attempt at chained
            // count() + sum() on the same builder produced bogus `sum(distinct
            // student_id)` SQL because builder aggregate state leaks between
            // calls (uuid sum() crashes in pgsql).
            $q = Bill::query()
                ->where('school_id', $school->id)
                ->whereIn('status', [Bill::STATUS_PENDING, Bill::STATUS_PARTIAL])
                ->where('due_date', '<', $today);

            if ($range[0] !== null) {
                $q->where('due_date', '>=', $range[0]);
            }
            $q->where('due_date', '<=', $range[1]);

            $row = $q->selectRaw('COUNT(DISTINCT student_id) AS student_count, COALESCE(SUM(expected_amount - paid_amount), 0) AS total_remaining')
                ->first();

            return [$bucket => [
                'student_count' => (int) ($row->student_count ?? 0),
                'total_remaining' => (int) ($row->total_remaining ?? 0),
            ]];
        })->all();

        return $this->successResponse($buckets, 'Arrears summary retrieved');
    }

    public function arrearsStudents(Request $request): JsonResponse
    {
        $school = School::activeOrFail();
        $bucket = $request->input('bucket', 'overdue_1_month');
        $today = Carbon::now('Asia/Jakarta')->toDateString();
        $perPage = min((int) $request->input('per_page', 25), 100);

        $range = self::arrearsBucketRange($bucket, $today);

        $query = Bill::query()
            ->with(['student', 'assignment.feeType'])
            ->where('school_id', $school->id)
            ->whereIn('status', [Bill::STATUS_PENDING, Bill::STATUS_PARTIAL])
            ->where('due_date', '<', $today);

        if ($range[0] !== null) {
            $query->where('due_date', '>=', $range[0]);
        }
        $query->where('due_date', '<=', $range[1]);

        $paginated = $query->orderBy('due_date')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => BillResource::collection($paginated->items()),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
            'message' => 'Arrears students retrieved',
        ]);
    }

    private static function arrearsBucketKeys(): array
    {
        return [
            'overdue_1_month' => true,
            'overdue_2_3_months' => true,
            'overdue_more_than_3_months' => true,
        ];
    }

    /**
     * Returns [lowerBoundOrNull, upperBound] inclusive due_date range for the
     * given bucket relative to today (Asia/Jakarta). Null lower bound means
     * unbounded (everything older than the upper).
     */
    private static function arrearsBucketRange(string $bucket, string $today): array
    {
        $base = Carbon::parse($today);

        return match ($bucket) {
            'overdue_2_3_months' => [
                $base->copy()->subMonths(3)->toDateString(),
                $base->copy()->subMonth()->subDay()->toDateString(),
            ],
            'overdue_more_than_3_months' => [
                null,
                $base->copy()->subMonths(3)->subDay()->toDateString(),
            ],
            default => [
                $base->copy()->subMonth()->toDateString(),
                $today,
            ],
        };
    }

    public function destroy(Bill $bill): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($bill);

        if ($bill->payments()->exists()) {
            return $this->errorResponse(
                'Tidak dapat menghapus tagihan yang sudah memiliki pembayaran. Batalkan pembayaran dulu.',
                code: 409
            );
        }

        $bill->delete();

        return $this->successResponse(null, 'Bill deleted');
    }

    private function applyFilters($query, Request $request): void
    {
        if ($period = $request->input('period')) {
            $query->forPeriod($period);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($feeTypeId = $request->input('fee_type_id')) {
            $query->whereHas('assignment', fn ($q) => $q->where('fee_type_id', $feeTypeId));
        }
        if ($classLevel = $request->input('class_level')) {
            $query->whereHas('student', fn ($q) => $q->where('class_level', $classLevel));
        }
        if ($search = $request->input('student_search')) {
            $query->whereHas('student', fn ($q) => $q->where('full_name', 'ILIKE', '%'.$search.'%'));
        }
    }
}

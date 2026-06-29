<?php

namespace App\Http\Controllers\Api\Keuangan;

use App\Http\Controllers\Controller;
use App\Http\Requests\Keuangan\BulkStoreStudentPaymentRequest;
use App\Http\Requests\Keuangan\StoreStudentPaymentRequest;
use App\Http\Resources\Keuangan\StudentPaymentResource;
use App\Models\School;
use App\Models\StudentPayment;
use App\Services\Keuangan\PaymentProofStorageService;
use App\Services\Keuangan\StudentPaymentService;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentPaymentController extends Controller
{
    use EnsuresActiveSchoolTenancy;

    public function __construct(
        private StudentPaymentService $paymentService,
        private PaymentProofStorageService $proofStorage,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $school = School::activeOrFail();
        $perPage = min((int) $request->input('per_page', 25), 100);

        $query = StudentPayment::query()
            ->with(['student', 'bill.assignment.feeType', 'cashBookEntry'])
            ->where('school_id', $school->id)
            ->orderByDesc('payment_date')
            ->orderByDesc('created_at');

        if ($startDate = $request->input('start_date')) {
            $query->whereDate('payment_date', '>=', $startDate);
        }
        if ($endDate = $request->input('end_date')) {
            $query->whereDate('payment_date', '<=', $endDate);
        }
        if ($studentId = $request->input('student_id')) {
            $query->where('student_id', $studentId);
        }
        if ($billId = $request->input('bill_id')) {
            $query->where('bill_id', $billId);
        }
        if ($method = $request->input('payment_method')) {
            $query->where('payment_method', $method);
        }

        $paginated = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => StudentPaymentResource::collection($paginated->items()),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
            'message' => 'Payments retrieved',
        ]);
    }

    public function store(StoreStudentPaymentRequest $request): JsonResponse
    {
        $payment = $this->paymentService->record(
            $request->validated(),
            $request->file('proof'),
            $request->user()->id,
        );
        $payment->load(['student', 'bill.assignment.feeType', 'cashBookEntry']);

        return $this->successResponse(new StudentPaymentResource($payment), 'Payment recorded', 201);
    }

    public function bulkStore(BulkStoreStudentPaymentRequest $request): JsonResponse
    {
        $result = $this->paymentService->bulkRecord(
            $request->validated()['items'],
            $request->user()->id,
        );

        $loaded = collect($result['payments'])->map(
            fn (StudentPayment $p) => $p->load(['student', 'bill.assignment.feeType', 'cashBookEntry'])
        );

        return $this->successResponse(
            ['payments' => StudentPaymentResource::collection($loaded), 'errors' => $result['errors']],
            'Payments recorded',
            201
        );
    }

    public function destroy(Request $request, StudentPayment $studentPayment): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($studentPayment);

        $this->paymentService->reverse(
            $studentPayment,
            $request->user()->id,
            $request->input('reason'),
        );

        return $this->successResponse(null, 'Payment reversed');
    }

    public function streamProof(StudentPayment $studentPayment): StreamedResponse
    {
        $this->ensureBelongsToActiveSchool($studentPayment);

        if ($studentPayment->proof_file_path === null) {
            abort(404, 'Bukti tidak ditemukan.');
        }

        return $this->proofStorage->streamResponse(
            $studentPayment->proof_file_path,
            $studentPayment->proof_file_mime ?? 'application/octet-stream',
        );
    }
}

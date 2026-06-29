<?php

namespace App\Http\Controllers\Api\Keuangan;

use App\Http\Controllers\Controller;
use App\Http\Requests\Keuangan\StoreFeeTypeRequest;
use App\Http\Requests\Keuangan\UpdateFeeTypeRequest;
use App\Models\FeeType;
use App\Services\Keuangan\FeeTypeService;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeeTypeController extends Controller
{
    use EnsuresActiveSchoolTenancy;

    public function __construct(
        private FeeTypeService $feeTypeService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['is_active', 'per_page']);
        $feeTypes = $this->feeTypeService->listFeeTypes($filters);

        return $this->paginatedResponse($feeTypes, 'Fee types retrieved');
    }

    public function store(StoreFeeTypeRequest $request): JsonResponse
    {
        $feeType = $this->feeTypeService->createFeeType($request->validated());

        return $this->successResponse($feeType, 'Fee type created', 201);
    }

    public function update(UpdateFeeTypeRequest $request, FeeType $feeType): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($feeType);

        $feeType = $this->feeTypeService->updateFeeType($feeType, $request->validated());

        return $this->successResponse($feeType, 'Fee type updated');
    }

    public function destroy(FeeType $feeType): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($feeType);
        $this->feeTypeService->deleteFeeType($feeType);

        return $this->successResponse(null, 'Fee type deleted');
    }
}

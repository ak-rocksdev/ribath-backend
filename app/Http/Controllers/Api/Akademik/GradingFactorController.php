<?php

namespace App\Http\Controllers\Api\Akademik;

use App\Http\Controllers\Controller;
use App\Http\Requests\Akademik\UpdateGradingFactorRequest;
use App\Models\GradingFactor;
use App\Services\Akademik\GradingSettingsService;
use Illuminate\Http\JsonResponse;

class GradingFactorController extends Controller
{
    public function __construct(
        private GradingSettingsService $gradingSettingsService,
    ) {}

    public function index(): JsonResponse
    {
        $factors = $this->gradingSettingsService->listFactors();

        return $this->successResponse($factors, 'Faktor penilaian berhasil diambil');
    }

    /**
     * Tenancy is checked in UpdateGradingFactorRequest::authorize().
     */
    public function update(UpdateGradingFactorRequest $request, GradingFactor $gradingFactor): JsonResponse
    {
        $updatedFactor = $this->gradingSettingsService->updateFactor($gradingFactor, $request->validated());

        return $this->successResponse($updatedFactor, 'Faktor penilaian berhasil diperbarui');
    }
}

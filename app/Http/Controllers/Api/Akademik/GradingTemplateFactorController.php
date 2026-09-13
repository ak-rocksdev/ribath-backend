<?php

namespace App\Http\Controllers\Api\Akademik;

use App\Http\Controllers\Controller;
use App\Http\Requests\Akademik\ReplaceSemesterWeightsRequest;
use App\Http\Requests\Akademik\ShowGradingTemplateFactorsRequest;
use App\Services\Akademik\GradingSettingsService;
use Illuminate\Http\JsonResponse;

class GradingTemplateFactorController extends Controller
{
    public function __construct(
        private GradingSettingsService $gradingSettingsService,
    ) {}

    public function index(ShowGradingTemplateFactorsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $templates = $this->gradingSettingsService->getSemesterWeights($data['academic_year_id'], (int) $data['semester']);

        return $this->successResponse($templates, 'Bobot faktor penilaian berhasil diambil');
    }

    public function update(ReplaceSemesterWeightsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $template = $this->gradingSettingsService->replaceSemesterWeights(
            $data['academic_year_id'],
            (int) $data['semester'],
            $data['grading_template_id'],
            $data['factors']
        );

        return $this->successResponse($template, 'Bobot faktor penilaian berhasil disimpan');
    }
}

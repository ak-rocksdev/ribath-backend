<?php

namespace App\Http\Controllers\Api\Akademik;

use App\Http\Controllers\Controller;
use App\Services\Akademik\GradingSettingsService;
use Illuminate\Http\JsonResponse;

class GradingTemplateController extends Controller
{
    public function __construct(
        private GradingSettingsService $gradingSettingsService,
    ) {}

    public function index(): JsonResponse
    {
        $templates = $this->gradingSettingsService->listTemplates();

        return $this->successResponse($templates, 'Template penilaian berhasil diambil');
    }
}

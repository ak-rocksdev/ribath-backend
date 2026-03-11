<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\PSB\QuickRegistrationRequest;
use App\Services\PsbService;
use App\Services\RegistrationPeriodService;
use Illuminate\Http\JsonResponse;

class PublicPsbController extends Controller
{
    public function __construct(
        private PsbService $psbService,
        private RegistrationPeriodService $registrationPeriodService,
    ) {}

    public function activePeriod(): JsonResponse
    {
        $activePeriod = $this->registrationPeriodService->findCurrentlyOpenPeriod();

        if (! $activePeriod) {
            return $this->errorResponse('No active registration period found', null, 404);
        }

        return $this->successResponse($activePeriod, 'Active registration period retrieved');
    }

    public function activePeriods(): JsonResponse
    {
        $periods = $this->registrationPeriodService->findActivePeriodsForLanding();

        return $this->successResponse($periods, 'Active registration periods retrieved');
    }

    public function register(QuickRegistrationRequest $request): JsonResponse
    {
        $registration = $this->psbService->register($request->validated());

        return $this->successResponse($registration, 'Registration submitted successfully', 201);
    }
}

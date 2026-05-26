<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\PSB\QuickRegistrationRequest;
use App\Models\RegistrationPeriod;
use App\Models\School;
use App\Services\PsbPeriodBiayaResolver;
use App\Services\PsbService;
use App\Services\RegistrationPeriodService;
use Illuminate\Http\JsonResponse;

class PublicPsbController extends Controller
{
    public function __construct(
        private PsbService $psbService,
        private RegistrationPeriodService $registrationPeriodService,
        private PsbPeriodBiayaResolver $biayaResolver,
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

    // Public ≠ cross-tenant: route model binding resolves any periode regardless
    // of school, so we 404 cross-tenant IDs to prevent UUID-guess enumeration.
    public function periodBiaya(RegistrationPeriod $registrationPeriod): JsonResponse
    {
        $school = School::activeOrFail();
        abort_unless($registrationPeriod->school_id === $school->id, 404);

        $biaya = $this->biayaResolver->resolveForPeriod($registrationPeriod);

        return $this->successResponse($biaya, 'Period biaya retrieved')
            ->header('Cache-Control', 'public, max-age=60, s-maxage=60');
    }
}

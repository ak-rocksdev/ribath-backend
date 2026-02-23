<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\PSB\QuickRegistrationRequest;
use App\Models\RegistrationPeriod;
use App\Services\PsbService;

class PublicPsbController extends Controller
{
    public function __construct(
        private PsbService $psbService
    ) {}

    public function activePeriod()
    {
        $activePeriod = RegistrationPeriod::where('is_active', true)
            ->where('registration_open', '<=', now())
            ->where('registration_close', '>=', now())
            ->first();

        if (! $activePeriod) {
            return $this->errorResponse('No active registration period found', null, 404);
        }

        return $this->successResponse($activePeriod, 'Active registration period retrieved');
    }

    public function register(QuickRegistrationRequest $request)
    {
        $registration = $this->psbService->register($request->validated());

        return $this->successResponse($registration, 'Registration submitted successfully', 201);
    }
}

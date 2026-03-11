<?php

namespace App\Http\Controllers\Api\PSB;

use App\Exceptions\HasDependentsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PSB\StoreRegistrationPeriodRequest;
use App\Http\Requests\PSB\UpdateRegistrationPeriodRequest;
use App\Models\RegistrationPeriod;
use App\Services\RegistrationPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegistrationPeriodController extends Controller
{
    public function __construct(
        private RegistrationPeriodService $registrationPeriodService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 15), 100);
        $periods = $this->registrationPeriodService->listPeriods($perPage);

        return $this->paginatedResponse($periods, 'Registration periods retrieved');
    }

    public function store(StoreRegistrationPeriodRequest $request): JsonResponse
    {
        $period = $this->registrationPeriodService->createPeriod($request->validated());

        return $this->successResponse($period, 'Registration period created', 201);
    }

    public function show(RegistrationPeriod $registrationPeriod): JsonResponse
    {
        $registrationPeriod->loadCount('registrations');

        return $this->successResponse($registrationPeriod, 'Registration period retrieved');
    }

    public function update(UpdateRegistrationPeriodRequest $request, RegistrationPeriod $registrationPeriod): JsonResponse
    {
        $updatedPeriod = $this->registrationPeriodService->updatePeriod(
            $registrationPeriod,
            $request->validated()
        );

        return $this->successResponse($updatedPeriod, 'Registration period updated');
    }

    public function destroy(RegistrationPeriod $registrationPeriod): JsonResponse
    {
        try {
            $this->registrationPeriodService->deletePeriod($registrationPeriod);
        } catch (HasDependentsException $e) {
            return $this->errorResponse($e->getMessage(), code: 422);
        }

        return $this->successResponse(null, 'Registration period deleted');
    }
}

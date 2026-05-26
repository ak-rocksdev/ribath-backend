<?php

namespace App\Http\Controllers\Api\PSB;

use App\Http\Controllers\Controller;
use App\Http\Requests\PSB\StorePeriodFeeOverrideRequest;
use App\Http\Requests\PSB\UpdatePeriodFeeOverrideRequest;
use App\Models\RegistrationPeriod;
use App\Models\RegistrationPeriodFeeOverride;
use App\Services\Keuangan\RegistrationPeriodFeeOverrideService;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Http\JsonResponse;

class RegistrationPeriodFeeOverrideController extends Controller
{
    use EnsuresActiveSchoolTenancy;

    public function __construct(
        private RegistrationPeriodFeeOverrideService $overrideService,
    ) {}

    public function index(RegistrationPeriod $registrationPeriod): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($registrationPeriod);

        $overrides = $this->overrideService->listForPeriod($registrationPeriod);

        return $this->successResponse($overrides, 'Period fee overrides retrieved');
    }

    public function store(StorePeriodFeeOverrideRequest $request, RegistrationPeriod $registrationPeriod): JsonResponse
    {
        $this->ensureBelongsToActiveSchool($registrationPeriod);

        $override = $this->overrideService->createOverride(
            $registrationPeriod,
            $request->validated(),
            $request->user()->id,
        );

        return $this->successResponse($override, 'Period fee override created', 201);
    }

    public function update(
        UpdatePeriodFeeOverrideRequest $request,
        RegistrationPeriod $registrationPeriod,
        RegistrationPeriodFeeOverride $override,
    ): JsonResponse {
        $this->ensureBelongsToActiveSchool($registrationPeriod);
        $this->ensureBelongsToActiveSchool($override);
        // Make sure the override actually belongs to the route's periode so
        // an attacker can't update overrides of one periode via another's URL.
        abort_unless($override->registration_period_id === $registrationPeriod->id, 404);

        $updated = $this->overrideService->updateOverride($override, $request->validated(), $request->user()->id);

        return $this->successResponse($updated, 'Period fee override updated');
    }

    public function destroy(
        RegistrationPeriod $registrationPeriod,
        RegistrationPeriodFeeOverride $override,
    ): JsonResponse {
        $this->ensureBelongsToActiveSchool($registrationPeriod);
        $this->ensureBelongsToActiveSchool($override);
        abort_unless($override->registration_period_id === $registrationPeriod->id, 404);

        $this->overrideService->deleteOverride($override);

        return $this->successResponse(null, 'Period fee override deleted');
    }
}

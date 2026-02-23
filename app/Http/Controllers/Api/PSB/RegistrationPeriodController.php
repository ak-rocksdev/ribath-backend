<?php

namespace App\Http\Controllers\Api\PSB;

use App\Http\Controllers\Controller;
use App\Http\Requests\PSB\StoreRegistrationPeriodRequest;
use App\Http\Requests\PSB\UpdateRegistrationPeriodRequest;
use App\Models\RegistrationPeriod;

class RegistrationPeriodController extends Controller
{
    public function index()
    {
        $periods = RegistrationPeriod::orderBy('created_at', 'desc')->paginate(15);

        return $this->paginatedResponse($periods, 'Registration periods retrieved');
    }

    public function store(StoreRegistrationPeriodRequest $request)
    {
        $period = RegistrationPeriod::create($request->validated());

        return $this->successResponse($period, 'Registration period created', 201);
    }

    public function show(RegistrationPeriod $registrationPeriod)
    {
        $registrationPeriod->loadCount('registrations');

        return $this->successResponse($registrationPeriod, 'Registration period retrieved');
    }

    public function update(UpdateRegistrationPeriodRequest $request, RegistrationPeriod $registrationPeriod)
    {
        $registrationPeriod->update($request->validated());

        return $this->successResponse($registrationPeriod->fresh(), 'Registration period updated');
    }

    public function destroy(RegistrationPeriod $registrationPeriod)
    {
        if ($registrationPeriod->registrations()->exists()) {
            return $this->errorResponse('Cannot delete period with existing registrations', code: 422);
        }

        $registrationPeriod->delete();

        return $this->successResponse(null, 'Registration period deleted');
    }
}

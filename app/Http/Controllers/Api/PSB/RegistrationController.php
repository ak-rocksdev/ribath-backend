<?php

namespace App\Http\Controllers\Api\PSB;

use App\Http\Controllers\Controller;
use App\Http\Requests\PSB\AcceptRegistrationRequest;
use App\Http\Requests\PSB\RejectRegistrationRequest;
use App\Http\Requests\PSB\UpdateRegistrationStatusRequest;
use App\Models\Registration;
use App\Services\PsbService;

class RegistrationController extends Controller
{
    public function __construct(
        private PsbService $psbService
    ) {}

    public function index()
    {
        $query = Registration::with('period')
            ->orderBy('created_at', 'desc');

        if (request()->boolean('archived_only')) {
            $query->archived();
        } elseif (! request()->boolean('include_archived')) {
            $query->notArchived();
        }

        if (request('status')) {
            $query->where('status', request('status'));
        }

        if (request('registration_period_id')) {
            $query->where('registration_period_id', request('registration_period_id'));
        }

        if (request('search')) {
            $searchTerm = request('search');
            $driver = $query->getQuery()->getConnection()->getDriverName();
            $likeOperator = $driver === 'pgsql' ? 'ilike' : 'like';

            $query->where(function ($q) use ($searchTerm, $likeOperator) {
                $q->where('full_name', $likeOperator, "%{$searchTerm}%")
                    ->orWhere('registration_number', $likeOperator, "%{$searchTerm}%")
                    ->orWhere('guardian_phone', $likeOperator, "%{$searchTerm}%");
            });
        }

        $registrations = $query->paginate(15);

        return $this->paginatedResponse($registrations, 'Registrations retrieved');
    }

    public function stats()
    {
        $stats = $this->psbService->getRegistrationStats(request('registration_period_id'));

        return $this->successResponse($stats, 'Registration statistics retrieved');
    }

    public function show(Registration $registration)
    {
        $registration->load(['period', 'contactedBy', 'reviewedBy', 'student']);

        return $this->successResponse($registration, 'Registration retrieved');
    }

    public function updateStatus(UpdateRegistrationStatusRequest $request, Registration $registration)
    {
        $updateData = ['status' => $request->validated()['status']];

        if ($request->validated()['status'] === Registration::STATUS_CONTACTED) {
            $updateData['contacted_at'] = now();
            $updateData['contacted_by'] = $request->user()->id;
        }

        if ($request->validated()['status'] === Registration::STATUS_VISITED) {
            $updateData['visited_at'] = now();
        }

        if (isset($request->validated()['admin_notes'])) {
            $updateData['admin_notes'] = $request->validated()['admin_notes'];
        }

        $registration->update($updateData);

        return $this->successResponse($registration->fresh(), 'Registration status updated');
    }

    public function accept(AcceptRegistrationRequest $request, Registration $registration)
    {
        if ($registration->status === Registration::STATUS_ACCEPTED) {
            return $this->errorResponse('Registration is already accepted', code: 422);
        }

        $result = $this->psbService->acceptRegistration(
            $registration,
            $request->user(),
            $request->validated()['class_level']
        );

        return $this->successResponse($result, 'Registration accepted successfully');
    }

    public function reject(RejectRegistrationRequest $request, Registration $registration)
    {
        if ($registration->status === Registration::STATUS_REJECTED) {
            return $this->errorResponse('Registration is already rejected', code: 422);
        }

        $result = $this->psbService->rejectRegistration(
            $registration,
            $request->user(),
            $request->validated()['rejection_reason']
        );

        return $this->successResponse($result, 'Registration rejected');
    }

    public function archive(Registration $registration)
    {
        if ($registration->is_archived) {
            return $this->errorResponse('Registration is already archived', code: 422);
        }

        if (! $registration->canBeArchived()) {
            return $this->errorResponse('Only rejected or cancelled registrations can be archived', code: 422);
        }

        $result = $this->psbService->archiveRegistration($registration);

        return $this->successResponse($result, 'Registration archived');
    }

    public function unarchive(Registration $registration)
    {
        if (! $registration->is_archived) {
            return $this->errorResponse('Registration is not archived', code: 422);
        }

        $result = $this->psbService->unarchiveRegistration($registration);

        return $this->successResponse($result, 'Registration unarchived');
    }

    public function destroy(Registration $registration)
    {
        $registration->delete();

        return $this->successResponse(null, 'Registration deleted');
    }
}

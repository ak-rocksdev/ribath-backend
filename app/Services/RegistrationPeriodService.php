<?php

namespace App\Services;

use App\Exceptions\HasDependentsException;
use App\Models\RegistrationPeriod;
use App\Models\School;
use Illuminate\Database\Eloquent\Collection;

class RegistrationPeriodService
{
    public function listPeriods(int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return RegistrationPeriod::withCount('registrations')
            ->orderByDesc('year')
            ->orderByDesc('wave')
            ->paginate($perPage);
    }

    public function findCurrentlyOpenPeriod(): ?RegistrationPeriod
    {
        return RegistrationPeriod::withCount('registrations')
            ->where('is_active', true)
            ->where('registration_open', '<=', now()->startOfDay()->addDay())
            ->where('registration_close', '>=', now()->startOfDay())
            ->first();
    }

    public function findActivePeriodsForLanding(): Collection
    {
        return RegistrationPeriod::where('is_active', true)
            ->where('registration_close', '>=', now()->startOfDay())
            ->orderBy('registration_open')
            ->get();
    }

    public function createPeriod(array $validatedData): RegistrationPeriod
    {
        $defaultSchool = School::where('is_active', true)->first();

        if (! $defaultSchool) {
            throw new \RuntimeException('No active school found. Please run: php artisan db:seed --class=SchoolSeeder');
        }

        $validatedData['school_id'] = $defaultSchool->id;

        return RegistrationPeriod::create($validatedData);
    }

    public function updatePeriod(RegistrationPeriod $registrationPeriod, array $validatedData): RegistrationPeriod
    {
        $registrationPeriod->update($validatedData);

        return $registrationPeriod->fresh();
    }

    public function deletePeriod(RegistrationPeriod $registrationPeriod): void
    {
        if ($registrationPeriod->registrations()->exists()) {
            throw new HasDependentsException(
                'Cannot delete period with existing registrations'
            );
        }

        $registrationPeriod->delete();
    }
}

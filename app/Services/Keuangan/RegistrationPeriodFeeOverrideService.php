<?php

namespace App\Services\Keuangan;

use App\Models\FeeSchedule;
use App\Models\FeeType;
use App\Models\RegistrationPeriod;
use App\Models\RegistrationPeriodFeeOverride;
use App\Models\School;
use Illuminate\Database\Eloquent\Collection;

class RegistrationPeriodFeeOverrideService
{
    public function listForPeriod(RegistrationPeriod $period): Collection
    {
        // Defense in depth — US2 snapshot will call this without the
        // controller's ensureBelongsToActiveSchool guard.
        $school = School::activeOrFail();

        return RegistrationPeriodFeeOverride::query()
            ->where('school_id', $school->id)
            ->where('registration_period_id', $period->id)
            ->with(RegistrationPeriodFeeOverride::EAGER_LOAD_RELATIONS)
            ->orderBy('created_at')
            ->get();
    }

    public function createOverride(RegistrationPeriod $period, array $data, int $userId): RegistrationPeriodFeeOverride
    {
        $school = School::activeOrFail();

        $feeType = $this->loadValidatedFeeType($data['fee_type_id'], $school->id);
        $this->ensureScheduleExistsForPeriodAy($period->academic_year_id, $feeType->id);

        $override = RegistrationPeriodFeeOverride::create([
            'school_id' => $school->id,
            'registration_period_id' => $period->id,
            'fee_type_id' => $feeType->id,
            'amount' => (int) $data['amount'],
            'reason' => $data['reason'],
            'created_by' => $userId,
        ]);

        return $override->fresh()->load(RegistrationPeriodFeeOverride::EAGER_LOAD_RELATIONS);
    }

    public function updateOverride(RegistrationPeriodFeeOverride $override, array $data, int $userId): RegistrationPeriodFeeOverride
    {
        $attributes = [];

        if (array_key_exists('amount', $data)) {
            $attributes['amount'] = (int) $data['amount'];
        }
        if (array_key_exists('reason', $data)) {
            $attributes['reason'] = $data['reason'];
        }

        if (! empty($attributes)) {
            $override->fill($attributes);
            if ($override->isDirty()) {
                $override->updated_by = $userId;
                $override->save();
            }
        }

        return $override->fresh()->load(RegistrationPeriodFeeOverride::EAGER_LOAD_RELATIONS);
    }

    public function deleteOverride(RegistrationPeriodFeeOverride $override): void
    {
        $override->delete();
    }

    private function loadValidatedFeeType(string $feeTypeId, string $schoolId): FeeType
    {
        $feeType = FeeType::find($feeTypeId);

        if ($feeType === null || $feeType->school_id !== $schoolId) {
            abort(422, 'Jenis biaya tidak ditemukan untuk pesantren ini.');
        }

        if ($feeType->default_cadence !== FeeType::CADENCE_ONCE_AT_ENROLLMENT) {
            abort(422, 'Override hanya berlaku untuk biaya pendaftaran (cadence sekali saat masuk). Untuk biaya berulang gunakan tarif default di Biaya Pendidikan.');
        }

        return $feeType;
    }

    // Reject (period × fee_type) pairs with no matching fee_schedule in the
    // period's academic_year — the resolver iterates schedules and silently
    // drops orphan overrides, so the admin would save data that never shows up.
    private function ensureScheduleExistsForPeriodAy(string $academicYearId, string $feeTypeId): void
    {
        $exists = FeeSchedule::query()
            ->where('academic_year_id', $academicYearId)
            ->where('fee_type_id', $feeTypeId)
            ->exists();

        abort_unless(
            $exists,
            422,
            'Tetapkan dulu tarif default jenis biaya ini di Biaya Pendidikan untuk tahun ajaran periode tsb sebelum membuat override.'
        );
    }
}

<?php

namespace App\Services\Keuangan;

use App\Models\FeeType;
use App\Models\RegistrationPeriod;
use App\Models\RegistrationPeriodFeeOverride;
use App\Models\School;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RegistrationPeriodFeeOverrideService
{
    public function listForPeriod(RegistrationPeriod $period): Collection
    {
        return RegistrationPeriodFeeOverride::query()
            ->where('registration_period_id', $period->id)
            ->with(RegistrationPeriodFeeOverride::EAGER_LOAD_RELATIONS)
            ->orderBy('created_at')
            ->get();
    }

    public function createOverride(RegistrationPeriod $period, array $data, int $userId): RegistrationPeriodFeeOverride
    {
        $school = School::activeOrFail();

        $this->ensureFeeTypeBelongsToSchool($data['fee_type_id'], $school->id);
        $this->ensureFeeTypeIsOnceAtEnrollment($data['fee_type_id']);

        $override = RegistrationPeriodFeeOverride::create([
            'school_id' => $school->id,
            'registration_period_id' => $period->id,
            'fee_type_id' => $data['fee_type_id'],
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

    private function ensureFeeTypeBelongsToSchool(string $feeTypeId, string $schoolId): void
    {
        $exists = FeeType::query()
            ->whereKey($feeTypeId)
            ->where('school_id', $schoolId)
            ->exists();

        abort_unless($exists, 422, 'Jenis biaya tidak ditemukan untuk pesantren ini.');
    }

    /**
     * Override hanya berlaku untuk biaya pendaftaran (sekali saat masuk).
     * Cadence lain (monthly, yearly, dst) tidak boleh — gunakan fee_schedules
     * default per AY. Cek di service supaya pesan validasi user-friendly.
     */
    private function ensureFeeTypeIsOnceAtEnrollment(string $feeTypeId): void
    {
        $feeType = FeeType::findOrFail($feeTypeId);

        if ($feeType->default_cadence !== FeeType::CADENCE_ONCE_AT_ENROLLMENT) {
            throw new HttpException(
                422,
                'Override hanya berlaku untuk biaya pendaftaran (cadence sekali saat masuk). Untuk biaya berulang gunakan tarif default di Biaya Pendidikan.'
            );
        }
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Override biaya per gelombang PSB. Bila ada row di sini untuk
 * (registration_period_id × fee_type_id), nilai amount-nya menggantikan
 * fee_schedules.amount default saat snapshot santri di gelombang tsb.
 *
 * Hanya boleh untuk fee_type cadence `once_at_enrollment` — validasi di
 * RegistrationPeriodFeeOverrideService::ensureFeeTypeIsOnceAtEnrollment.
 */
class RegistrationPeriodFeeOverride extends Model
{
    use HasFactory, HasUuids;

    const EAGER_LOAD_RELATIONS = ['feeType'];

    protected $fillable = [
        'school_id',
        'registration_period_id',
        'fee_type_id',
        'amount',
        'reason',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function registrationPeriod(): BelongsTo
    {
        return $this->belongsTo(RegistrationPeriod::class);
    }

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }
}

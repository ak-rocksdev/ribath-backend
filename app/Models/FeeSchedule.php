<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeSchedule extends Model
{
    use HasFactory, HasUuids;

    /**
     * Eager-load both AY and fee_type for resource serialization. fee_type
     * is also the authoritative source for the schedule's billing frequency
     * (the per-schedule override was removed — see the
     * drop_cadence_override_from_fee_schedules migration).
     */
    const EAGER_LOAD_RELATIONS = ['academicYear', 'feeType'];

    protected $fillable = [
        'school_id',
        'academic_year_id',
        'fee_type_id',
        'amount',
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

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }
}

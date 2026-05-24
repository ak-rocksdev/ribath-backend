<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeSchedule extends Model
{
    use HasFactory, HasUuids;

    /**
     * Eager-load both AY and fee_type for resource serialization. The fee_type
     * eager-load also lets the `effective_cadence` accessor avoid an extra
     * query when cadence_override is null and we need to fall back.
     */
    const EAGER_LOAD_RELATIONS = ['academicYear', 'feeType'];

    protected $fillable = [
        'school_id',
        'academic_year_id',
        'fee_type_id',
        'amount',
        'cadence_override',
    ];

    /**
     * Always expose `effective_cadence` in JSON so API clients don't have
     * to recompute "override ?? default" themselves.
     */
    protected $appends = ['effective_cadence'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    /**
     * Returns the effective cadence applied when generating bills:
     * the per-schedule override if set, otherwise the parent fee_type
     * default. Relies on `feeType` being eager-loaded to stay cheap.
     */
    protected function effectiveCadence(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->cadence_override ?? $this->feeType?->default_cadence,
        );
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

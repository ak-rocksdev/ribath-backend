<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeType extends Model
{
    use HasFactory, HasUuids;

    const CADENCE_MONTHLY = 'monthly';

    const CADENCE_QUARTERLY = 'quarterly';

    const CADENCE_SEMESTERLY = 'semesterly';

    const CADENCE_YEARLY = 'yearly';

    const CADENCE_ONCE_AT_ENROLLMENT = 'once_at_enrollment';

    const CADENCES = [
        self::CADENCE_MONTHLY,
        self::CADENCE_QUARTERLY,
        self::CADENCE_SEMESTERLY,
        self::CADENCE_YEARLY,
        self::CADENCE_ONCE_AT_ENROLLMENT,
    ];

    /**
     * Eager-load relations for index/show responses so resources avoid
     * the N+1 trap when nesting the cash book category.
     */
    const EAGER_LOAD_RELATIONS = ['cashBookCategory'];

    protected $fillable = [
        'school_id',
        'code',
        'label',
        'default_cadence',
        'cash_book_category_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function cashBookCategory(): BelongsTo
    {
        return $this->belongsTo(CashBookCategory::class, 'cash_book_category_id');
    }

    public function feeSchedules(): HasMany
    {
        return $this->hasMany(FeeSchedule::class);
    }
}

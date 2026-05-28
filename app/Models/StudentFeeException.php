<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StudentFeeException extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    const KIND_FULL_WAIVER = 'full_waiver';

    const KIND_PARTIAL_NOMINAL = 'partial_nominal';

    const KINDS = [
        self::KIND_FULL_WAIVER,
        self::KIND_PARTIAL_NOMINAL,
    ];

    protected $fillable = [
        'student_fee_assignment_id',
        'kind',
        'discount_amount',
        'reason',
        'effective_from',
        'effective_until',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'discount_amount' => 'integer',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(StudentFeeAssignment::class, 'student_fee_assignment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Window covering test: effective_from <= atDate AND
    // (effective_until IS NULL OR effective_until >= atDate). Single source of
    // truth for both the SQL scope (below) and the in-memory checks used by
    // EffectiveAmountCalculator + the resources when the relation is eager-loaded.
    public static function isActiveAt(self $exception, string $atDate): bool
    {
        $from = $exception->effective_from?->toDateString();
        $until = $exception->effective_until?->toDateString();

        return $from !== null
            && $from <= $atDate
            && ($until === null || $until >= $atDate);
    }

    public function scopeActiveAt(Builder $q, Carbon $atDate): Builder
    {
        $date = $atDate->toDateString();

        return $q->whereDate('effective_from', '<=', $date)
            ->where(function (Builder $sub) use ($date) {
                $sub->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $date);
            });
    }
}

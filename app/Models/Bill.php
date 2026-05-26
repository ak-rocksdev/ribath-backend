<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bill extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    const STATUS_PENDING = 'pending';

    const STATUS_PARTIAL = 'partial';

    const STATUS_PAID = 'paid';

    const STATUS_WAIVED = 'waived';

    const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PARTIAL,
        self::STATUS_PAID,
        self::STATUS_WAIVED,
    ];

    protected $fillable = [
        'school_id',
        'student_id',
        'student_fee_assignment_id',
        'billing_period_start',
        'billing_period_end',
        'cadence_at_generation',
        'expected_amount',
        'paid_amount',
        'status',
        'due_date',
    ];

    protected function casts(): array
    {
        return [
            'billing_period_start' => 'date',
            'billing_period_end' => 'date',
            'due_date' => 'date',
            'expected_amount' => 'integer',
            'paid_amount' => 'integer',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(StudentFeeAssignment::class, 'student_fee_assignment_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(StudentPayment::class);
    }

    public function scopeForPeriod(Builder $q, string $month): Builder
    {
        // $month format YYYY-MM. Bills whose period_start falls in that month.
        return $q->whereYear('billing_period_start', substr($month, 0, 4))
            ->whereMonth('billing_period_start', substr($month, 5, 2));
    }

    public function scopeArrears(Builder $q): Builder
    {
        return $q->whereIn('status', [self::STATUS_PENDING, self::STATUS_PARTIAL])
            ->where('due_date', '<', now('Asia/Jakarta')->toDateString());
    }
}

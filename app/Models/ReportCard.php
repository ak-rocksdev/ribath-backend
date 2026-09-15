<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rapor: one santri's grading result for one semester akademik. Once
 * finalized it is a snapshot (ADR 0001) — its entries are read instead of
 * the live recap, and per-santri source writes for that semester are
 * rejected (FinalizedReportCardGuard). Only super_admin can put a final
 * rapor back to draft, with a recorded reason.
 */
class ReportCard extends Model
{
    use HasUuids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_FINAL = 'final';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_FINAL];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected $fillable = [
        'school_id',
        'student_id',
        'academic_year_id',
        'semester',
        'class_level_id',
        'status',
        'finalized_at',
        'finalized_by',
        'unfinalize_reason',
        'unfinalized_at',
        'unfinalized_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'semester' => 'integer',
            'finalized_at' => 'datetime',
            'unfinalized_at' => 'datetime',
            'finalized_by' => 'integer',
            'unfinalized_by' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function isFinal(): bool
    {
        return $this->status === self::STATUS_FINAL;
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ReportCardEntry::class);
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function unfinalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unfinalized_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentFeeAssignment extends Model
{
    use HasFactory, HasUuids;

    const SOURCE_AUTO_ENROLLMENT = 'auto_enrollment';

    const SOURCE_MANUAL_SNAPSHOT = 'manual_snapshot';

    const SOURCE_MANUAL_ENTRY = 'manual_entry';

    const SOURCES = [
        self::SOURCE_AUTO_ENROLLMENT,
        self::SOURCE_MANUAL_SNAPSHOT,
        self::SOURCE_MANUAL_ENTRY,
    ];

    protected $fillable = [
        'school_id',
        'student_id',
        'fee_type_id',
        'locked_amount',
        'cadence',
        'source_academic_year_id',
        'source',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'locked_amount' => 'integer',
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

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }

    public function sourceAcademicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'source_academic_year_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(StudentFeeException::class);
    }
}

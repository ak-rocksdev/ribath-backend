<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pertemuan: one real occurrence of a teaching schedule (Jadwal Mengajar)
 * on one date. `held` sessions carry student_attendances; `cancelled` ones
 * (Pertemuan Dibatalkan) carry a cancel_reason and are neither counted in
 * the absensi denominator nor reported as bolong. Class, kitab and teacher
 * are a snapshot of the schedule at recording time.
 */
class ClassSession extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_HELD = 'held';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_HELD, self::STATUS_CANCELLED];

    protected $fillable = [
        'school_id',
        'teaching_schedule_id',
        'session_date',
        'academic_year_id',
        'semester',
        'class_level_id',
        'subject_book_id',
        'teacher_id',
        'status',
        'cancel_reason',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'session_date' => 'date:Y-m-d',
            'semester' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function teachingSchedule(): BelongsTo
    {
        return $this->belongsTo(TeachingSchedule::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }

    public function subjectBook(): BelongsTo
    {
        return $this->belongsTo(SubjectBook::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(StudentAttendance::class);
    }
}

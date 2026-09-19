<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Absensi: one santri's status at one Pertemuan. Sick and excused are
 * neutral for the absensi score (left out of the denominator).
 *
 * `class_level_id` is the Kelas the santri was recorded for, kept per row
 * because one Pertemuan of a jadwal gabungan covers several Kelas (ADR
 * 0006). The Rekap Kehadiran of a Kelas is counted from these rows.
 */
class StudentAttendance extends Model
{
    use HasUuids;

    public const STATUS_PRESENT = 'present';

    public const STATUS_SICK = 'sick';

    public const STATUS_EXCUSED = 'excused';

    public const STATUS_ABSENT = 'absent';

    public const STATUSES = [
        self::STATUS_PRESENT,
        self::STATUS_SICK,
        self::STATUS_EXCUSED,
        self::STATUS_ABSENT,
    ];

    public const NOTES_MAX_LENGTH = 255;

    protected $fillable = [
        'school_id',
        'class_session_id',
        'student_id',
        'class_level_id',
        'status',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function classSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
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

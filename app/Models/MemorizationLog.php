<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Setoran (`new`) or Murajaah (`review`): one entry of a santri reciting
 * Halaman on a date, given a quality score. subject_book_id always points
 * at the active school's Tahfizh kitab — resolved server-side by
 * MemorizationLogService, never taken from client input.
 *
 * A log does not require the student to have a Target Hafalan for the
 * semester; MemorizationLogService::progressForStudent() reports a NULL
 * achievement_percent when there is none (see
 * Calculation\MemorizationFactorCalculator).
 */
class MemorizationLog extends Model
{
    use HasUuids, SoftDeletes;

    public const TYPE_NEW = 'new';

    public const TYPE_REVIEW = 'review';

    protected $fillable = [
        'school_id',
        'student_id',
        'subject_book_id',
        'academic_year_id',
        'semester',
        'teacher_id',
        'log_date',
        'type',
        'juz',
        'start_page',
        'end_page',
        'pages',
        'material_note',
        'quality_score',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'semester' => 'integer',
            'log_date' => 'date:Y-m-d',
            'juz' => 'integer',
            'start_page' => 'integer',
            'end_page' => 'integer',
            'pages' => 'decimal:1',
            'quality_score' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
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

    public function subjectBook(): BelongsTo
    {
        return $this->belongsTo(SubjectBook::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
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
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tugas (Penilaian): one assignment given to a Kelas × Kitab in one
 * semester akademik. Soft-deleted tasks are excluded from
 * TaskFactorScoreProvider's average and from every listing by default.
 */
class ClassTask extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'school_id',
        'class_level_id',
        'subject_book_id',
        'academic_year_id',
        'semester',
        'title',
        'task_date',
        'description',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'semester' => 'integer',
            'task_date' => 'date:Y-m-d',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }

    public function subjectBook(): BelongsTo
    {
        return $this->belongsTo(SubjectBook::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(StudentTaskScore::class);
    }
}

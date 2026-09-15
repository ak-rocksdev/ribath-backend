<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manually entered grade (Penilaian) for one student, kitab, grading
 * factor and semester akademik. score NULL = belum diinput (never 0).
 */
class StudentGrade extends Model
{
    use HasUuids;

    protected $fillable = [
        'school_id',
        'student_id',
        'subject_book_id',
        'grading_factor_id',
        'academic_year_id',
        'semester',
        'class_level_id',
        'score',
        'scale_level',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'semester' => 'integer',
            'score' => 'decimal:2',
            'scale_level' => 'integer',
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

    public function gradingFactor(): BelongsTo
    {
        return $this->belongsTo(GradingFactor::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
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

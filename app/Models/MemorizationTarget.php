<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Target Hafalan (Tahfidz): the number of Halaman a santri must set orally
 * in one semester akademik. Per ADR 0003, having a non-deleted target for a
 * semester is what makes a santri count as taking Tahfizh that semester —
 * see GradableSubjectService and StudentGradeService::listGradedStudents().
 *
 * One Halaman ("page") is the base unit; PAGES_PER_JUZ converts a Juz input
 * to Halaman for storage (target_pages is always what is persisted).
 */
class MemorizationTarget extends Model
{
    use HasUuids, SoftDeletes;

    /** One juz is always converted to this many Halaman for storage. */
    public const PAGES_PER_JUZ = 20;

    protected $fillable = [
        'school_id',
        'student_id',
        'academic_year_id',
        'semester',
        'target_pages',
        'teacher_id',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'semester' => 'integer',
            'target_pages' => 'decimal:1',
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

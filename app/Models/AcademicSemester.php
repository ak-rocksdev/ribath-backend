<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicSemester extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'school_id',
        'academic_year_id',
        'semester',
        'start_date',
        'end_date',
        'midterm_exam_date',
        'uts_enabled',
    ];

    protected function casts(): array
    {
        return [
            'semester' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'midterm_exam_date' => 'date',
            'uts_enabled' => 'boolean',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Semester akademik is looked up by the (academic_year_id, semester)
     * pair, never referenced by FK (ADR 0002).
     */
    public static function findByPair(string $academicYearId, int $semester): ?self
    {
        return static::where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->first();
    }
}

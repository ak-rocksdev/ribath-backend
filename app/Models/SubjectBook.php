<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubjectBook extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'school_id',
        'subject_category_id',
        'grading_template_id',
        'title',
        'class_levels',
        'semesters',
        'sessions_per_week',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'class_levels' => 'array',
            'semesters' => 'array',
            'sessions_per_week' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function subjectCategory(): BelongsTo
    {
        return $this->belongsTo(SubjectCategory::class);
    }

    public function gradingTemplate(): BelongsTo
    {
        return $this->belongsTo(GradingTemplate::class);
    }

    /**
     * Kitab Tahfizh: any kitab graded with the Tahfizh template (ADR 0003).
     * The one definition, as a query scope here and on a loaded kitab in
     * usesTahfizhTemplate().
     */
    public function scopeTahfizh(Builder $query): Builder
    {
        return $query->whereHas('gradingTemplate', fn (Builder $templateQuery) => $templateQuery->where('code', GradingTemplate::CODE_TAHFIZH));
    }

    /** Whether this kitab is a Kitab Tahfizh (see scopeTahfizh()); expects gradingTemplate to be loaded. */
    public function usesTahfizhTemplate(): bool
    {
        return $this->gradingTemplate?->code === GradingTemplate::CODE_TAHFIZH;
    }

    public function teachingSchedules(): HasMany
    {
        return $this->hasMany(TeachingSchedule::class);
    }

    public function studentGrades(): HasMany
    {
        return $this->hasMany(StudentGrade::class);
    }
}

<?php

namespace App\Models;

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

    public function teachingSchedules(): HasMany
    {
        return $this->hasMany(TeachingSchedule::class);
    }
}

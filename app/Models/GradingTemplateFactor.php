<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradingTemplateFactor extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'school_id',
        'grading_template_id',
        'grading_factor_id',
        'academic_year_id',
        'semester',
        'weight',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'semester' => 'integer',
            'weight' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function gradingTemplate(): BelongsTo
    {
        return $this->belongsTo(GradingTemplate::class);
    }

    public function gradingFactor(): BelongsTo
    {
        return $this->belongsTo(GradingFactor::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}

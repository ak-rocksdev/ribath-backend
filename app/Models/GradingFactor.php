<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradingFactor extends Model
{
    use HasFactory, HasUuids;

    public const INPUT_TYPE_MANUAL_ONCE = 'manual_once';

    public const INPUT_TYPE_MANUAL_PERIODIC = 'manual_periodic';

    public const INPUT_TYPE_END_OF_SEMESTER_BULK = 'end_of_semester_bulk';

    public const INPUT_TYPE_AUTO_FROM_LOG = 'auto_from_log';

    public const INPUT_TYPE_AUTO_FROM_ATTENDANCE = 'auto_from_attendance';

    public const SCORE_SCALE_PERCENT = 'percent';

    public const SCORE_SCALE_LEVEL_1_4 = 'level_1_4';

    protected $fillable = [
        'school_id',
        'code',
        'name',
        'input_type',
        'score_scale',
        'scale_levels',
        'is_midterm_exam',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'scale_levels' => 'array',
            'is_midterm_exam' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function templateFactors(): HasMany
    {
        return $this->hasMany(GradingTemplateFactor::class);
    }
}

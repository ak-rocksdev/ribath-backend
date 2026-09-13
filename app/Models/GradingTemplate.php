<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradingTemplate extends Model
{
    use HasFactory, HasUuids;

    public const CODE_TEORI_KITAB = 'teori_kitab';

    public const CODE_TAHFIZH = 'tahfizh';

    protected $fillable = [
        'school_id',
        'code',
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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

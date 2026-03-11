<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubjectCategory extends Model
{
    use HasFactory;

    // No HasUuids — uses integer auto-increment PK

    protected $fillable = [
        'school_id',
        'slug',
        'name',
        'color',
        'description',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function subjectBooks(): HasMany
    {
        return $this->hasMany(SubjectBook::class);
    }
}

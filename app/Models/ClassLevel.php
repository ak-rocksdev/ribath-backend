<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassLevel extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'school_id',
        'slug',
        'label',
        'category',
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

    /**
     * The `class_level` object every API payload nests: {id, slug, label}.
     *
     * @return array{id: string, slug: string, label: string}
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'label' => $this->label,
        ];
    }

    /**
     * The id of the school's class level with the given slug, or null when
     * the school has no such class level. Students store both the slug
     * (class_level) and this id (class_level_id); Penilaian defines class
     * membership by the id. A student without a school has no class level.
     */
    public static function idForSchoolSlug(?string $schoolId, string $slug): ?string
    {
        if ($schoolId === null) {
            return null;
        }

        return static::where('school_id', $schoolId)
            ->where('slug', $slug)
            ->value('id');
    }
}

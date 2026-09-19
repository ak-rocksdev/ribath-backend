<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /** How a set of Kelas is named together: "Ibtida 2 + Tsanawiyah 1". */
    public const LABEL_SEPARATOR = ' + ';

    /**
     * A Kelas reached through a join table carries its pivot row; nothing
     * reads it, so it stays out of every payload.
     */
    protected $hidden = ['pivot'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The order the Kelas master defines — the one order Kelas are listed,
     * named and labelled in, so "Ibtida 2 + Tsanawiyah 1" reads the same
     * wherever it is printed.
     *
     * @param  Builder<ClassLevel>  $query
     * @return Builder<ClassLevel>
     */
    public function scopeInMasterOrder(Builder $query): Builder
    {
        return $query->orderBy('class_levels.sort_order')->orderBy('class_levels.label');
    }

    /**
     * These Kelas named together, the one way they are joined on screen, in
     * the PDF and in every message: "Ibtida 2 + Tsanawiyah 1", or the single
     * label of an ordinary one-Kelas set.
     *
     * @param  iterable<int, ClassLevel>  $classLevels
     */
    public static function joinedLabel(iterable $classLevels): string
    {
        return collect($classLevels)->pluck('label')->filter()->implode(self::LABEL_SEPARATOR);
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

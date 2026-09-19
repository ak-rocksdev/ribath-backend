<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * One Jadwal Mengajar × Kelas row (ADR 0006). It carries a uuid of its own
 * and the `school_id` every table in this codebase carries, so the
 * `classLevels` relation writes its rows through this model instead of a
 * hand-built insert.
 */
class TeachingScheduleClassLevel extends Pivot
{
    use HasUuids;

    protected $table = 'teaching_schedule_class_levels';

    public $incrementing = false;

    protected $keyType = 'string';
}

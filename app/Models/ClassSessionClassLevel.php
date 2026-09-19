<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * One Pertemuan × Kelas row (ADR 0006): the Kelas a Pertemuan was held for,
 * written once when it is first stored. Like its Jadwal Mengajar twin it
 * carries a uuid and a `school_id`, so the `classLevels` relation writes its
 * rows through this model.
 */
class ClassSessionClassLevel extends Pivot
{
    use HasUuids;

    protected $table = 'class_session_class_levels';

    public $incrementing = false;

    protected $keyType = 'string';
}

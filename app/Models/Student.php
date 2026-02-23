<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    const STATUS_ACTIVE = 'active';
    const STATUS_GRADUATED = 'graduated';
    const STATUS_TRANSFERRED = 'transferred';
    const STATUS_WITHDRAWN = 'withdrawn';

    const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_GRADUATED,
        self::STATUS_TRANSFERRED,
        self::STATUS_WITHDRAWN,
    ];

    const CLASS_LEVELS = [
        'tamhidi',
        'ibtida_1',
        'ibtida_2',
        'tsanawiyah_1',
        'tsanawiyah_2',
        'tahfidz_1',
        'tahfidz_2',
        'tahfidz_3',
        'takhassus_1',
        'takhassus_2',
        'takhassus_3',
    ];

    protected $fillable = [
        'registration_id',
        'guardian_user_id',
        'user_id',
        'full_name',
        'birth_place',
        'birth_date',
        'gender',
        'program',
        'status',
        'entry_date',
        'class_level',
        'address',
        'photo_url',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'entry_date' => 'date',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class, 'registration_id');
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guardian_user_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

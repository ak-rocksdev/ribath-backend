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

    const REQUIRED_PROFILE_FIELDS = [
        'full_name',
        'birth_date',
        'birth_place',
        'gender',
        'program',
        'entry_date',
        'class_level',
        'address',
    ];

    protected $fillable = [
        'school_id',
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
        'class_level_id',
        'address',
        'photo_url',
        'notes',
        'profile_completed_at',
    ];

    protected $appends = [
        'is_profile_complete',
        'incomplete_fields',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'entry_date' => 'date',
            'profile_completed_at' => 'datetime',
        ];
    }

    public function isProfileComplete(): bool
    {
        return empty($this->getIncompleteFields());
    }

    public function getIncompleteFields(): array
    {
        $incompleteFields = [];

        foreach (self::REQUIRED_PROFILE_FIELDS as $field) {
            $value = $this->getAttribute($field);
            if ($value === null || $value === '') {
                $incompleteFields[] = $field;
            }
        }

        return $incompleteFields;
    }

    public function getIsProfileCompleteAttribute(): bool
    {
        return $this->isProfileComplete();
    }

    public function getIncompleteFieldsAttribute(): array
    {
        return $this->getIncompleteFields();
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
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

    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }
}

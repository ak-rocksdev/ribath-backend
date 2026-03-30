<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'nik',
        'email',
        'phone',
        'birth_place',
        'birth_date',
        'gender',
        'child_order',
        'siblings_count',
        'program',
        'status',
        'profile_completion_status',
        'entry_date',
        'class_level',
        'class_level_id',
        'address',
        'photo_url',
        'notes',
        'motivation',
        'family_income_range',
        'profile_completed_at',
        'agreed_to_rules_at',
        'agreed_to_commitment_at',
        'data_verified_at',
    ];

    protected $attributes = [
        'profile_completion_status' => 'incomplete',
    ];

    protected $appends = [
        'is_profile_complete',
        'incomplete_fields',
        'profile_completion_status_label',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'entry_date' => 'date',
            'child_order' => 'integer',
            'siblings_count' => 'integer',
            'profile_completed_at' => 'datetime',
            'agreed_to_rules_at' => 'datetime',
            'agreed_to_commitment_at' => 'datetime',
            'data_verified_at' => 'datetime',
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

    public function getProfileCompletionStatusLabelAttribute(): string
    {
        return match ($this->profile_completion_status) {
            'completed' => 'Lengkap',
            'draft' => 'Draft',
            default => 'Belum Lengkap',
        };
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

    public function parents(): HasMany
    {
        return $this->hasMany(StudentParent::class);
    }

    public function health(): HasOne
    {
        return $this->hasOne(StudentHealth::class);
    }

    public function educationHistory(): HasOne
    {
        return $this->hasOne(StudentEducationHistory::class);
    }

    public function religiousProfile(): HasOne
    {
        return $this->hasOne(StudentReligiousProfile::class);
    }

    public function additionalInfo(): HasOne
    {
        return $this->hasOne(StudentAdditionalInfo::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StudentDocument::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Registration extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public const STATUS_NEW = 'new';

    public const STATUS_CONTACTED = 'contacted';

    public const STATUS_INTERVIEW = 'interview';

    public const STATUS_VISITED = 'visited';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WAITLIST = 'waitlist';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_CONTACTED,
        self::STATUS_INTERVIEW,
        self::STATUS_VISITED,
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
        self::STATUS_WAITLIST,
        self::STATUS_CANCELLED,
    ];

    public const ARCHIVABLE_STATUSES = [
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'school_id',
        'registration_period_id',
        'registration_number',
        'status',
        'registrant_type',
        'full_name',
        'birth_place',
        'birth_date',
        'gender',
        'preferred_program',
        'guardian_name',
        'guardian_phone',
        'guardian_email',
        'info_source',
        'admin_notes',
        'contacted_at',
        'contacted_by',
        'interviewed_at',
        'visited_at',
        'reviewed_at',
        'reviewed_by',
        'rejection_reason',
        'is_archived',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'contacted_at' => 'datetime',
            'interviewed_at' => 'datetime',
            'visited_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'is_archived' => 'boolean',
        ];
    }

    public function canBeArchived(): bool
    {
        return in_array($this->status, self::ARCHIVABLE_STATUSES);
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('is_archived', true);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(RegistrationPeriod::class, 'registration_period_id');
    }

    public function contactedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contacted_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class, 'registration_id');
    }
}

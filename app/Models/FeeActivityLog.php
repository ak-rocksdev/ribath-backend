<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeActivityLog extends Model
{
    use HasFactory, HasUuids;

    const SUBJECT_FEE_TYPE = 'fee_type';

    const SUBJECT_FEE_SCHEDULE = 'fee_schedule';

    const SUBJECT_ASSIGNMENT = 'assignment';

    const SUBJECT_EXCEPTION = 'exception';

    const SUBJECT_BILL = 'bill';

    const SUBJECT_PAYMENT = 'payment';

    const SUBJECTS = [
        self::SUBJECT_FEE_TYPE,
        self::SUBJECT_FEE_SCHEDULE,
        self::SUBJECT_ASSIGNMENT,
        self::SUBJECT_EXCEPTION,
        self::SUBJECT_BILL,
        self::SUBJECT_PAYMENT,
    ];

    const ACTION_CREATED = 'created';

    const ACTION_UPDATED = 'updated';

    const ACTION_DELETED = 'deleted';

    const ACTION_RESTORED = 'restored';

    const ACTION_GENERATED = 'generated';

    const ACTION_REVERSED = 'reversed';

    const ACTIONS = [
        self::ACTION_CREATED,
        self::ACTION_UPDATED,
        self::ACTION_DELETED,
        self::ACTION_RESTORED,
        self::ACTION_GENERATED,
        self::ACTION_REVERSED,
    ];

    const ACTOR_USER = 'user';

    const ACTOR_SCHEDULER = 'scheduler';

    const ACTOR_PSB_HOOK = 'psb_hook';

    const ACTOR_CONSOLE = 'console';

    const ACTOR_SYSTEM = 'system';

    const ACTOR_KINDS = [
        self::ACTOR_USER,
        self::ACTOR_SCHEDULER,
        self::ACTOR_PSB_HOOK,
        self::ACTOR_CONSOLE,
        self::ACTOR_SYSTEM,
    ];

    /**
     * Indonesian label for non-`user` actor kinds. Frontend viewer can
     * call this (or replicate the mapping) to render "Sistem (Penjadwal)"
     * instead of leaving an empty actor cell when actor_id is null.
     */
    const ACTOR_KIND_LABELS = [
        self::ACTOR_USER => 'Pengguna',
        self::ACTOR_SCHEDULER => 'Sistem (Penjadwal)',
        self::ACTOR_PSB_HOOK => 'Sistem (Hook PSB)',
        self::ACTOR_CONSOLE => 'Konsol (manual)',
        self::ACTOR_SYSTEM => 'Sistem',
    ];

    /**
     * Immutable: only `created_at` exists in schema; activity logs are
     * append-only and never edited or deleted by application code.
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'school_id',
        'subject_type',
        'subject_id',
        'action',
        'actor_kind',
        'actor_id',
        'changes',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    /**
     * Renders the actor as a display label. For `user` rows this returns
     * the user name; for system rows it returns the localized actor_kind
     * label (e.g. "Sistem (Penjadwal)") so audit viewers never show a
     * blank actor column when actor_id is null.
     */
    public function actorDisplayLabel(): string
    {
        if ($this->actor_kind === self::ACTOR_USER && $this->actor) {
            return $this->actor->name;
        }

        return self::ACTOR_KIND_LABELS[$this->actor_kind] ?? 'Sistem';
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

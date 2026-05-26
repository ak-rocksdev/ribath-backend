<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashBookActivityLog extends Model
{
    use HasFactory, HasUuids;

    const SUBJECT_ENTRY = 'entry';

    const SUBJECT_CATEGORY = 'category';

    const SUBJECTS = [
        self::SUBJECT_ENTRY,
        self::SUBJECT_CATEGORY,
    ];

    const ACTION_CREATED = 'created';

    const ACTION_UPDATED = 'updated';

    const ACTION_DELETED = 'deleted';

    const ACTION_RESTORED = 'restored';

    // Used by StudentPaymentService::reverse — payment reversal triggers a
    // single audit row per side with semantic meaning, not raw `deleted`.
    const ACTION_REVERSED = 'reversed';

    const ACTIONS = [
        self::ACTION_CREATED,
        self::ACTION_UPDATED,
        self::ACTION_DELETED,
        self::ACTION_RESTORED,
        self::ACTION_REVERSED,
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
        'actor_id',
        'changes',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
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

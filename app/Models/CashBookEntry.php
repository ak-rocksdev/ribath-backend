<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashBookEntry extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    const TYPE_IN = 'in';

    const TYPE_OUT = 'out';

    const TYPES = [
        self::TYPE_IN,
        self::TYPE_OUT,
    ];

    const EAGER_LOAD_RELATIONS = [
        'category:id,name,is_system,is_active',
        'creator:id,name',
        'updater:id,name',
    ];

    protected $fillable = [
        'school_id',
        'transaction_date',
        'type',
        'category_id',
        'description',
        'counterparty',
        'amount',
        'proof_file_path',
        'proof_file_mime',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'amount' => 'integer',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CashBookCategory::class, 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StudentPayment extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    const METHOD_CASH = 'cash';

    const METHOD_TRANSFER = 'transfer';

    const METHOD_OTHER = 'other';

    const METHODS = [
        self::METHOD_CASH,
        self::METHOD_TRANSFER,
        self::METHOD_OTHER,
    ];

    protected $fillable = [
        'school_id',
        'student_id',
        'bill_id',
        'amount',
        'payment_date',
        'payment_method',
        'cash_book_entry_id',
        'proof_file_path',
        'proof_file_mime',
        'notes',
        'confirmed_by',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'payment_date' => 'date',
            'confirmed_at' => 'datetime',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function cashBookEntry(): BelongsTo
    {
        return $this->belongsTo(CashBookEntry::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}

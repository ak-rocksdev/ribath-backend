<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class PsbRegistration extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public const STATUS_BARU = 'baru';
    public const STATUS_DIHUBUNGI = 'dihubungi';
    public const STATUS_INTERVIEW = 'interview';
    public const STATUS_DITERIMA = 'diterima';
    public const STATUS_DITOLAK = 'ditolak';
    public const STATUS_WAITLIST = 'waitlist';
    public const STATUS_BATAL = 'batal';

    public const STATUSES = [
        self::STATUS_BARU,
        self::STATUS_DIHUBUNGI,
        self::STATUS_INTERVIEW,
        self::STATUS_DITERIMA,
        self::STATUS_DITOLAK,
        self::STATUS_WAITLIST,
        self::STATUS_BATAL,
    ];

    protected $fillable = [
        'psb_period_id',
        'registration_number',
        'status',
        'registrant_type',
        'nama_lengkap',
        'tempat_lahir',
        'tanggal_lahir',
        'jenis_kelamin',
        'program_minat',
        'nama_wali',
        'no_hp_wali',
        'email_wali',
        'sumber_info',
        'admin_notes',
        'contacted_at',
        'contacted_by',
        'interviewed_at',
        'reviewed_at',
        'reviewed_by',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_lahir' => 'date',
            'contacted_at' => 'datetime',
            'interviewed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PsbPeriod::class, 'psb_period_id');
    }

    public function contactedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contacted_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function santri(): HasOne
    {
        return $this->hasOne(Santri::class, 'psb_registration_id');
    }
}

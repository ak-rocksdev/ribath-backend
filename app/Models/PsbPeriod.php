<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PsbPeriod extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'year',
        'gelombang',
        'pendaftaran_buka',
        'pendaftaran_tutup',
        'tanggal_masuk',
        'biaya_pendaftaran',
        'biaya_spp_bulanan',
        'kuota_santri',
        'kuota_terisi',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'pendaftaran_buka' => 'datetime',
            'pendaftaran_tutup' => 'datetime',
            'tanggal_masuk' => 'date',
            'biaya_pendaftaran' => 'decimal:2',
            'biaya_spp_bulanan' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(PsbRegistration::class);
    }
}

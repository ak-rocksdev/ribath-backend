<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RegistrationPeriod extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'school_id',
        'name',
        'year',
        'wave',
        'registration_open',
        'registration_close',
        'entry_date',
        'registration_fee',
        'monthly_tuition_fee',
        'student_quota',
        'enrolled_count',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'registration_open' => 'datetime',
            'registration_close' => 'datetime',
            'entry_date' => 'date',
            'registration_fee' => 'decimal:2',
            'monthly_tuition_fee' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class, 'registration_period_id');
    }
}

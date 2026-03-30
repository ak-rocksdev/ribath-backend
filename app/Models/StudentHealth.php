<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentHealth extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'student_health';

    protected $fillable = [
        'student_id',
        'blood_type',
        'disease_history',
        'allergies',
        'special_conditions',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}

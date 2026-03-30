<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentParent extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'student_id',
        'relation',
        'name',
        'occupation',
        'email',
        'phone',
        'address',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}

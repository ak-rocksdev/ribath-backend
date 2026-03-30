<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentEducationHistory extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'student_education_history';

    protected $fillable = [
        'student_id',
        'last_school_name',
        'last_education_level',
        'graduation_year',
        'achievements',
    ];

    protected function casts(): array
    {
        return [
            'graduation_year' => 'integer',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}

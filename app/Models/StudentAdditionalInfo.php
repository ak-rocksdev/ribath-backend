<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentAdditionalInfo extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'student_additional_info';

    protected $fillable = [
        'student_id',
        'hobbies_talents',
        'extracurricular_interests',
        'post_graduation_hopes',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentReligiousProfile extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'student_religious_profile';

    protected $fillable = [
        'student_id',
        'quran_reading_ability',
        'memorized_juz',
        'has_pesantren_experience',
        'previous_pesantren_name',
        'other_skills',
    ];

    protected function casts(): array
    {
        return [
            'memorized_juz' => 'integer',
            'has_pesantren_experience' => 'boolean',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}

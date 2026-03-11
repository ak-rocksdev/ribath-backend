<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeachingSchedule extends Model
{
    use HasFactory, HasUuids;

    const DAYS_OF_WEEK = [
        'monday', 'tuesday', 'wednesday', 'thursday',
        'friday', 'saturday', 'sunday',
    ];

    const EAGER_LOAD_RELATIONS = [
        'subjectBook:id,title,subject_category_id,sessions_per_week',
        'subjectBook.subjectCategory:id,name,color',
        'teacher:id,full_name,code',
        'timeSlot:id,code,label,type,start_time,end_time,sort_order',
        'classLevel:id,slug,label,category',
        'academicYear:id,name',
    ];

    protected $fillable = [
        'school_id',
        'academic_year_id',
        'semester',
        'day_of_week',
        'time_slot_id',
        'class_level_id',
        'subject_book_id',
        'teacher_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'semester' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function timeSlot(): BelongsTo
    {
        return $this->belongsTo(TimeSlot::class);
    }

    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }

    public function subjectBook(): BelongsTo
    {
        return $this->belongsTo(SubjectBook::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}

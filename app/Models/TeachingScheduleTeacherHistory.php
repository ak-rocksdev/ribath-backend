<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of the riwayat pengajar (ADR 0005): the Ustadz, Kelas and Kitab
 * a Jadwal Mengajar had before one change of any of them, in the schedule's
 * Semester Akademik. Written only by TeachingScheduleService; read by
 * TeachingScopeResolver (Cakupan Mengajar) and GradableSubjectService.
 */
class TeachingScheduleTeacherHistory extends Model
{
    use HasUuids;

    /** Append-only: the change time is the row's only timestamp. */
    const CREATED_AT = 'changed_at';

    const UPDATED_AT = null;

    protected $fillable = [
        'school_id',
        'teaching_schedule_id',
        'academic_year_id',
        'semester',
        'previous_teacher_id',
        'previous_class_level_id',
        'previous_subject_book_id',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'semester' => 'integer',
            'changed_by' => 'integer',
            'changed_at' => 'datetime',
        ];
    }

    public function teachingSchedule(): BelongsTo
    {
        return $this->belongsTo(TeachingSchedule::class);
    }

    public function previousTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'previous_teacher_id');
    }

    public function previousClassLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class, 'previous_class_level_id');
    }

    public function previousSubjectBook(): BelongsTo
    {
        return $this->belongsTo(SubjectBook::class, 'previous_subject_book_id');
    }
}

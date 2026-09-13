<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's score for one class_task (Tugas). score NULL = belum dinilai
 * (never coalesced to 0).
 */
class StudentTaskScore extends Model
{
    use HasUuids;

    protected $fillable = [
        'school_id',
        'class_task_id',
        'student_id',
        'score',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function classTask(): BelongsTo
    {
        return $this->belongsTo(ClassTask::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

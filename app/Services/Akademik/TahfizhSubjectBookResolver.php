<?php

namespace App\Services\Akademik;

use App\Models\School;
use App\Models\SubjectBook;

/**
 * The active school's Tahfizh kitab (SubjectBook::scopeTahfizh()) that
 * Target Hafalan and Log Setoran attach to, or null if the school has
 * none yet. With more than one, the oldest (created_at, then id), so the
 * answer never depends on the order the database returns rows in.
 * One place for this lookup, shared by GradableSubjectService (ADR 0003
 * target-derived pairs) and Tahfidz\MemorizationLogService (Log Setoran
 * dan Murajaah's subject_book_id is always resolved server-side, never
 * taken from client input).
 */
class TahfizhSubjectBookResolver
{
    public function resolve(): ?SubjectBook
    {
        return SubjectBook::query()
            ->where('school_id', School::activeOrFail()->id)
            ->tahfizh()
            ->with('gradingTemplate:id,code,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }
}

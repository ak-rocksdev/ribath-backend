<?php

namespace App\Services\Akademik;

use App\Models\GradingTemplate;
use App\Models\School;
use App\Models\SubjectBook;
use Illuminate\Database\Eloquent\Builder;

/**
 * The active school's single Tahfizh kitab — the subject_books row whose
 * grading template code is `tahfizh` — or null if the school has none yet.
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
            ->whereHas('gradingTemplate', fn (Builder $query) => $query->where('code', GradingTemplate::CODE_TAHFIZH))
            ->with('gradingTemplate:id,code,name')
            ->first();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One frozen kitab of a finalized Rapor (ADR 0001). `breakdown` is the
 * kitab's per-santri recap subject row at finalization (see
 * ReportCardService for its shape); `final_score` repeats its final score
 * as a column so it can be queried and printed without decoding JSON.
 */
class ReportCardEntry extends Model
{
    use HasUuids;

    protected $fillable = [
        'school_id',
        'report_card_id',
        'subject_book_id',
        'final_score',
        'breakdown',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'final_score' => 'decimal:2',
            'breakdown' => 'array',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function reportCard(): BelongsTo
    {
        return $this->belongsTo(ReportCard::class);
    }

    public function subjectBook(): BelongsTo
    {
        return $this->belongsTo(SubjectBook::class);
    }
}

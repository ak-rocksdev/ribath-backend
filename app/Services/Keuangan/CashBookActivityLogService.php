<?php

namespace App\Services\Keuangan;

use App\Models\CashBookActivityLog;
use App\Models\School;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CashBookActivityLogService
{
    public function listActivityLogs(array $filters): LengthAwarePaginator
    {
        $school = School::activeOrFail();

        $query = CashBookActivityLog::query()
            ->where('school_id', $school->id)
            ->with('actor:id,name')
            ->orderByDesc('created_at');

        if (! empty($filters['subject_type'])) {
            $query->where('subject_type', $filters['subject_type']);
        }

        if (! empty($filters['subject_id'])) {
            $query->where('subject_id', $filters['subject_id']);
        }

        if (! empty($filters['actor_id'])) {
            $query->where('actor_id', $filters['actor_id']);
        }

        if (! empty($filters['start_date'])) {
            $query->whereDate('created_at', '>=', $filters['start_date']);
        }

        if (! empty($filters['end_date'])) {
            $query->whereDate('created_at', '<=', $filters['end_date']);
        }

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));

        return $query->paginate($perPage);
    }
}

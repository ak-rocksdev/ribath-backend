<?php

namespace App\Services\Keuangan;

use App\Models\CashBookEntry;
use App\Models\School;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class CashBookEntryService
{
    public function __construct(
        private CashBookProofStorageService $proofStorage
    ) {}

    public function listEntries(array $filters): LengthAwarePaginator
    {
        $school = School::activeOrFail();

        $query = CashBookEntry::query()
            ->where('school_id', $school->id)
            ->with(CashBookEntry::EAGER_LOAD_RELATIONS)
            ->orderByDesc('transaction_date')
            ->orderByDesc('created_at');

        if (! empty($filters['start_date'])) {
            $query->whereDate('transaction_date', '>=', $filters['start_date']);
        }

        if (! empty($filters['end_date'])) {
            $query->whereDate('transaction_date', '<=', $filters['end_date']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));

        return $query->paginate($perPage);
    }

    public function summaryEntries(array $filters): array
    {
        $school = School::activeOrFail();

        $base = CashBookEntry::query()->where('school_id', $school->id);

        $saldoTotalNow = (int) $base->clone()
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'in' THEN amount ELSE 0 END), 0)
                        - COALESCE(SUM(CASE WHEN type = 'out' THEN amount ELSE 0 END), 0) as saldo")
            ->value('saldo');

        $rangeQuery = $base->clone();

        if (! empty($filters['start_date'])) {
            $rangeQuery->whereDate('transaction_date', '>=', $filters['start_date']);
        }

        if (! empty($filters['end_date'])) {
            $rangeQuery->whereDate('transaction_date', '<=', $filters['end_date']);
        }

        if (! empty($filters['type'])) {
            $rangeQuery->where('type', $filters['type']);
        }

        if (! empty($filters['category_id'])) {
            $rangeQuery->where('category_id', $filters['category_id']);
        }

        $rangeAggregate = $rangeQuery
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'in' THEN amount ELSE 0 END), 0) as total_in,
                         COALESCE(SUM(CASE WHEN type = 'out' THEN amount ELSE 0 END), 0) as total_out")
            ->first();

        $totalIn = (int) ($rangeAggregate->total_in ?? 0);
        $totalOut = (int) ($rangeAggregate->total_out ?? 0);

        return [
            'saldo_total_now' => $saldoTotalNow,
            'total_in_range' => $totalIn,
            'total_out_range' => $totalOut,
            'net_in_range' => $totalIn - $totalOut,
            'range' => [
                'start_date' => $filters['start_date'] ?? null,
                'end_date' => $filters['end_date'] ?? null,
            ],
        ];
    }

    public function createEntry(array $data, ?UploadedFile $proof, int $userId): CashBookEntry
    {
        $school = School::activeOrFail();

        $entry = DB::transaction(function () use ($data, $proof, $userId, $school) {
            $entry = CashBookEntry::create([
                'school_id' => $school->id,
                'transaction_date' => $data['transaction_date'],
                'type' => $data['type'],
                'category_id' => $data['category_id'],
                'description' => $data['description'],
                'counterparty' => $data['counterparty'] ?? null,
                'amount' => $data['amount'],
                'created_by' => $userId,
            ]);

            if ($proof !== null) {
                $stored = $this->proofStorage->store($proof, $school->id, $entry->id);
                $entry->update([
                    'proof_file_path' => $stored['path'],
                    'proof_file_mime' => $stored['mime'],
                ]);
            }

            return $entry;
        });

        return $entry->fresh()->load(CashBookEntry::EAGER_LOAD_RELATIONS);
    }

    public function updateEntry(
        CashBookEntry $entry,
        array $data,
        ?UploadedFile $proof,
        bool $removeProof,
        int $userId
    ): CashBookEntry {
        $school = School::activeOrFail();
        $oldProofPath = null;

        DB::transaction(function () use ($entry, $data, $proof, $removeProof, $userId, $school, &$oldProofPath) {
            $newValues = [];

            foreach (['transaction_date', 'type', 'category_id', 'description', 'counterparty', 'amount'] as $field) {
                if (array_key_exists($field, $data)) {
                    $newValues[$field] = $data[$field];
                }
            }

            if ($proof !== null) {
                $stored = $this->proofStorage->store($proof, $school->id, $entry->id);
                $newValues['proof_file_path'] = $stored['path'];
                $newValues['proof_file_mime'] = $stored['mime'];
            } elseif ($removeProof) {
                $newValues['proof_file_path'] = null;
                $newValues['proof_file_mime'] = null;
            }

            if (empty($newValues)) {
                return;
            }

            // Fill in-memory, then check isDirty() to skip no-op saves. Without
            // this gate, a PATCH that submits already-current values would bump
            // updated_by + updated_at without any audit log diff to explain why.
            $entry->fill($newValues);

            if ($entry->isDirty()) {
                $oldProofPath = $entry->getOriginal('proof_file_path');
                $entry->updated_by = $userId;
                $entry->save();
            }
        });

        // Delete old file outside the DB transaction so that a storage failure
        // does not roll back successful database changes (and vice versa).
        if ($oldProofPath !== null && $oldProofPath !== $entry->proof_file_path) {
            $this->proofStorage->delete($oldProofPath);
        }

        return $entry->fresh()->load(CashBookEntry::EAGER_LOAD_RELATIONS);
    }

    public function deleteEntry(CashBookEntry $entry): void
    {
        $entry->delete();
    }
}

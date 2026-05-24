<?php

namespace App\Services\Keuangan;

use App\Models\CashBookEntry;
use App\Models\School;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB as DBFacade;

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
        $bindings = ['school_id' => $school->id];

        // Build dynamic range clause inline to keep saldo_total_now (all-time) and
        // total_*_range (filtered) in a single round-trip.
        $rangeClauses = ['1=1'];
        if (! empty($filters['start_date'])) {
            $rangeClauses[] = 'transaction_date >= :start_date';
            $bindings['start_date'] = $filters['start_date'];
        }
        if (! empty($filters['end_date'])) {
            $rangeClauses[] = 'transaction_date <= :end_date';
            $bindings['end_date'] = $filters['end_date'];
        }
        if (! empty($filters['type'])) {
            $rangeClauses[] = 'type = :type';
            $bindings['type'] = $filters['type'];
        }
        if (! empty($filters['category_id'])) {
            $rangeClauses[] = 'category_id = :category_id';
            $bindings['category_id'] = $filters['category_id'];
        }
        $rangeWhere = implode(' AND ', $rangeClauses);

        $row = DBFacade::selectOne(
            "SELECT
                COALESCE(SUM(CASE WHEN type = 'in' THEN amount ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN type = 'out' THEN amount ELSE 0 END), 0) AS saldo_total_now,
                COALESCE(SUM(CASE WHEN type = 'in' AND ({$rangeWhere}) THEN amount ELSE 0 END), 0) AS total_in,
                COALESCE(SUM(CASE WHEN type = 'out' AND ({$rangeWhere}) THEN amount ELSE 0 END), 0) AS total_out
             FROM cash_book_entries
             WHERE school_id = :school_id AND deleted_at IS NULL",
            $bindings
        );

        $totalIn = (int) ($row->total_in ?? 0);
        $totalOut = (int) ($row->total_out ?? 0);

        return [
            'saldo_total_now' => (int) ($row->saldo_total_now ?? 0),
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

        $entry = DBFacade::transaction(function () use ($data, $proof, $userId, $school) {
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

        DBFacade::transaction(function () use ($entry, $data, $proof, $removeProof, $userId, $school, &$oldProofPath) {
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

<?php

namespace App\Services\Keuangan;

use App\Models\Bill;
use App\Models\CashBookActivityLog;
use App\Models\CashBookEntry;
use App\Models\FeeActivityLog;
use App\Models\School;
use App\Models\StudentPayment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class StudentPaymentService
{
    public function __construct(
        private PaymentProofStorageService $proofStorage,
    ) {}

    // Records a single payment + atomically creates the cash_book_entry that
    // mirrors it (FR-029 + FR-030: one-way FK from payment → entry). Returns
    // the persisted payment with cash_book_entry_id set.
    //
    // Proof file upload happens AFTER commit (outside txn) — DB integrity
    // is the source of truth; if the file write fails the payment + entry
    // are still consistent and admin can re-upload via update.
    public function record(array $data, ?UploadedFile $proof, int $userId): StudentPayment
    {
        $school = School::activeOrFail();

        /** @var StudentPayment $payment */
        $payment = DB::transaction(function () use ($data, $school, $userId) {
            // Eager load assignment.feeType + student so the lazy-load
            // round trips don't extend the lockForUpdate window.
            $bill = Bill::query()
                ->with(['assignment.feeType', 'student'])
                ->where('school_id', $school->id)
                ->where('id', $data['bill_id'])
                ->lockForUpdate()
                ->first();

            if ($bill === null) {
                abort(404, 'Tagihan tidak ditemukan.');
            }
            if ($bill->status === Bill::STATUS_WAIVED) {
                abort(422, 'Tagihan beasiswa penuh tidak menerima pembayaran.');
            }

            $remaining = (int) $bill->expected_amount - (int) $bill->paid_amount;
            $amount = (int) $data['amount'];
            if ($amount > $remaining) {
                abort(422, "Jumlah pembayaran melebihi sisa tagihan (sisa: Rp {$remaining}).");
            }

            $assignment = $bill->assignment;
            $feeType = $assignment?->feeType;
            if ($feeType === null) {
                abort(422, 'Jenis biaya untuk tagihan tidak ditemukan.');
            }
            if ($feeType->cash_book_category_id === null) {
                abort(422, 'Jenis biaya belum dikonfigurasi kategori Buku Kas (jalankan setup di Master Jenis Biaya).');
            }

            // 1. Create cash_book_entries first so we have the FK for payment.
            $description = sprintf(
                '%s — %s (%s)',
                $feeType->label,
                $bill->student?->full_name ?? '—',
                $bill->billing_period_start?->format('M Y') ?? ''
            );

            $entry = CashBookEntry::create([
                'school_id' => $school->id,
                'transaction_date' => $data['payment_date'],
                'type' => 'in',
                'category_id' => $feeType->cash_book_category_id,
                'description' => $description,
                'counterparty' => $bill->student?->full_name,
                'amount' => $amount,
                'created_by' => $userId,
            ]);

            $payment = StudentPayment::create([
                'school_id' => $school->id,
                'student_id' => $bill->student_id,
                'bill_id' => $bill->id,
                'amount' => $amount,
                'payment_date' => $data['payment_date'],
                'payment_method' => $data['payment_method'],
                'cash_book_entry_id' => $entry->id,
                'notes' => $data['notes'] ?? null,
                'confirmed_by' => $userId,
                'confirmed_at' => now(),
            ]);

            $this->applyPaymentToBill($bill, $amount);

            return $payment;
        });

        if ($proof !== null) {
            $stored = $this->proofStorage->store($proof, $school->id, $payment->id);
            $payment->update([
                'proof_file_path' => $stored['path'],
                'proof_file_mime' => $stored['mime'],
            ]);
        }

        return $payment->fresh(['cashBookEntry']);
    }

    // Atomic batch: validates all items first, then runs each through ::record
    // in a single transaction. If any fails, rolls back everything and returns
    // a per-index error map so the UI can highlight the bad rows.
    public function bulkRecord(array $items, int $userId): array
    {
        $errors = [];
        $payments = [];

        DB::transaction(function () use ($items, $userId, &$errors, &$payments) {
            foreach ($items as $index => $item) {
                try {
                    $payments[$index] = $this->record($item, null, $userId);
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                    $errors[$index] = $e->getMessage();
                }
            }

            if (! empty($errors)) {
                abort(422, json_encode(['bulk_errors' => $errors]));
            }
        });

        return ['payments' => $payments, 'errors' => $errors];
    }

    // Reverses a payment by soft-deleting both the payment AND the linked
    // cash_book_entry, then decrementing the bill's paid_amount and recomputing
    // its status. Uses withoutEvents on both sides to suppress observer-fired
    // `deleted` log rows — the service writes ONE semantic `reversed` row per
    // side instead (spec Clarifications ronde 2 Q2).
    public function reverse(StudentPayment $payment, int $userId, ?string $reason = null): void
    {
        DB::transaction(function () use ($payment, $userId, $reason) {
            // Lock the payment row first to serialize concurrent reversal
            // requests on the same payment. Without this, two simultaneous
            // DELETE requests both pass route binding, both decrement the
            // bill, and paid_amount goes negative (PG CHECK constraint
            // crashes mid-transaction).
            $locked = StudentPayment::query()->lockForUpdate()->find($payment->id);
            if ($locked === null || $locked->trashed()) {
                abort(409, 'Pembayaran sudah dibatalkan sebelumnya.');
            }

            $originalAmount = (int) $locked->amount;
            $entry = $locked->cashBookEntry;
            $proofPath = $locked->proof_file_path;

            StudentPayment::withoutEvents(fn () => $locked->delete());
            if ($entry !== null) {
                CashBookEntry::withoutEvents(fn () => $entry->delete());
            }

            $bill = $locked->bill()->lockForUpdate()->first();
            if ($bill !== null) {
                $this->applyPaymentToBill($bill, -$originalAmount);
            }

            FeeActivityLog::create([
                'school_id' => $locked->school_id,
                'subject_type' => FeeActivityLog::SUBJECT_PAYMENT,
                'subject_id' => $locked->id,
                'action' => FeeActivityLog::ACTION_REVERSED,
                'actor_kind' => FeeActivityLog::ACTOR_USER,
                'actor_id' => $userId,
                'changes' => ['original_amount' => $originalAmount, 'reason' => $reason],
            ]);

            if ($entry !== null) {
                CashBookActivityLog::create([
                    'school_id' => $entry->school_id,
                    'subject_type' => CashBookActivityLog::SUBJECT_ENTRY,
                    'subject_id' => $entry->id,
                    'action' => CashBookActivityLog::ACTION_REVERSED,
                    'actor_id' => $userId,
                    'changes' => ['original_amount' => $originalAmount, 'reason' => $reason],
                ]);
            }

            // Drop proof file from disk — soft-deleted payment cannot be
            // restored via API, so the file is orphaned anyway. Storage is
            // not part of the DB transaction; if the unlink fails the txn
            // commits regardless (file integrity is best-effort).
            if ($proofPath !== null) {
                $this->proofStorage->delete($proofPath);
            }
        });
    }

    // Adjusts bill.paid_amount by $delta (positive for record, negative for
    // reverse) and recomputes status. Caller is responsible for holding the
    // bill row lock if running concurrent payments on the same bill.
    private function applyPaymentToBill(Bill $bill, int $delta): void
    {
        $newPaid = max(0, (int) $bill->paid_amount + $delta);
        $newStatus = $this->statusForPaidAmount($bill->expected_amount, $newPaid, $bill->status);

        $bill->update([
            'paid_amount' => $newPaid,
            'status' => $newStatus,
        ]);
    }

    private function statusForPaidAmount(int $expected, int $paid, string $currentStatus): string
    {
        // Waived stays waived — admin must un-waive explicitly if they want to bill again.
        if ($currentStatus === Bill::STATUS_WAIVED) {
            return Bill::STATUS_WAIVED;
        }
        if ($paid <= 0) {
            return Bill::STATUS_PENDING;
        }
        if ($paid >= $expected) {
            return Bill::STATUS_PAID;
        }

        return Bill::STATUS_PARTIAL;
    }
}

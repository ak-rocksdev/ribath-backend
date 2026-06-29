<?php

namespace App\Http\Requests\Keuangan;

use App\Models\School;
use App\Models\StudentPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentPaymentRequest extends FormRequest
{
    private const PROOF_MIMES = 'image/jpeg,image/png,image/webp,application/pdf';

    private const PROOF_MAX_KB = 5120; // 5 MB

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $school = School::activeOrFail();

        return [
            'bill_id' => [
                'required', 'uuid',
                Rule::exists('bills', 'id')->where('school_id', $school->id)->whereNull('deleted_at'),
            ],
            'amount' => ['required', 'integer', 'min:1'],
            'payment_date' => [
                'required', 'date',
                // Use explicit Asia/Jakarta today; the plain `today` token uses
                // app TZ (UTC) and breaks during 00:00–07:00 WIB per memory.
                'before_or_equal:'.now('Asia/Jakarta')->toDateString(),
            ],
            'payment_method' => ['required', 'string', Rule::in(StudentPayment::METHODS)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'proof' => ['nullable', 'file', 'mimetypes:'.self::PROOF_MIMES, 'max:'.self::PROOF_MAX_KB],
        ];
    }

    public function messages(): array
    {
        return [
            'bill_id.required' => 'Tagihan wajib dipilih.',
            'bill_id.exists' => 'Tagihan tidak ditemukan untuk pesantren ini.',
            'amount.required' => 'Jumlah pembayaran wajib diisi.',
            'amount.min' => 'Jumlah pembayaran harus lebih dari 0.',
            'payment_date.required' => 'Tanggal pembayaran wajib diisi.',
            'payment_date.before_or_equal' => 'Tanggal pembayaran tidak boleh di masa depan.',
            'payment_method.in' => 'Metode pembayaran tidak valid (gunakan tunai/transfer/lainnya).',
            'proof.mimetypes' => 'Bukti harus berformat JPG/PNG/WebP/PDF.',
            'proof.max' => 'Bukti maksimal 5 MB.',
        ];
    }
}

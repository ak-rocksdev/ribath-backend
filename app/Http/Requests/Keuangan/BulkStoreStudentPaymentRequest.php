<?php

namespace App\Http\Requests\Keuangan;

use App\Models\School;
use App\Models\StudentPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkStoreStudentPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $school = School::activeOrFail();

        return [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.bill_id' => [
                'required', 'uuid',
                Rule::exists('bills', 'id')->where('school_id', $school->id)->whereNull('deleted_at'),
            ],
            'items.*.amount' => ['required', 'integer', 'min:1'],
            'items.*.payment_date' => [
                'required', 'date',
                'before_or_equal:'.now('Asia/Jakarta')->toDateString(),
            ],
            'items.*.payment_method' => ['required', 'string', Rule::in(StudentPayment::METHODS)],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Minimal 1 pembayaran harus dipilih.',
            'items.max' => 'Maksimal 100 pembayaran per batch.',
            'items.*.bill_id.exists' => 'Tagihan tidak ditemukan untuk pesantren ini.',
            'items.*.amount.min' => 'Jumlah pembayaran harus lebih dari 0.',
            'items.*.payment_date.before_or_equal' => 'Tanggal pembayaran tidak boleh di masa depan.',
            'items.*.payment_method.in' => 'Metode pembayaran tidak valid.',
        ];
    }
}

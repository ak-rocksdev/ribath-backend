<?php

namespace App\Http\Requests\Keuangan;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFeeExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // kind is immutable post-create — reject any attempt to change it.
        return [
            'kind' => ['prohibited'],
            'discount_amount' => ['sometimes', 'integer', 'min:1'],
            'reason' => ['sometimes', 'string', 'max:500'],
            'effective_from' => ['sometimes', 'date'],
            // Resolve the comparison target against the existing row when
            // effective_from isn't part of this PATCH — otherwise
            // `after_or_equal:effective_from` references an absent field,
            // passes vacuously, and the DB CHECK throws a raw 500.
            'effective_until' => ['nullable', 'date', 'after_or_equal:'.$this->resolveEffectiveFrom()],
        ];
    }

    public function messages(): array
    {
        return [
            'kind.prohibited' => 'Jenis pengecualian tidak dapat diubah. Hapus lalu buat baru.',
            'discount_amount.min' => 'Nominal potongan harus lebih dari 0.',
            'reason.max' => 'Alasan maksimal 500 karakter.',
            'effective_until.after_or_equal' => 'Tanggal selesai harus sama atau setelah tanggal mulai.',
        ];
    }

    private function resolveEffectiveFrom(): string
    {
        if ($this->filled('effective_from')) {
            return $this->input('effective_from');
        }

        $existing = $this->route('exception');

        return $existing?->effective_from?->toDateString() ?? '1970-01-01';
    }
}

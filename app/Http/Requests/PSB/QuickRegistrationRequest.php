<?php

namespace App\Http\Requests\PSB;

use Illuminate\Foundation\Http\FormRequest;

class QuickRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'registrant_type' => ['required', 'string', 'in:wali,santri'],
            'nama_lengkap' => ['required', 'string', 'min:3', 'max:100'],
            'tempat_lahir' => ['nullable', 'string', 'max:100'],
            'tanggal_lahir' => ['required', 'date', 'before:today'],
            'jenis_kelamin' => ['required', 'string', 'in:L,P'],
            'program_minat' => ['required', 'string', 'in:tahfidz,regular'],
            'nama_wali' => ['required_if:registrant_type,wali', 'nullable', 'string', 'min:3', 'max:100'],
            'no_hp_wali' => ['required', 'string', 'regex:/^(\+62|62|0)8[1-9][0-9]{7,10}$/'],
            'email_wali' => ['nullable', 'email', 'max:255'],
            'sumber_info' => ['nullable', 'string', 'in:sosial_media,website,teman_keluarga,alumni,masjid,brosur,google,lainnya'],
        ];
    }
}

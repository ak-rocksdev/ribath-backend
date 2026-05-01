<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class UploadSchoolLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'logo' => [
                'required',
                File::image()
                    ->types(['png', 'jpeg', 'jpg', 'webp'])
                    ->max(2 * 1024) // 2 MB
                    ->dimensions(
                        Rule::dimensions()
                            ->minWidth(256)
                            ->minHeight(256),
                    ),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'logo.required'   => 'Logo wajib diunggah.',
            'logo.image'      => 'File harus berupa gambar.',
            'logo.mimes'      => 'Format yang didukung: PNG, JPEG, WebP.',
            'logo.max'        => 'Ukuran file maksimal 2 MB.',
            'logo.dimensions' => 'Resolusi minimal 256×256 piksel.',
        ];
    }
}

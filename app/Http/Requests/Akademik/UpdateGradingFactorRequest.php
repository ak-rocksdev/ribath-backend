<?php

namespace App\Http\Requests\Akademik;

use App\Models\GradingFactor;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateGradingFactorRequest extends FormRequest
{
    use EnsuresActiveSchoolTenancy;

    /**
     * Tenancy is enforced here (before rules()/withValidator() read the
     * route-bound factor's score_scale) so a foreign school's factor is
     * uniformly 404, never leaked through a 422-vs-200 difference.
     */
    public function authorize(): bool
    {
        $gradingFactor = $this->route('gradingFactor');

        if ($gradingFactor) {
            $this->ensureBelongsToActiveSchool($gradingFactor);
        }

        return true;
    }

    public function rules(): array
    {
        $rules = [
            'name' => ['sometimes', 'string', 'max:100'],
        ];

        if ($this->has('scale_levels')) {
            $rules['scale_levels'] = ['array', 'size:4'];
            $rules['scale_levels.*.level'] = ['required', 'integer', 'between:1,4'];
            $rules['scale_levels.*.label'] = ['required', 'string', 'max:255'];
            $rules['scale_levels.*.description'] = ['required', 'string'];
            $rules['scale_levels.*.score'] = ['required', 'numeric', 'min:0', 'max:100'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.string' => 'Nama harus berupa teks.',
            'name.max' => 'Nama maksimal 100 karakter.',
            'scale_levels.array' => 'Skala level harus berupa daftar.',
            'scale_levels.size' => 'Skala level harus terdiri dari 4 level.',
            'scale_levels.*.level.required' => 'Level wajib diisi.',
            'scale_levels.*.level.between' => 'Level harus antara 1 dan 4.',
            'scale_levels.*.label.required' => 'Label level wajib diisi.',
            'scale_levels.*.description.required' => 'Deskripsi level wajib diisi.',
            'scale_levels.*.score.required' => 'Skor level wajib diisi.',
            'scale_levels.*.score.min' => 'Skor level minimal 0.',
            'scale_levels.*.score.max' => 'Skor level maksimal 100.',
        ];
    }

    /**
     * scale_levels only applies to level_1_4 factors, and must cover
     * exactly levels 1-4 (no duplicates, none missing) — checked here
     * rather than in rules() because it depends on both the submitted
     * array's content and the route-bound factor's score_scale.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->has('scale_levels')) {
                return;
            }

            $gradingFactor = $this->route('gradingFactor');

            if ($gradingFactor instanceof GradingFactor && $gradingFactor->score_scale !== GradingFactor::SCORE_SCALE_LEVEL_1_4) {
                $validator->errors()->add('scale_levels', 'Skala level hanya berlaku untuk faktor dengan skala level_1_4.');

                return;
            }

            $submittedLevels = $this->input('scale_levels');

            if (! is_array($submittedLevels)) {
                return;
            }

            $levels = collect($submittedLevels)
                ->pluck('level')
                ->filter(fn ($level) => $level !== null)
                ->map(fn ($level) => (int) $level)
                ->sort()
                ->values()
                ->all();

            if ($levels !== [1, 2, 3, 4]) {
                $validator->errors()->add('scale_levels', 'Skala level harus terdiri dari level 1 sampai 4, masing-masing tepat satu kali.');
            }
        });
    }
}

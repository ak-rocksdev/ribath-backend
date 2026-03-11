<?php

namespace App\Http\Requests\Admin;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTimeSlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:30', 'regex:/^[a-z0-9_:\-]+$/',
                Rule::unique('time_slots')->where('school_id', School::activeOrFail()->id),
            ],
            'label' => ['required', 'string', 'max:50'],
            'type' => ['required', 'string', 'in:prayer_based,fixed_clock'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Code must contain only lowercase letters, numbers, underscores, colons, and hyphens.',
            'end_time.after' => 'End time must be after start time.',
        ];
    }
}

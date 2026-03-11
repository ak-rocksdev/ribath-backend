<?php

namespace App\Http\Requests\Admin;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTimeSlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => [
                'sometimes', 'string', 'max:30', 'regex:/^[a-z0-9_:\-]+$/',
                Rule::unique('time_slots')
                    ->where('school_id', School::activeOrFail()->id)
                    ->ignore($this->route('timeSlot')?->id),
            ],
            'label' => ['sometimes', 'string', 'max:50'],
            'type' => ['sometimes', 'string', 'in:prayer_based,fixed_clock'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', function ($attribute, $value, $fail) {
                $startTime = $this->input('start_time', $this->route('timeSlot')?->start_time);
                if ($startTime && $value && $value <= $startTime) {
                    $fail('The end time must be after the start time.');
                }
            }],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Code must contain only lowercase letters, numbers, underscores, colons, and hyphens.',
        ];
    }
}

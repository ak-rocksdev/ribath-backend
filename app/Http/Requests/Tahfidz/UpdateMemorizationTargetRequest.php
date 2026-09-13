<?php

namespace App\Http\Requests\Tahfidz;

use App\Models\School;
use App\Models\Teacher;
use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /memorization-targets/{memorizationTarget}. Only target_pages/
 * target_juz, teacher_id and notes can change — the student, semester
 * akademik and school a target belongs to are fixed at creation. Tenancy is
 * checked here (authorize()), before the service can act on a foreign row.
 */
class UpdateMemorizationTargetRequest extends FormRequest
{
    use EnsuresActiveSchoolTenancy;

    public function authorize(): bool
    {
        $memorizationTarget = $this->route('memorizationTarget');

        if ($memorizationTarget) {
            $this->ensureBelongsToActiveSchool($memorizationTarget);
        }

        return true;
    }

    public function rules(): array
    {
        $school = School::activeOrFail();

        return [
            'target_pages' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:604'],
            'target_juz' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:30'],
            'teacher_id' => [
                'sometimes',
                'required',
                'uuid',
                Rule::exists('teachers', 'id')
                    ->where('school_id', $school->id)
                    ->where('status', Teacher::STATUS_ACTIVE),
            ],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'target_pages.numeric' => 'Target halaman harus berupa angka.',
            'target_pages.gt' => 'Target halaman harus lebih dari 0.',
            'target_pages.max' => 'Target halaman maksimal 604 halaman (satu mushaf).',
            'target_juz.numeric' => 'Target juz harus berupa angka.',
            'target_juz.gt' => 'Target juz harus lebih dari 0.',
            'target_juz.max' => 'Target juz maksimal 30.',
            'teacher_id.required' => 'Ustadz wajib dipilih.',
            'teacher_id.uuid' => 'Ustadz tidak valid.',
            'teacher_id.exists' => 'Ustadz tidak ditemukan atau tidak berstatus aktif untuk pesantren ini.',
            'notes.string' => 'Catatan tidak valid.',
        ];
    }
}

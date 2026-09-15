<?php

namespace App\Http\Requests\Akademik;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Structural validation of POST /class-tasks. The class × kitab pair being
 * gradable, the semester being configured, and the task_date falling
 * within the semester's dates are checked in ClassTaskService::create()
 * so the errors reuse the grid's message constants.
 */
class StoreClassTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge(StudentGradeGridRules::gridSelectionRules(School::activeOrFail()), [
            'title' => ['required', 'string', 'max:150'],
            'task_date' => ['required', 'date'],
            'description' => ['nullable', 'string'],
        ]);
    }

    public function messages(): array
    {
        return array_merge(StudentGradeGridRules::gridSelectionMessages(), [
            'title.required' => 'Judul tugas wajib diisi.',
            'title.max' => 'Judul tugas maksimal 150 karakter.',
            'task_date.required' => 'Tanggal tugas wajib diisi.',
            'task_date.date' => 'Tanggal tugas tidak valid.',
            'description.string' => 'Deskripsi tidak valid.',
        ]);
    }
}

<?php

namespace App\Http\Requests\Akademik;

use App\Traits\EnsuresActiveSchoolTenancy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /class-tasks/{classTask}. Only title, task_date and description can
 * be changed — the class × kitab × semester a task belongs to is fixed at
 * creation. Tenancy is checked here, before ClassTaskService::update() can
 * read the task's own academic_semesters row for the date-range check.
 */
class UpdateClassTaskRequest extends FormRequest
{
    use EnsuresActiveSchoolTenancy;

    public function authorize(): bool
    {
        $classTask = $this->route('classTask');

        if ($classTask) {
            $this->ensureBelongsToActiveSchool($classTask);
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:150'],
            'task_date' => ['sometimes', 'required', 'date'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Judul tugas wajib diisi.',
            'title.max' => 'Judul tugas maksimal 150 karakter.',
            'task_date.required' => 'Tanggal tugas wajib diisi.',
            'task_date.date' => 'Tanggal tugas tidak valid.',
            'description.string' => 'Deskripsi tidak valid.',
        ];
    }
}

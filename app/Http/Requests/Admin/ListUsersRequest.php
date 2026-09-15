<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /users?search&role&is_active&page&per_page — the Akun Pengguna list.
 */
class ListUsersRequest extends FormRequest
{
    public const MAX_PER_PAGE = 100;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Query strings carry "true"/"false"; turn them into booleans so the
     * boolean rule accepts them. An empty value means no status filter;
     * anything else stays as sent and fails.
     */
    protected function prepareForValidation(): void
    {
        $requestedStatus = $this->query('is_active');

        if ($requestedStatus === null || $requestedStatus === '') {
            return;
        }

        $isActive = filter_var($requestedStatus, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($isActive !== null) {
            $this->merge(['is_active' => $isActive]);
        }
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'exists:roles,name'],
            'is_active' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function messages(): array
    {
        return [
            'search.max' => 'Kata pencarian maksimal 100 karakter.',
            'role.exists' => 'Peran tidak ditemukan.',
            'is_active.boolean' => 'Status harus aktif atau nonaktif.',
            'page.integer' => 'Halaman harus berupa angka.',
            'page.min' => 'Halaman minimal 1.',
            'per_page.integer' => 'Jumlah per halaman harus berupa angka.',
            'per_page.min' => 'Jumlah per halaman minimal 1.',
            'per_page.max' => 'Jumlah per halaman maksimal '.self::MAX_PER_PAGE.'.',
        ];
    }
}

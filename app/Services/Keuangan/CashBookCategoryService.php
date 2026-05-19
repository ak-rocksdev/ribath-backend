<?php

namespace App\Services\Keuangan;

use App\Models\CashBookCategory;
use App\Models\School;
use Illuminate\Database\Eloquent\Collection;

class CashBookCategoryService
{
    public function listCategories(array $filters): Collection
    {
        $school = School::activeOrFail();

        $query = CashBookCategory::query()
            ->where('school_id', $school->id)
            ->withCount('entries')
            ->orderBy('is_system', 'desc')
            ->orderBy('name');

        // Default to active-only unless caller explicitly opts in to include inactive.
        $includeInactive = array_key_exists('is_active', $filters)
            && in_array($filters['is_active'], ['false', '0', 0, false], true);

        if (! $includeInactive) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    public function createCategory(array $data): CashBookCategory
    {
        $school = School::activeOrFail();

        return CashBookCategory::create([
            'school_id' => $school->id,
            'name' => $data['name'],
            'is_system' => false,
            'is_active' => true,
        ]);
    }

    public function updateCategory(CashBookCategory $category, array $data): CashBookCategory
    {
        $attributes = [];

        if (array_key_exists('name', $data)) {
            $attributes['name'] = $data['name'];
        }

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        if (! empty($attributes)) {
            $category->fill($attributes);
            if ($category->isDirty()) {
                $category->save();
            }
        }

        return $category->fresh();
    }

    public function deleteCategory(CashBookCategory $category): void
    {
        $category->delete();
    }
}

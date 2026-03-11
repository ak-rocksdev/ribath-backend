<?php

namespace App\Services;

use App\Exceptions\HasDependentsException;
use App\Models\School;
use App\Models\SubjectCategory;
use Illuminate\Database\Eloquent\Collection;

class SubjectCategoryService
{
    public function listActive(): Collection
    {
        $school = School::activeOrFail();

        $query = SubjectCategory::where('school_id', $school->id)
            ->where('is_active', true)
            ->orderBy('sort_order');

        if (class_exists(\App\Models\SubjectBook::class)) {
            $query->withCount('subjectBooks');
        }

        return $query->get();
    }

    public function listForAdmin(): Collection
    {
        $school = School::activeOrFail();

        $query = SubjectCategory::where('school_id', $school->id)
            ->orderBy('sort_order');

        if (class_exists(\App\Models\SubjectBook::class)) {
            $query->withCount('subjectBooks');
        }

        return $query->get();
    }

    public function createCategory(array $data): SubjectCategory
    {
        $school = School::activeOrFail();

        $data['school_id'] = $school->id;

        return SubjectCategory::create($data);
    }

    public function updateCategory(SubjectCategory $subjectCategory, array $data): SubjectCategory
    {
        $subjectCategory->update($data);

        return $subjectCategory->fresh();
    }

    public function deleteCategory(SubjectCategory $subjectCategory): void
    {
        if (class_exists(\App\Models\SubjectBook::class)) {
            if ($subjectCategory->subjectBooks()->exists()) {
                throw new HasDependentsException(
                    'Cannot delete subject category with existing books'
                );
            }
        }

        $subjectCategory->delete();
    }
}

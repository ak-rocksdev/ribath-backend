<?php

namespace App\Services;

use App\Models\ClassLevel;
use App\Models\School;
use App\Models\Student;

class ClassLevelService
{
    public function listAllClassLevels(): array
    {
        $classLevels = ClassLevel::orderBy('sort_order')
            ->get(['id', 'slug', 'label', 'category', 'sort_order', 'is_active']);

        $studentCountsByClassLevel = Student::whereNull('deleted_at')
            ->whereNotNull('class_level')
            ->selectRaw('class_level, count(*) as student_count')
            ->groupBy('class_level')
            ->pluck('student_count', 'class_level');

        return $classLevels->map(function (ClassLevel $classLevel) use ($studentCountsByClassLevel) {
            return [
                'id' => $classLevel->id,
                'slug' => $classLevel->slug,
                'label' => $classLevel->label,
                'category' => $classLevel->category,
                'sort_order' => $classLevel->sort_order,
                'is_active' => $classLevel->is_active,
                'student_count' => $studentCountsByClassLevel[$classLevel->slug] ?? 0,
            ];
        })->toArray();
    }

    public function createClassLevel(array $data): ClassLevel
    {
        $defaultSchool = School::where('is_active', true)->first();

        if (! $defaultSchool) {
            throw new \RuntimeException('No active school found. Please run: php artisan db:seed --class=SchoolSeeder');
        }

        $maxSortOrder = ClassLevel::max('sort_order') ?? 0;

        return ClassLevel::create([
            'school_id' => $defaultSchool->id,
            'slug' => $data['slug'],
            'label' => $data['label'],
            'category' => $data['category'],
            'sort_order' => $data['sort_order'] ?? ($maxSortOrder + 1),
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    public function updateClassLevel(ClassLevel $classLevel, array $data): ClassLevel
    {
        $classLevel->update($data);

        return $classLevel->fresh();
    }

    public function deleteClassLevel(ClassLevel $classLevel): bool
    {
        $studentCount = Student::where('class_level', $classLevel->slug)
            ->whereNull('deleted_at')
            ->count();

        if ($studentCount > 0) {
            return false;
        }

        $classLevel->delete();

        return true;
    }

    public function toggleStatus(ClassLevel $classLevel, bool $isActive): ClassLevel
    {
        $classLevel->update(['is_active' => $isActive]);

        return $classLevel->fresh();
    }

    public function reorderClassLevels(array $orderedIds): void
    {
        foreach ($orderedIds as $index => $id) {
            ClassLevel::where('id', $id)->update(['sort_order' => $index + 1]);
        }
    }
}

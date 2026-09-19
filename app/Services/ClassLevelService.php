<?php

namespace App\Services;

use App\Models\ClassLevel;
use App\Models\ClassSession;
use App\Models\School;
use App\Models\Student;
use App\Models\TeachingSchedule;
use Illuminate\Support\Facades\DB;

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
        $school = School::activeOrFail();

        $maxSortOrder = ClassLevel::max('sort_order') ?? 0;

        return ClassLevel::create([
            'school_id' => $school->id,
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

    public const MESSAGE_CLASS_LEVEL_HAS_STUDENTS = 'Kelas ini masih memiliki santri. Pindahkan santrinya lebih dulu.';

    public const MESSAGE_CLASS_LEVEL_ON_SCHEDULE = 'Kelas ini masih dipakai jadwal mengajar. Keluarkan kelas dari jadwal tersebut lebih dulu.';

    public const MESSAGE_CLASS_LEVEL_ON_CLASS_SESSION = 'Kelas ini masih dipakai pertemuan yang sudah tercatat.';

    /**
     * Deletes the Kelas, or says why it cannot go. Its santri, its Jadwal
     * Mengajar and its Pertemuan all keep it: the join table of a schedule
     * cascades, so deleting a Kelas would silently drop it out of every
     * jadwal gabungan that holds it (ADR 0006), and a Pertemuan already
     * recorded is history that must stay readable.
     *
     * @return string|null the Indonesian reason it was kept, or null when it was deleted
     */
    public function deleteClassLevel(ClassLevel $classLevel): ?string
    {
        $studentCount = Student::where('class_level', $classLevel->slug)
            ->whereNull('deleted_at')
            ->count();

        if ($studentCount > 0) {
            return self::MESSAGE_CLASS_LEVEL_HAS_STUDENTS;
        }

        $isOnSchedule = TeachingSchedule::query()
            ->whereHas('classLevels', fn ($classLevels) => $classLevels->where('class_levels.id', $classLevel->id))
            ->exists();

        if ($isOnSchedule) {
            return self::MESSAGE_CLASS_LEVEL_ON_SCHEDULE;
        }

        $isOnClassSession = ClassSession::withTrashed()
            ->where(fn ($query) => $query
                ->where('class_level_id', $classLevel->id)
                ->orWhereHas('classLevels', fn ($classLevels) => $classLevels->where('class_levels.id', $classLevel->id)))
            ->exists();

        if ($isOnClassSession) {
            return self::MESSAGE_CLASS_LEVEL_ON_CLASS_SESSION;
        }

        $classLevel->delete();

        return null;
    }

    public function toggleStatus(ClassLevel $classLevel, bool $isActive): ClassLevel
    {
        $classLevel->update(['is_active' => $isActive]);

        return $classLevel->fresh();
    }

    public function reorderClassLevels(array $orderedIds): void
    {
        $school = School::activeOrFail();

        DB::transaction(function () use ($orderedIds, $school) {
            foreach ($orderedIds as $index => $id) {
                ClassLevel::where('id', $id)
                    ->where('school_id', $school->id)
                    ->update(['sort_order' => $index + 1]);
            }
        });
    }
}

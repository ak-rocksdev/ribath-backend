<?php

namespace App\Services\Tahfidz;

use App\Models\AcademicSemester;
use App\Models\MemorizationTarget;
use App\Models\School;
use App\Services\Akademik\StudentGradeService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Target Hafalan (Tahfidz): CRUD of the per-student, per-semester Halaman
 * target that — per ADR 0003 — is what makes a santri count as taking
 * Tahfizh in that semester (GradableSubjectService,
 * StudentGradeService::listGradedStudents()).
 *
 * Uniqueness (one non-deleted target per student per semester) is enforced
 * here, not by a full DB unique constraint (the table is soft-deletable —
 * R5): the migration only adds a PARTIAL unique index (WHERE deleted_at IS
 * NULL) as a safety net; this service is what turns a violation into a
 * friendly 422 keyed by student_id.
 */
class MemorizationTargetService
{
    public const MESSAGE_DUPLICATE_TARGET = 'Santri ini sudah memiliki Target Hafalan semester ini.';

    public const DEFAULT_PER_PAGE = 15;

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(string $academicYearId, int $semester, ?string $classLevelId = null, ?string $search = null, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $school = School::activeOrFail();

        $paginator = MemorizationTarget::query()
            ->where('school_id', $school->id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->with(['student:id,full_name,class_level_id,status', 'student.classLevel:id,slug,label', 'teacher:id,full_name', 'updater:id,name'])
            ->when($classLevelId, fn ($query) => $query->whereHas(
                'student',
                fn ($studentQuery) => $studentQuery->where('class_level_id', $classLevelId)
            ))
            ->when($search, fn ($query) => $query->whereHas(
                'student',
                fn ($studentQuery) => $studentQuery->where('full_name', 'like', '%'.$search.'%')
            ))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $paginator->getCollection()->transform(fn (MemorizationTarget $target) => $this->present($target));

        return $paginator;
    }

    /**
     * @param  array{academic_year_id: string, semester: int, student_id: string, target_pages?: float|int|string|null, target_juz?: float|int|string|null, teacher_id: string, notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): array
    {
        $school = School::activeOrFail();
        $academicYearId = $data['academic_year_id'];
        $semester = (int) $data['semester'];

        $this->assertSemesterIsConfigured($academicYearId, $semester);
        $this->assertNoExistingTarget($data['student_id'], $academicYearId, $semester);

        $target = MemorizationTarget::create([
            'school_id' => $school->id,
            'student_id' => $data['student_id'],
            'academic_year_id' => $academicYearId,
            'semester' => $semester,
            'target_pages' => $this->resolveTargetPages($data),
            'teacher_id' => $data['teacher_id'],
            'notes' => $data['notes'] ?? null,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        return $this->present($target->fresh(['student.classLevel', 'teacher', 'updater']));
    }

    /**
     * Only target_pages/target_juz, teacher_id and notes can be changed —
     * the student, semester akademik and school a target belongs to are
     * fixed at creation (same convention as ClassTask).
     *
     * @param  array{target_pages?: float|int|string|null, target_juz?: float|int|string|null, teacher_id?: string, notes?: string|null}  $data
     */
    public function update(MemorizationTarget $target, array $data): array
    {
        $target->fill([
            'target_pages' => $this->hasTargetInput($data) ? $this->resolveTargetPages($data) : $target->target_pages,
            'teacher_id' => $data['teacher_id'] ?? $target->teacher_id,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $target->notes,
        ]);

        if ($target->isDirty()) {
            $target->updated_by = auth()->id();
            $target->save();
        }

        return $this->present($target->fresh(['student.classLevel', 'teacher', 'updater']));
    }

    /**
     * Soft-deletes the target. Allowed even if the student already has
     * Tahfizh grades that semester — the grades remain (audit trail), the
     * student simply drops out of the Tahfizh roster from now on.
     */
    public function delete(MemorizationTarget $target): void
    {
        $target->updated_by = auth()->id();
        $target->save();
        $target->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(MemorizationTarget $target): array
    {
        $targetPages = (float) $target->target_pages;
        $classLevel = $target->student?->classLevel;

        return [
            'id' => $target->id,
            'student' => [
                'id' => $target->student?->id,
                'full_name' => $target->student?->full_name,
                'class_level' => $classLevel ? [
                    'id' => $classLevel->id,
                    'slug' => $classLevel->slug,
                    'label' => $classLevel->label,
                ] : null,
            ],
            'academic_year_id' => $target->academic_year_id,
            'semester' => $target->semester,
            'target_pages' => $targetPages,
            'target_juz' => round($targetPages / MemorizationTarget::PAGES_PER_JUZ, 2),
            'teacher' => [
                'id' => $target->teacher?->id,
                'full_name' => $target->teacher?->full_name,
            ],
            'notes' => $target->notes,
            'created_by' => $target->created_by,
            'updated_by' => $target->updated_by,
            'updated_by_name' => $target->updater?->name,
            'created_at' => $target->created_at?->toJSON(),
            'updated_at' => $target->updated_at?->toJSON(),
        ];
    }

    private function hasTargetInput(array $data): bool
    {
        return (array_key_exists('target_pages', $data) && $data['target_pages'] !== null)
            || (array_key_exists('target_juz', $data) && $data['target_juz'] !== null);
    }

    /**
     * target_pages wins when both are sent; target_juz is converted at
     * MemorizationTarget::PAGES_PER_JUZ pages per juz, rounded to 1 decimal.
     */
    private function resolveTargetPages(array $data): float
    {
        if (array_key_exists('target_pages', $data) && $data['target_pages'] !== null) {
            return round((float) $data['target_pages'], 1);
        }

        return round(((float) $data['target_juz']) * MemorizationTarget::PAGES_PER_JUZ, 1);
    }

    /**
     * @throws ValidationException
     */
    private function assertSemesterIsConfigured(string $academicYearId, int $semester): void
    {
        if (AcademicSemester::findByPair($academicYearId, $semester) === null) {
            throw ValidationException::withMessages(['semester' => StudentGradeService::MESSAGE_SEMESTER_NOT_CONFIGURED]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertNoExistingTarget(string $studentId, string $academicYearId, int $semester): void
    {
        $exists = MemorizationTarget::query()
            ->where('student_id', $studentId)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['student_id' => self::MESSAGE_DUPLICATE_TARGET]);
        }
    }
}

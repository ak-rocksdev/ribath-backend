<?php

namespace App\Services\Keuangan;

use App\Models\AcademicYear;
use App\Models\FeeActivityLog;
use App\Models\FeeSchedule;
use App\Models\FeeType;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFeeAssignment;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class StudentFeeAssignmentService
{
    // Hook invoked from PsbService::acceptRegistration() inside the same
    // DB transaction. Writes the audit log explicitly with actor_kind=psb_hook
    // because auth() at this point is the admin user — observer would otherwise
    // attribute the snapshot to them, which is wrong per spec (system actor).
    public function snapshotForStudent(Student $student): array
    {
        $activeAy = $this->resolveActiveAcademicYearOrFail($student->school_id);

        $schedules = FeeSchedule::query()
            ->with('feeType')
            ->where('school_id', $student->school_id)
            ->where('academic_year_id', $activeAy->id)
            ->whereHas('feeType', fn ($q) => $q->where('is_active', true))
            ->get();

        $created = 0;
        $skipped = 0;

        StudentFeeAssignment::withoutEvents(function () use ($student, $schedules, $activeAy, &$created, &$skipped) {
            foreach ($schedules as $schedule) {
                if ($schedule->feeType === null) {
                    $skipped++;

                    continue;
                }

                $assignment = StudentFeeAssignment::create([
                    'school_id' => $student->school_id,
                    'student_id' => $student->id,
                    'fee_type_id' => $schedule->fee_type_id,
                    'locked_amount' => $schedule->amount,
                    'cadence' => $schedule->feeType->default_cadence,
                    'source_academic_year_id' => $activeAy->id,
                    'source' => StudentFeeAssignment::SOURCE_AUTO_ENROLLMENT,
                    'created_by' => null,
                ]);

                $this->writeSystemAuditLog($assignment, FeeActivityLog::ACTOR_PSB_HOOK);
                $created++;
            }
        });

        return [
            'created_count' => $created,
            'skipped_count' => $skipped,
            'academic_year' => $activeAy,
        ];
    }

    // Admin picks AY referensi — idempotent: skip fee_types already assigned to
    // this student. Audit log fires via observer (auth() = admin user).
    public function manualSnapshotByAcademicYear(Student $student, AcademicYear $sourceAy): array
    {
        $this->assertSchoolMatch($sourceAy->school_id, $student->school_id, 'Tahun ajaran tidak ditemukan untuk pesantren ini.');

        $schedules = FeeSchedule::query()
            ->with('feeType')
            ->where('school_id', $student->school_id)
            ->where('academic_year_id', $sourceAy->id)
            ->whereHas('feeType', fn ($q) => $q->where('is_active', true))
            ->get();

        if ($schedules->isEmpty()) {
            abort(422, 'Tidak ada tarif tersimpan untuk tahun ajaran ini. Pilih tahun ajaran lain atau gunakan mode "Input Manual".');
        }

        // DB::transaction so a concurrent admin doing the same snapshot can't
        // leave partial rows on a unique-violation crash mid-loop. Both racers
        // serialize through the txn; loser hits the unique on a single row
        // and the whole batch rolls back instead of half-committing.
        return DB::transaction(function () use ($student, $sourceAy, $schedules) {
            $existingFeeTypeIds = StudentFeeAssignment::query()
                ->where('student_id', $student->id)
                ->pluck('fee_type_id')
                ->all();

            $created = 0;
            $skipped = 0;

            foreach ($schedules as $schedule) {
                if (in_array($schedule->fee_type_id, $existingFeeTypeIds, true) || $schedule->feeType === null) {
                    $skipped++;

                    continue;
                }

                StudentFeeAssignment::create([
                    'school_id' => $student->school_id,
                    'student_id' => $student->id,
                    'fee_type_id' => $schedule->fee_type_id,
                    'locked_amount' => $schedule->amount,
                    'cadence' => $schedule->feeType->default_cadence,
                    'source_academic_year_id' => $sourceAy->id,
                    'source' => StudentFeeAssignment::SOURCE_MANUAL_SNAPSHOT,
                    'created_by' => auth()->id(),
                ]);
                $created++;
            }

            return ['created_count' => $created, 'skipped_count' => $skipped];
        });
    }

    // Admin inputs nominal+cadence per fee_type for legacy AY tarifnya tidak ada.
    // Idempotent: skip items whose fee_type already has assignment for this student.
    public function setManualEntries(Student $student, AcademicYear $sourceAy, array $items): array
    {
        $this->assertSchoolMatch($sourceAy->school_id, $student->school_id, 'Tahun ajaran tidak ditemukan untuk pesantren ini.');

        $existingFeeTypeIds = StudentFeeAssignment::query()
            ->where('student_id', $student->id)
            ->pluck('fee_type_id')
            ->all();

        $created = 0;
        $skipped = 0;

        foreach ($items as $item) {
            $feeType = FeeType::query()
                ->where('id', $item['fee_type_id'])
                ->where('school_id', $student->school_id)
                ->first();

            if ($feeType === null) {
                abort(422, 'Jenis biaya tidak ditemukan untuk pesantren ini.');
            }

            if (in_array($feeType->id, $existingFeeTypeIds, true)) {
                $skipped++;

                continue;
            }

            StudentFeeAssignment::create([
                'school_id' => $student->school_id,
                'student_id' => $student->id,
                'fee_type_id' => $feeType->id,
                'locked_amount' => (int) $item['locked_amount'],
                'cadence' => $item['cadence'],
                'source_academic_year_id' => $sourceAy->id,
                'source' => StudentFeeAssignment::SOURCE_MANUAL_ENTRY,
                'created_by' => auth()->id(),
            ]);
            $created++;
        }

        return ['created_count' => $created, 'skipped_count' => $skipped];
    }

    public function listForStudent(Student $student): Collection
    {
        return StudentFeeAssignment::query()
            ->with(['feeType', 'sourceAcademicYear', 'exceptions'])
            ->where('student_id', $student->id)
            ->orderBy('created_at')
            ->get();
    }

    // Active students di school yang belum punya assignment apapun — powers the
    // banner + per-row badge on /santri (R10 in spec).
    public function listUnassignedStudents(int $perPage = 25): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $school = School::activeOrFail();

        return $this->unassignedStudentsQuery($school->id)
            ->orderBy('full_name')
            ->paginate($perPage);
    }

    public function countUnassignedStudents(): int
    {
        $school = School::activeOrFail();

        return $this->unassignedStudentsQuery($school->id)->count();
    }

    private function unassignedStudentsQuery(string $schoolId)
    {
        return Student::query()
            ->where('school_id', $schoolId)
            ->where('status', Student::STATUS_ACTIVE)
            ->whereDoesntHave('feeAssignments');
    }

    private function resolveActiveAcademicYearOrFail(string $schoolId): AcademicYear
    {
        $activeAys = AcademicYear::query()
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->get();

        if ($activeAys->count() !== 1) {
            throw new DomainException(
                'Tidak dapat memproses penerimaan: tahun ajaran aktif tidak valid (harus tepat 1 AY berstatus aktif).'
            );
        }

        return $activeAys->first();
    }

    private function assertSchoolMatch(string $resourceSchoolId, string $studentSchoolId, string $message): void
    {
        if ($resourceSchoolId !== $studentSchoolId) {
            abort(422, $message);
        }
    }

    private function writeSystemAuditLog(StudentFeeAssignment $assignment, string $actorKind): void
    {
        FeeActivityLog::create([
            'school_id' => $assignment->school_id,
            'subject_type' => FeeActivityLog::SUBJECT_ASSIGNMENT,
            'subject_id' => $assignment->id,
            'action' => FeeActivityLog::ACTION_CREATED,
            'actor_kind' => $actorKind,
            'actor_id' => null,
            'changes' => null,
        ]);
    }
}

<?php

namespace App\Services\Keuangan;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\FeeActivityLog;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFeeAssignment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BillGeneratorService
{
    public function __construct(
        private EffectiveAmountCalculator $effectiveAmountCalculator,
    ) {}

    // Generate bills for all active santri × eligible fee_type for the
    // given reference month. Idempotent — re-running on the same period
    // produces zero duplicates thanks to DB unique constraint on
    // (assignment_id, billing_period_start). Per-santri transactional so
    // one santri's failure doesn't kill the batch.
    //
    // $periodMonth: 'YYYY-MM' (defaults to current month in Asia/Jakarta).
    // $cadenceFilter: 'all' | one of the cadence enum values.
    // $actorKind: who triggered this — 'scheduler' (cron), 'console' (artisan),
    //             'user' (manual API POST). Drives audit log attribution.
    public function generate(
        ?string $periodMonth = null,
        string $cadenceFilter = 'all',
        string $actorKind = FeeActivityLog::ACTOR_SCHEDULER,
        ?int $actorId = null,
    ): array {
        $startedAt = microtime(true);
        $school = School::activeOrFail();
        $reference = $periodMonth !== null
            ? Carbon::createFromFormat('Y-m', $periodMonth, 'Asia/Jakarta')->startOfMonth()
            : Carbon::now('Asia/Jakarta')->startOfMonth();

        $assignments = StudentFeeAssignment::query()
            ->with(['student', 'feeType'])
            ->where('school_id', $school->id)
            ->whereHas('student', fn ($q) => $q->where('status', Student::STATUS_ACTIVE))
            ->get();

        $stats = [
            'period' => $reference->format('Y-m'),
            'cadence_filter' => $cadenceFilter,
            'students_processed' => 0,
            'bills_created' => 0,
            'bills_skipped_existing' => 0,
            'bills_skipped_cadence' => 0,
            'bills_failed' => 0,
        ];

        $processedStudents = [];

        foreach ($assignments as $assignment) {
            if ($assignment->feeType === null) {
                continue;
            }
            $cadence = $assignment->cadence;

            if (! $this->cadenceMatchesFilter($cadence, $cadenceFilter)) {
                $stats['bills_skipped_cadence']++;

                continue;
            }

            if (! $this->shouldGenerate($cadence, $reference, $assignment, $actorKind)) {
                $stats['bills_skipped_cadence']++;

                continue;
            }

            try {
                $created = DB::transaction(function () use ($assignment, $cadence, $reference) {
                    $period = $this->periodFromCadence($cadence, $reference);

                    $effectiveAmount = $this->effectiveAmountCalculator->compute(
                        $assignment,
                        Carbon::parse($period['start'], 'Asia/Jakarta')
                    );
                    $status = $effectiveAmount === 0 ? Bill::STATUS_WAIVED : Bill::STATUS_PENDING;

                    // Bill::withoutEvents avoids per-bill observer logs in batch mode
                    // — the batch summary FeeActivityLog row below captures the run.
                    return Bill::withoutEvents(function () use ($assignment, $cadence, $period, $effectiveAmount, $status) {
                        // Manual exists-check instead of firstOrCreate to sidestep
                        // the date-cast roundtrip mismatch (string '2026-05-01'
                        // vs cast 'datetime' '2026-05-01 00:00:00').
                        $exists = Bill::query()
                            ->where('student_fee_assignment_id', $assignment->id)
                            ->whereDate('billing_period_start', $period['start'])
                            ->exists();

                        if ($exists) {
                            return false;
                        }

                        Bill::create([
                            'school_id' => $assignment->school_id,
                            'student_id' => $assignment->student_id,
                            'student_fee_assignment_id' => $assignment->id,
                            'billing_period_start' => $period['start'],
                            'billing_period_end' => $period['end'],
                            'cadence_at_generation' => $cadence,
                            'expected_amount' => $effectiveAmount,
                            'paid_amount' => 0,
                            'status' => $status,
                            'due_date' => $period['due_date'],
                        ]);

                        return true;
                    });
                });

                if ($created) {
                    $stats['bills_created']++;
                } else {
                    $stats['bills_skipped_existing']++;
                }
                $processedStudents[$assignment->student_id] = true;
            } catch (\Throwable $e) {
                $stats['bills_failed']++;
                Log::error('BillGenerator: failed to generate bill', [
                    'assignment_id' => $assignment->id,
                    'student_id' => $assignment->student_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        $stats['students_processed'] = count($processedStudents);
        $stats['duration_ms'] = (int) ((microtime(true) - $startedAt) * 1000);

        FeeActivityLog::create([
            'school_id' => $school->id,
            'subject_type' => FeeActivityLog::SUBJECT_BILL,
            'subject_id' => (string) Str::uuid(), // placeholder — batch summary not tied to single bill
            'action' => FeeActivityLog::ACTION_GENERATED,
            'actor_kind' => $actorKind,
            'actor_id' => $actorKind === FeeActivityLog::ACTOR_USER ? $actorId : null,
            'changes' => $stats,
        ]);

        return $stats;
    }

    // For a given cadence, return [start, end, due_date] as YYYY-MM-DD strings.
    // Due-date offsets per spec Clarifications Q5 (monthly→tgl 10, etc).
    public function periodFromCadence(string $cadence, Carbon $reference): array
    {
        $ref = $reference->copy()->setTimezone('Asia/Jakarta');

        return match ($cadence) {
            'monthly' => [
                'start' => $ref->copy()->startOfMonth()->toDateString(),
                'end' => $ref->copy()->endOfMonth()->toDateString(),
                'due_date' => $ref->copy()->startOfMonth()->addDays(9)->toDateString(), // tgl 10
            ],
            'quarterly' => [
                'start' => $ref->copy()->firstOfQuarter()->toDateString(),
                'end' => $ref->copy()->lastOfQuarter()->toDateString(),
                'due_date' => $ref->copy()->firstOfQuarter()->addDays(10)->toDateString(),
            ],
            'semesterly' => $this->semesterPeriod($ref),
            'yearly' => [
                'start' => $ref->copy()->startOfYear()->toDateString(),
                'end' => $ref->copy()->endOfYear()->toDateString(),
                'due_date' => $ref->copy()->startOfYear()->addDays(30)->toDateString(),
            ],
            'once_at_enrollment' => [
                // Not generated by scheduler; included only when admin triggers
                // manually. Period == entry month (single bill ever).
                'start' => $ref->copy()->startOfMonth()->toDateString(),
                'end' => $ref->copy()->endOfMonth()->toDateString(),
                'due_date' => $ref->copy()->startOfMonth()->addDays(30)->toDateString(),
            ],
            default => throw new \InvalidArgumentException("Unknown cadence: {$cadence}"),
        };
    }

    private function semesterPeriod(Carbon $ref): array
    {
        // Akademik convention: Sem 1 = Jul-Dec, Sem 2 = Jan-Jun.
        if ($ref->month >= 7) {
            $start = Carbon::create($ref->year, 7, 1, 0, 0, 0, 'Asia/Jakarta');
            $end = Carbon::create($ref->year, 12, 31, 0, 0, 0, 'Asia/Jakarta');
        } else {
            $start = Carbon::create($ref->year, 1, 1, 0, 0, 0, 'Asia/Jakarta');
            $end = Carbon::create($ref->year, 6, 30, 0, 0, 0, 'Asia/Jakarta');
        }

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'due_date' => $start->copy()->addDays(30)->toDateString(),
        ];
    }

    public function cadenceMatchesFilter(string $cadence, string $filter): bool
    {
        return $filter === 'all' || $filter === $cadence;
    }

    // Returns true if a bill should be generated for this cadence given
    // the reference month and actor context.
    //
    // - monthly/quarterly/semesterly/yearly: idempotent re-runs (existing
    //   exists() check at write time handles dedup)
    // - once_at_enrollment: scheduler is blocked entirely (enrollment bill
    //   is created at PSB approval time via future hook, not by daily cron).
    //   User-triggered (manual API POST) or console (artisan) may still
    //   generate it once per assignment.
    public function shouldGenerate(
        string $cadence,
        Carbon $reference,
        StudentFeeAssignment $assignment,
        string $actorKind,
    ): bool {
        if ($cadence === 'once_at_enrollment') {
            if ($actorKind === FeeActivityLog::ACTOR_SCHEDULER) {
                return false;
            }

            return ! Bill::query()
                ->where('student_fee_assignment_id', $assignment->id)
                ->exists();
        }

        return true;
    }
}

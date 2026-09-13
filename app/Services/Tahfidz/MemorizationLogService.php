<?php

namespace App\Services\Tahfidz;

use App\Models\AcademicSemester;
use App\Models\MemorizationLog;
use App\Models\MemorizationTarget;
use App\Models\School;
use App\Models\Student;
use App\Models\SubjectBook;
use App\Services\Akademik\Calculation\MemorizationFactorCalculator;
use App\Services\Akademik\StudentGradeService;
use App\Services\Akademik\TahfizhSubjectBookResolver;
use App\Support\BusinessDate;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Log Setoran dan Murajaah (Tahfidz): CRUD of a santri's dated Halaman
 * entries (type `new` = Setoran, `review` = Murajaah) and the live-computed
 * progress summary a "Progres" view reads.
 *
 * Unlike Target Hafalan, a log never requires the santri to have a target
 * for the semester — logging is open to any active santri of the active
 * school; progressForStudent() simply reports a NULL achievement_percent
 * ("Target belum diset") when there is none (MemorizationFactorCalculator).
 *
 * subject_book_id is always resolved server-side to the active school's
 * Tahfizh kitab (TahfizhSubjectBookResolver) — never taken from client
 * input, so a school without one gets a friendly 422 instead of a
 * broken foreign key.
 */
class MemorizationLogService
{
    public const MESSAGE_NO_TAHFIZH_BOOK = 'Kitab Tahfizh belum tersedia.';

    public const MESSAGE_LOG_DATE_IN_FUTURE = 'Tanggal setoran tidak boleh di masa depan.';

    public const MESSAGE_LOG_DATE_OUTSIDE_SEMESTER = 'Tanggal setoran di luar rentang semester.';

    public const MESSAGE_PAGES_NOT_HALF_STEP = 'Jumlah halaman harus kelipatan 0,5.';

    public const DEFAULT_PER_PAGE = 15;

    private const LOG_RELATIONS = [
        'student:id,full_name,class_level_id',
        'student.classLevel:id,slug,label',
        'teacher:id,full_name',
    ];

    public function __construct(
        private TahfizhSubjectBookResolver $tahfizhSubjectBookResolver,
    ) {}

    /**
     * @param  array{academic_year_id: string, semester: int|string, student_id?: string|null, type?: string|null, date_from?: string|null, date_to?: string|null}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function list(array $filters, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $school = School::activeOrFail();

        $paginator = MemorizationLog::query()
            ->where('school_id', $school->id)
            ->where('academic_year_id', $filters['academic_year_id'])
            ->where('semester', (int) $filters['semester'])
            ->when($filters['student_id'] ?? null, fn ($query, $studentId) => $query->where('student_id', $studentId))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['date_from'] ?? null, fn ($query, $dateFrom) => $query->whereDate('log_date', '>=', $dateFrom))
            ->when($filters['date_to'] ?? null, fn ($query, $dateTo) => $query->whereDate('log_date', '<=', $dateTo))
            ->with(self::LOG_RELATIONS)
            ->orderByDesc('log_date')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $paginator->getCollection()->transform(fn (MemorizationLog $log) => $this->present($log));

        return $paginator;
    }

    /**
     * @param  array{academic_year_id: string, semester: int|string, student_id: string, teacher_id: string, log_date: string, type: string, juz?: int|null, start_page?: int|null, end_page?: int|null, pages?: float|int|string|null, material_note?: string|null, quality_score: int, notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): array
    {
        $school = School::activeOrFail();
        $academicYearId = $data['academic_year_id'];
        $semester = (int) $data['semester'];

        $tahfizhBook = $this->tahfizhSubjectBookOrFail();
        $this->assertSemesterIsConfigured($academicYearId, $semester, $data['log_date']);

        $pages = $this->assertPagesAreValid($this->resolvePages($data));

        $log = MemorizationLog::create([
            'school_id' => $school->id,
            'student_id' => $data['student_id'],
            'subject_book_id' => $tahfizhBook->id,
            'academic_year_id' => $academicYearId,
            'semester' => $semester,
            'teacher_id' => $data['teacher_id'],
            'log_date' => $data['log_date'],
            'type' => $data['type'],
            'juz' => $data['juz'] ?? null,
            'start_page' => $data['start_page'] ?? null,
            'end_page' => $data['end_page'] ?? null,
            'pages' => $pages,
            'material_note' => $data['material_note'] ?? null,
            'quality_score' => (int) $data['quality_score'],
            'notes' => $data['notes'] ?? null,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        return $this->present($log->fresh(self::LOG_RELATIONS));
    }

    /**
     * @param  array{teacher_id?: string, log_date?: string, type?: string, juz?: int|null, start_page?: int|null, end_page?: int|null, pages?: float|int|string|null, material_note?: string|null, quality_score?: int, notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function update(MemorizationLog $log, array $data): array
    {
        if (array_key_exists('log_date', $data)) {
            $this->assertSemesterIsConfigured($log->academic_year_id, $log->semester, $data['log_date']);
        }

        $pages = $log->pages;

        if ($this->hasPagesInput($data)) {
            $merged = array_merge([
                'start_page' => array_key_exists('start_page', $data) ? $data['start_page'] : $log->start_page,
                'end_page' => array_key_exists('end_page', $data) ? $data['end_page'] : $log->end_page,
            ], $data);
            $pages = $this->assertPagesAreValid($this->resolvePages($merged));
        }

        $log->fill([
            'teacher_id' => $data['teacher_id'] ?? $log->teacher_id,
            'log_date' => $data['log_date'] ?? $log->log_date,
            'type' => $data['type'] ?? $log->type,
            'juz' => array_key_exists('juz', $data) ? $data['juz'] : $log->juz,
            'start_page' => array_key_exists('start_page', $data) ? $data['start_page'] : $log->start_page,
            'end_page' => array_key_exists('end_page', $data) ? $data['end_page'] : $log->end_page,
            'pages' => $pages,
            'material_note' => array_key_exists('material_note', $data) ? $data['material_note'] : $log->material_note,
            'quality_score' => array_key_exists('quality_score', $data) ? (int) $data['quality_score'] : $log->quality_score,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $log->notes,
        ]);

        if ($log->isDirty()) {
            $log->updated_by = auth()->id();
            $log->save();
        }

        return $this->present($log->fresh(self::LOG_RELATIONS));
    }

    public function delete(MemorizationLog $log): void
    {
        $log->updated_by = auth()->id();
        $log->save();
        $log->delete();
    }

    /**
     * Progres Hafalan of one santri in one semester: target vs total Setoran
     * Halaman, achievement percent (NULL without a target), counts and
     * average kualitas per type, and the 10 most recent logs.
     *
     * @return array{
     *     target_pages: float|null,
     *     target_juz: float|null,
     *     total_new_pages: float,
     *     achievement_percent: float|null,
     *     new_count: int,
     *     review_count: int,
     *     average_new_quality: float|null,
     *     average_review_quality: float|null,
     *     recent_logs: array<int, array<string, mixed>>,
     * }
     */
    public function progressForStudent(Student $student, string $academicYearId, int $semester): array
    {
        $school = School::activeOrFail();

        $target = MemorizationTarget::query()
            ->where('school_id', $school->id)
            ->where('student_id', $student->id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->first();

        $targetPages = $target !== null ? (float) $target->target_pages : null;

        $logs = MemorizationLog::query()
            ->where('school_id', $school->id)
            ->where('student_id', $student->id)
            ->where('academic_year_id', $academicYearId)
            ->where('semester', $semester)
            ->with(self::LOG_RELATIONS)
            ->orderByDesc('log_date')
            ->orderByDesc('created_at')
            ->get();

        $newLogs = $logs->where('type', MemorizationLog::TYPE_NEW);
        $reviewLogs = $logs->where('type', MemorizationLog::TYPE_REVIEW);
        $totalNewPages = round((float) $newLogs->sum(fn (MemorizationLog $log) => (float) $log->pages), 1);

        return [
            'target_pages' => $targetPages,
            'target_juz' => $target !== null ? round($targetPages / MemorizationTarget::PAGES_PER_JUZ, 2) : null,
            'total_new_pages' => $totalNewPages,
            'achievement_percent' => (new MemorizationFactorCalculator)->calculateTargetAchievement($targetPages, $totalNewPages),
            'new_count' => $newLogs->count(),
            'review_count' => $reviewLogs->count(),
            'average_new_quality' => $this->averageQualityOf($newLogs),
            'average_review_quality' => $this->averageQualityOf($reviewLogs),
            'recent_logs' => $logs->take(10)->map(fn (MemorizationLog $log) => $this->present($log))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(MemorizationLog $log): array
    {
        $classLevel = $log->student?->classLevel;

        return [
            'id' => $log->id,
            'student' => [
                'id' => $log->student?->id,
                'full_name' => $log->student?->full_name,
                'class_level' => $classLevel ? [
                    'id' => $classLevel->id,
                    'slug' => $classLevel->slug,
                    'label' => $classLevel->label,
                ] : null,
            ],
            'academic_year_id' => $log->academic_year_id,
            'semester' => $log->semester,
            'teacher' => [
                'id' => $log->teacher?->id,
                'full_name' => $log->teacher?->full_name,
            ],
            'log_date' => $log->log_date?->toDateString(),
            'type' => $log->type,
            'juz' => $log->juz,
            'start_page' => $log->start_page,
            'end_page' => $log->end_page,
            'pages' => (float) $log->pages,
            'material_note' => $log->material_note,
            'quality_score' => $log->quality_score,
            'notes' => $log->notes,
            'created_at' => $log->created_at?->toJSON(),
            'updated_at' => $log->updated_at?->toJSON(),
        ];
    }

    private function averageQualityOf(Collection $logs): ?float
    {
        if ($logs->isEmpty()) {
            return null;
        }

        return round((float) $logs->avg(fn (MemorizationLog $log) => (float) $log->quality_score), 2);
    }

    private function hasPagesInput(array $data): bool
    {
        return array_key_exists('pages', $data)
            || array_key_exists('start_page', $data)
            || array_key_exists('end_page', $data);
    }

    /**
     * pages wins when sent; otherwise derived as end_page - start_page + 1
     * when both are given.
     *
     * @param  array{pages?: float|int|string|null, start_page?: int|null, end_page?: int|null}  $data
     *
     * @throws ValidationException
     */
    private function resolvePages(array $data): float
    {
        if (array_key_exists('pages', $data) && $data['pages'] !== null) {
            return round((float) $data['pages'], 1);
        }

        $startPage = $data['start_page'] ?? null;
        $endPage = $data['end_page'] ?? null;

        if ($startPage !== null && $endPage !== null) {
            return (float) ($endPage - $startPage + 1);
        }

        throw ValidationException::withMessages(['pages' => 'Isi jumlah halaman, atau halaman awal dan akhir.']);
    }

    /**
     * @throws ValidationException
     */
    private function assertPagesAreValid(float $pages): float
    {
        if ($pages <= 0) {
            throw ValidationException::withMessages(['pages' => 'Jumlah halaman harus lebih dari 0.']);
        }

        // Multiple of 0.5: doubled value must be a whole number.
        if (abs(round($pages * 2) - ($pages * 2)) > 0.0001) {
            throw ValidationException::withMessages(['pages' => self::MESSAGE_PAGES_NOT_HALF_STEP]);
        }

        return $pages;
    }

    /**
     * @throws ValidationException
     */
    private function tahfizhSubjectBookOrFail(): SubjectBook
    {
        $tahfizhBook = $this->tahfizhSubjectBookResolver->resolve();

        if ($tahfizhBook === null) {
            throw ValidationException::withMessages(['subject_book_id' => self::MESSAGE_NO_TAHFIZH_BOOK]);
        }

        return $tahfizhBook;
    }

    /**
     * @throws ValidationException
     */
    private function assertSemesterIsConfigured(string $academicYearId, int $semester, string $logDate): void
    {
        $academicSemester = AcademicSemester::findByPair($academicYearId, $semester);

        if ($academicSemester === null) {
            throw ValidationException::withMessages(['semester' => StudentGradeService::MESSAGE_SEMESTER_NOT_CONFIGURED]);
        }

        $logDateAsString = Carbon::parse($logDate)->toDateString();

        if ($logDateAsString > BusinessDate::todayString()) {
            throw ValidationException::withMessages(['log_date' => self::MESSAGE_LOG_DATE_IN_FUTURE]);
        }

        if ($academicSemester->start_date === null || $academicSemester->end_date === null) {
            return;
        }

        if ($logDateAsString < $academicSemester->start_date->toDateString() || $logDateAsString > $academicSemester->end_date->toDateString()) {
            throw ValidationException::withMessages(['log_date' => self::MESSAGE_LOG_DATE_OUTSIDE_SEMESTER]);
        }
    }
}

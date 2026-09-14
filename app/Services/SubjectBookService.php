<?php

namespace App\Services;

use App\Exceptions\HasDependentsException;
use App\Models\School;
use App\Models\SubjectBook;
use App\Models\TeachingSchedule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SubjectBookService
{
    public const MESSAGE_HAS_CLASS_TASKS = 'Kitab tidak dapat dihapus karena sudah memiliki data Tugas.';

    public const MESSAGE_HAS_CLASS_SESSIONS = 'Kitab tidak dapat dihapus karena sudah memiliki data Pertemuan (absensi).';

    public const MESSAGE_HAS_MEMORIZATION_LOGS = 'Kitab tidak dapat dihapus karena sudah memiliki data Log Setoran.';

    public const MESSAGE_HAS_REPORT_CARD_ENTRIES = 'Kitab tidak dapat dihapus karena sudah tercantum di Rapor.';

    /**
     * Penilaian tables holding a restrict FK on subject_books, checked with
     * the query builder so soft-deleted rows (which still hold the FK) count
     * too — otherwise the delete would surface as a 500.
     */
    private const PENILAIAN_DEPENDENT_MESSAGES = [
        'class_tasks' => self::MESSAGE_HAS_CLASS_TASKS,
        'class_sessions' => self::MESSAGE_HAS_CLASS_SESSIONS,
        'memorization_logs' => self::MESSAGE_HAS_MEMORIZATION_LOGS,
        'report_card_entries' => self::MESSAGE_HAS_REPORT_CARD_ENTRIES,
    ];

    public function listBooks(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $school = School::activeOrFail();

        $query = SubjectBook::where('school_id', $school->id)
            ->with(['subjectCategory:id,name,color', 'gradingTemplate:id,code,name']);

        if (class_exists(TeachingSchedule::class)) {
            $query->withCount('teachingSchedules');
        }

        if (! empty($filters['subject_category_id'])) {
            $query->where('subject_category_id', $filters['subject_category_id']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['search'])) {
            $driver = DB::getDriverName();
            $likeOperator = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
            $query->where('title', $likeOperator, '%'.$filters['search'].'%');
        }

        return $query->orderBy('title')->paginate($perPage);
    }

    public function listAllActive(): Collection
    {
        $school = School::activeOrFail();

        $query = SubjectBook::where('school_id', $school->id)
            ->where('is_active', true)
            ->with(['subjectCategory:id,name,color', 'gradingTemplate:id,code,name']);

        if (class_exists(TeachingSchedule::class)) {
            $query->withCount('teachingSchedules');
        }

        return $query->orderBy('title')->get();
    }

    public function createBook(array $data): SubjectBook
    {
        $school = School::activeOrFail();

        $data['school_id'] = $school->id;

        $book = SubjectBook::create($data);

        return $book->load(['subjectCategory:id,name,color', 'gradingTemplate:id,code,name']);
    }

    public function updateBook(SubjectBook $subjectBook, array $data): SubjectBook
    {
        $subjectBook->update($data);

        return $subjectBook->fresh()->load(['subjectCategory:id,name,color', 'gradingTemplate:id,code,name']);
    }

    public function deleteBook(SubjectBook $subjectBook): void
    {
        if (class_exists(TeachingSchedule::class)) {
            if ($subjectBook->teachingSchedules()->exists()) {
                throw new HasDependentsException(
                    'Cannot delete subject book with existing teaching schedules'
                );
            }
        }

        if ($subjectBook->studentGrades()->exists()) {
            throw new HasDependentsException(
                'Cannot delete subject book with recorded student grades'
            );
        }

        foreach (self::PENILAIAN_DEPENDENT_MESSAGES as $table => $message) {
            if (DB::table($table)->where('subject_book_id', $subjectBook->id)->exists()) {
                throw new HasDependentsException($message);
            }
        }

        $subjectBook->delete();
    }
}

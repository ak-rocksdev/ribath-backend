<?php

namespace App\Services;

use App\Exceptions\HasDependentsException;
use App\Models\School;
use App\Models\SubjectBook;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SubjectBookService
{
    public function listBooks(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $school = School::activeOrFail();

        $query = SubjectBook::where('school_id', $school->id)
            ->with('subjectCategory:id,name,color');

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

        return SubjectBook::where('school_id', $school->id)
            ->where('is_active', true)
            ->with('subjectCategory:id,name,color')
            ->orderBy('title')
            ->get();
    }

    public function createBook(array $data): SubjectBook
    {
        $school = School::activeOrFail();

        $data['school_id'] = $school->id;

        $book = SubjectBook::create($data);

        return $book->load('subjectCategory:id,name,color');
    }

    public function updateBook(SubjectBook $subjectBook, array $data): SubjectBook
    {
        $subjectBook->update($data);

        return $subjectBook->fresh()->load('subjectCategory:id,name,color');
    }

    public function deleteBook(SubjectBook $subjectBook): void
    {
        if (class_exists(\App\Models\TeachingSchedule::class)) {
            if ($subjectBook->teachingSchedules()->exists()) {
                throw new HasDependentsException(
                    'Cannot delete subject book with existing teaching schedules'
                );
            }
        }

        $subjectBook->delete();
    }
}

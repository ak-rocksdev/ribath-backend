<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A Rapor cannot be finalized while it is Belum Lengkap (spec US88):
 * rendered as a 422 keyed `student_id`, plus `incomplete_subjects` — one
 * entry per gradable kitab that still has an empty counted factor:
 * {subject_book: {id, title}, missing_factor_codes, missing_factors:
 * [{code, name, missing_reason}]}.
 */
class IncompleteReportCardException extends RuntimeException implements ShouldntReport
{
    /**
     * @param  array<int, array<string, mixed>>  $incompleteSubjects
     */
    public function __construct(string $message, private array $incompleteSubjects)
    {
        parent::__construct($message);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function incompleteSubjects(): array
    {
        return $this->incompleteSubjects;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => ['student_id' => [$this->getMessage()]],
            'incomplete_subjects' => $this->incompleteSubjects,
        ], 422);
    }
}

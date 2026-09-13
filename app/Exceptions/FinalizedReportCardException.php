<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A per-santri write touched a semester whose Rapor is already final
 * (ADR 0001, spec US91). Rendered as a 422 in the usual validation shape
 * ({success, message, errors}) so every grid/form can reuse its existing
 * server-error mapping: bulk endpoints key the error by each santri's id
 * (like their other per-santri errors), single-record endpoints by
 * `student_id`.
 */
class FinalizedReportCardException extends RuntimeException implements ShouldntReport
{
    public const MESSAGE = 'Rapor santri ini sudah final untuk semester tersebut.';

    /**
     * @param  array<int, string>  $errorKeys
     */
    private function __construct(private array $errorKeys)
    {
        parent::__construct(self::MESSAGE);
    }

    /**
     * For single-record writes (log, target, finalize): keyed `student_id`.
     */
    public static function forStudent(): self
    {
        return new self(['student_id']);
    }

    /**
     * For bulk writes: one error per finalized santri, keyed by its id.
     *
     * @param  array<int, string>  $studentIds
     */
    public static function forStudents(array $studentIds): self
    {
        return new self(array_values($studentIds));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return collect($this->errorKeys)
            ->mapWithKeys(fn (string $errorKey) => [$errorKey => [self::MESSAGE]])
            ->all();
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => self::MESSAGE,
            'errors' => $this->errors(),
        ], 422);
    }
}

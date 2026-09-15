<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A user limited to his Cakupan Mengajar (only the "milik sendiri"
 * permission, ADR 0004) chose something outside it through a request
 * parameter or body — a Kelas × Kitab pair, or a santri who is not his
 * santri bimbingan. Rendered as a 403 in the usual envelope so the client
 * shows the message as is. Records bound to the route (tugas, pertemuan,
 * log, target) are answered with 404 instead, like tenancy.
 */
class OutsideTeachingScopeException extends RuntimeException implements ShouldntReport
{
    public const MESSAGE_CLASS_SUBJECT_PAIR = 'Kelas dan kitab ini di luar Cakupan Mengajar Anda.';

    public const MESSAGE_STUDENT = 'Santri ini di luar Cakupan Mengajar Anda.';

    public static function forClassSubjectPair(): self
    {
        return new self(self::MESSAGE_CLASS_SUBJECT_PAIR);
    }

    public static function forStudent(): self
    {
        return new self(self::MESSAGE_STUDENT);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
        ], 403);
    }
}

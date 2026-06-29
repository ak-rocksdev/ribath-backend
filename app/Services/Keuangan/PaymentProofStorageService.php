<?php

namespace App\Services\Keuangan;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Mirror of CashBookProofStorageService, scoped to student_payments root.
class PaymentProofStorageService
{
    private const DISK = 'local';

    private const ROOT_DIRECTORY = 'student_payments';

    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /** @return array{path: string, mime: string} */
    public function store(UploadedFile $file, string $schoolId, string $paymentId): array
    {
        $mime = $file->getMimeType() ?? '';
        $extension = self::MIME_EXTENSIONS[$mime] ?? null;

        if ($extension === null) {
            throw new \InvalidArgumentException("Unsupported MIME type for proof file: {$mime}");
        }

        $directory = self::ROOT_DIRECTORY.'/'.$schoolId.'/'.$paymentId;
        $filename = Str::uuid()->toString().'.'.$extension;
        $path = $directory.'/'.$filename;

        Storage::disk(self::DISK)->putFileAs($directory, $file, $filename);

        return ['path' => $path, 'mime' => $mime];
    }

    public function delete(?string $path): void
    {
        if ($path === null) {
            return;
        }
        Storage::disk(self::DISK)->delete($path);
    }

    public function streamResponse(string $path, string $mime): StreamedResponse
    {
        return Storage::disk(self::DISK)->response(
            $path,
            null,
            ['Content-Type' => $mime],
            'inline'
        );
    }
}

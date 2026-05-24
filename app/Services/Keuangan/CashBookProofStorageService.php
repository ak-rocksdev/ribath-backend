<?php

namespace App\Services\Keuangan;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CashBookProofStorageService
{
    private const DISK = 'local';

    private const ROOT_DIRECTORY = 'cash_book';

    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /**
     * Store an uploaded proof file under cash_book/{school_id}/{entry_id}/{uuid}.{ext}.
     *
     * Uses a fresh UUID as filename to avoid collision across replace cycles, and derives
     * extension from the verified MIME type rather than client-provided filename.
     *
     * @return array{path: string, mime: string}
     */
    public function store(UploadedFile $file, string $schoolId, string $entryId): array
    {
        $mime = $file->getMimeType() ?? '';
        $extension = self::MIME_EXTENSIONS[$mime] ?? null;

        if ($extension === null) {
            throw new \InvalidArgumentException("Unsupported MIME type for proof file: {$mime}");
        }

        $directory = self::ROOT_DIRECTORY.'/'.$schoolId.'/'.$entryId;
        $filename = Str::uuid()->toString().'.'.$extension;
        $path = $directory.'/'.$filename;

        Storage::disk(self::DISK)->putFileAs($directory, $file, $filename);

        return ['path' => $path, 'mime' => $mime];
    }

    /**
     * Delete a proof file from storage. Idempotent — silently skips when path is null
     * or the file does not exist.
     */
    public function delete(?string $path): void
    {
        if ($path === null) {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }

    /**
     * Stream a proof file inline (suitable for browser preview of images and PDFs).
     * Caller is responsible for authorization checks before invoking.
     */
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

<?php

namespace App\Services;

use App\Models\School;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves the logo to embed in a PDF export as a base64 data URI. Prefers
 * the school's uploaded logo (schools.logo_path) when present; falls back
 * to the bundled default logo when the school has none or the file is
 * missing on disk. Shared by every PDF export (teaching schedule, rapor, …)
 * so the fallback behaviour cannot drift between exports.
 */
class SchoolLogoResolver
{
    /** Per-process cache for the default logo so we don't re-read the PNG on every export. */
    private static ?string $cachedDefaultLogoDataUri = null;

    private static bool $defaultLogoLoaded = false;

    public function dataUri(?School $school): ?string
    {
        if (! $school?->logo_path) {
            return $this->defaultLogoDataUri();
        }

        $absolutePath = Storage::disk('public')->path($school->logo_path);

        if (! file_exists($absolutePath)) {
            return $this->defaultLogoDataUri();
        }

        $mime = mime_content_type($absolutePath) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($absolutePath));
    }

    /**
     * Read the default school logo from disk and return a base64 data URI.
     * Returns null if the file is missing so the Blade can render without a
     * logo. Memoised at the PHP-process level — the file is fixed at build
     * time and never changes between requests served by the same FPM worker.
     */
    private function defaultLogoDataUri(): ?string
    {
        if (! self::$defaultLogoLoaded) {
            $path = public_path('images/default-school-logo.png');
            self::$cachedDefaultLogoDataUri = file_exists($path)
                ? 'data:image/png;base64,'.base64_encode(file_get_contents($path))
                : null;
            self::$defaultLogoLoaded = true;
        }

        return self::$cachedDefaultLogoDataUri;
    }
}

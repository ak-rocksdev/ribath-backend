<?php

namespace App\Services;

use App\Models\School;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;

class SchoolService
{
    private const LOGO_DISK = 'public';
    private const LOGO_DIRECTORY = 'school-logos';
    private const LOGO_MAX_DIMENSION = 1024;

    public function update(School $school, array $data): School
    {
        $school->update($data);

        return $school->fresh();
    }

    public function uploadLogo(School $school, UploadedFile $file): School
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $extension = $extension === 'jpg' ? 'jpeg' : $extension;
        $filename = $school->id.'-'.Str::uuid().'.'.$extension;
        $relativePath = self::LOGO_DIRECTORY.'/'.$filename;
        $absolutePath = Storage::disk(self::LOGO_DISK)->path($relativePath);

        Storage::disk(self::LOGO_DISK)->makeDirectory(self::LOGO_DIRECTORY);

        // Resize on upload so we never store oversized originals.
        Image::load($file->getRealPath())
            ->fit(Fit::Max, self::LOGO_MAX_DIMENSION, self::LOGO_MAX_DIMENSION)
            ->save($absolutePath);

        $previousPath = $school->logo_path;
        $school->update(['logo_path' => $relativePath]);

        if ($previousPath && $previousPath !== $relativePath) {
            Storage::disk(self::LOGO_DISK)->delete($previousPath);
        }

        return $school->fresh();
    }

    public function deleteLogo(School $school): School
    {
        if ($school->logo_path) {
            Storage::disk(self::LOGO_DISK)->delete($school->logo_path);
            $school->update(['logo_path' => null]);
        }

        return $school->fresh();
    }
}

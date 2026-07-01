<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Laravel\Facades\Image;

/**
 * Single place that turns an uploaded file into a stored asset + a Media
 * row. Used by the Media Library upload endpoint and by any other upload
 * flow (e.g. article inline body images) that should also be browsable and
 * reusable from the Media Library instead of living as an orphaned file.
 */
class MediaLibraryService
{
    public function store(UploadedFile $file, string $collection, ?string $altText = null, ?int $uploadedBy = null): Media
    {
        $mimeType = $file->getMimeType();
        $isSvg    = $mimeType === 'image/svg+xml';
        $isVideo  = str_starts_with($mimeType, 'video/');

        $uuid         = Str::uuid()->toString();
        $ext          = $file->guessExtension() ?? 'bin';
        $filename     = $uuid . '.' . $ext;
        $originalName = $file->getClientOriginalName();

        if ($isSvg || $isVideo) {
            // SVGs and videos are stored as-is — no image processing
            $path = $file->storeAs($collection, $filename, 'public');
            $w    = null;
            $h    = null;
        } else {
            // Resize to max 2000px on longest side, strip EXIF, save
            $image = Image::decode($file->get());
            $image->scaleDown(2000, 2000);

            $path    = $collection . '/' . $filename;
            $content = (string) $image->encode(new JpegEncoder(quality: 90, strip: true));
            Storage::disk('public')->put($path, $content);

            $w = $image->width();
            $h = $image->height();
        }

        $fullPath = Storage::disk('public')->path($path);
        $size     = file_exists($fullPath) ? filesize($fullPath) : $file->getSize();

        return Media::create([
            'filename'      => $filename,
            'original_name' => $originalName,
            'path'          => $path,
            'url'           => url('storage/' . $path),
            'mime_type'     => $mimeType,
            'size_bytes'    => $size,
            'width'         => $w,
            'height'        => $h,
            'alt_text'      => $altText,
            'collection'    => $collection,
            'uploaded_by'   => $uploadedBy,
        ]);
    }
}

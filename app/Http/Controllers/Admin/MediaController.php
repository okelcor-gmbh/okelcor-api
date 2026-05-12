<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UploadMediaRequest;
use App\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

class MediaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Media::orderByDesc('created_at');

        if ($request->filled('collection')) {
            $query->where('collection', $request->collection);
        }
        if ($request->filled('search')) {
            $query->where('original_name', 'like', '%' . $request->search . '%');
        }

        $perPage   = min((int) $request->input('per_page', 48), 200);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => $paginated->map(fn ($m) => $this->formatMedia($m)),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    public function store(UploadMediaRequest $request): JsonResponse
    {
        $file       = $request->file('file');
        $collection = $request->input('collection', 'general');
        $altText    = $request->input('alt_text');
        $mimeType   = $file->getMimeType();
        $isSvg      = $mimeType === 'image/svg+xml';
        $isVideo    = str_starts_with($mimeType, 'video/');

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
            $image = Image::read($file);
            $image->scaleDown(2000, 2000);

            $path    = $collection . '/' . $filename;
            $content = $image->toJpeg(90);
            Storage::disk('public')->put($path, $content);

            $w = $image->width();
            $h = $image->height();
        }

        $fullPath = Storage::disk('public')->path($path);
        $size     = file_exists($fullPath) ? filesize($fullPath) : $file->getSize();

        $media = Media::create([
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
            'uploaded_by'   => $request->user()?->id,
        ]);

        return response()->json(['data' => $this->formatMedia($media)], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $media = Media::findOrFail($id);

        Storage::disk('public')->delete($media->path);
        $media->delete();

        return response()->json(['message' => 'File deleted.']);
    }

    private function formatMedia(Media $m): array
    {
        return [
            'id'            => $m->id,
            'filename'      => $m->filename,
            'original_name' => $m->original_name,
            'path'          => $m->path,
            'url'           => url('storage/' . $m->path),
            'mime_type'     => $m->mime_type,
            'size_bytes'    => $m->size_bytes,
            'width'         => $m->width,
            'height'        => $m->height,
            'alt_text'      => $m->alt_text,
            'collection'    => $m->collection,
            'created_at'    => $m->created_at?->toIso8601String(),
        ];
    }
}

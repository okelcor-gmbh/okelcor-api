<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UploadMediaRequest;
use App\Models\Media;
use App\Services\MediaLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    public function __construct(private MediaLibraryService $mediaLibrary) {}

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
        $media = $this->mediaLibrary->store(
            file: $request->file('file'),
            collection: $request->input('collection', 'general'),
            altText: $request->input('alt_text'),
            uploadedBy: $request->user()?->id,
        );

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

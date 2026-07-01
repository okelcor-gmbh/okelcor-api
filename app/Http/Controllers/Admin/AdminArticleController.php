<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreArticleRequest;
use App\Http\Requests\Admin\UpdateArticleRequest;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Services\ArticleHtmlSanitizer;
use App\Services\MediaLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminArticleController extends Controller
{
    public function __construct(
        private ArticleHtmlSanitizer $sanitizer,
        private MediaLibraryService $mediaLibrary,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 20);

        $query = $request->input('trashed') === 'only'
            ? Article::onlyTrashed()->with('translations')
            : Article::with('translations');

        $paginated = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => $paginated->map(fn ($a) => $this->formatArticleList($a)),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    public function store(StoreArticleRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $article = Article::create([
            'slug'         => $validated['slug'],
            'image'        => $validated['image'] ?? null,
            'og_image'     => $validated['og_image'] ?? null,
            'published_at' => $validated['published_at'] ?? null,
            'is_published' => $validated['is_published'] ?? false,
            'sort_order'   => $validated['sort_order'] ?? 0,
        ]);

        try {
            $this->syncTranslations($article, $validated['translations']);
        } catch (\Throwable $e) {
            $article->forceDelete();
            Log::error('Article create failed during translation sync', [
                'admin_id'  => $request->user()?->id,
                'route'     => $request->route()?->getName(),
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $article->load('translations');

        return response()->json(['data' => $this->formatArticle($article)], 201);
    }

    public function show(int $id): JsonResponse
    {
        $article = Article::with('translations')->findOrFail($id);

        return response()->json(['data' => $this->formatArticle($article)]);
    }

    public function update(UpdateArticleRequest $request, int $id): JsonResponse
    {
        $article   = Article::findOrFail($id);
        $validated = $request->validated();

        // Build update payload using array_key_exists so explicitly-null fields
        // (e.g. clearing published_at to unpublish) are honoured.
        $updateData = [];
        if (array_key_exists('slug', $validated))         $updateData['slug']         = $validated['slug'];
        if (array_key_exists('image', $validated))        $updateData['image']        = $validated['image'];
        if (array_key_exists('og_image', $validated))     $updateData['og_image']     = $validated['og_image'];
        if (array_key_exists('published_at', $validated)) $updateData['published_at'] = $validated['published_at'];
        if (array_key_exists('is_published', $validated)) $updateData['is_published'] = $validated['is_published'];
        if (array_key_exists('sort_order', $validated))   $updateData['sort_order']   = $validated['sort_order'];

        if (! empty($updateData)) {
            $article->update($updateData);
        }

        if (array_key_exists('translations', $validated)) {
            try {
                $this->syncTranslations($article, $validated['translations']);
            } catch (\Throwable $e) {
                Log::error('Article update failed during translation sync', [
                    'article_id' => $id,
                    'admin_id'   => $request->user()?->id,
                    'route'      => $request->route()?->getName(),
                    'exception'  => get_class($e),
                    'message'    => $e->getMessage(),
                ]);
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        $article->load('translations');

        return response()->json(['data' => $this->formatArticle($article)]);
    }

    public function destroy(int $id): JsonResponse
    {
        Article::findOrFail($id)->delete();

        return response()->json(['message' => 'Article deleted.']);
    }

    public function restore(int $id): JsonResponse
    {
        $article = Article::onlyTrashed()->findOrFail($id);
        $article->restore();

        return response()->json([
            'data'    => ['id' => $article->id],
            'message' => 'Article restored',
        ]);
    }

    /**
     * Upload the article cover image.
     */
    public function uploadImage(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);

        $article = Article::findOrFail($id);

        if ($article->image) {
            Storage::disk('public')->delete($article->image);
        }

        $file     = $request->file('image');
        $filename = Str::uuid() . '.' . ($file->guessExtension() ?? 'bin');
        $path     = $file->storeAs('articles', $filename, 'public');

        $article->update(['image' => $path]);
        $article->load('translations');

        return response()->json(['data' => $this->formatArticle($article)]);
    }

    /**
     * Upload the OG / social-share image (distinct from the article cover).
     * Stores to articles/og/ and saves the relative path to articles.og_image.
     */
    public function uploadOgImage(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);

        $article = Article::findOrFail($id);

        if ($article->og_image) {
            Storage::disk('public')->delete($article->og_image);
        }

        $file     = $request->file('image');
        $filename = Str::uuid() . '.' . ($file->guessExtension() ?? 'bin');
        $path     = $file->storeAs('articles/og', $filename, 'public');

        $article->update(['og_image' => $path]);
        $article->load('translations');

        return response()->json(['data' => $this->formatArticle($article)]);
    }

    /**
     * Upload an image embedded inside the article body (rich editor inline image).
     * Returns the public URL for the editor to inject as an <img src="...">.
     *
     * Also registers the upload in the shared Media Library (collection:
     * "articles") so it's browsable/reusable later instead of becoming an
     * orphaned file only this one article body links to.
     */
    public function uploadBodyImage(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);

        Article::findOrFail($id); // 404 if article not found

        $media = $this->mediaLibrary->store(
            file: $request->file('image'),
            collection: 'articles',
            uploadedBy: $request->user()?->id,
        );

        return response()->json([
            'data' => [
                'url'      => $media->url,
                'path'     => $media->path,
                'media_id' => $media->id,
            ],
            'message' => 'Image uploaded.',
        ]);
    }

    // -------------------------------------------------------------------------

    private function syncTranslations(Article $article, array $translations): void
    {
        foreach ($translations as $locale => $t) {
            if (! in_array($locale, ['en', 'de', 'fr', 'es'])) {
                continue;
            }

            // Sanitize the rich HTML body before persisting
            $rawBody       = $t['body'] ?? '';
            $sanitizedBody = $this->sanitizer->sanitize($rawBody);

            ArticleTranslation::updateOrCreate(
                ['article_id' => $article->id, 'locale' => $locale],
                [
                    'category'         => $t['category'],
                    'title'            => $t['title'],
                    'read_time'        => $t['read_time'] ?? '',
                    'summary'          => $t['summary'],
                    'body'             => $sanitizedBody,
                    'body_format'      => 'html',
                    'meta_title'       => $t['meta_title'] ?? null,
                    'meta_description' => $t['meta_description'] ?? null,
                    'cover_alt'        => $t['cover_alt'] ?? null,
                ]
            );
        }
    }

    private function formatArticleList(Article $a): array
    {
        $en = $a->translations->firstWhere('locale', 'en');

        return [
            'id'           => $a->id,
            'slug'         => $a->slug,
            'image'        => $a->image ? url('storage/' . $a->image) : null,
            'published_at' => $a->published_at?->toDateString(),
            'is_published' => (bool) $a->is_published,
            'sort_order'   => $a->sort_order,
            'title'        => $en?->title,
            'category'     => $en?->category,
            'created_at'   => $a->created_at?->toIso8601String(),
            'deleted_at'   => $a->deleted_at?->toIso8601String(),
        ];
    }

    private function formatArticle(Article $a): array
    {
        $translations = [];
        foreach ($a->translations as $t) {
            $translations[$t->locale] = [
                'category'         => $t->category,
                'title'            => $t->title,
                'read_time'        => $t->read_time,
                'summary'          => $t->summary,
                'body'             => $t->body_html,
                'body_format'      => $t->body_format ?? 'json_array',
                'meta_title'       => $t->meta_title,
                'meta_description' => $t->meta_description,
                'cover_alt'        => $t->cover_alt,
            ];
        }

        $en              = $a->translations->firstWhere('locale', 'en');
        $presentLocales  = $a->translations->pluck('locale')->all();
        $missingLocales  = array_values(array_diff(['en', 'de', 'fr', 'es'], $presentLocales));

        return [
            'id'              => $a->id,
            'slug'            => $a->slug,
            'image'           => $a->image ? url('storage/' . $a->image) : null,
            'og_image'        => $a->og_image ? url('storage/' . $a->og_image) : null,
            'published_at'    => $a->published_at?->toDateString(),
            'is_published'    => (bool) $a->is_published,
            'sort_order'      => $a->sort_order,
            'title'           => $en?->title,
            'category'        => $en?->category,
            'translations'    => $translations,
            'missing_locales' => $missingLocales,
            'created_at'      => $a->created_at?->toIso8601String(),
        ];
    }
}

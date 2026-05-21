<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreArticleRequest;
use App\Http\Requests\Admin\UpdateArticleRequest;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Services\ArticleHtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminArticleController extends Controller
{
    public function __construct(private ArticleHtmlSanitizer $sanitizer) {}

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

        $this->syncTranslations($article, $validated['translations']);

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

        $article->update(array_filter([
            'slug'         => $validated['slug'] ?? null,
            'image'        => array_key_exists('image', $validated) ? $validated['image'] : $article->image,
            'og_image'     => array_key_exists('og_image', $validated) ? $validated['og_image'] : $article->og_image,
            'published_at' => $validated['published_at'] ?? null,
            'is_published' => $validated['is_published'] ?? $article->is_published,
            'sort_order'   => $validated['sort_order'] ?? $article->sort_order,
        ], fn ($v) => $v !== null));

        if (isset($validated['translations'])) {
            $this->syncTranslations($article, $validated['translations']);
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
     * Upload an image embedded inside the article body (rich editor inline image).
     * Returns the public URL for the editor to inject as an <img src="...">.
     */
    public function uploadBodyImage(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);

        Article::findOrFail($id); // 404 if article not found

        $file     = $request->file('image');
        $filename = Str::uuid() . '.' . ($file->guessExtension() ?? 'bin');
        $path     = $file->storeAs('articles/body', $filename, 'public');
        $url      = url(Storage::url($path));

        return response()->json([
            'data' => [
                'url'  => $url,
                'path' => $path,
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
                'body'             => $t->body_html,        // resolved HTML (handles legacy too)
                'body_format'      => $t->body_format ?? 'json_array',
                'meta_title'       => $t->meta_title,
                'meta_description' => $t->meta_description,
                'cover_alt'        => $t->cover_alt,
            ];
        }

        $en = $a->translations->firstWhere('locale', 'en');

        return [
            'id'           => $a->id,
            'slug'         => $a->slug,
            'image'        => $a->image ? url('storage/' . $a->image) : null,
            'og_image'     => $a->og_image ? url('storage/' . $a->og_image) : null,
            'published_at' => $a->published_at?->toDateString(),
            'is_published' => (bool) $a->is_published,
            'sort_order'   => $a->sort_order,
            'title'        => $en?->title,
            'category'     => $en?->category,
            'translations' => $translations,
            'created_at'   => $a->created_at?->toIso8601String(),
        ];
    }
}

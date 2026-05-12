<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreArticleRequest;
use App\Http\Requests\Admin\UpdateArticleRequest;
use App\Models\Article;
use App\Models\ArticleTranslation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminArticleController extends Controller
{
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

    public function uploadImage(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);

        $article = Article::findOrFail($id);

        // Delete old image from storage if one exists
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

    private function syncTranslations(Article $article, array $translations): void
    {
        foreach ($translations as $locale => $t) {
            if (! in_array($locale, ['en', 'de', 'fr', 'es'])) {
                continue;
            }

            ArticleTranslation::updateOrCreate(
                ['article_id' => $article->id, 'locale' => $locale],
                [
                    'category'  => $t['category'],
                    'title'     => $t['title'],
                    'read_time' => $t['read_time'] ?? '',
                    'summary'   => $t['summary'],
                    'body'      => $t['body'],
                ]
            );
        }
    }

    /**
     * Compact list format for the admin index — includes top-level title/category
     * from the EN translation so the admin table can display them directly.
     */
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

    /**
     * Full format for show/store/update — includes all translations.
     */
    private function formatArticle(Article $a): array
    {
        $translations = [];
        foreach ($a->translations as $t) {
            $translations[$t->locale] = [
                'category'  => $t->category,
                'title'     => $t->title,
                'read_time' => $t->read_time,
                'summary'   => $t->summary,
                'body'      => $t->body,
            ];
        }

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
            'translations' => $translations,
            'created_at'   => $a->created_at?->toIso8601String(),
        ];
    }
}

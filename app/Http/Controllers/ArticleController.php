<?php

namespace App\Http\Controllers;

use App\Models\Article;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArticleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $locale  = $this->resolveLocale($request);
        $perPage = (int) $request->input('per_page', 12);

        $locales = array_unique([$locale, 'en']);

        $query = Article::with(['translations' => fn ($q) => $q->whereIn('locale', $locales)])
            ->where('is_published', true)
            ->orderByDesc('published_at');

        if ($request->filled('category')) {
            $query->whereHas('translations', function ($q) use ($request, $locale) {
                $q->where('locale', $locale)->where('category', $request->category);
            });
        }

        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => $paginated->map(fn ($a) => $this->formatArticleList($a, $locale)),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $locale  = $this->resolveLocale($request);
        $locales = array_unique([$locale, 'en']);

        $article = Article::with(['translations' => fn ($q) => $q->whereIn('locale', $locales)])
            ->where('is_published', true)
            ->where('slug', $slug)
            ->firstOrFail();

        $translation = $article->translations->firstWhere('locale', $locale)
            ?? $article->translations->firstWhere('locale', 'en');

        return response()->json([
            'data' => [
                'id'               => $article->id,
                'slug'             => $article->slug,
                'image'            => $article->image ? url('storage/' . $article->image) : null,
                'og_image'         => $article->og_image ? url('storage/' . $article->og_image) : null,
                'published_at'     => $article->published_at?->toDateString(),
                'category'         => $translation?->category,
                'title'            => $translation?->title,
                'read_time'        => $translation?->read_time,
                'summary'          => $translation?->summary,
                'body'             => $translation?->body_html ?? '',  // always sanitized HTML
                'cover_alt'        => $translation?->cover_alt,
                'meta_title'       => $translation?->meta_title,
                'meta_description' => $translation?->meta_description,
                'locale'           => $locale,
            ],
        ]);
    }

    private function formatArticleList(Article $a, string $locale): array
    {
        $t = $a->translations->firstWhere('locale', $locale)
            ?? $a->translations->firstWhere('locale', 'en');

        return [
            'id'               => $a->id,
            'slug'             => $a->slug,
            'image'            => $a->image ? url('storage/' . $a->image) : null,
            'published_at'     => $a->published_at?->toDateString(),
            'category'         => $t?->category,
            'title'            => $t?->title,
            'read_time'        => $t?->read_time,
            'summary'          => $t?->summary,
            'cover_alt'        => $t?->cover_alt,
            'meta_title'       => $t?->meta_title,
            'meta_description' => $t?->meta_description,
            'locale'           => $locale,
        ];
    }

    private function resolveLocale(Request $request): string
    {
        return in_array($request->query('locale'), ['en', 'de', 'fr', 'es'])
            ? $request->query('locale')
            : 'en';
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'q'      => ['required', 'string', 'min:2', 'max:200'],
            'locale' => ['nullable', 'string', 'in:en,de,fr,es'],
        ]);

        $q      = $request->input('q');
        $locale = in_array($request->query('locale'), ['en', 'de', 'fr', 'es'])
            ? $request->query('locale')
            : 'en';

        $products = Product::where('is_active', true)
            ->where(function ($query) use ($q) {
                $query->where('brand', 'like', "%{$q}%")
                      ->orWhere('name', 'like', "%{$q}%")
                      ->orWhere('size', 'like', "%{$q}%")
                      ->orWhere('sku', 'like', "%{$q}%");
            })
            ->limit(6)
            ->get(['id', 'slug', 'brand', 'name', 'size', 'type', 'price', 'primary_image']);

        $articles = Article::with(['translations' => fn ($tq) => $tq->where('locale', $locale)])
            ->where('is_published', true)
            ->whereHas('translations', function ($tq) use ($q, $locale) {
                $tq->where('locale', $locale)
                   ->where(function ($inner) use ($q) {
                       $inner->where('title', 'like', "%{$q}%")
                             ->orWhere('summary', 'like', "%{$q}%")
                             ->orWhere('category', 'like', "%{$q}%");
                   });
            })
            ->limit(4)
            ->get();

        $productResults = $products->map(fn ($p) => [
            'id'            => $p->id,
            'slug'          => $p->slug,
            'brand'         => $p->brand,
            'name'          => $p->name,
            'size'          => $p->size,
            'type'          => $p->type,
            'price'         => (float) $p->price,
            'primary_image' => $p->primary_image ? url('storage/' . $p->primary_image) : null,
            // Slug URL when the product has one (Session 92 SEO shape); the id
            // URL for legacy rows. Both resolve on GET /products/{idOrSlug}.
            'href'          => '/shop/' . ($p->slug ?: $p->id),
        ]);

        $articleResults = $articles->map(function ($a) use ($locale) {
            $t = $a->translations->first();

            return [
                'slug'     => $a->slug,
                'title'    => $t?->title,
                'category' => $t?->category,
                'date'     => $a->published_at?->toDateString(),
                'image'    => $a->image ? url('storage/' . $a->image) : null,
                'href'     => "/news/{$a->slug}",
            ];
        });

        return response()->json([
            'data' => [
                'products' => $productResults,
                'articles' => $articleResults,
                'total'    => $productResults->count() + $articleResults->count(),
            ],
        ]);
    }
}

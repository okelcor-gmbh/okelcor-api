<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $locale  = in_array($request->query('locale'), ['en', 'de', 'fr', 'es'])
            ? $request->query('locale')
            : 'en';

        $locales = array_unique([$locale, 'en']);

        $categories = Category::with(['translations' => fn ($q) => $q->whereIn('locale', $locales)])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $data = $categories->map(function ($c) use ($locale) {
            // Requested locale first → EN fallback → empty string (never null)
            $t  = $c->translations->firstWhere('locale', $locale)
                ?? $c->translations->firstWhere('locale', 'en');

            return [
                'id'       => $c->id,
                'slug'     => $c->slug,
                'image'    => $c->image ? url('storage/' . $c->image) : null,
                'title'    => $t?->title ?? '',
                'label'    => $t?->label ?? '',
                'subtitle' => $t?->subtitle ?? '',
            ];
        });

        return response()->json(['data' => $data]);
    }
}

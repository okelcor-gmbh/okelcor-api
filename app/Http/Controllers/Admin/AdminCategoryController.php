<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Models\Category;
use App\Models\CategoryTranslation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class AdminCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::with('translations')->orderBy('sort_order')->get();

        return response()->json([
            'data' => $categories->map(fn ($c) => $this->formatCategory($c)),
        ]);
    }

    public function update(UpdateCategoryRequest $request, int $id): JsonResponse
    {
        $category  = Category::findOrFail($id);
        $validated = $request->validated();

        if ($request->hasFile('image')) {
            $file     = $request->file('image');
            $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $path     = $file->storeAs('categories', $filename, 'public');
            $category->update(['image' => $path]);
        }

        if (isset($validated['sort_order'])) {
            $category->update(['sort_order' => $validated['sort_order']]);
        }
        if (isset($validated['is_active'])) {
            $category->update(['is_active' => $validated['is_active']]);
        }

        if (isset($validated['translations'])) {
            foreach ($validated['translations'] as $locale => $t) {
                if (! in_array($locale, ['en', 'de', 'fr', 'es'])) {
                    continue;
                }

                CategoryTranslation::updateOrCreate(
                    ['category_id' => $category->id, 'locale' => $locale],
                    [
                        'title'    => $t['title'],
                        'label'    => $t['label'],
                        'subtitle' => $t['subtitle'],
                    ]
                );
            }
        }

        $category->load('translations');

        return response()->json(['data' => $this->formatCategory($category)]);
    }

    private function formatCategory(Category $c): array
    {
        $translations = [];
        foreach ($c->translations as $t) {
            $translations[$t->locale] = [
                'title'    => $t->title,
                'label'    => $t->label,
                'subtitle' => $t->subtitle,
            ];
        }

        $presentLocales = $c->translations->pluck('locale')->all();
        $missingLocales = array_values(array_diff(['en', 'de', 'fr', 'es'], $presentLocales));

        return [
            'id'              => $c->id,
            'slug'            => $c->slug,
            'image'           => $c->image ? url('storage/' . $c->image) : null,
            'sort_order'      => $c->sort_order,
            'is_active'       => (bool) $c->is_active,
            'translations'    => $translations,
            'missing_locales' => $missingLocales,
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\HeroSlide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class HeroSlideController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $locale  = in_array($request->query('locale'), ['en', 'de', 'fr', 'es'])
            ? $request->query('locale')
            : 'en';

        $locales = array_unique([$locale, 'en']);

        $slides = HeroSlide::with(['translations' => fn ($q) => $q->whereIn('locale', $locales)])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'data'    => $slides->map(fn ($s) => $this->formatSlide($s, $locale))->values(),
            'message' => 'success',
        ]);
    }

    private function formatSlide(HeroSlide $s, string $locale): array
    {
        // Requested locale first → EN translation fallback → base table columns as last resort
        $t = $s->translations->firstWhere('locale', $locale)
            ?? $s->translations->firstWhere('locale', 'en');

        return [
            'id'                  => $s->id,
            'title'               => $t?->title ?? $s->title,
            'subtitle'            => $t?->subtitle ?? $s->subtitle,
            'media_type'          => $s->media_type ?? 'image',
            'image_url'           => $s->image_url ? url(Storage::url($s->image_url)) : null,
            'video_url'           => $s->video_url ? url(Storage::url($s->video_url)) : null,
            'order'               => $s->sort_order,
            'cta_primary_label'   => $t?->cta_primary ?? $s->cta_primary_label,
            'cta_primary_href'    => $s->cta_primary_href,
            'cta_secondary_label' => $t?->cta_secondary ?? $s->cta_secondary_label,
            'cta_secondary_href'  => $s->cta_secondary_href,
            'locale'              => $locale,
        ];
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HeroSlide;
use App\Models\HeroSlideTranslation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminHeroSlideController extends Controller
{
    public function index(): JsonResponse
    {
        $slides = HeroSlide::with('translations')->orderBy('sort_order')->get();

        return response()->json([
            'data'    => $slides->map(fn ($s) => $this->formatSlide($s))->values(),
            'message' => 'success',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate($this->textRules());

        $slide = HeroSlide::create([
            'title'               => $request->title,
            'subtitle'            => $request->subtitle,
            'media_type'          => $request->input('media_type', 'image'),
            'sort_order'          => $request->input('order', 1),
            'cta_primary_label'   => $request->cta_primary_label,
            'cta_primary_href'    => $request->cta_primary_href,
            'cta_secondary_label' => $request->cta_secondary_label,
            'cta_secondary_href'  => $request->cta_secondary_href,
            'is_active'           => true,
        ]);

        // Seed EN translation from the direct fields so locale fallback is consistent
        $this->syncTranslations($slide, $request);

        $slide->load('translations');

        return response()->json([
            'data'    => $this->formatSlide($slide),
            'message' => 'Hero slide created.',
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $slide = HeroSlide::with('translations')->findOrFail($id);

        return response()->json(['data' => $this->formatSlide($slide)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate($this->textRules());

        $slide = HeroSlide::findOrFail($id);
        $slide->update([
            'title'               => $request->title,
            'subtitle'            => $request->subtitle,
            'media_type'          => $request->input('media_type', $slide->media_type),
            'sort_order'          => $request->input('order', $slide->sort_order),
            'cta_primary_label'   => $request->cta_primary_label,
            'cta_primary_href'    => $request->cta_primary_href,
            'cta_secondary_label' => $request->cta_secondary_label,
            'cta_secondary_href'  => $request->cta_secondary_href,
        ]);

        // Also accept per-locale translations if provided
        $this->syncTranslations($slide, $request);

        $slide->load('translations');

        return response()->json([
            'data'    => $this->formatSlide($slide->fresh()),
            'message' => 'Hero slide updated.',
        ]);
    }

    public function uploadMedia(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'media'      => ['required', 'file', 'mimes:jpeg,png,jpg,gif,webp,svg,mp4,mov,avi,webm', 'max:51200'],
            'media_type' => ['required', Rule::in(['image', 'video'])],
        ]);

        $slide = HeroSlide::findOrFail($id);

        // Delete old file for whichever type we're replacing
        if ($request->media_type === 'image' && $slide->image_url) {
            Storage::disk('public')->delete($slide->image_url);
        }
        if ($request->media_type === 'video' && $slide->video_url) {
            Storage::disk('public')->delete($slide->video_url);
        }

        $file     = $request->file('media');
        $filename = Str::uuid() . '.' . ($file->guessExtension() ?? 'bin');
        $path     = Storage::disk('public')->putFileAs('hero', $file, $filename);

        if ($request->media_type === 'image') {
            $slide->update([
                'media_type' => 'image',
                'image_url'  => $path,
                'video_url'  => null,
            ]);
        } else {
            $slide->update([
                'media_type' => 'video',
                'video_url'  => $path,
                'image_url'  => null,
            ]);
        }

        return response()->json([
            'data'    => $this->formatSlide($slide->fresh()),
            'message' => 'Media uploaded.',
        ]);
    }

    public function destroy(int $id): Response
    {
        $slide = HeroSlide::findOrFail($id);

        if ($slide->image_url) {
            Storage::disk('public')->delete($slide->image_url);
        }
        if ($slide->video_url) {
            Storage::disk('public')->delete($slide->video_url);
        }

        $slide->delete();

        return response()->noContent();
    }

    private function textRules(): array
    {
        return [
            'title'                        => ['required', 'string', 'max:300'],
            'subtitle'                     => ['nullable', 'string', 'max:1000'],
            'media_type'                   => ['nullable', Rule::in(['image', 'video'])],
            'order'                        => ['nullable', 'integer'],
            'cta_primary_label'            => ['nullable', 'string', 'max:100'],
            'cta_primary_href'             => ['nullable', 'string', 'max:500'],
            'cta_secondary_label'          => ['nullable', 'string', 'max:100'],
            'cta_secondary_href'           => ['nullable', 'string', 'max:500'],
            // Optional per-locale translations
            'translations'                 => ['nullable', 'array'],
            'translations.*.locale'        => ['required_with:translations', Rule::in(['en', 'de', 'fr', 'es'])],
            'translations.*.title'         => ['required_with:translations', 'string', 'max:300'],
            'translations.*.subtitle'      => ['nullable', 'string', 'max:1000'],
            'translations.*.cta_primary'   => ['nullable', 'string', 'max:100'],
            'translations.*.cta_secondary' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Sync translations for a slide.
     * Always writes/updates the EN translation from the direct fields.
     * Also processes any additional locales passed in request->translations[].
     */
    private function syncTranslations(HeroSlide $slide, Request $request): void
    {
        // Always upsert the EN translation from the primary fields
        HeroSlideTranslation::updateOrCreate(
            ['slide_id' => $slide->id, 'locale' => 'en'],
            [
                'title'         => $request->title,
                'subtitle'      => $request->subtitle ?? '',
                'cta_primary'   => $request->cta_primary_label ?? '',
                'cta_secondary' => $request->cta_secondary_label ?? '',
            ]
        );

        // Process any extra locale translations sent by the admin UI
        foreach ($request->input('translations', []) as $t) {
            $locale = $t['locale'] ?? null;
            if (! $locale || ! in_array($locale, ['en', 'de', 'fr', 'es'])) {
                continue;
            }

            HeroSlideTranslation::updateOrCreate(
                ['slide_id' => $slide->id, 'locale' => $locale],
                [
                    'title'         => $t['title'],
                    'subtitle'      => $t['subtitle'] ?? '',
                    'cta_primary'   => $t['cta_primary'] ?? '',
                    'cta_secondary' => $t['cta_secondary'] ?? '',
                ]
            );
        }
    }

    private function formatSlide(HeroSlide $s): array
    {
        // Build translations map for admin response
        $translations = [];
        foreach ($s->translations ?? [] as $t) {
            $translations[$t->locale] = [
                'title'         => $t->title,
                'subtitle'      => $t->subtitle,
                'cta_primary'   => $t->cta_primary,
                'cta_secondary' => $t->cta_secondary,
            ];
        }

        return [
            'id'                  => $s->id,
            'title'               => $s->title,
            'subtitle'            => $s->subtitle,
            'media_type'          => $s->media_type ?? 'image',
            'image_url'           => $s->image_url ? url(Storage::url($s->image_url)) : null,
            'video_url'           => $s->video_url ? url(Storage::url($s->video_url)) : null,
            'order'               => $s->sort_order,
            'cta_primary_label'   => $s->cta_primary_label,
            'cta_primary_href'    => $s->cta_primary_href,
            'cta_secondary_label' => $s->cta_secondary_label,
            'cta_secondary_href'  => $s->cta_secondary_href,
            'translations'        => $translations,
        ];
    }
}

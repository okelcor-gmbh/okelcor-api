<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Services\PromotionPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminPromotionController extends Controller
{
    public function index(): JsonResponse
    {
        $promotions = Promotion::orderByDesc('created_at')->get();

        return response()->json([
            'data'    => $promotions->map(fn ($p) => $this->format($p)),
            'message' => 'success',
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $promotion = Promotion::findOrFail($id);

        return response()->json(['data' => $this->format($promotion)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'                 => ['required', 'string', 'max:200'],
            'subheadline'           => ['nullable', 'string', 'max:300'],
            'short_text'            => ['nullable', 'string', 'max:255'],
            'emoji'                 => ['nullable', 'string', 'max:16'],
            'placement'             => ['nullable', 'in:announcement_bar,shop_inline,both,shop_hero'],
            'brand_name'            => ['nullable', 'string', 'max:100'],
            'customer_type_target'  => ['nullable', 'in:b2b,b2c'],
            'discount_pct'          => ['nullable', 'numeric', 'min:0', 'max:100'],
            'promo_code'            => ['nullable', 'string', 'max:50', Rule::unique('promotions', 'code')],
            'button_text'           => ['nullable', 'string', 'max:100'],
            'button_link'           => ['nullable', 'string', 'max:300'],
            'is_active'             => ['nullable', 'boolean'],
            'start_date'            => ['nullable', 'date'],
            'end_date'              => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $data['placement'] ??= 'shop_inline';

        if (array_key_exists('promo_code', $data)) {
            $data['code'] = $data['promo_code'] ? strtoupper(trim($data['promo_code'])) : null;
            unset($data['promo_code']);
        }

        $promotion = Promotion::create($data);

        return response()->json([
            'data'    => $this->format($promotion),
            'message' => 'Promotion created.',
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $promotion = Promotion::findOrFail($id);

        $oldDiscountPct = (float) $promotion->discount_pct;

        $data = $request->validate([
            'title'                 => ['sometimes', 'string', 'max:200'],
            'subheadline'           => ['nullable', 'string', 'max:300'],
            'short_text'            => ['nullable', 'string', 'max:255'],
            'emoji'                 => ['nullable', 'string', 'max:16'],
            'placement'             => ['nullable', 'in:announcement_bar,shop_inline,both,shop_hero'],
            'brand_name'            => ['nullable', 'string', 'max:100'],
            'customer_type_target'  => ['nullable', 'in:b2b,b2c'],
            'discount_pct'          => ['nullable', 'numeric', 'min:0', 'max:100'],
            'promo_code'            => ['nullable', 'string', 'max:50', Rule::unique('promotions', 'code')->ignore($promotion->id)],
            'button_text'           => ['nullable', 'string', 'max:100'],
            'button_link'           => ['nullable', 'string', 'max:300'],
            'is_active'             => ['nullable', 'boolean'],
            'start_date'            => ['nullable', 'date'],
            'end_date'              => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        if (array_key_exists('promo_code', $data)) {
            $data['code'] = $data['promo_code'] ? strtoupper(trim($data['promo_code'])) : null;
            unset($data['promo_code']);
        }

        $promotion->update($data);

        $fresh              = $promotion->fresh();
        $recalculatedCount  = null;

        $discountChanged = array_key_exists('discount_pct', $data)
            && (float) $data['discount_pct'] !== $oldDiscountPct;

        if ($discountChanged && $fresh->brand_name) {
            $recalculatedCount = (new PromotionPricingService())->recalculateForPromotion($fresh);
        }

        $response = [
            'data'    => $this->format($fresh),
            'message' => 'Promotion updated.',
        ];

        if ($recalculatedCount !== null) {
            $response['recalculated_products'] = $recalculatedCount;
        }

        return response()->json($response);
    }

    public function toggle(int $id): JsonResponse
    {
        $promotion = Promotion::findOrFail($id);

        $promotion->update(['is_active' => ! $promotion->is_active]);

        return response()->json([
            'data'    => $this->format($promotion->fresh()),
            'message' => 'Promotion ' . ($promotion->is_active ? 'activated' : 'deactivated') . '.',
        ]);
    }

    public function destroy(int $id): \Illuminate\Http\Response
    {
        $promotion = Promotion::findOrFail($id);

        if ($promotion->image_url) {
            Storage::disk('public')->delete($promotion->image_url);
        }

        $promotion->delete();

        return response()->noContent();
    }

    public function uploadMedia(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
        ]);

        $promotion = Promotion::findOrFail($id);

        // Remove old banner
        if ($promotion->image_url) {
            Storage::disk('public')->delete($promotion->image_url);
        }

        $ext      = $request->file('image')->guessExtension() ?? 'bin';
        $filename = Str::uuid() . '.' . $ext;
        $path     = Storage::disk('public')->putFileAs('promotions', $request->file('image'), $filename);

        $promotion->update(['image_url' => $path]);

        return response()->json([
            'data'    => ['image_url' => url(Storage::url($path))],
            'message' => 'Banner uploaded.',
        ]);
    }

    private function format(Promotion $p): array
    {
        return [
            'id'                    => $p->id,
            'title'                 => $p->title,
            'subheadline'           => $p->subheadline,
            'short_text'            => $p->short_text,
            'emoji'                 => $p->emoji,
            'placement'             => $p->placement ?? 'shop_inline',
            'brand_name'            => $p->brand_name,
            'customer_type_target'  => $p->customer_type_target,
            'discount_pct'          => $p->discount_pct !== null ? (float) $p->discount_pct : null,
            'code'                  => $p->code,
            'promo_code'            => $p->code,
            'button_text'           => $p->button_text,
            'button_link'           => $p->button_link,
            'image_url'             => $p->image_url ? url(Storage::url($p->image_url)) : null,
            'is_active'             => $p->is_active,
            'start_date'            => $p->start_date?->toDateString(),
            'end_date'              => $p->end_date?->toDateString(),
            'created_at'            => $p->created_at?->toIso8601String(),
        ];
    }
}

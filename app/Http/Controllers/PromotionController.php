<?php

namespace App\Http\Controllers;

use App\Models\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class PromotionController extends Controller
{
    public function active(): JsonResponse
    {
        $today = now()->toDateString();

        $promotions = Promotion::where('is_active', true)
            ->where(function ($q) use ($today) {
                $q->whereNull('start_date')->orWhere('start_date', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $today);
            })
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $promotions->map(fn ($p) => $this->format($p))->values(),
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

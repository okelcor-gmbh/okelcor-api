<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Product;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $hasFilter = $request->filled('search')
            || $request->filled('q')
            || $request->filled('type')
            || $request->filled('brand')
            || $request->filled('season')
            || $request->filled('size')
            || $request->filled('price_min')
            || $request->filled('price_max')
            || $request->has('in_stock');

        if (! $hasFilter) {
            return response()->json([
                'data'    => [],
                'meta'    => [
                    'current_page' => 1,
                    'per_page'     => 50,
                    'total'        => 0,
                    'last_page'    => 1,
                ],
                'filters' => ['brands' => [], 'types' => [], 'seasons' => []],
                'message' => 'Please search or filter to find products.',
            ])->withHeaders(['Cache-Control' => 'no-store, no-cache, must-revalidate']);
        }

        $query = Product::with('images')->where('is_active', true);

        if ($request->has('in_stock')) {
            $query->where('in_stock', (bool) $request->input('in_stock'));
        }

        if ($request->filled('customer_type')) {
            match ($request->customer_type) {
                'b2b'   => $query->whereNotNull('price_b2b')->where('price_b2b', '>', 0),
                'b2c'   => $query->whereNotNull('price_b2c')->where('price_b2c', '>', 0),
                default => null,
            };
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('brand')) {
            $query->where('brand', $request->brand);
        }
        if ($request->filled('season')) {
            $query->where('season', $request->season);
        }
        if ($request->filled('size')) {
            $query->where('size', 'like', '%' . $request->size . '%');
        }
        if ($request->filled('price_min')) {
            $query->where('price', '>=', (float) $request->price_min);
        }
        if ($request->filled('price_max')) {
            $query->where('price', '<=', (float) $request->price_max);
        }
        $searchTerm = $request->filled('q') ? $request->q : $request->input('search');
        if ($searchTerm) {
            $query->where(function ($q) use ($searchTerm) {
                $q->where('brand', 'like', "%{$searchTerm}%")
                  ->orWhere('name', 'like', "%{$searchTerm}%")
                  ->orWhere('size', 'like', "%{$searchTerm}%")
                  ->orWhere('sku', 'like', "%{$searchTerm}%");
            });
        }

        // Filters are derived from the current (pre-pagination) result set
        $filtersQuery = clone $query;
        $filters = [
            'brands'  => $filtersQuery->clone()->distinct()->orderBy('brand')->pluck('brand'),
            'types'   => $filtersQuery->clone()->distinct()->orderBy('type')->pluck('type'),
            'seasons' => $filtersQuery->clone()->distinct()->orderBy('season')->pluck('season'),
        ];

        match ($request->input('sort', 'newest')) {
            'price_asc'  => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            default      => $query->orderByDesc('created_at'),
        };

        // Accept either per_page or limit (frontend specials component uses limit=8)
        $perPage   = min((int) $request->input('limit', $request->input('per_page', 50)), 200);
        $paginated = $query->paginate($perPage);

        $data = $paginated->map(fn ($p) => $this->formatProduct($p));

        return response()->json([
            'data'    => $data,
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
            'filters' => $filters,
            'message' => 'success',
        ])->withHeaders(['Cache-Control' => 'no-store, no-cache, must-revalidate']);
    }

    public function specs(): JsonResponse
    {
        $base = Product::where('is_active', true);

        $pluck = function (string $column) use ($base) {
            return $base->clone()
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->distinct()
                ->orderByRaw("CAST({$column} AS UNSIGNED)")
                ->pluck($column)
                ->values();
        };

        return response()->json([
            'data' => [
                'widths'       => $pluck('width'),
                'heights'      => $pluck('height'),
                'rims'         => $pluck('rim'),
                'load_indexes' => $pluck('load_index'),
                'speed_ratings' => $base->clone()
                    ->whereNotNull('speed_rating')
                    ->where('speed_rating', '!=', '')
                    ->distinct()
                    ->orderBy('speed_rating')
                    ->pluck('speed_rating')
                    ->values(),
            ],
        ])->withHeaders(['Cache-Control' => 'no-store, no-cache, must-revalidate']);
    }

    public function brands(): JsonResponse
    {
        $brands = Product::where('is_active', true)
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->distinct()
            ->orderBy('brand')
            ->pluck('brand');

        return response()->json(['data' => $brands])
            ->withHeaders(['Cache-Control' => 'no-store, no-cache, must-revalidate']);
    }

    public function show(int $id): JsonResponse
    {
        $product = Product::with('images')->where('is_active', true)->findOrFail($id);

        $related = Product::where('type', $product->type)
            ->where('id', '!=', $product->id)
            ->where('is_active', true)
            ->inRandomOrder()
            ->limit(4)
            ->get(['id', 'brand', 'name', 'size', 'price', 'price_b2b', 'price_b2c', 'primary_image']);

        $data = $this->formatProduct($product);
        $data['related'] = $related->map(fn ($r) => [
            'id'            => $r->id,
            'brand'         => $r->brand,
            'name'          => $r->name,
            'size'          => $r->size,
            'price'         => (float) $r->price,
            'price_b2b'     => $r->price_b2b !== null ? (float) $r->price_b2b : null,
            'price_b2c'     => $r->price_b2c !== null ? (float) $r->price_b2c : null,
            'primary_image' => $r->primary_image ? url('storage/' . $r->primary_image) : null,
        ]);

        return response()->json(['data' => $data])
            ->withHeaders(['Cache-Control' => 'no-store, no-cache, must-revalidate']);
    }

    private function formatProduct(Product $p): array
    {
        return [
            'id'            => $p->id,
            'sku'           => $p->sku,
            'brand'         => $p->brand,
            'name'          => $p->name,
            'size'          => $p->size,
            'spec'          => $p->spec,
            'width'         => $p->width,
            'height'        => $p->height,
            'rim'           => $p->rim,
            'load_index'    => $p->load_index,
            'speed_rating'  => $p->speed_rating,
            'season'        => $p->season,
            'type'          => $p->type,
            'price'         => (float) $p->price,
            'price_b2b'     => $p->price_b2b !== null ? (float) $p->price_b2b : null,
            'price_b2c'     => $p->price_b2c !== null ? (float) $p->price_b2c : null,
            'description'   => $p->description,
            'primary_image' => $p->primary_image ? url('storage/' . $p->primary_image) : null,
            'brand_image'   => $this->brandImageFor($p->brand),
            'images'        => $p->images->map(fn ($img) => url('storage/' . $img->path))->values(),
            'is_active'     => (bool) $p->is_active,
            'stock'         => (int) $p->stock,
            'in_stock'      => (bool) $p->in_stock,

            // Null unless the order manager has set a real number in Admin →
            // Settings, and null for anything not in stock — the frontend
            // renders this verbatim, so it must never carry a dispatch
            // promise for a tyre we don't have.
            'estimated_dispatch_days' => $p->in_stock ? $this->estimatedDispatchDays() : null,

            'tyre_batch'    => $this->formatTyreBatch($p),
        ];
    }

    /**
     * Null until an admin fills in tyre-passport data, so the frontend can
     * skip the card entirely rather than render one full of blanks.
     */
    private function formatTyreBatch(Product $p): ?array
    {
        if (! $p->hasTyreBatchData()) {
            return null;
        }

        return [
            'condition_grade'   => $p->condition_grade,
            'tread_depth_mm'    => $p->tread_depth_mm !== null ? (float) $p->tread_depth_mm : null,
            'dot_code'          => $p->dot_code,
            'inspection_date'   => $p->inspection_date?->toDateString(),
            'inspection_photos' => collect($p->inspection_photos ?? [])
                ->map(fn ($path) => url(Storage::url($path)))
                ->values()
                ->all(),
        ];
    }

    // Resolved once per request — formatProduct() runs per row.
    private ?int $dispatchDaysCache = null;
    private bool $dispatchDaysLoaded = false;

    private function estimatedDispatchDays(): ?int
    {
        if (! $this->dispatchDaysLoaded) {
            $this->dispatchDaysLoaded = true;

            $raw = SiteSetting::where('key', 'estimated_dispatch_days')->value('value');

            // Blank/absent/non-numeric all mean "not configured" — omit
            // rather than guess.
            $this->dispatchDaysCache = is_numeric($raw) ? (int) $raw : null;
        }

        return $this->dispatchDaysCache;
    }

    // Lazily loaded once per request; keyed by lowercase brand name.
    private ?array $brandLogoCache = null;

    private function brandLogoCache(): array
    {
        if ($this->brandLogoCache === null) {
            $this->brandLogoCache = Brand::whereNotNull('logo')
                ->get(['name', 'logo'])
                ->mapWithKeys(fn ($b) => [strtolower($b->name) => url(Storage::url($b->logo))])
                ->all();
        }

        return $this->brandLogoCache;
    }

    private function brandImageFor(string $brand): ?string
    {
        return $this->brandLogoCache()[strtolower($brand)] ?? null;
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ArticleHtmlSanitizer;
use App\Services\ProductSlugger;
use App\Support\ProductSearch;
use App\Support\TyreSpecs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminProductController extends Controller
{
    public function __construct(
        private ProductSlugger $slugger,
        private ArticleHtmlSanitizer $sanitizer,
    ) {}

    /**
     * GET /api/v1/admin/products/spec-options
     *
     * The tyre specification sheet, served rather than hardcoded — the admin
     * form renders whatever this returns, so adding an attribute is one entry
     * in TyreSpecs and no frontend deploy. Same pattern as the trade-document
     * upload-options endpoint (Session 76), for the same reason: the last
     * hardcoded copy of a backend vocabulary drifted.
     */
    public function specOptions(): JsonResponse
    {
        return response()->json([
            'data' => [
                'sheet' => TyreSpecs::SHEET,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Product::with('images');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('brand')) {
            $query->where('brand', $request->brand);
        }
        if ($request->has('is_active')) {
            $query->where('is_active', (bool) $request->input('is_active'));
        }
        if ($request->has('ebay_listed')) {
            $query->where('ebay_listed', (bool) $request->input('ebay_listed'));
        }
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
        if ($request->filled('segment')) {
            match ($request->segment) {
                'b2b'   => $query->whereNotNull('price_b2b')->where('price_b2b', '>', 0),
                'b2c'   => $query->whereNotNull('price_b2c')->where('price_b2c', '>', 0),
                default => null,
            };
        }
        // Same search the shop and navbar run — one definition, so what the
        // marketer finds in the panel is what a customer finds on the site.
        //
        // `q` OR `search`, same as the public endpoint: the admin panel has
        // sent `q` since it was built while this endpoint only ever read
        // `search`, so the panel's search box silently filtered nothing —
        // typing a SKU worked only if the product happened to be on the first
        // page. That mismatch is the actual "cannot search products" report.
        ProductSearch::apply($query, $request->filled('q') ? $request->q : $request->input('search'));

        $perPage   = min((int) $request->input('per_page', 24), 100);
        $paginated = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data'    => $paginated->map(fn ($p) => $this->formatProduct($p)),
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
            'message' => 'success',
        ]);
    }

    /**
     * Bulk stock update. Historically boolean-only (`in_stock`); now also
     * accepts a real `stock` quantity, since that column was previously
     * writable *only* by the Wix/Rapid importers — an order manager had no
     * way to correct a wrong number from the panel at all, while the public
     * product payload has been exposing it all along.
     *
     * Either field alone is valid, so existing callers sending just
     * `in_stock` are unaffected.
     */
    public function bulkStock(Request $request): JsonResponse
    {
        $request->validate([
            'in_stock' => ['required_without:stock', 'nullable', 'boolean'],
            'stock'    => ['required_without:in_stock', 'nullable', 'integer', 'min:0'],
            'all'      => ['required', 'boolean'],
            'ids'      => ['nullable', 'array'],
            'ids.*'    => ['integer'],
        ]);

        $query = Product::query();

        if (! $request->boolean('all')) {
            $request->validate(['ids' => ['required', 'array', 'min:1']]);
            $query->whereIn('id', $request->ids);
        }

        $update = [];

        if ($request->has('stock')) {
            $update['stock'] = (int) $request->input('stock');
        }

        if ($request->has('in_stock')) {
            $update['in_stock'] = $request->boolean('in_stock');
        } elseif (array_key_exists('stock', $update)) {
            // Keep the flag coherent with the number rather than allowing
            // "In Stock" to sit on top of a zero quantity.
            $update['in_stock'] = $update['stock'] > 0;
        }

        $affected = $query->update($update);

        return response()->json([
            'message'  => 'Updated successfully.',
            'affected' => $affected,
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $data              = $request->validated();
        $data['spec']    ??= '';
        $data['is_active'] ??= true;
        $data              = $this->reconcileStockFlag($data);
        $data              = $this->applyOptimizationFields($data);

        // Every product gets a slug at birth: typed one normalized, blank one
        // generated from brand+name+season (the marketing brief's URL shape).
        $data['slug'] = ! empty($data['slug'])
            ? $this->slugger->fromInput($data['slug'])
            : $this->slugger->generate($data['brand'], $data['name'], $data['season']);

        // Handle primary_image file upload if present
        if ($request->hasFile('primary_image')) {
            $data['primary_image'] = $this->storeImage($request->file('primary_image'), 'products');
        } else {
            unset($data['primary_image']); // prevent overwriting with null
        }

        $product = Product::create($data);
        $product->load('images');

        return response()->json(['data' => $this->formatProduct($product)], 201);
    }

    public function show(int $id): JsonResponse
    {
        $product = Product::with('images')->findOrFail($id);

        return response()->json(['data' => $this->formatProduct($product)]);
    }

    public function update(UpdateProductRequest $request, int $id): JsonResponse
    {
        $product  = Product::findOrFail($id);
        $data     = $this->reconcileStockFlag($request->validated());
        $data     = $this->applyOptimizationFields($data);

        // The slug moves ONLY when the request carries one. A rename must not
        // relocate a live URL as a side effect — that URL is in Google's index
        // and in campaign e-mails. Blank slug on a product that never had one
        // (pre-migration rows before the backfill) gets a generated one.
        if (array_key_exists('slug', $data)) {
            $data['slug'] = ! empty($data['slug'])
                ? $this->slugger->fromInput($data['slug'], $product->id)
                : ($product->slug ?? $this->slugger->generate(
                    $data['brand'] ?? $product->brand,
                    $data['name'] ?? $product->name,
                    $data['season'] ?? $product->season,
                    $product->id
                ));
        }

        // Handle primary_image file upload if present
        if ($request->hasFile('primary_image')) {
            // Delete old primary image from storage
            if ($product->primary_image) {
                Storage::disk('public')->delete($product->primary_image);
            }
            $data['primary_image'] = $this->storeImage($request->file('primary_image'), 'products');
        } else {
            unset($data['primary_image']); // leave existing value untouched
        }

        $product->update($data);
        $product->load('images');

        return response()->json(['data' => $this->formatProduct($product)]);
    }

    public function destroyAll(): JsonResponse
    {
        $count = Product::count();
        Product::query()->delete(); // soft-deletes via SoftDeletes trait

        return response()->json([
            'data'    => ['deleted' => $count],
            'message' => "{$count} products moved to trash.",
        ]);
    }

    public function restore(int $id): JsonResponse
    {
        $product = Product::onlyTrashed()->findOrFail($id);
        $product->restore();

        return response()->json([
            'data'    => ['id' => $product->id],
            'message' => 'Product restored',
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        // Delete primary image from storage
        if ($product->primary_image) {
            Storage::disk('public')->delete($product->primary_image);
        }

        // Delete gallery images from storage
        foreach ($product->images as $image) {
            Storage::disk('public')->delete($image->path);
        }

        $product->images()->delete();
        $product->delete(); // soft delete — excluded from all queries automatically

        return response()->json(null, 204);
    }

    public function uploadImages(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'images'   => ['required', 'array', 'min:1'],
            'images.*' => ['file', 'mimes:jpeg,png,jpg,gif,webp,svg', 'max:5120'],
        ]);

        $product = Product::findOrFail($id);
        $files   = $request->file('images');

        $created = [];
        foreach ($files as $file) {
            $path = $this->storeImage($file, 'products');

            $image     = ProductImage::create([
                'product_id' => $product->id,
                'path'       => $path,
                'sort_order' => 0,
            ]);
            $created[] = [
                'id'         => $image->id,
                'product_id' => $image->product_id,
                'url'        => url(Storage::url($path)),
            ];
        }

        return response()->json(['data' => $created], 201);
    }

    public function deleteImage(int $productId, int $imageId): JsonResponse
    {
        $image = ProductImage::where('product_id', $productId)->findOrFail($imageId);

        Storage::disk('public')->delete($image->path);
        $image->delete();

        return response()->json(['message' => 'Image deleted.']);
    }

    /**
     * Appends inspection photos to a product's tyre passport. Separate from
     * the gallery (`product_images`) on purpose — these are evidence of a
     * specific inspection, not marketing shots, and the frontend renders them
     * inside the passport card rather than the carousel.
     */
    public function uploadInspectionPhotos(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'photos'   => ['required', 'array', 'min:1', 'max:10'],
            'photos.*' => ['file', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
        ]);

        $product = Product::findOrFail($id);
        $photos  = $product->inspection_photos ?? [];

        foreach ($request->file('photos') as $file) {
            $photos[] = $this->storeImage($file, 'inspections');
        }

        $product->update(['inspection_photos' => array_values($photos)]);
        $product->load('images');

        return response()->json([
            'data'    => $this->formatProduct($product),
            'message' => 'Inspection photos uploaded.',
        ], 201);
    }

    /**
     * Removes one inspection photo by its index in the stored array.
     */
    public function deleteInspectionPhoto(int $id, int $index): JsonResponse
    {
        $product = Product::findOrFail($id);
        $photos  = $product->inspection_photos ?? [];

        if (! array_key_exists($index, $photos)) {
            return response()->json(['message' => 'Inspection photo not found.'], 404);
        }

        Storage::disk('public')->delete($photos[$index]);
        unset($photos[$index]);

        $product->update(['inspection_photos' => array_values($photos)]);
        $product->load('images');

        return response()->json([
            'data'    => $this->formatProduct($product),
            'message' => 'Inspection photo deleted.',
        ]);
    }

    /**
     * Keeps `in_stock` consistent with `stock` when a caller sets the number
     * but not the flag. An explicit `in_stock` always wins — an admin can
     * still deliberately hide a product that has quantity on hand.
     */
    private function reconcileStockFlag(array $data): array
    {
        if (array_key_exists('stock', $data) && $data['stock'] !== null && ! array_key_exists('in_stock', $data)) {
            $data['in_stock'] = ((int) $data['stock']) > 0;
        }

        return $data;
    }

    private function storeImage($file, string $collection): string
    {
        $ext      = $file->guessExtension() ?? 'bin';
        $filename = Str::uuid() . '.' . $ext;

        return Storage::disk('public')->putFileAs($collection, $file, $filename);
    }

    /**
     * The Session 92 content fields, shared by store and update.
     *
     * `description_html` gets the exact treatment article bodies get — same
     * sanitizer, same rules, same failure mode (422, not a stored script tag).
     * `specs`, when present, REPLACES the stored object: the admin form always
     * sends the whole sheet, and merge semantics would make a cleared field
     * unclearable. Unknown keys and blanks are dropped so the JSON column
     * never accumulates junk a removed form field once wrote.
     */
    private function applyOptimizationFields(array $data): array
    {
        if (array_key_exists('description_html', $data) && $data['description_html'] !== null) {
            // Same failure contract as article bodies: a purifier failure is a
            // 422 the form can show, never a 500 that loses the whole save.
            try {
                $data['description_html'] = $this->sanitizer->sanitize($data['description_html']) ?: null;
            } catch (\RuntimeException) {
                abort(422, 'The rich description could not be processed. Simplify its formatting and try again.');
            }
        }

        if (array_key_exists('specs', $data)) {
            $data['specs'] = TyreSpecs::cleanForStorage($data['specs']);
        }

        return $data;
    }

    private function formatProduct(Product $p): array
    {
        return [
            'id'            => $p->id,
            'sku'           => $p->sku,
            'slug'          => $p->slug,
            'brand'         => $p->brand,
            'name'          => $p->name,
            'size'          => $p->size,
            'spec'          => $p->spec,
            'season'        => $p->season,
            'type'          => $p->type,
            'price'         => (float) $p->price,
            'price_b2b'     => $p->price_b2b !== null ? (float) $p->price_b2b : null,
            'price_b2c'     => $p->price_b2c !== null ? (float) $p->price_b2c : null,
            'description'   => $p->description,
            'description_html' => $p->description_html,
            'specs'         => $p->specs,
            'shipping_info' => $p->shipping_info,
            'returns_info'  => $p->returns_info,
            // The column-backed half of the spec sheet — the form initializes
            // its Artikelmerkmale section from these.
            'width'         => $p->width,
            'height'        => $p->height,
            'rim'           => $p->rim,
            'load_index'    => $p->load_index,
            'speed_rating'  => $p->speed_rating,
            'ean'           => $p->ean,
            'tread_depth_mm' => $p->tread_depth_mm !== null ? (float) $p->tread_depth_mm : null,
            'primary_image' => $p->primary_image ? url(Storage::url($p->primary_image)) : null,
            'images'        => $p->images->map(fn ($img) => [
                'id'  => $img->id,
                'url' => url(Storage::url($img->path)),
            ])->values(),
            'is_active'     => (bool) $p->is_active,
            'in_stock'      => (bool) $p->in_stock,
            'stock'         => (int) $p->stock,
            'ebay_listed'   => (bool) $p->ebay_listed,
            'ebay_item_id'  => $p->ebay_item_id,
            'sort_order'    => $p->sort_order,
            'tyre_batch'    => $this->formatTyreBatch($p),
            'created_at'    => $p->created_at?->toIso8601String(),
        ];
    }

    /**
     * Null until an admin enters something, so the frontend can decide
     * whether to render a passport card at all instead of one full of blanks.
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
}

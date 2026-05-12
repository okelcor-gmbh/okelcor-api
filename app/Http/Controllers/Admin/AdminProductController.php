<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminProductController extends Controller
{
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
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('brand', 'like', "%{$s}%")
                  ->orWhere('name', 'like', "%{$s}%")
                  ->orWhere('sku', 'like', "%{$s}%");
            });
        }

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

    public function bulkStock(Request $request): JsonResponse
    {
        $request->validate([
            'in_stock' => ['required', 'boolean'],
            'all'      => ['required', 'boolean'],
            'ids'      => ['nullable', 'array'],
            'ids.*'    => ['integer'],
        ]);

        $query = Product::query();

        if (! $request->boolean('all')) {
            $request->validate(['ids' => ['required', 'array', 'min:1']]);
            $query->whereIn('id', $request->ids);
        }

        $affected = $query->update(['in_stock' => $request->boolean('in_stock')]);

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
        $data     = $request->validated();

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

    private function storeImage($file, string $collection): string
    {
        $ext      = $file->guessExtension() ?? 'bin';
        $filename = Str::uuid() . '.' . $ext;

        return Storage::disk('public')->putFileAs($collection, $file, $filename);
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
            'season'        => $p->season,
            'type'          => $p->type,
            'price'         => (float) $p->price,
            'price_b2b'     => $p->price_b2b !== null ? (float) $p->price_b2b : null,
            'price_b2c'     => $p->price_b2c !== null ? (float) $p->price_b2c : null,
            'description'   => $p->description,
            'primary_image' => $p->primary_image ? url(Storage::url($p->primary_image)) : null,
            'images'        => $p->images->map(fn ($img) => [
                'id'  => $img->id,
                'url' => url(Storage::url($img->path)),
            ])->values(),
            'is_active'     => (bool) $p->is_active,
            'in_stock'      => (bool) $p->in_stock,
            'ebay_listed'   => (bool) $p->ebay_listed,
            'ebay_item_id'  => $p->ebay_item_id,
            'sort_order'    => $p->sort_order,
            'created_at'    => $p->created_at?->toIso8601String(),
        ];
    }
}

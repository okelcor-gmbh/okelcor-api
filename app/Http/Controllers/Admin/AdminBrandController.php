<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\ArticleHtmlSanitizer;
use App\Support\TyreSpecs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminBrandController extends Controller
{
    public function __construct(private ArticleHtmlSanitizer $sanitizer) {}
    public function index(): JsonResponse
    {
        $brands = Brand::orderBy('sort_order')->get();

        return response()->json([
            'data'    => $brands->map(fn ($b) => $this->formatBrand($b))->values(),
            'message' => 'success',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $brand = Brand::create([
            'name'       => $request->name,
            'sort_order' => 0,
        ]);

        return response()->json([
            'data'    => $this->formatBrand($brand),
            'message' => 'Brand created.',
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->formatBrand(Brand::findOrFail($id))]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        // Content defaults (Session 93): entered once here, inherited by every
        // product of this brand that has no value of its own — with ~15,000
        // products, per-product entry was never a workflow. Same validation
        // and the same sanitizer as the product's own fields, so a value is
        // held to one standard wherever it enters the chain.
        $data = $request->validate([
            'name'             => ['required', 'string', 'max:100'],
            'description_html' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'shipping_info'    => ['sometimes', 'nullable', 'string', 'max:2000'],
            'returns_info'     => ['sometimes', 'nullable', 'string', 'max:2000'],
        ] + TyreSpecs::validationRules());

        if (array_key_exists('description_html', $data) && $data['description_html'] !== null) {
            // Same failure contract as article bodies: 422, never a 500.
            try {
                $data['description_html'] = $this->sanitizer->sanitize($data['description_html']) ?: null;
            } catch (\RuntimeException) {
                abort(422, 'The brand description could not be processed. Simplify its formatting and try again.');
            }
        }

        if (array_key_exists('specs', $data)) {
            $data['specs'] = TyreSpecs::cleanForStorage($data['specs']);
        }

        $brand = Brand::findOrFail($id);
        $brand->update($data);

        return response()->json([
            'data'    => $this->formatBrand($brand->fresh()),
            'message' => 'Brand updated.',
        ]);
    }

    public function uploadLogo(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'file', 'mimes:jpeg,png,jpg,webp,svg', 'max:5120'],
        ]);

        $brand = Brand::findOrFail($id);

        if ($brand->logo) {
            Storage::disk('public')->delete($brand->logo);
        }

        $filename = Str::uuid() . '.' . ($request->file('logo')->guessExtension() ?? 'bin');
        $path     = Storage::disk('public')->putFileAs('brands', $request->file('logo'), $filename);
        $brand->update(['logo' => $path]);

        return response()->json([
            'data'    => $this->formatBrand($brand->fresh()),
            'message' => 'Logo uploaded.',
        ]);
    }

    public function destroy(int $id): Response
    {
        $brand = Brand::findOrFail($id);

        if ($brand->logo) {
            Storage::disk('public')->delete($brand->logo);
        }

        $brand->delete();

        return response()->noContent();
    }

    private function formatBrand(Brand $b): array
    {
        return [
            'id'       => $b->id,
            'name'     => $b->name,
            'logo_url' => $b->logo ? url(Storage::url($b->logo)) : '',
            'order'    => $b->sort_order,
            // Content defaults (Session 93) — inherited by every product of
            // this brand that has no value of its own.
            'description_html' => $b->description_html ?? null,
            'specs'            => $b->specs ?? null,
            'shipping_info'    => $b->shipping_info ?? null,
            'returns_info'     => $b->returns_info ?? null,
        ];
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminBrandController extends Controller
{
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
        $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $brand = Brand::findOrFail($id);
        $brand->update(['name' => $request->name]);

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
        ];
    }
}

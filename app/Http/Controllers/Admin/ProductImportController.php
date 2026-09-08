<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\WixProductImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImportController extends Controller
{
    public function __construct(private WixProductImportService $importer) {}

    /**
     * POST /api/v1/admin/products/import
     *
     * ?segment=b2b → price column written to price_b2b only
     * ?segment=b2c → price column written to price_b2c only
     * no segment   → price written to price; within-file duplicate SKUs merged as B2B/B2C pair
     */
    public function import(Request $request): JsonResponse
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $request->validate([
            'file'    => ['required', 'file', 'extensions:csv', 'max:51200'],
            'segment' => ['nullable', 'string', 'in:b2b,b2c'],
        ]);

        try {
            $result = $this->importer->import(
                $request->file('file')->getRealPath(),
                $request->input('segment')
            );

            return response()->json([
                'data' => [
                    'imported' => $result['imported'],
                    'updated'  => $result['updated'],
                    'skipped'  => $result['skipped'],
                    'errors'   => $result['errors'],
                ],
                'message' => 'Import completed successfully.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'data' => [
                    'imported' => 0,
                    'updated'  => 0,
                    'skipped'  => 0,
                    'errors'   => [['row' => null, 'message' => $e->getMessage()]],
                ],
                'message' => 'Import failed.',
            ], 422);
        }
    }

    /**
     * GET /api/v1/admin/products/export
     *
     * ?segment=b2b → products where price_b2b IS NOT NULL; price column = price_b2b
     * ?segment=b2c → products where price_b2c IS NOT NULL; price column = price_b2c
     * no segment   → full catalogue; price column = base price
     */
    public function export(Request $request): StreamedResponse
    {
        $segment  = $request->input('segment');
        // ?brand= — one brand's products only, for the scripted-edit
        // round-trip: export Michelin, edit descriptions in Python, import
        // the same file back (the importer upserts by SKU).
        $brand    = trim((string) $request->input('brand', ''));
        $datePart = now()->format('Y-m-d_His');

        $brandPart = $brand !== '' ? \Illuminate\Support\Str::slug($brand) . '-' : '';
        $filename  = match ($segment) {
            'b2b'   => "products-{$brandPart}b2b-{$datePart}.csv",
            'b2c'   => "products-{$brandPart}b2c-{$datePart}.csv",
            default => "products-{$brandPart}{$datePart}.csv",
        };

        return response()->streamDownload(function () use ($segment, $brand) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'sku', 'name', 'brand', 'price', 'description', 'visible',
                'season', 'type', 'size', 'spec',
                'width', 'height', 'rim', 'load_index', 'speed_rating',
                'inventory', 'cost', 'created_at',
            ]);

            $query = Product::withoutTrashed()->orderBy('id');

            if ($brand !== '') {
                $query->where('brand', $brand);
            }

            if ($segment === 'b2b') {
                $query->whereNotNull('price_b2b');
            } elseif ($segment === 'b2c') {
                $query->whereNotNull('price_b2c');
            }

            $query->chunk(500, function ($products) use ($handle, $segment) {
                foreach ($products as $p) {
                    $price = match ($segment) {
                        'b2b'   => $p->price_b2b,
                        'b2c'   => $p->price_b2c,
                        default => $p->price,
                    };

                    fputcsv($handle, [
                        $p->sku,
                        $p->brand ? "{$p->brand} {$p->name}" : $p->name,
                        $p->brand,
                        $price,
                        $p->description,
                        $p->is_active ? 'True' : 'False',
                        $p->season,
                        $p->type,
                        $p->size,
                        $p->spec,
                        $p->width,
                        $p->height,
                        $p->rim,
                        $p->load_index,
                        $p->speed_rating,
                        $p->stock,
                        $p->cost_price,
                        $p->created_at?->toIso8601String(),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}

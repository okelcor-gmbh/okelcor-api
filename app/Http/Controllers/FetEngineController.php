<?php

namespace App\Http\Controllers;

use App\Models\FetEngine;
use App\Models\FetPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FetEngineController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = FetEngine::query();

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        $search = $request->input('search');
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('manufacturer', 'like', "%{$search}%")
                  ->orWhere('model_series', 'like', "%{$search}%")
                  ->orWhere('engine_code', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 50), 200);

        $result = $query
            ->orderBy('category')
            ->orderBy('manufacturer')
            ->paginate($perPage);

        // Retail price per tier. `publicMap()` returns price and currency
        // ONLY — our supplier cost lives in the same table and must never
        // reach this route, which is unauthenticated.
        $prices = FetPrice::publicMap();

        $items = array_map(function (FetEngine $engine) use ($prices) {
            $tier = FetPrice::tierFor($engine->fet_model);
            $row  = $engine->toArray();

            $row['fet_tier'] = $tier;
            $row['price']    = $tier === null ? null : ($prices[$tier]['price'] ?? null);
            $row['currency'] = $tier === null ? null : ($prices[$tier]['currency'] ?? 'EUR');

            return $row;
        }, $result->items());

        return response()->json([
            'data' => $items,
            'meta' => [
                'total'        => $result->total(),
                'current_page' => $result->currentPage(),
                'last_page'    => $result->lastPage(),
                'per_page'     => $result->perPage(),
            ],
        ]);
    }
}

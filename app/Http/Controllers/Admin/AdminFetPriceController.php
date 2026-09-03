<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FetPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * FET pricing — `fet.pricing` (super_admin, admin, finance).
 *
 * Deliberately NOT under `products.edit`, which is where the FET engine
 * routes live. That key is held by editors, content managers and marketing
 * and NOT by finance — so putting the price list there would have locked
 * finance out of the one number that is theirs, which is the same fault
 * reported twice in the last week on the snapshot board and the to-do list.
 *
 * This is the only place `cost_price` is readable or writable.
 */
class AdminFetPriceController extends Controller
{
    // ── GET /api/v1/admin/fet/prices ─────────────────────────────────────────
    public function index(): JsonResponse
    {
        if (! FetPrice::available()) {
            return response()->json([
                'data'    => [],
                'meta'    => ['fet_pricing_available' => false],
                'message' => 'FET pricing is not available yet — the database migration has not run.',
            ]);
        }

        return response()->json([
            'data' => FetPrice::adminRows(),
            'meta' => [
                'fet_pricing_available' => true,
                'currency'              => 'EUR',
                // Said plainly, because the difference is the whole risk:
                // the supplier list is what we pay, not what we charge.
                'note' => 'cost_price is the supplier price. price is what the customer pays '
                    . 'and is the only figure served publicly — a tier with no price shows no price on the website.',
            ],
            'message' => 'success',
        ]);
    }

    // ── PUT /api/v1/admin/fet/prices ─────────────────────────────────────────
    //
    // Takes the whole list, so finance sets all four in one save rather than
    // four requests that can half-apply.
    public function update(Request $request): JsonResponse
    {
        if (! FetPrice::available()) {
            return response()->json([
                'message' => 'FET pricing is not available yet — the database migration has not run.',
            ], 503);
        }

        $data = $request->validate([
            'rows'              => ['required', 'array', 'min:1', 'max:4'],
            'rows.*.tier'       => ['required', Rule::in(FetPrice::TIERS)],
            'rows.*.price'      => ['present', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'rows.*.cost_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
        ]);

        $warnings = [];

        foreach ($data['rows'] as $row) {
            $price = FetPrice::where('tier', $row['tier'])->first();

            if (! $price) {
                continue;
            }

            $price->price = $row['price'];

            if (array_key_exists('cost_price', $row)) {
                $price->cost_price = $row['cost_price'];
            }

            // Not refused — a loss-leader is a legitimate commercial choice
            // and finance is the authority here. But selling below what we
            // pay is worth saying out loud rather than discovering in a
            // month-end margin report.
            if ($price->price !== null && $price->cost_price !== null
                && (float) $price->price < (float) $price->cost_price) {
                $warnings[] = $price->label . ' sells below cost ('
                    . number_format((float) $price->price, 2) . ' vs '
                    . number_format((float) $price->cost_price, 2) . ').';
            }

            $price->updated_by = $request->user()?->id;
            $price->save();
        }

        return response()->json([
            'data'    => FetPrice::adminRows(),
            'meta'    => ['warnings' => $warnings],
            'message' => $warnings === []
                ? 'FET prices updated.'
                : 'FET prices updated, with warnings.',
        ]);
    }
}

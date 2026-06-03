<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * CRUD for quote request line items (quote_request_items table).
 *
 * Items stored here are used as the primary source for:
 *   - Proposal drafts (AdminProposalController::buildItemsFromQuote)
 *   - Convert-to-order (admin can copy items from here into the order payload)
 *
 * Routes (all under permission:quotes.update):
 *   GET    /admin/quote-requests/{id}/items
 *   POST   /admin/quote-requests/{id}/items
 *   PATCH  /admin/quote-requests/{id}/items/{itemId}
 *   DELETE /admin/quote-requests/{id}/items/{itemId}
 *   POST   /admin/quote-requests/{id}/items/import-from-inquiry
 */
class AdminQuoteRequestItemController extends Controller
{
    // ── GET /admin/quote-requests/{id}/items ─────────────────────────────────

    public function index(int $id): JsonResponse
    {
        $quote = QuoteRequest::findOrFail($id);

        return response()->json([
            'data'    => $quote->items->map(fn ($item) => $this->format($item))->values(),
            'meta'    => ['count' => $quote->items->count()],
            'message' => 'success',
        ]);
    }

    // ── POST /admin/quote-requests/{id}/items ────────────────────────────────

    public function store(Request $request, int $id): JsonResponse
    {
        $quote = QuoteRequest::findOrFail($id);

        $data = $request->validate([
            'brand'       => ['nullable', 'string', 'max:100'],
            'model'       => ['nullable', 'string', 'max:200'],
            'size'        => ['nullable', 'string', 'max:100'],
            'season'      => ['nullable', 'string', 'max:50'],
            'load_index'  => ['nullable', 'string', 'max:20'],
            'speed_index' => ['nullable', 'string', 'max:10'],
            'condition'   => ['nullable', 'string', 'in:new,used'],
            'quantity'    => ['required', 'integer', 'min:1'],
            'unit_price'  => ['nullable', 'numeric', 'min:0'],
            'currency'    => ['sometimes', 'string', 'size:3'],
            'notes'       => ['nullable', 'string', 'max:500'],
            'sort_order'  => ['sometimes', 'integer', 'min:0'],
        ]);

        // At least one identifying field must be present
        if (empty($data['brand']) && empty($data['model']) && empty($data['size'])) {
            return response()->json([
                'message' => 'At least one of brand, model, or size is required.',
                'code'    => 'item_no_description',
            ], 422);
        }

        $item = QuoteRequestItem::create([
            'quote_request_id' => $quote->id,
            'brand'            => $data['brand'] ?? null,
            'model'            => $data['model'] ?? null,
            'size'             => $data['size'] ?? null,
            'season'           => $data['season'] ?? null,
            'load_index'       => $data['load_index'] ?? null,
            'speed_index'      => $data['speed_index'] ?? null,
            'condition'        => $data['condition'] ?? null,
            'quantity'         => $data['quantity'],
            'unit_price'       => $data['unit_price'] ?? null,
            'currency'         => strtoupper($data['currency'] ?? 'EUR'),
            'notes'            => $data['notes'] ?? null,
            'sort_order'       => $data['sort_order'] ?? $quote->items()->count(),
        ]);

        Log::info('[quote_item_added] Quote item added', [
            'event'     => 'quote_item_added',
            'quote_ref' => $quote->ref_number,
            'item_id'   => $item->id,
            'by_admin'  => $request->user()?->id,
        ]);

        return response()->json([
            'data'    => $this->format($item),
            'message' => 'Item added.',
        ], 201);
    }

    // ── PATCH /admin/quote-requests/{id}/items/{itemId} ──────────────────────

    public function update(Request $request, int $id, int $itemId): JsonResponse
    {
        $quote = QuoteRequest::findOrFail($id);
        $item  = QuoteRequestItem::where('quote_request_id', $quote->id)->findOrFail($itemId);

        $data = $request->validate([
            'brand'       => ['sometimes', 'nullable', 'string', 'max:100'],
            'model'       => ['sometimes', 'nullable', 'string', 'max:200'],
            'size'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'season'      => ['sometimes', 'nullable', 'string', 'max:50'],
            'load_index'  => ['sometimes', 'nullable', 'string', 'max:20'],
            'speed_index' => ['sometimes', 'nullable', 'string', 'max:10'],
            'condition'   => ['sometimes', 'nullable', 'string', 'in:new,used'],
            'quantity'    => ['sometimes', 'integer', 'min:1'],
            'unit_price'  => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency'    => ['sometimes', 'string', 'size:3'],
            'notes'       => ['sometimes', 'nullable', 'string', 'max:500'],
            'sort_order'  => ['sometimes', 'integer', 'min:0'],
        ]);

        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }

        $item->update($data);

        Log::info('[quote_item_updated] Quote item updated', [
            'event'     => 'quote_item_updated',
            'quote_ref' => $quote->ref_number,
            'item_id'   => $item->id,
            'by_admin'  => $request->user()?->id,
        ]);

        return response()->json([
            'data'    => $this->format($item->fresh()),
            'message' => 'Item updated.',
        ]);
    }

    // ── DELETE /admin/quote-requests/{id}/items/{itemId} ─────────────────────

    public function destroy(Request $request, int $id, int $itemId): JsonResponse
    {
        $quote = QuoteRequest::findOrFail($id);
        $item  = QuoteRequestItem::where('quote_request_id', $quote->id)->findOrFail($itemId);

        $item->delete();

        Log::info('[quote_item_deleted] Quote item deleted', [
            'event'     => 'quote_item_deleted',
            'quote_ref' => $quote->ref_number,
            'item_id'   => $itemId,
            'by_admin'  => $request->user()?->id,
        ]);

        return response()->json(['message' => 'Item removed.']);
    }

    // ── POST /admin/quote-requests/{id}/items/import-from-inquiry ────────────

    /**
     * Parse the quote's existing tyre_items JSON and/or legacy tyre_size/quantity
     * fields and create quote_request_items rows from them.
     *
     * Safe rules:
     *   - Never overwrites existing items (returns 409 if any items already exist,
     *     unless ?force=true is passed)
     *   - Skips rows with no identifiable size/brand
     *   - unit_price is always null (admin fills in pricing)
     *   - Returns a dry-run preview when ?dry_run=true
     */
    public function importFromInquiry(Request $request, int $id): JsonResponse
    {
        $quote = QuoteRequest::findOrFail($id);

        $dryRun = $request->boolean('dry_run');
        $force  = $request->boolean('force');

        // Guard: existing items
        $existingCount = $quote->items()->count();
        if ($existingCount > 0 && ! $force) {
            return response()->json([
                'message'        => "This quote already has {$existingCount} item(s). Pass ?force=true to append anyway.",
                'code'           => 'items_already_exist',
                'existing_count' => $existingCount,
            ], 409);
        }

        $parsed = $this->parseInquiryItems($quote);

        if (empty($parsed)) {
            return response()->json([
                'message' => 'No structured tyre items found in this inquiry. Add items manually.',
                'code'    => 'nothing_to_import',
            ], 422);
        }

        if ($dryRun) {
            return response()->json([
                'data'    => $parsed,
                'meta'    => ['count' => count($parsed), 'dry_run' => true],
                'message' => count($parsed) . ' item(s) would be imported. Re-send without ?dry_run=true to apply.',
            ]);
        }

        $created = [];
        $nextSort = $existingCount;

        foreach ($parsed as $row) {
            $item = QuoteRequestItem::create([
                'quote_request_id' => $quote->id,
                'brand'            => $row['brand'],
                'model'            => $row['model'],
                'size'             => $row['size'],
                'season'           => $row['season'],
                'condition'        => $row['condition'],
                'quantity'         => $row['quantity'],
                'unit_price'       => null,
                'currency'         => 'EUR',
                'sort_order'       => $nextSort++,
            ]);
            $created[] = $this->format($item);
        }

        Log::info('[quote_items_imported] Items imported from inquiry', [
            'event'     => 'quote_items_imported',
            'quote_ref' => $quote->ref_number,
            'count'     => count($created),
            'by_admin'  => $request->user()?->id,
        ]);

        return response()->json([
            'data'    => $created,
            'meta'    => ['count' => count($created), 'dry_run' => false],
            'message' => count($created) . ' item(s) imported from inquiry data. Set unit prices before sending the proposal.',
        ], 201);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Parse the quote's existing tyre fields into a candidate items array.
     * Does NOT write to the DB — used by importFromInquiry and dry-run.
     *
     * @return array<int, array>
     */
    private function parseInquiryItems(QuoteRequest $quote): array
    {
        $brand     = $quote->brand_preference ?: null;
        $condition = $quote->tyre_condition ?: null;
        $items     = [];

        // 1. tyre_items JSON array (multi-row)
        if (! empty($quote->tyre_items) && is_array($quote->tyre_items)) {
            foreach ($quote->tyre_items as $row) {
                $size = trim((string) ($row['size'] ?? ''));
                if ($size === '') {
                    continue;
                }
                $qty = max(1, (int) ($row['quantity'] ?? 1));
                $items[] = [
                    'brand'     => $brand,
                    'model'     => null,
                    'size'      => $size,
                    'season'    => null,
                    'condition' => $condition,
                    'quantity'  => $qty,
                ];
            }
        }

        // 2. Legacy single-row tyre_size + quantity
        if (empty($items)) {
            $legacySize = trim((string) ($quote->tyre_size ?? ''));
            if ($legacySize !== '') {
                preg_match('/^(\d+)/', (string) ($quote->quantity ?? '1'), $m);
                $qty = max(1, (int) ($m[1] ?? 1));
                $items[] = [
                    'brand'     => $brand,
                    'model'     => null,
                    'size'      => $legacySize,
                    'season'    => null,
                    'condition' => $condition,
                    'quantity'  => $qty,
                ];
            }
        }

        return $items;
    }

    private function format(QuoteRequestItem $item): array
    {
        return [
            'id'          => $item->id,
            'brand'       => $item->brand,
            'model'       => $item->model,
            'size'        => $item->size,
            'season'      => $item->season,
            'load_index'  => $item->load_index,
            'speed_index' => $item->speed_index,
            'condition'   => $item->condition,
            'quantity'    => $item->quantity,
            'unit_price'  => $item->unit_price !== null ? (float) $item->unit_price : null,
            'line_total'  => $item->line_total,
            'currency'    => $item->currency,
            'notes'       => $item->notes,
            'sort_order'  => $item->sort_order,
            'product_id'  => $item->product_id,
        ];
    }
}

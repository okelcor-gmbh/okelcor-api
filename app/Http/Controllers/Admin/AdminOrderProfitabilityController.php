<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderCostLine;
use App\Models\OrderFinanceRecord;
use App\Models\OrderLog;
use App\Services\OrderProfitabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * One order's profitability: the finalized revenue invoice the customer
 * agreed to, the supplier invoices and fees against it, and finance's
 * sign-off over the resulting figure.
 *
 * Reads are finance.view (which order managers hold — the order page embeds
 * this); writes are finance.manage. Every write lands in order_logs, and any
 * write that moves the money withdraws a standing verification — an approval
 * of figures that have since changed is worse than no approval at all.
 */
class AdminOrderProfitabilityController extends Controller
{
    public function __construct(private readonly OrderProfitabilityService $service)
    {
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/orders/{id}/profitability — finance.view
    // -------------------------------------------------------------------------
    public function show(int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        if (! OrderFinanceRecord::available()) {
            return $this->unavailable();
        }

        return response()->json([
            'data' => $this->service->forOrder($order) + [
                // What the rest of the system already believes about this
                // order's money, so finance types the revenue figure with the
                // existing evidence in front of them rather than from memory.
                'context' => $this->context($order),
            ],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/orders/{id}/profitability/revenue — finance.manage
    //
    // Records (or replaces) the revenue invoice. One request including the
    // PDF — finance has the document in front of them when they type the
    // number, and a separate "now attach it" step is a step that gets skipped.
    // -------------------------------------------------------------------------
    public function setRevenue(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        if (! OrderFinanceRecord::available()) {
            return $this->unavailable();
        }

        $data = $request->validate([
            'invoice_number'  => ['required', 'string', 'max:50'],
            'amount'          => ['required', 'numeric', 'min:0', 'max:99999999'],
            'currency'        => ['nullable', 'string', 'size:3'],
            'issued_on'       => ['nullable', 'date'],
            // The definition of a revenue invoice is one the customer agreed
            // to, so this defaults on — passing false records the figure while
            // being honest that the agreement is not confirmed yet.
            'customer_agreed' => ['nullable', 'boolean'],
            'file'            => ['nullable', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $record = $this->record($order);

        $previousAmount = $record->revenue_amount;

        $record->fill([
            'revenue_invoice_number' => $data['invoice_number'],
            'revenue_amount'         => $data['amount'],
            'revenue_currency'       => strtoupper($data['currency'] ?? ($order->currency ?? 'EUR')),
            'revenue_issued_on'      => $data['issued_on'] ?? null,
            'revenue_finalized_at'   => now(),
            'customer_agreed_at'     => ($data['customer_agreed'] ?? true) ? now() : null,
            'revenue_set_by'         => $request->user()?->id,
        ]);

        $moved = $record->isDirty(['revenue_amount', 'revenue_currency']);

        $record->save();

        $this->withdrawVerificationIf($moved, $request, $order, $record, 'the revenue invoice changed');

        $this->writeLog($request, $order, 'revenue_invoice_set', [
            'old_value' => $previousAmount !== null ? (string) $previousAmount : null,
            'new_value' => (string) $data['amount'],
            'notes'     => "Revenue invoice {$data['invoice_number']} recorded as the finalized figure.",
        ]);

        $warning = null;

        if ($request->hasFile('file') && ! $this->storeRevenueFile($request, $record)) {
            $warning = 'Revenue invoice recorded, but the file could not be saved. Attach it again.';
        }

        return response()->json([
            'data'    => $this->service->forOrder($order->fresh(['financeRecord', 'costLines'])),
            'message' => $warning ?? 'Revenue invoice recorded.',
        ], 201);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/orders/{id}/profitability/revenue/download — finance.view
    // -------------------------------------------------------------------------
    public function downloadRevenueFile(int $id): BinaryFileResponse|JsonResponse
    {
        $order  = Order::findOrFail($id);
        $record = OrderFinanceRecord::available() ? $order->financeRecord : null;
        $path   = $record?->getRawOriginal('revenue_file_path');

        if (! $path) {
            return response()->json(['message' => 'No revenue invoice document is attached to this order.'], 404);
        }

        return $this->downloadFromDisk($path, $record->revenue_original_filename);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/orders/{id}/profitability/costs — finance.manage
    // -------------------------------------------------------------------------
    public function storeCost(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        if (! OrderFinanceRecord::available()) {
            return $this->unavailable();
        }

        $data = $request->validate([
            'kind'        => ['required', Rule::in(OrderCostLine::KINDS)],
            // A fee without a category cannot be reported by channel, which is
            // the reason fees are recorded at all. Supplier invoices carry a
            // supplier name instead.
            'category'    => ['required_if:kind,' . OrderCostLine::KIND_FEE, 'nullable', Rule::in(OrderCostLine::FEE_CATEGORIES)],
            'supplier'    => ['required_if:kind,' . OrderCostLine::KIND_SUPPLIER_INVOICE, 'nullable', 'string', 'max:150'],
            'reference'   => ['nullable', 'string', 'max:60'],
            'amount'      => ['required', 'numeric', 'min:0', 'max:99999999'],
            'currency'    => ['nullable', 'string', 'size:3'],
            'incurred_on' => ['nullable', 'date'],
            'notes'       => ['nullable', 'string', 'max:500'],
            'file'        => ['nullable', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $line = OrderCostLine::create([
            'order_id'    => $order->id,
            'order_ref'   => $order->ref,
            'kind'        => $data['kind'],
            'category'    => $data['kind'] === OrderCostLine::KIND_FEE ? $data['category'] : null,
            'supplier'    => $data['supplier'] ?? null,
            'reference'   => $data['reference'] ?? null,
            'amount'      => $data['amount'],
            'currency'    => strtoupper($data['currency'] ?? 'EUR'),
            'incurred_on' => $data['incurred_on'] ?? null,
            'notes'       => $data['notes'] ?? null,
            'entered_by'  => $request->user()?->id,
        ]);

        $this->withdrawVerificationIf(true, $request, $order, $order->financeRecord, 'a cost line was added');

        $this->writeLog($request, $order, 'cost_line_added', [
            'new_value' => (string) $data['amount'],
            'notes'     => ucfirst(str_replace('_', ' ', $data['kind'])) . ' '
                . ($data['supplier'] ?? $data['category'] ?? '')
                . " {$line->currency} {$data['amount']} recorded.",
        ]);

        $warning = null;

        if ($request->hasFile('file') && ! $this->storeCostFile($request, $line)) {
            $warning = 'Cost recorded, but the file could not be saved. Attach it again.';
        }

        return response()->json([
            'data'    => $this->service->formatLine($line->fresh('enteredBy')),
            'message' => $warning ?? 'Cost recorded.',
        ], 201);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/v1/admin/orders/{id}/profitability/costs/{costId} — finance.manage
    // -------------------------------------------------------------------------
    public function updateCost(Request $request, int $id, int $costId): JsonResponse
    {
        $order = Order::findOrFail($id);

        if (! OrderFinanceRecord::available()) {
            return $this->unavailable();
        }

        $line = OrderCostLine::where('order_id', $order->id)->findOrFail($costId);

        $data = $request->validate([
            'kind'        => ['sometimes', Rule::in(OrderCostLine::KINDS)],
            'category'    => ['sometimes', 'nullable', Rule::in(OrderCostLine::FEE_CATEGORIES)],
            'supplier'    => ['sometimes', 'nullable', 'string', 'max:150'],
            'reference'   => ['sometimes', 'nullable', 'string', 'max:60'],
            'amount'      => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
            'currency'    => ['sometimes', 'string', 'size:3'],
            'incurred_on' => ['sometimes', 'nullable', 'date'],
            'notes'       => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }

        $previousAmount = (string) $line->amount;

        $line->fill($data);

        // Only a change that moves the money invalidates the sign-off —
        // fixing a typo in a note must not cost finance a signature.
        $moved = $line->isDirty(['amount', 'currency', 'kind']);

        $line->save();

        $this->withdrawVerificationIf($moved, $request, $order, $order->financeRecord, 'a cost line changed');

        $this->writeLog($request, $order, 'cost_line_updated', [
            'old_value' => $previousAmount,
            'new_value' => (string) $line->amount,
            'notes'     => "Cost line #{$line->id} updated.",
        ]);

        return response()->json([
            'data'    => $this->service->formatLine($line->fresh('enteredBy')),
            'message' => 'Cost updated.',
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/admin/orders/{id}/profitability/costs/{costId} — finance.manage
    // -------------------------------------------------------------------------
    public function destroyCost(Request $request, int $id, int $costId): JsonResponse
    {
        $order = Order::findOrFail($id);

        if (! OrderFinanceRecord::available()) {
            return $this->unavailable();
        }

        $line = OrderCostLine::where('order_id', $order->id)->findOrFail($costId);

        if ($path = $line->getRawOriginal('file_path')) {
            Storage::disk('local')->delete($path);
        }

        $amount = (string) $line->amount;
        $label  = $line->supplier ?? $line->category ?? $line->kind;

        $line->delete();

        $this->withdrawVerificationIf(true, $request, $order, $order->financeRecord, 'a cost line was removed');

        $this->writeLog($request, $order, 'cost_line_removed', [
            'old_value' => $amount,
            'notes'     => "Cost line ({$label}, {$amount}) removed.",
        ]);

        return response()->json(['message' => 'Cost removed.']);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/orders/{id}/profitability/costs/{costId}/file — finance.manage
    // -------------------------------------------------------------------------
    public function uploadCostFile(Request $request, int $id, int $costId): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $order = Order::findOrFail($id);

        if (! OrderFinanceRecord::available()) {
            return $this->unavailable();
        }

        $line = OrderCostLine::where('order_id', $order->id)->findOrFail($costId);

        if (! $this->storeCostFile($request, $line)) {
            return response()->json(['message' => 'File could not be saved. Please try again.'], 500);
        }

        return response()->json([
            'data'    => $this->service->formatLine($line->fresh('enteredBy')),
            'message' => 'Document attached.',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/orders/{id}/profitability/costs/{costId}/download — finance.view
    // -------------------------------------------------------------------------
    public function downloadCostFile(int $id, int $costId): BinaryFileResponse|JsonResponse
    {
        $order = Order::findOrFail($id);

        if (! OrderFinanceRecord::available()) {
            return $this->unavailable();
        }

        $line = OrderCostLine::where('order_id', $order->id)->findOrFail($costId);
        $path  = $line->getRawOriginal('file_path');

        if (! $path) {
            return response()->json(['message' => 'No document is attached to this cost line.'], 404);
        }

        return $this->downloadFromDisk($path, $line->original_filename);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/orders/{id}/profitability/verify — finance.manage
    // -------------------------------------------------------------------------
    public function verify(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        if (! OrderFinanceRecord::available()) {
            return $this->unavailable();
        }

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $record = $order->financeRecord;

        if ($record === null || ! $record->hasRevenueInvoice()) {
            // A verification of a profit with no revenue figure verifies
            // nothing — record the revenue invoice first.
            return response()->json([
                'message' => 'Record the revenue invoice before verifying — there is no figure to sign off yet.',
                'code'    => 'no_revenue_invoice',
            ], 422);
        }

        $record->forceFill([
            'verified_at'   => now(),
            'verified_by'   => $request->user()?->id,
            'verified_note' => $data['note'] ?? null,
        ])->save();

        $this->writeLog($request, $order, 'profitability_verified', [
            'notes' => $data['note'] ?? null,
        ]);

        return response()->json([
            'data'    => $this->service->forOrder($order->fresh(['financeRecord', 'costLines'])),
            'message' => 'Profitability verified.',
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/admin/orders/{id}/profitability/verify — finance.manage
    //
    // A withdrawal is itself evidence: it requires a written reason and lands
    // in the order log, same as the sign-off it undoes.
    // -------------------------------------------------------------------------
    public function unverify(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $record = OrderFinanceRecord::available() ? $order->financeRecord : null;

        if ($record === null || ! $record->isVerified()) {
            return response()->json(['message' => 'This order is not verified.'], 422);
        }

        $record->forceFill([
            'verified_at'   => null,
            'verified_by'   => null,
            'verified_note' => null,
        ])->save();

        $this->writeLog($request, $order, 'profitability_verification_withdrawn', [
            'notes' => $data['reason'],
        ]);

        return response()->json([
            'data'    => $this->service->forOrder($order->fresh(['financeRecord', 'costLines'])),
            'message' => 'Verification withdrawn.',
        ]);
    }

    // -------------------------------------------------------------------------

    private function record(Order $order): OrderFinanceRecord
    {
        return OrderFinanceRecord::firstOrCreate(
            ['order_id' => $order->id],
            ['order_ref' => $order->ref],
        );
    }

    /**
     * Withdraws a standing verification because the figures moved under it,
     * and says so in the order log. A no-op when nothing is verified.
     */
    private function withdrawVerificationIf(bool $moved, Request $request, Order $order, ?OrderFinanceRecord $record, string $why): void
    {
        if (! $moved || $record === null || ! $record->isVerified()) {
            return;
        }

        $record->forceFill([
            'verified_at'   => null,
            'verified_by'   => null,
            'verified_note' => null,
        ])->save();

        $this->writeLog($request, $order, 'profitability_verification_withdrawn', [
            'notes' => "Withdrawn automatically: {$why} after verification.",
        ]);
    }

    private function storeRevenueFile(Request $request, OrderFinanceRecord $record): bool
    {
        $file = $request->file('file');

        $safe = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), '_');
        $ext  = strtolower($file->getClientOriginalExtension());
        $path = 'order-finance/' . Str::slug($record->order_ref, '_') . '/'
            . now()->format('YmdHis') . '_revenue_' . $safe . '.' . $ext;

        try {
            Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));
        } catch (\Throwable $e) {
            Log::error('Revenue invoice file could not be stored', [
                'order_ref' => $record->order_ref,
                'error'     => $e->getMessage(),
            ]);

            return false;
        }

        // Replacing a document removes the one it replaced — otherwise the
        // disk fills with superseded copies nothing can reach.
        if ($previous = $record->getRawOriginal('revenue_file_path')) {
            Storage::disk('local')->delete($previous);
        }

        $record->update([
            'revenue_file_path'         => $path,
            'revenue_original_filename' => $file->getClientOriginalName(),
            'revenue_mime_type'         => $file->getClientMimeType(),
            'revenue_file_size'         => $file->getSize(),
            'revenue_uploaded_at'       => now(),
        ]);

        return true;
    }

    private function storeCostFile(Request $request, OrderCostLine $line): bool
    {
        $file = $request->file('file');

        $safe = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), '_');
        $ext  = strtolower($file->getClientOriginalExtension());
        $path = 'order-finance/' . Str::slug($line->order_ref, '_') . '/'
            . now()->format('YmdHis') . "_cost{$line->id}_" . $safe . '.' . $ext;

        try {
            Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));
        } catch (\Throwable $e) {
            Log::error('Cost line file could not be stored', [
                'order_ref' => $line->order_ref,
                'cost_id'   => $line->id,
                'error'     => $e->getMessage(),
            ]);

            return false;
        }

        if ($previous = $line->getRawOriginal('file_path')) {
            Storage::disk('local')->delete($previous);
        }

        $line->update([
            'file_path'         => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type'         => $file->getClientMimeType(),
            'file_size'         => $file->getSize(),
            'uploaded_at'       => now(),
        ]);

        return true;
    }

    private function downloadFromDisk(string $path, ?string $filename): BinaryFileResponse|JsonResponse
    {
        // Asked of the disk rather than assembled from storage_path(): the
        // `local` root is configuration, and hardcoding it means the download
        // silently 404s anywhere that root differs from the one assumed here.
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            Log::warning('Order finance file missing on disk', ['path' => $path]);

            return response()->json(['message' => 'The attached document could not be found.'], 404);
        }

        return response()->download($disk->path($path), $filename ?: basename($path));
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Order $order): array
    {
        $invoice = $order->invoice;

        return [
            'order_total'                => (float) $order->total,
            'order_currency'             => $order->currency ?? 'EUR',
            'order_status'               => $order->status,
            'counts_as_confirmed'        => in_array($order->status, OrderProfitabilityService::CONFIRMED_STATUSES, true),
            'customer_acceptance_status' => $order->customer_acceptance_status ?? 'pending',
            'customer_accepted_at'       => $order->customer_accepted_at?->toIso8601String(),
            // The tax invoice this system raised, where one exists — a
            // starting point for the revenue figure, not a substitute for
            // finance deciding it.
            'system_invoice_number'      => $invoice?->invoice_number,
            'system_invoice_amount'      => $invoice?->amount !== null ? (float) $invoice->amount : null,
        ];
    }

    private function unavailable(): JsonResponse
    {
        return response()->json([
            'message' => 'Profitability tracking is not available yet — the database migration has not run.',
            'code'    => 'profitability_unavailable',
        ], 503);
    }

    private function writeLog(Request $request, Order $order, string $action, array $extra = []): void
    {
        try {
            $admin = $request->user();

            OrderLog::create([
                'order_id'         => $order->id,
                'order_ref'        => $order->ref,
                'admin_user_id'    => $admin?->id,
                'admin_user_email' => $admin?->email,
                'action'           => $action,
                'old_value'        => $extra['old_value'] ?? null,
                'new_value'        => $extra['new_value'] ?? null,
                'notes'            => $extra['notes'] ?? null,
                'ip_address'       => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('OrderLog write failed', [
                'order_ref' => $order->ref,
                'action'    => $action,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}

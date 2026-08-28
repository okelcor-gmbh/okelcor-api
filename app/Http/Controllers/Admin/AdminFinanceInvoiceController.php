<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FinanceInvoice;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Invoices as sevDesk has them, typed in by finance.
 *
 * Not an integration, deliberately — see the migration. Finance enters what
 * they raised, and the board compares the count against the invoices this
 * system produced.
 */
class AdminFinanceInvoiceController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /api/v1/admin/finance-invoices — finance.view
    // -------------------------------------------------------------------------
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from'    => ['nullable', 'date'],
            'to'      => ['nullable', 'date'],
            'channel' => ['nullable', Rule::in(FinanceInvoice::CHANNELS)],
            'system'  => ['nullable', 'string', 'max:30'],
            'role'    => ['nullable', Rule::in(FinanceInvoice::ROLES)],
            'matched'  => ['nullable', 'in:yes,no'],
            'has_file' => ['nullable', 'in:yes,no'],
            'q'       => ['nullable', 'string', 'max:100'],
        ]);

        $query = FinanceInvoice::query()->with('recordedBy:id,name')->orderByDesc('issued_on')->orderByDesc('id');

        if ($request->filled('from')) {
            $query->whereDate('issued_on', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('issued_on', '<=', $request->input('to'));
        }

        $filterable = FinanceInvoice::rolesAvailable() ? ['channel', 'system', 'role'] : ['channel', 'system'];

        foreach ($filterable as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('has_file')) {
            $request->input('has_file') === 'yes'
                ? $query->whereNotNull('file_path')
                : $query->whereNull('file_path');
        }

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(fn ($sub) => $sub->where('external_number', 'like', "%{$q}%")
                ->orWhere('order_ref', 'like', "%{$q}%")
                ->orWhere('invoice_number', 'like', "%{$q}%"));
        }

        // "Matched" means this system knows the order it names — which is the
        // filter finance actually wants, because the unmatched ones are the
        // work.
        if ($request->filled('matched')) {
            $refs = Order::query()->pluck('ref')->all();

            $request->input('matched') === 'yes'
                ? $query->whereIn('order_ref', $refs ?: [''])
                : $query->where(fn ($sub) => $sub->whereNull('order_ref')->orWhereNotIn('order_ref', $refs ?: ['']));
        }

        $perPage   = min((int) $request->input('per_page', 25), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paginated->items())->map(fn ($f) => $this->format($f))->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
                'systems'        => FinanceInvoice::SYSTEMS,
                'manual_systems' => FinanceInvoice::MANUAL_SYSTEMS,
                'channels'     => FinanceInvoice::CHANNELS,
                'roles'        => FinanceInvoice::ROLES,
            ],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/finance-invoices — finance.manage
    // -------------------------------------------------------------------------
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // MANUAL_SYSTEMS, not SYSTEMS: `okelcor` rows are written by the
            // registrar for invoices this API produced, and letting someone
            // hand-create one would put a number on our side of the
            // reconciliation that nothing on our side actually issued.
            'system'          => ['nullable', Rule::in(FinanceInvoice::MANUAL_SYSTEMS)],
            'external_number' => ['required', 'string', 'max:60'],
            'file'            => ['nullable', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png'],
            // A revenue or supplier invoice is meaningless without the order
            // whose profit it belongs to; a plain register row can float free.
            'role'            => ['nullable', Rule::in(FinanceInvoice::ROLES)],
            'order_ref'       => ['nullable', 'string', 'max:30',
                'required_if:role,' . FinanceInvoice::ROLE_REVENUE . ',' . FinanceInvoice::ROLE_SUPPLIER],
            'supplier_name'   => ['nullable', 'string', 'max:150',
                'required_if:role,' . FinanceInvoice::ROLE_SUPPLIER],
            'invoice_number'  => ['nullable', 'string', 'max:50'],
            'amount'          => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'currency'        => ['nullable', 'string', 'size:3'],
            'issued_on'       => ['required', 'date'],
            'channel'         => ['nullable', Rule::in(FinanceInvoice::CHANNELS)],
            'notes'           => ['nullable', 'string', 'max:500'],
        ]);

        $data['system'] ??= 'sevdesk';
        $data['role']   ??= FinanceInvoice::ROLE_REGISTER;

        // Between this code deploying and its migration running, recording an
        // invoice has to keep working — the row just stays a plain register
        // entry until the columns exist.
        if (! FinanceInvoice::rolesAvailable()) {
            unset($data['role'], $data['supplier_name']);
        }

        $duplicate = FinanceInvoice::where('system', $data['system'])
            ->where('external_number', $data['external_number'])
            ->first();

        if ($duplicate !== null) {
            // A friendly 422 rather than a database error: entering the same
            // invoice twice would make the two sides of the board agree when
            // they do not, which is the exact failure this board exists to
            // catch.
            return response()->json([
                'message' => "Invoice {$data['external_number']} is already recorded (entered "
                    . ($duplicate->created_at?->toDateString() ?? 'earlier') . ').',
                'errors'  => ['external_number' => ['This invoice number has already been entered.']],
                'data'    => $this->format($duplicate),
            ], 422);
        }

        $data['channel']  ??= $this->inferChannel($data['order_ref'] ?? null);
        $data['currency']   = strtoupper($data['currency'] ?? 'EUR');
        $data['recorded_by'] = $request->user()->id;

        unset($data['file']);

        $invoice = FinanceInvoice::create($data);

        // One request rather than two. Finance has the PDF in front of them
        // when they type the number, and a second "now attach the file" step
        // is a step that gets skipped.
        if ($request->hasFile('file') && ! $this->storeFile($request, $invoice)) {
            return response()->json([
                'data'    => $this->format($invoice->fresh('recordedBy')),
                'message' => 'Invoice recorded, but the file could not be saved. Attach it again from the list.',
            ], 201);
        }

        return response()->json([
            'data'    => $this->format($invoice->fresh('recordedBy')),
            'message' => 'Finance invoice recorded.',
        ], 201);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/v1/admin/finance-invoices/{id} — finance.manage
    // -------------------------------------------------------------------------
    public function update(Request $request, int $id): JsonResponse
    {
        $invoice = FinanceInvoice::findOrFail($id);

        if ($invoice->isAutoRegistered()) {
            return response()->json([
                'message' => 'This row is maintained automatically from the invoice this system issued. '
                    . 'Correct the invoice or the order, and this will follow.',
                'code'    => 'auto_registered',
            ], 409);
        }

        if ($invoice->isFinalized()) {
            // The customer agreed to this figure and profit reports count it.
            // Editing it in place would change history nobody re-approved —
            // withdraw the finalization first, on purpose and attributably.
            return response()->json([
                'message' => 'This invoice is finalized — the customer-agreed revenue figure is locked. '
                    . 'Unfinalize it first if it genuinely has to change.',
                'code'    => 'finalized_locked',
            ], 409);
        }

        $data = $request->validate([
            'external_number' => ['sometimes', 'string', 'max:60',
                Rule::unique('finance_invoices')->where('system', $invoice->system)->ignore($invoice->id)],
            'order_ref'      => ['sometimes', 'nullable', 'string', 'max:30'],
            'role'           => ['sometimes', Rule::in(FinanceInvoice::ROLES)],
            'supplier_name'  => ['sometimes', 'nullable', 'string', 'max:150'],
            'invoice_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'amount'         => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'currency'       => ['sometimes', 'string', 'size:3'],
            'issued_on'      => ['sometimes', 'date'],
            'channel'        => ['sometimes', Rule::in(FinanceInvoice::CHANNELS)],
            'notes'          => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }

        $invoice->update($data);

        return response()->json([
            'data'    => $this->format($invoice->fresh('recordedBy')),
            'message' => 'Finance invoice updated.',
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/admin/finance-invoices/{id} — finance.manage
    // -------------------------------------------------------------------------
    public function destroy(int $id): JsonResponse
    {
        $invoice = FinanceInvoice::findOrFail($id);

        if ($invoice->isAutoRegistered()) {
            // Deleting it would only mean it reappears the next time the
            // invoice behind it is saved, and in the meantime the
            // reconciliation would report a gap that is not real. Void or
            // supersede the document instead — the registrar follows that.
            return response()->json([
                'message' => 'This row describes an invoice this system issued, so it is maintained automatically. '
                    . 'Void or supersede the document itself and this will follow.',
                'code'    => 'auto_registered',
            ], 409);
        }

        if ($invoice->isFinalized()) {
            return response()->json([
                'message' => 'This invoice is finalized — deleting it would silently remove the revenue an '
                    . 'order reports. Unfinalize it first if it genuinely has to go.',
                'code'    => 'finalized_locked',
            ], 409);
        }

        if ($path = $invoice->getRawOriginal('file_path')) {
            Storage::disk('local')->delete($path);
        }

        $invoice->delete();

        return response()->json(['message' => 'Finance invoice removed.']);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/finance-invoices/{id}/finalize — finance.manage
    //
    // "The invoice that has been finalized, that the customer has agreed to —
    // that's our revenue invoice." This is that moment: from here the row IS
    // the order's revenue, its money fields lock, and profitability starts
    // reporting a figure instead of `awaiting_revenue_invoice`.
    // -------------------------------------------------------------------------
    public function finalize(Request $request, int $id): JsonResponse
    {
        $invoice = FinanceInvoice::findOrFail($id);

        if ($invoice->isFinalized()) {
            return response()->json([
                'message' => 'This invoice is already finalized.',
                'code'    => 'already_finalized',
                'data'    => $this->format($invoice),
            ], 409);
        }

        if ($invoice->role === FinanceInvoice::ROLE_SUPPLIER) {
            return response()->json([
                'message' => 'A supplier invoice is a cost, not revenue — it cannot be finalized as the '
                    . 'revenue invoice.',
                'code'    => 'not_revenue',
            ], 409);
        }

        // Revenue with no amount, or no order to belong to, is not a revenue
        // figure anything can report on.
        if ($invoice->amount === null) {
            return response()->json([
                'message' => 'Enter the invoice amount before finalizing — the finalized amount becomes the '
                    . "order's revenue.",
                'code'    => 'amount_missing',
            ], 409);
        }

        $order = $invoice->order_ref ? Order::where('ref', $invoice->order_ref)->first() : null;

        if ($order === null) {
            return response()->json([
                'message' => 'This invoice does not name an order this system knows, so there is no order '
                    . 'for its revenue to belong to. Set its order ref first.',
                'code'    => 'order_unknown',
            ], 409);
        }

        // One revenue figure per order — two finalized revenue invoices would
        // double the order's revenue in every report at once.
        $standing = FinanceInvoice::query()->finalizedRevenue()
            ->where('order_ref', $invoice->order_ref)
            ->where('id', '!=', $invoice->id)
            ->first();

        if ($standing !== null) {
            return response()->json([
                'message' => "Order {$invoice->order_ref} already has a finalized revenue invoice "
                    . "({$standing->external_number}). Unfinalize that one first if this replaces it.",
                'code'    => 'revenue_invoice_exists',
                'data'    => $this->format($standing),
            ], 409);
        }

        $invoice->update([
            'role'         => FinanceInvoice::ROLE_REVENUE,
            'finalized_at' => now(),
            'finalized_by' => $request->user()->id,
        ]);

        return response()->json([
            'data'    => $this->format($invoice->fresh(['recordedBy', 'finalizedBy'])),
            'message' => "Revenue invoice finalized for order {$invoice->order_ref}.",
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/finance-invoices/{id}/unfinalize — finance.manage
    // -------------------------------------------------------------------------
    public function unfinalize(int $id): JsonResponse
    {
        $invoice = FinanceInvoice::findOrFail($id);

        if (! $invoice->isFinalized()) {
            return response()->json([
                'message' => 'This invoice is not finalized.',
                'code'    => 'not_finalized',
            ], 409);
        }

        // Role stays `revenue` — it is still the draft revenue invoice, just
        // no longer the agreed one, so profit goes back to awaiting it.
        $invoice->update(['finalized_at' => null, 'finalized_by' => null]);

        return response()->json([
            'data'    => $this->format($invoice->fresh('recordedBy')),
            'message' => 'Finalization withdrawn — the order reports no agreed revenue until an invoice is '
                . 'finalized again.',
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/finance-invoices/{id}/file — finance.manage
    //
    // The sevDesk PDF itself. Finance held the document and had nowhere to put
    // it, so the invoice and the record of it lived in different systems —
    // which is the same class of problem the register exists to close.
    // -------------------------------------------------------------------------
    public function uploadFile(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $invoice = FinanceInvoice::findOrFail($id);

        if (! $this->storeFile($request, $invoice)) {
            return response()->json(['message' => 'File could not be saved. Please try again.'], 500);
        }

        return response()->json([
            'data'    => $this->format($invoice->fresh('recordedBy')),
            'message' => 'Invoice document attached.',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/finance-invoices/{id}/download — finance.view
    // -------------------------------------------------------------------------
    public function download(int $id): BinaryFileResponse|JsonResponse
    {
        $invoice = FinanceInvoice::findOrFail($id);
        $path    = $invoice->getRawOriginal('file_path');

        if (! $path) {
            return response()->json(['message' => 'No document is attached to this invoice.'], 404);
        }

        // Asked of the disk rather than assembled from storage_path(): the
        // `local` root is configuration, and hardcoding it means the download
        // silently 404s anywhere that root differs from the one assumed here.
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            Log::warning('Finance invoice file missing on disk', ['id' => $id, 'path' => $path]);

            return response()->json(['message' => 'The attached document could not be found.'], 404);
        }

        return response()->download($disk->path($path), $invoice->original_filename ?: basename($path));
    }

    // -------------------------------------------------------------------------

    /**
     * Stores the upload on the private disk and records it. Returns false on
     * failure so the caller decides what that means — recording the invoice
     * without its file is still worth keeping.
     */
    private function storeFile(Request $request, FinanceInvoice $invoice): bool
    {
        $file = $request->file('file');

        $safe = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), '_');
        $ext  = strtolower($file->getClientOriginalExtension());
        $path = 'finance-invoices/' . $invoice->system . '/'
            . now()->format('YmdHis') . '_' . Str::slug($invoice->external_number, '_') . '_' . $safe . '.' . $ext;

        try {
            Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));
        } catch (\Throwable $e) {
            Log::error('Finance invoice file could not be stored', [
                'id'    => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        // Replacing a document removes the one it replaced — otherwise the
        // disk fills with superseded copies nothing can reach.
        if ($previous = $invoice->getRawOriginal('file_path')) {
            Storage::disk('local')->delete($previous);
        }

        $invoice->update([
            'file_path'         => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type'         => $file->getClientMimeType(),
            'file_size'         => $file->getSize(),
            'uploaded_at'       => now(),
        ]);

        return true;
    }

    // -------------------------------------------------------------------------

    /**
     * An entry naming an eBay order is an eBay invoice; everything else is
     * normal. Only a default — the field is editable, because the row worth
     * recording most is the one naming an order this system does not have.
     */
    private function inferChannel(?string $orderRef): string
    {
        if ($orderRef === null || $orderRef === '') {
            return Order::CHANNEL_NORMAL;
        }

        return Order::where('ref', $orderRef)->value('source') === 'ebay'
            ? Order::CHANNEL_EBAY
            : Order::CHANNEL_NORMAL;
    }

    /**
     * @return array<string, mixed>
     */
    private function format(FinanceInvoice $f): array
    {
        return [
            'id'              => $f->id,
            'system'          => $f->system,
            'external_number' => $f->external_number,
            'order_ref'       => $f->order_ref,
            'invoice_number'  => $f->invoice_number,
            'amount'          => $f->amount === null ? null : (float) $f->amount,
            'currency'        => $f->currency,
            'issued_on'       => $f->issued_on?->toDateString(),
            'channel'         => $f->channel,
            'role'            => $f->role ?? FinanceInvoice::ROLE_REGISTER,
            'supplier_name'   => $f->supplier_name,
            'finalized'       => $f->isFinalized(),
            'finalized_at'    => $f->finalized_at?->toIso8601String(),
            'finalized_by'    => $f->finalizedBy?->name,
            'notes'           => $f->notes,
            'recorded_by'     => $f->recordedBy?->name,
            'recorded_at'     => $f->created_at?->toIso8601String(),
            // Written for the operator rather than by them — the UI should show
            // these read-only, with the reason.
            'auto_registered' => $f->isAutoRegistered(),
            'source_type'     => $f->source_type,
            'source_id'       => $f->source_id,
            'has_file'        => $f->hasFile(),
            'file_name'       => $f->original_filename,
            'file_size'       => $f->file_size,
            'uploaded_at'     => $f->uploaded_at?->toIso8601String(),
            'order_known_here' => $f->order_ref !== null && $f->order_ref !== ''
                && Order::where('ref', $f->order_ref)->exists(),
        ];
    }
}

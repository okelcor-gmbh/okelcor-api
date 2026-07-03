<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderLog;
use App\Models\TradeDocument;
use App\Services\AdminNotificationService;
use App\Services\CustomerNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TradeDocumentController extends Controller
{
    /**
     * Documents that only make sense once the balance is paid — per
     * Okelcor's stated terms ("balance against bill of lading"), packing
     * lists, delivery notes, and shipment documents (BoL/CMR etc.) all sit
     * at/after the balance-due point in the flow, same as the commercial
     * invoice.
     */
    private const PAYMENT_GATED_TYPES = ['commercial_invoice', 'packing_list', 'delivery_note', 'shipment_document'];

    /**
     * GET /api/v1/auth/orders/{ref}/trade-documents
     *
     * List valid trade documents for a customer's order.
     * Only status=issued|sent documents are exposed (superseded/void/draft are excluded).
     */
    public function index(Request $request, string $ref): JsonResponse
    {
        $customer = $request->user();

        // Access guard — documents require explicit approval (CRM-4)
        if (! ($customer->approved_for_documents ?? false)) {
            return response()->json([
                'message'                => 'Document access is not yet enabled for your account. Please contact Okelcor.',
                'code'                   => 'documents_not_approved',
                'approved_for_documents' => false,
            ], 403);
        }

        $order = Order::where('ref', $ref)->first();

        if (! $order || strtolower($order->customer_email) !== strtolower($customer->email)) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $documents = TradeDocument::where('order_id', $order->id)
            ->whereIn('status', ['issued', 'sent'])
            ->whereIn('type', ['order_confirmation', 'proforma', 'proforma_signed', 'commercial_invoice', 'packing_list', 'delivery_note', 'shipment_document'])
            ->orderByDesc('issued_at')
            ->get()
            ->reject(fn ($d) => in_array($d->type, self::PAYMENT_GATED_TYPES, true) && ! $order->isFullyPaid())
            ->values();

        return response()->json([
            'data'    => $documents->map(fn ($d) => [
                'id'                => $d->id,
                'type'              => $d->type,
                'type_label'        => $d->type_label,
                'number'            => $d->number,
                'status'            => $d->status,
                'issued_at'         => $d->issued_at?->toIso8601String(),
                'sent_at'           => $d->sent_at?->toIso8601String(),
                'has_pdf'           => (bool) $d->getRawOriginal('pdf_path'),
                'has_file'          => (bool) $d->getRawOriginal('file_path'),
                'original_filename' => $d->original_filename,
                'mime_type'         => $d->mime_type,
                'file_size'         => $d->file_size,
            ])->values(),
            'message' => 'success',
        ]);
    }

    /**
     * GET /api/v1/auth/trade-documents/{id}/download
     *
     * Stream a trade document PDF for the authenticated customer.
     * Verifies document belongs to the customer's order and is issued.
     */
    public function download(Request $request, int $id): BinaryFileResponse|JsonResponse
    {
        $customer = $request->user();

        // Access guard — documents require explicit approval (CRM-4)
        if (! ($customer->approved_for_documents ?? false)) {
            return response()->json([
                'message'                => 'Document access is not yet enabled for your account. Please contact Okelcor.',
                'code'                   => 'documents_not_approved',
                'approved_for_documents' => false,
            ], 403);
        }

        $document = TradeDocument::findOrFail($id);

        // Verify the document's order belongs to the customer
        $order = Order::find($document->order_id);

        if (! $order || strtolower($order->customer_email) !== strtolower($customer->email)) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        if (! in_array($document->status, ['issued', 'sent'], true)) {
            return response()->json(['message' => 'This document is not available for download.'], 404);
        }

        if (in_array($document->type, self::PAYMENT_GATED_TYPES, true) && ! $order->isFullyPaid()) {
            return response()->json(['message' => 'This document is not available for download.'], 404);
        }

        // Generated PDFs use pdf_path; uploaded files use file_path
        $storedPath = $document->getRawOriginal('pdf_path')
            ?? $document->getRawOriginal('file_path');

        if (! $storedPath) {
            return response()->json(['message' => 'Document file is not available yet.'], 404);
        }

        $fullPath = storage_path('app/private/' . $storedPath);

        if (! file_exists($fullPath)) {
            Log::warning('Customer trade document download: file missing', [
                'document_id' => $document->id,
                'customer_id' => $customer->id,
                'stored_path' => $storedPath,
            ]);
            return response()->json(['message' => 'Document file was not found.'], 404);
        }

        $filename = $document->original_filename
            ?? ($document->number ? $document->number . '.pdf' : 'document.pdf');

        return response()->download($fullPath, $filename);
    }

    /**
     * POST /api/v1/auth/orders/{ref}/proforma/signed-copy
     *
     * Customer prints the proforma invoice, signs it (and stamps it, if
     * applicable), and uploads a scan/photo of the signed copy back — this
     * is the customer's documented acceptance of price/products/terms.
     * Idempotent-ish: a new upload supersedes any previous signed copy for
     * the same order, so there's always at most one current one.
     */
    public function uploadSignedProforma(Request $request, string $ref): JsonResponse
    {
        $customer = $request->user();

        if (! ($customer->approved_for_documents ?? false)) {
            return response()->json([
                'message'                => 'Document access is not yet enabled for your account. Please contact Okelcor.',
                'code'                   => 'documents_not_approved',
                'approved_for_documents' => false,
            ], 403);
        }

        $order = Order::where('ref', $ref)->first();

        if (! $order || strtolower($order->customer_email) !== strtolower($customer->email)) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $hasProforma = TradeDocument::where('order_id', $order->id)
            ->where('type', 'proforma')
            ->whereIn('status', ['issued', 'sent'])
            ->exists();

        if (! $hasProforma) {
            return response()->json([
                'message' => 'No proforma invoice has been issued for this order yet.',
                'code'    => 'no_proforma',
            ], 422);
        }

        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $uploaded     = $request->file('file');
        $originalName = $uploaded->getClientOriginalName();
        $safeName     = Str::slug(pathinfo($originalName, PATHINFO_FILENAME), '_');
        $ext          = strtolower($uploaded->getClientOriginalExtension());
        $storedName   = now()->format('YmdHis') . '_' . $safeName . '.' . $ext;
        $storagePath  = 'trade-documents/proforma-signed/' . $order->ref . '/' . $storedName;

        try {
            Storage::disk('local')->put($storagePath, file_get_contents($uploaded->getRealPath()));
        } catch (\Throwable $e) {
            Log::error('Signed proforma upload failed (storage write)', [
                'order_ref' => $order->ref,
                'error'     => $e->getMessage(),
            ]);
            return response()->json(['message' => 'File could not be saved. Please try again.'], 500);
        }

        // Supersede any previous signed copy so there's only one current one.
        TradeDocument::where('order_id', $order->id)
            ->where('type', 'proforma_signed')
            ->whereIn('status', ['issued', 'sent'])
            ->update(['status' => 'superseded', 'superseded_at' => now()]);

        $document = TradeDocument::create([
            'order_id'          => $order->id,
            'order_ref'         => $order->ref,
            'type'              => 'proforma_signed',
            'type_label'        => 'Signed Proforma Invoice',
            'status'            => 'issued',
            'file_path'         => $storagePath,
            'original_filename' => $originalName,
            'mime_type'         => $uploaded->getClientMimeType(),
            'file_size'         => $uploaded->getSize(),
            'issued_at'         => now(),
        ]);

        try {
            OrderLog::create([
                'order_id'  => $order->id,
                'order_ref' => $order->ref,
                'action'    => 'proforma_signed_returned',
                'notes'     => 'Customer uploaded a signed copy of the proforma invoice.',
                'ip_address' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('OrderLog write failed (signed proforma upload)', ['order_ref' => $order->ref]);
        }

        AdminNotificationService::notifyPermission(
            permission:  'orders.update',
            type:        'proforma_signed_returned',
            title:       "Signed proforma received — {$order->ref}",
            body:        "{$order->customer_name} uploaded a signed copy of the proforma invoice for order {$order->ref}.",
            actionUrl:   "/admin/orders/{$order->id}",
            severity:    'success',
            relatedType: 'order',
            relatedId:   $order->id,
            metadata:    ['order_ref' => $order->ref, 'document_id' => $document->id],
        );

        CustomerNotifier::notify(
            $customer,
            'proforma_signed',
            "Signed proforma received — {$order->ref}",
            'Thanks — we received your signed proforma invoice and will proceed with your order.',
            [
                'severity'     => 'success',
                'action_url'   => "/account/orders/{$order->ref}",
                'related_type' => 'order',
                'related_id'   => $order->ref,
                'metadata'     => ['order_ref' => $order->ref],
            ]
        );

        return response()->json([
            'data'    => [
                'id'                => $document->id,
                'type'              => $document->type,
                'original_filename' => $document->original_filename,
            ],
            'message' => 'Signed proforma invoice received. Thank you.',
        ], 201);
    }
}

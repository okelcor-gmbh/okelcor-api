<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\TradeDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TradeDocumentController extends Controller
{
    /**
     * GET /api/v1/auth/orders/{ref}/trade-documents
     *
     * List issued trade documents for a customer's order.
     * Only status=issued documents are exposed; uploads are excluded.
     */
    public function index(Request $request, string $ref): JsonResponse
    {
        $customer = $request->user();

        $order = Order::where('ref', $ref)->first();

        if (! $order || strtolower($order->customer_email) !== strtolower($customer->email)) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $documents = TradeDocument::where('order_id', $order->id)
            ->where('status', 'issued')
            ->whereIn('type', ['order_confirmation', 'proforma', 'commercial_invoice', 'packing_list', 'delivery_note', 'shipment_document'])
            ->orderByDesc('issued_at')
            ->get();

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
        $document = TradeDocument::findOrFail($id);

        // Verify the document's order belongs to the customer
        $order = Order::find($document->order_id);

        if (! $order || strtolower($order->customer_email) !== strtolower($customer->email)) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        if ($document->status !== 'issued') {
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
}

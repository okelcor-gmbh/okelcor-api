<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\TradeDocument;
use App\Services\TradeDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminTradeDocumentController extends Controller
{
    public function __construct(private TradeDocumentService $service) {}

    /**
     * POST /api/v1/admin/orders/{id}/trade-documents/proforma
     *
     * Generate (or return existing) proforma invoice for an order.
     * Idempotent — calling multiple times returns the same issued document.
     */
    public function generateProforma(Request $request, int $id): JsonResponse
    {
        $order = Order::with('items')->findOrFail($id);
        $admin = $request->user();

        $document = $this->service->generateProformaForOrder($order, $admin);

        if ($document->wasRecentlyCreated) {
            try {
                OrderLog::create([
                    'order_id'         => $order->id,
                    'order_ref'        => $order->ref,
                    'admin_user_id'    => $admin?->id,
                    'admin_user_email' => $admin?->email,
                    'action'           => 'document_generated',
                    'new_value'        => $document->number,
                    'notes'            => 'Proforma invoice generated.',
                    'ip_address'       => $request->ip(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('OrderLog write failed (proforma generation)', [
                    'order_ref' => $order->ref,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'data'    => $this->formatDocument($document),
            'message' => $document->wasRecentlyCreated
                ? 'Proforma invoice generated.'
                : 'Proforma invoice already exists for this order.',
        ], $document->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * POST /api/v1/admin/orders/{id}/generate-packing-list
     *
     * Generate (or return existing) packing list for an order.
     * Idempotent — calling multiple times returns the same issued document.
     */
    public function generatePackingList(Request $request, int $id): JsonResponse
    {
        $order = Order::with(['items', 'shipmentEvents'])->findOrFail($id);
        $admin = $request->user();

        try {
            $document = $this->service->generatePackingListForOrder($order, $admin);
        } catch (\Throwable $e) {
            Log::error('Packing list generation failed', [
                'order_id'  => $id,
                'order_ref' => $order->ref,
                'error'     => $e->getMessage(),
                'file'      => $e->getFile() . ':' . $e->getLine(),
            ]);
            return response()->json([
                'message' => 'Unable to generate packing list. Please try again or contact support.',
            ], 500);
        }

        if ($document->wasRecentlyCreated) {
            try {
                OrderLog::create([
                    'order_id'         => $order->id,
                    'order_ref'        => $order->ref,
                    'admin_user_id'    => $admin?->id,
                    'admin_user_email' => $admin?->email,
                    'action'           => 'document_generated',
                    'new_value'        => $document->number,
                    'notes'            => 'Packing list generated.',
                    'ip_address'       => $request->ip(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('OrderLog write failed (packing list generation)', [
                    'order_ref' => $order->ref,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'data'    => $this->formatDocument($document),
            'message' => $document->wasRecentlyCreated
                ? 'Packing list generated.'
                : 'Packing list already exists for this order.',
        ], $document->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * POST /api/v1/admin/orders/{id}/generate-delivery-note
     *
     * Generate (or return existing) delivery note for an order.
     * Idempotent — calling multiple times returns the same issued document.
     */
    public function generateDeliveryNote(Request $request, int $id): JsonResponse
    {
        $order = Order::with(['items', 'shipmentEvents'])->findOrFail($id);
        $admin = $request->user();

        try {
            $document = $this->service->generateDeliveryNoteForOrder($order, $admin);
        } catch (\Throwable $e) {
            Log::error('Delivery note generation failed', [
                'order_id'  => $id,
                'order_ref' => $order->ref,
                'error'     => $e->getMessage(),
                'file'      => $e->getFile() . ':' . $e->getLine(),
            ]);
            return response()->json([
                'message' => 'Unable to generate delivery note. Please try again or contact support.',
            ], 500);
        }

        if ($document->wasRecentlyCreated) {
            try {
                OrderLog::create([
                    'order_id'         => $order->id,
                    'order_ref'        => $order->ref,
                    'admin_user_id'    => $admin?->id,
                    'admin_user_email' => $admin?->email,
                    'action'           => 'document_generated',
                    'new_value'        => $document->number,
                    'notes'            => 'Delivery note generated.',
                    'ip_address'       => $request->ip(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('OrderLog write failed (delivery note generation)', [
                    'order_ref' => $order->ref,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'data'    => $this->formatDocument($document),
            'message' => $document->wasRecentlyCreated
                ? 'Delivery note generated.'
                : 'Delivery note already exists for this order.',
        ], $document->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * POST /api/v1/admin/orders/{id}/generate-commercial-invoice
     *
     * Generate (or return existing) commercial invoice for an order.
     * Idempotent — calling multiple times returns the same issued document.
     */
    public function generateCommercialInvoice(Request $request, int $id): JsonResponse
    {
        $order = Order::with(['items', 'shipmentEvents'])->findOrFail($id);
        $admin = $request->user();

        try {
            $document = $this->service->generateCommercialInvoiceForOrder($order, $admin);
        } catch (\Throwable $e) {
            Log::error('Commercial invoice generation failed', [
                'order_id'  => $id,
                'order_ref' => $order->ref,
                'error'     => $e->getMessage(),
                'file'      => $e->getFile() . ':' . $e->getLine(),
            ]);
            return response()->json([
                'message' => 'Unable to generate commercial invoice. Please try again or contact support.',
            ], 500);
        }

        if ($document->wasRecentlyCreated) {
            try {
                OrderLog::create([
                    'order_id'         => $order->id,
                    'order_ref'        => $order->ref,
                    'admin_user_id'    => $admin?->id,
                    'admin_user_email' => $admin?->email,
                    'action'           => 'document_generated',
                    'new_value'        => $document->number,
                    'notes'            => 'Commercial invoice generated.',
                    'ip_address'       => $request->ip(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('OrderLog write failed (commercial invoice generation)', [
                    'order_ref' => $order->ref,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'data'    => $this->formatDocument($document),
            'message' => $document->wasRecentlyCreated
                ? 'Commercial invoice generated.'
                : 'Commercial invoice already exists for this order.',
        ], $document->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * POST /api/v1/admin/orders/{id}/trade-documents/upload
     *
     * Upload a shipment document (Bill of Lading, CMR, etc.) to an order.
     */
    public function uploadShipmentDocument(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);
        $admin = $request->user();

        // Accept both field names — frontend may send document_label or type_label
        if (!$request->has('type_label') && $request->has('document_label')) {
            $request->merge(['type_label' => $request->input('document_label')]);
        }

        $request->validate([
            'file'       => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,xls,xlsx,csv'],
            'type_label' => ['required', 'string', 'max:100'],
            'notes'      => ['nullable', 'string', 'max:500'],
        ]);

        $uploaded = $request->file('file');

        // Build a safe, collision-free filename
        $originalName  = $uploaded->getClientOriginalName();
        $safeName      = Str::slug(pathinfo($originalName, PATHINFO_FILENAME), '_');
        $ext           = strtolower($uploaded->getClientOriginalExtension());
        $storedName    = now()->format('YmdHis') . '_' . $safeName . '.' . $ext;
        $storagePath   = 'trade-documents/uploads/' . $order->ref . '/' . $storedName;

        try {
            Storage::disk('local')->put($storagePath, file_get_contents($uploaded->getRealPath()));
        } catch (\Throwable $e) {
            Log::error('Shipment document upload failed (storage write)', [
                'order_id'  => $id,
                'order_ref' => $order->ref,
                'error'     => $e->getMessage(),
            ]);
            return response()->json(['message' => 'File could not be saved. Please try again.'], 500);
        }

        $document = TradeDocument::create([
            'order_id'          => $order->id,
            'order_ref'         => $order->ref,
            'type'              => 'shipment_document',
            'type_label'        => $request->input('type_label'),
            'status'            => 'issued',
            'file_path'         => $storagePath,
            'original_filename' => $originalName,
            'mime_type'         => $uploaded->getClientMimeType(),
            'file_size'         => $uploaded->getSize(),
            'notes'             => $request->input('notes'),
            'issued_by'         => $admin?->id,
            'issued_at'         => now(),
        ]);

        try {
            OrderLog::create([
                'order_id'         => $order->id,
                'order_ref'        => $order->ref,
                'admin_user_id'    => $admin?->id,
                'admin_user_email' => $admin?->email,
                'action'           => 'document_uploaded',
                'new_value'        => $request->input('type_label'),
                'notes'            => 'Shipment document uploaded: ' . $originalName,
                'ip_address'       => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('OrderLog write failed (shipment document upload)', [
                'order_ref' => $order->ref,
                'error'     => $e->getMessage(),
            ]);
        }

        return response()->json([
            'data'    => $this->formatDocument($document),
            'message' => 'Document uploaded successfully.',
        ], 201);
    }

    /**
     * DELETE /api/v1/admin/trade-documents/{id}
     *
     * Delete an uploaded shipment document.
     * Only shipment_document type may be deleted — generated PDFs are protected.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $document = TradeDocument::findOrFail($id);
        $admin    = $request->user();

        if ($document->type !== 'shipment_document') {
            return response()->json([
                'message' => 'Only uploaded shipment documents can be deleted.',
            ], 422);
        }

        $filePath = $document->getRawOriginal('file_path');
        if ($filePath && Storage::disk('local')->exists($filePath)) {
            Storage::disk('local')->delete($filePath);
        }

        $orderRef  = $document->order_ref;
        $orderId   = $document->order_id;
        $typeLabel = $document->type_label;
        $filename  = $document->original_filename;

        $document->delete();

        try {
            OrderLog::create([
                'order_id'         => $orderId,
                'order_ref'        => $orderRef,
                'admin_user_id'    => $admin?->id,
                'admin_user_email' => $admin?->email,
                'action'           => 'document_deleted',
                'new_value'        => $typeLabel,
                'notes'            => 'Shipment document deleted: ' . $filename,
                'ip_address'       => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('OrderLog write failed (shipment document delete)', [
                'order_ref' => $orderRef,
                'error'     => $e->getMessage(),
            ]);
        }

        return response()->json(['message' => 'Document deleted.']);
    }

    /**
     * GET /api/v1/admin/orders/{id}/trade-documents
     *
     * List all trade documents attached to an order.
     */
    public function indexForOrder(int $id): JsonResponse
    {
        $order     = Order::findOrFail($id);
        $documents = TradeDocument::where('order_id', $order->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data'    => $documents->map(fn ($d) => $this->formatDocument($d))->values(),
            'message' => 'success',
        ]);
    }

    /**
     * GET /api/v1/admin/trade-documents/{id}/download
     *
     * Stream a trade document PDF or uploaded file from the private disk.
     */
    public function download(int $id): BinaryFileResponse|JsonResponse
    {
        $document = TradeDocument::findOrFail($id);

        $filePath = $document->pdf_path ?? $document->file_path;

        if (! $filePath) {
            return response()->json(['message' => 'No file is available for this document.'], 404);
        }

        $fullPath = storage_path('app/private/' . $filePath);

        if (! file_exists($fullPath)) {
            Log::warning('Admin trade document download: file missing', [
                'document_id' => $document->id,
                'file_path'   => $filePath,
            ]);
            return response()->json(['message' => 'Document file was not found.'], 404);
        }

        $filename = $document->original_filename
            ?? ($document->number ? $document->number . '.pdf' : 'document.pdf');

        return response()->download($fullPath, $filename);
    }

    // -------------------------------------------------------------------------

    private function formatDocument(TradeDocument $d): array
    {
        return [
            'id'                => $d->id,
            'order_id'          => $d->order_id,
            'order_ref'         => $d->order_ref,
            'type'              => $d->type,
            'type_label'        => $d->type_label,
            'number'            => $d->number,
            'status'            => $d->status,
            'has_pdf'           => (bool) $d->getRawOriginal('pdf_path'),
            'has_file'          => (bool) $d->getRawOriginal('file_path'),
            'original_filename' => $d->original_filename,
            'mime_type'         => $d->mime_type,
            'file_size'         => $d->file_size,
            'notes'             => $d->notes,
            'issued_by'         => $d->issued_by,
            'issued_at'         => $d->issued_at?->toIso8601String(),
            'sent_at'           => $d->sent_at?->toIso8601String(),
            'created_at'        => $d->created_at?->toIso8601String(),
            'updated_at'        => $d->updated_at?->toIso8601String(),
        ];
    }
}

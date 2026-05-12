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

        $document = $this->service->generatePackingListForOrder($order, $admin);

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

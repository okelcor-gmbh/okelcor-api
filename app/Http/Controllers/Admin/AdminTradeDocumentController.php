<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\OrderConfirmationAcceptanceRequest;
use App\Mail\TradeDocumentEmail;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\TradeDocument;
use App\Services\TradeDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminTradeDocumentController extends Controller
{
    public function __construct(private TradeDocumentService $service) {}

    /**
     * POST /api/v1/admin/orders/{id}/trade-documents/order-confirmation
     *
     * Generate (or return existing) order confirmation for an order.
     * Idempotent — calling multiple times returns the same issued document.
     */
    public function generateOrderConfirmation(Request $request, int $id): JsonResponse
    {
        $order = Order::with('items')->findOrFail($id);
        $admin = $request->user();

        $document = $this->service->generateOrderConfirmationForOrder($order, $admin);

        if ($document->wasRecentlyCreated) {
            try {
                OrderLog::create([
                    'order_id'         => $order->id,
                    'order_ref'        => $order->ref,
                    'admin_user_id'    => $admin?->id,
                    'admin_user_email' => $admin?->email,
                    'action'           => 'document_generated',
                    'new_value'        => $document->number,
                    'notes'            => 'Order confirmation generated.',
                    'ip_address'       => $request->ip(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('OrderLog write failed (order confirmation generation)', [
                    'order_ref' => $order->ref,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'data'    => $this->formatDocument($document),
            'message' => $document->wasRecentlyCreated
                ? 'Order confirmation generated.'
                : 'Order confirmation already exists for this order.',
        ], $document->wasRecentlyCreated ? 201 : 200);
    }

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

        // Gate: require customer acceptance unless super_admin overrides explicitly
        if ($order->customer_acceptance_status !== 'accepted') {
            $isOverride = $request->boolean('override_acceptance') && $admin?->role === 'super_admin';

            if (! $isOverride) {
                try {
                    OrderLog::create([
                        'order_id'         => $order->id,
                        'order_ref'        => $order->ref,
                        'admin_user_id'    => $admin?->id,
                        'admin_user_email' => $admin?->email,
                        'action'           => 'proforma_generation_blocked_no_acceptance',
                        'notes'            => 'Proforma blocked: customer_acceptance_status=' . $order->customer_acceptance_status,
                        'ip_address'       => $request->ip(),
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('OrderLog write failed (proforma blocked)', ['order_ref' => $order->ref]);
                }

                return response()->json([
                    'message'  => 'Customer acceptance is required before generating a proforma invoice.',
                    'code'     => 'customer_acceptance_required',
                    'status'   => $order->customer_acceptance_status,
                ], 409);
            }
        }

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

        $depositStages = ['deposit_paid', 'balance_due', 'balance_paid', 'shipment_released'];
        if (! in_array($order->payment_stage, $depositStages, true)) {
            $this->logDocumentBlocked($request, $order, 'packing_list', $order->payment_stage);
            return response()->json([
                'message'       => 'Packing list can only be generated after the deposit has been confirmed.',
                'code'          => 'document_generation_blocked_payment_stage',
                'payment_stage' => $order->payment_stage,
                'required'      => 'deposit_paid',
            ], 409);
        }

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

        if ($order->payment_stage !== 'shipment_released') {
            $this->logDocumentBlocked($request, $order, 'delivery_note', $order->payment_stage);
            return response()->json([
                'message'       => 'Delivery note can only be generated after the shipment has been released.',
                'code'          => 'document_generation_blocked_payment_stage',
                'payment_stage' => $order->payment_stage,
                'required'      => 'shipment_released',
            ], 409);
        }

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

        $depositStages = ['deposit_paid', 'balance_due', 'balance_paid', 'shipment_released'];
        if (! in_array($order->payment_stage, $depositStages, true)) {
            $this->logDocumentBlocked($request, $order, 'commercial_invoice', $order->payment_stage);
            return response()->json([
                'message'       => 'Commercial invoice can only be generated after the deposit has been confirmed.',
                'code'          => 'document_generation_blocked_payment_stage',
                'payment_stage' => $order->payment_stage,
                'required'      => 'deposit_paid',
            ], 409);
        }

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

        $depositStages = ['deposit_paid', 'balance_due', 'balance_paid', 'shipment_released'];
        if (! in_array($order->payment_stage, $depositStages, true)) {
            $this->logDocumentBlocked($request, $order, 'shipment_document_upload', $order->payment_stage);
            return response()->json([
                'message'       => 'Shipment documents can only be uploaded after the deposit has been confirmed.',
                'code'          => 'document_generation_blocked_payment_stage',
                'payment_stage' => $order->payment_stage,
                'required'      => 'deposit_paid',
            ], 409);
        }

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
     * POST /api/v1/admin/trade-documents/{id}/send-email
     *
     * Send a trade document to a recipient by email with the file attached.
     * Updates sent_at on success and writes a document_sent order log entry.
     */
    public function sendEmail(Request $request, int $id): JsonResponse
    {
        $document = TradeDocument::findOrFail($id);
        $admin    = $request->user();

        $request->validate([
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'message'         => ['nullable', 'string', 'max:1000'],
        ]);

        // Verify a file is present — either generated PDF or uploaded file
        $storedPath = $document->getRawOriginal('pdf_path')
            ?? $document->getRawOriginal('file_path');

        if (! $storedPath) {
            return response()->json([
                'message' => 'This document has no file to send.',
            ], 422);
        }

        $fullPath = storage_path('app/private/' . $storedPath);

        if (! file_exists($fullPath)) {
            Log::warning('Trade document send: file missing on disk', [
                'document_id' => $document->id,
                'stored_path' => $storedPath,
            ]);
            return response()->json([
                'message' => 'Document file was not found.',
            ], 404);
        }

        // Resolve recipient — fallback to the order's customer email
        $order          = Order::find($document->order_id);
        $recipientEmail = $request->input('recipient_email') ?? $order?->customer_email;

        if (! $recipientEmail) {
            return response()->json([
                'message' => 'No recipient email could be determined. Please provide one.',
            ], 422);
        }

        // Send mail
        try {
            Mail::to($recipientEmail)->send(new TradeDocumentEmail(
                document:       $document,
                order:          $order,
                recipientEmail: $recipientEmail,
                adminMessage:   $request->input('message'),
            ));
        } catch (\Throwable $e) {
            Log::error('Trade document email failed', [
                'document_id' => $document->id,
                'order_ref'   => $document->order_ref,
                'recipient'   => $recipientEmail,
                'error'       => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Failed to send email. Please try again or contact support.',
            ], 500);
        }

        // Advance lifecycle: issued → sent
        $document->update(['status' => 'sent', 'sent_at' => now()]);

        // Audit log — wrapped so it never blocks the 200 response
        try {
            OrderLog::create([
                'order_id'         => $document->order_id,
                'order_ref'        => $document->order_ref,
                'admin_user_id'    => $admin?->id,
                'admin_user_email' => $admin?->email,
                'action'           => 'document_sent',
                'new_value'        => $document->number ?? $document->type_label,
                'notes'            => 'Document sent to ' . $recipientEmail
                    . ': ' . ($document->original_filename ?? $document->number ?? $document->type),
                'ip_address'       => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('OrderLog write failed (document send)', [
                'order_ref' => $document->order_ref,
                'error'     => $e->getMessage(),
            ]);
        }

        return response()->json([
            'data'    => [
                'id'              => $document->id,
                'sent_at'         => $document->sent_at->toIso8601String(),
                'recipient_email' => $recipientEmail,
            ],
            'message' => 'Document sent successfully.',
        ]);
    }

    /**
     * POST /api/v1/admin/orders/{id}/trade-documents/{documentId}/supersede
     *
     * Mark a generated trade document as superseded (e.g. wrong delivery fee).
     * The original PDF is preserved for audit. A new document can then be regenerated.
     * Only generated types (proforma/commercial_invoice/packing_list/delivery_note) are supersedable.
     * Only status=issued documents may be superseded.
     */
    public function supersede(Request $request, int $orderId, int $documentId): JsonResponse
    {
        $order    = Order::findOrFail($orderId);
        $document = TradeDocument::where('id', $documentId)
            ->where('order_id', $order->id)
            ->firstOrFail();

        $admin = $request->user();

        $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $supersedable = ['order_confirmation', 'proforma', 'commercial_invoice', 'packing_list', 'delivery_note'];

        if (! in_array($document->type, $supersedable, true)) {
            return response()->json([
                'message' => 'Only generated documents (order confirmation, proforma, commercial invoice, packing list, delivery note) can be superseded.',
            ], 422);
        }

        if (! in_array($document->status, ['issued', 'sent'], true)) {
            return response()->json([
                'message' => "Document is already '{$document->status}' and cannot be superseded.",
            ], 422);
        }

        $document->update([
            'status'           => 'superseded',
            'superseded_at'    => now(),
            'superseded_by_id' => $admin?->id,
            'supersede_reason' => $request->input('reason'),
        ]);

        try {
            OrderLog::create([
                'order_id'         => $order->id,
                'order_ref'        => $order->ref,
                'admin_user_id'    => $admin?->id,
                'admin_user_email' => $admin?->email,
                'action'           => 'document_superseded',
                'old_value'        => $document->number,
                'new_value'        => 'superseded',
                'notes'            => 'Document superseded: ' . ($document->number ?? $document->type)
                    . '. Reason: ' . $request->input('reason'),
                'ip_address'       => $request->ip(),
                'created_at'       => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('OrderLog write failed (document supersede)', [
                'order_ref'   => $order->ref,
                'document_id' => $document->id,
                'error'       => $e->getMessage(),
            ]);
        }

        return response()->json([
            'data'    => $this->formatDocument($document->refresh()),
            'message' => "Document {$document->number} marked as superseded. You can now regenerate a corrected document.",
        ]);
    }

    /**
     * POST /api/v1/admin/orders/{id}/generate-acceptance-link
     *
     * Generate a secure 64-char token and return a frontend URL the customer can use
     * to accept/reject the order confirmation without needing an account.
     * Token expires in 7 days. Calling again rotates the token.
     */
    public function generateAcceptanceLink(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        if ($order->customer_acceptance_status === 'accepted') {
            return response()->json([
                'message' => 'Customer has already accepted this order confirmation.',
            ], 409);
        }

        $token   = bin2hex(random_bytes(32)); // 64 hex chars
        $expires = now()->addDays(7);

        $order->update([
            'acceptance_token'            => $token,
            'acceptance_token_expires_at' => $expires,
        ]);

        $frontendUrl = rtrim(config('app.frontend_url', 'https://okelcor.com'), '/');
        $acceptUrl   = $frontendUrl . '/orders/' . $order->ref . '/accept-confirmation?token=' . $token;

        try {
            OrderLog::create([
                'order_id'         => $order->id,
                'order_ref'        => $order->ref,
                'admin_user_id'    => $request->user()?->id,
                'admin_user_email' => $request->user()?->email,
                'action'           => 'acceptance_link_generated',
                'notes'            => 'Acceptance link generated. Expires: ' . $expires->toDateString(),
                'ip_address'       => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('OrderLog write failed (acceptance link generation)', ['order_ref' => $order->ref]);
        }

        return response()->json([
            'data' => [
                'accept_url'  => $acceptUrl,
                'expires_at'  => $expires->toIso8601String(),
                'order_ref'   => $order->ref,
            ],
            'message' => 'Acceptance link generated. Share this with the customer to collect their response.',
        ]);
    }

    /**
     * POST /api/v1/admin/trade-documents/{id}/void
     *
     * Mark a generated document as void (cancelled, invalid, not superseded by another).
     * Only issued/sent generated documents may be voided.
     */
    public function void(Request $request, int $id): JsonResponse
    {
        $document = TradeDocument::findOrFail($id);
        $admin    = $request->user();

        $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $voidable = ['order_confirmation', 'proforma', 'commercial_invoice', 'packing_list', 'delivery_note'];

        if (! in_array($document->type, $voidable, true)) {
            return response()->json([
                'message' => 'Only generated documents can be voided.',
            ], 422);
        }

        if (! in_array($document->status, ['issued', 'sent'], true)) {
            return response()->json([
                'message' => "Document is already '{$document->status}' and cannot be voided.",
            ], 422);
        }

        $document->update(['status' => 'void']);

        try {
            OrderLog::create([
                'order_id'         => $document->order_id,
                'order_ref'        => $document->order_ref,
                'admin_user_id'    => $admin?->id,
                'admin_user_email' => $admin?->email,
                'action'           => 'document_voided',
                'old_value'        => $document->number,
                'new_value'        => 'void',
                'notes'            => 'Document voided: ' . ($document->number ?? $document->type)
                    . '. Reason: ' . $request->input('reason'),
                'ip_address'       => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('OrderLog write failed (document void)', [
                'order_ref'   => $document->order_ref,
                'document_id' => $document->id,
                'error'       => $e->getMessage(),
            ]);
        }

        return response()->json([
            'data'    => $this->formatDocument($document->refresh()),
            'message' => "Document {$document->number} has been voided.",
        ]);
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

    private function logDocumentBlocked(Request $request, Order $order, string $docType, string $currentStage): void
    {
        try {
            $admin = $request->user();
            OrderLog::create([
                'order_id'         => $order->id,
                'order_ref'        => $order->ref,
                'admin_user_id'    => $admin?->id,
                'admin_user_email' => $admin?->email,
                'action'           => 'document_generation_blocked_payment_stage',
                'notes'            => "Blocked: {$docType} — payment_stage={$currentStage}",
                'ip_address'       => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('OrderLog write failed (document generation blocked)', [
                'order_ref' => $order->ref,
                'doc_type'  => $docType,
            ]);
        }
    }

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
            'superseded_at'     => $d->superseded_at?->toIso8601String(),
            'superseded_by_id'  => $d->superseded_by_id,
            'supersede_reason'  => $d->supersede_reason,
            'created_at'        => $d->created_at?->toIso8601String(),
            'updated_at'        => $d->updated_at?->toIso8601String(),
        ];
    }

    /**
     * POST /api/v1/admin/orders/{id}/send-acceptance-request
     *
     * Generate (or rotate) an acceptance token, email the customer the Order
     * Confirmation PDF with an embedded accept/reject link, and log the event.
     *
     * Body (optional):
     *   recipient_email  — override; defaults to order.customer_email
     *   message          — optional note shown in the email body
     */
    public function sendAcceptanceRequest(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);
        $admin = $request->user();

        $request->validate([
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'message'         => ['nullable', 'string', 'max:1000'],
        ]);

        if ($order->customer_acceptance_status === 'accepted') {
            return response()->json([
                'message' => 'Customer has already accepted this order confirmation.',
            ], 409);
        }

        // Must have a generated order confirmation document to attach
        $document = TradeDocument::where('order_id', $order->id)
            ->where('type', 'order_confirmation')
            ->whereIn('status', ['issued', 'sent'])
            ->first();

        if (! $document) {
            return response()->json([
                'message' => 'Generate the Order Confirmation document first before sending an acceptance request.',
                'code'    => 'no_order_confirmation',
            ], 422);
        }

        // Generate / rotate acceptance token (7-day TTL)
        $token   = bin2hex(random_bytes(32));
        $expires = now()->addDays(7);

        $order->update([
            'acceptance_token'            => $token,
            'acceptance_token_expires_at' => $expires,
        ]);

        $frontendUrl = rtrim(config('app.frontend_url', 'https://okelcor.com'), '/');
        $acceptUrl   = $frontendUrl . '/orders/' . $order->ref . '/accept-confirmation?token=' . $token;

        $recipientEmail = $request->input('recipient_email') ?? $order->customer_email;

        if (! $recipientEmail) {
            return response()->json([
                'message' => 'No recipient email could be determined. Please provide one.',
            ], 422);
        }

        try {
            Mail::to($recipientEmail)->send(new OrderConfirmationAcceptanceRequest(
                order:        $order,
                document:     $document,
                acceptUrl:    $acceptUrl,
                expiresAt:    $expires->format('d M Y'),
                adminMessage: $request->input('message'),
            ));
        } catch (\Throwable $e) {
            Log::error('Acceptance request email failed', [
                'order_ref'  => $order->ref,
                'recipient'  => $recipientEmail,
                'error'      => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Failed to send acceptance request email. Please try again or contact support.',
            ], 500);
        }

        // Advance document lifecycle: issued → sent
        $document->update(['status' => 'sent', 'sent_at' => now()]);

        try {
            OrderLog::create([
                'order_id'         => $order->id,
                'order_ref'        => $order->ref,
                'admin_user_id'    => $admin?->id,
                'admin_user_email' => $admin?->email,
                'action'           => 'acceptance_request_sent',
                'notes'            => 'Acceptance request sent to ' . $recipientEmail
                    . '. Link expires: ' . $expires->toDateString(),
                'ip_address'       => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('OrderLog write failed (acceptance request sent)', ['order_ref' => $order->ref]);
        }

        return response()->json([
            'data' => [
                'accept_url'      => $acceptUrl,
                'expires_at'      => $expires->toIso8601String(),
                'recipient_email' => $recipientEmail,
                'order_ref'       => $order->ref,
            ],
            'message' => 'Acceptance request sent to ' . $recipientEmail . '.',
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\SignEuDeclarationRequest;
use App\Mail\EuDeclarationSigned;
use App\Models\EuDeclaration;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\EuDeclarationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EuDeclarationController extends Controller
{
    public function __construct(private EuDeclarationService $declarationService) {}

    /**
     * POST /api/v1/auth/orders/{ref}/declaration
     *
     * Customer signs their EU entry certificate (Gelangensbestätigung).
     * If no declaration row exists yet (order pre-dates Phase 2B-2 deployment),
     * one is auto-created provided the order still qualifies (reverse-charge + valid VAT).
     */
    public function sign(SignEuDeclarationRequest $request, string $ref): JsonResponse
    {
        $customer = $request->user();

        $order = Order::where('ref', $ref)->first();

        if (! $order || strtolower($order->customer_email) !== strtolower($customer->email)) {
            Log::warning('EU declaration sign: ownership check failed', [
                'order_ref'   => $ref,
                'customer_id' => $customer->id,
                'ip'          => request()->ip(),
            ]);
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ($order->payment_status !== 'paid') {
            return response()->json([
                'message' => 'Payment must be confirmed before the EU Entry Certificate can be signed.',
            ], 422);
        }

        if ($order->status !== 'delivered') {
            return response()->json([
                'message' => 'The EU Entry Certificate can only be signed after the order has been delivered.',
            ], 422);
        }

        $declaration = EuDeclaration::where('order_id', $order->id)->first();

        if (! $declaration) {
            // Guard: only auto-create when the order genuinely requires a declaration.
            // This covers reverse-charge orders created before Phase 2B-2 was deployed.
            if (! $this->declarationService->shouldRequireForOrder($order)) {
                return response()->json([
                    'message' => 'This order does not require an EU entry certificate.',
                ], 422);
            }

            $invoice     = Invoice::where('order_ref', $order->ref)->first();
            $declaration = $this->declarationService->createForOrder($order, $invoice);

            Log::info('EU declaration auto-created at signing time (pre-2B-2 order)', [
                'order_ref'      => $order->ref,
                'declaration_id' => $declaration->id,
            ]);
        }

        if ($declaration->status !== 'pending') {
            return response()->json([
                'message' => 'This declaration has already been signed.',
            ], 409);
        }

        $validated = $request->validated();

        // ------------------------------------------------------------------
        // Store signature PNG to private disk
        // ------------------------------------------------------------------
        $signatureData   = $validated['signature_data'];
        $base64Payload   = substr($signatureData, strpos($signatureData, ',') + 1);
        $pngBinary       = base64_decode($base64Payload);
        $signatureUuid   = Str::uuid()->toString();
        $signaturePath   = 'eu-declarations/signatures/' . $signatureUuid . '.png';

        Storage::disk('local')->put($signaturePath, $pngBinary);

        // ------------------------------------------------------------------
        // Persist signing fields
        // ------------------------------------------------------------------
        $declaration->update([
            'month_year_received'        => $validated['month_year_received'],
            'member_state_of_entry'      => $validated['member_state_of_entry'],
            'place_of_entry'             => $validated['place_of_entry'],
            'self_transported'           => $validated['self_transported'],
            'month_year_transport_ended' => $validated['month_year_transport_ended'] ?? null,
            'representative_name'        => $validated['representative_name'],
            'representative_title'       => $validated['representative_title'] ?? null,
            'signed_name'                => $validated['signed_name'],
            'accepted_terms'             => true,
            'issue_date'                 => now()->toDateString(),
            'signed_at'                  => now(),
            'signature_path'             => $signaturePath,
            'ip_address'                 => $request->ip(),
            'user_agent'                 => $request->userAgent(),
            'status'                     => 'signed',
        ]);

        $declaration->refresh();

        // ------------------------------------------------------------------
        // Generate PDF — non-blocking
        // ------------------------------------------------------------------
        try {
            $signatureBase64 = base64_encode(Storage::disk('local')->get($signaturePath));

            $pdfContent = Pdf::loadView('pdf.eu-declaration', [
                'declaration'     => $declaration,
                'signatureBase64' => $signatureBase64,
            ])->output();

            $pdfPath = 'eu-declarations/pdf/' . $declaration->order_ref . '.pdf';
            Storage::disk('local')->put($pdfPath, $pdfContent);
            $declaration->update(['pdf_path' => $pdfPath]);
        } catch (\Throwable $e) {
            Log::warning('EU declaration PDF generation failed', [
                'declaration_id' => $declaration->id,
                'order_ref'      => $declaration->order_ref,
                'error'          => $e->getMessage(),
            ]);
        }

        // ------------------------------------------------------------------
        // Send confirmation email to customer — non-blocking
        // ------------------------------------------------------------------
        try {
            Mail::to($declaration->customer_email)->send(new EuDeclarationSigned($declaration));
        } catch (\Throwable $e) {
            Log::error('EU declaration signed email failed', [
                'declaration_id' => $declaration->id,
                'error'          => $e->getMessage(),
            ]);
        }

        return response()->json([
            'data'    => [
                'status'     => $declaration->status,
                'signed_at'  => $declaration->signed_at?->toIso8601String(),
                'order_ref'  => $declaration->order_ref,
                'has_pdf'    => (bool) $declaration->pdf_path,
            ],
            'message' => 'Declaration signed successfully.',
        ]);
    }

    /**
     * GET /api/v1/auth/orders/{ref}/declaration/download
     *
     * Authenticated customer downloads their signed EU entry certificate PDF.
     * Order must belong to the authenticated customer; declaration must be signed.
     */
    public function download(Request $request, string $ref): BinaryFileResponse|JsonResponse
    {
        $customer = $request->user();

        $order = Order::where('ref', $ref)->first();

        if (! $order || strtolower($order->customer_email) !== strtolower($customer->email)) {
            Log::warning('EU declaration download: ownership check failed', [
                'order_ref'   => $ref,
                'customer_id' => $customer->id,
                'ip'          => request()->ip(),
            ]);
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $declaration = EuDeclaration::where('order_id', $order->id)->first();

        if (! $declaration) {
            return response()->json(['message' => 'No entry certificate exists for this order.'], 404);
        }

        if (! in_array($declaration->status, ['signed', 'acknowledged'])) {
            return response()->json(['message' => 'Declaration has not been signed yet.'], 404);
        }

        if (! $declaration->pdf_path) {
            Log::warning('EU declaration download: pdf_path is null', [
                'declaration_id' => $declaration->id,
                'order_ref'      => $declaration->order_ref,
            ]);
            return response()->json(['message' => 'Declaration PDF is not available yet.'], 404);
        }

        $path = storage_path('app/private/' . $declaration->pdf_path);

        if (! file_exists($path)) {
            Log::warning('EU declaration download: file missing on disk', [
                'declaration_id' => $declaration->id,
                'order_ref'      => $declaration->order_ref,
                'pdf_path'       => $declaration->pdf_path,
            ]);
            return response()->json(['message' => 'Declaration PDF file was not found.'], 404);
        }

        return response()->download($path, 'DECL-' . $declaration->order_ref . '.pdf');
    }
}

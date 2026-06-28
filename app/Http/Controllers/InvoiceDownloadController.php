<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Order;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InvoiceDownloadController extends Controller
{
    public function download(Request $request, Invoice $invoice): BinaryFileResponse|JsonResponse
    {
        $customer = $request->user();

        if (! $customer) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ((int) $customer->id !== (int) $invoice->customer_id) {
            // Self-healing fallback: the stored customer_id may be stale (e.g. created
            // before the right Customer account existed). Re-verify ownership via the
            // order's customer_email and silently repair if confirmed.
            $ownsOrder = $invoice->order_ref
                && Order::where('ref', $invoice->order_ref)
                         ->where('customer_email', $customer->email)
                         ->exists();

            if (! $ownsOrder) {
                Log::warning('Invoice download: ownership check failed', [
                    'invoice_id'          => $invoice->id,
                    'invoice_customer_id' => $invoice->customer_id,
                    'auth_customer_id'    => $customer->id,
                ]);
                return response()->json(['message' => 'You do not have access to this invoice.'], 403);
            }

            // Repair the stale customer_id so subsequent requests go through the fast path.
            $invoice->updateQuietly(['customer_id' => $customer->id]);
        }

        if (! $invoice->released_at) {
            return response()->json([
                'message' => 'Invoice is not available until the EU Entry Certificate has been reviewed and acknowledged.',
            ], 423);
        }

        // Resolve (and self-heal) the PDF: regenerates on demand if pdf_url is
        // null or the file is missing, so a one-off generation failure at
        // creation no longer leaves the customer permanently unable to download.
        $diskPath = app(InvoiceService::class)->ensurePdf($invoice);

        if (! $diskPath || ! Storage::disk('public')->exists($diskPath)) {
            Log::warning('Invoice download: PDF unavailable after ensurePdf', [
                'invoice_id'  => $invoice->id,
                'customer_id' => $customer->id,
                'pdf_url_raw' => $invoice->pdf_url,
                'order_ref'   => $invoice->order_ref,
            ]);
            return response()->json([
                'message' => 'Invoice PDF is not available yet. Please contact support.',
            ], 404);
        }

        $absolutePath = Storage::disk('public')->path($diskPath);

        Log::info('Invoice download: serving file', [
            'invoice_id'  => $invoice->id,
            'disk_path'   => $diskPath,
            'absolute'    => $absolutePath,
            'is_readable' => is_readable($absolutePath),
            'filesize'    => file_exists($absolutePath) ? filesize($absolutePath) : 'N/A',
        ]);

        try {
            return response()->file($absolutePath, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $invoice->invoice_number . '.pdf"',
            ]);
        } catch (\Throwable $e) {
            Log::error('Invoice download: streaming failed — no fallback redirect', [
                'invoice_id' => $invoice->id,
                'disk_path'  => $diskPath,
                'error'      => $e->getMessage(),
                'class'      => get_class($e),
            ]);

            return response()->json(['message' => 'Invoice PDF could not be streamed. Please contact support.'], 500);
        }
    }
}

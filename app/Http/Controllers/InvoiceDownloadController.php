<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
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

        if ($customer->id !== $invoice->customer_id) {
            Log::warning('Invoice download: ownership check failed', [
                'invoice_id'          => $invoice->id,
                'invoice_customer_id' => $invoice->customer_id,
                'auth_customer_id'    => $customer->id,
            ]);
            return response()->json(['message' => 'You do not have access to this invoice.'], 403);
        }

        if (! $invoice->released_at) {
            return response()->json([
                'message' => 'Invoice is not available until the EU Entry Certificate has been reviewed and acknowledged.',
            ], 423);
        }

        if (! $invoice->pdf_url) {
            Log::warning('Invoice download: pdf_url is null', [
                'invoice_id'  => $invoice->id,
                'customer_id' => $customer->id,
            ]);
            return response()->json(['message' => 'Invoice PDF is not available yet.'], 404);
        }

        // Normalize pdf_url to a relative disk path regardless of how it was stored.
        // Supported formats:
        //   "invoices/INV-2026-0004.pdf"                         → already correct
        //   "/storage/invoices/INV-2026-0004.pdf"                → strip /storage/ prefix
        //   "https://api.okelcor.com/storage/invoices/INV-….pdf" → extract after /storage/
        $diskPath = $this->normalizePdfPath($invoice->pdf_url);

        // Fallback: if the stored/normalized path is missing, try the canonical name.
        // Guards against a corrupted pdf_url column while the file is physically present.
        $canonical = 'invoices/' . $invoice->invoice_number . '.pdf';

        if (! Storage::disk('public')->exists($diskPath)) {
            if ($diskPath !== $canonical && Storage::disk('public')->exists($canonical)) {
                // Silently repair pdf_url to the correct relative path
                $invoice->updateQuietly(['pdf_url' => $canonical]);
                $diskPath = $canonical;
            } else {
                Log::warning('Invoice download: file missing on disk', [
                    'invoice_id'   => $invoice->id,
                    'customer_id'  => $customer->id,
                    'pdf_url_raw'  => $invoice->pdf_url,
                    'pdf_url_norm' => $diskPath,
                    'pdf_canonical'=> $canonical,
                    'full_path'    => Storage::disk('public')->path($diskPath),
                ]);
                return response()->json(['message' => 'Invoice PDF file was not found.'], 404);
            }
        }

        return response()->file(Storage::disk('public')->path($diskPath), [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $invoice->invoice_number . '.pdf"',
        ]);
    }

    /**
     * Normalize any pdf_url format to a relative public-disk path.
     *
     * Supported inputs → output:
     *   invoices/INV-2026-0004.pdf                            → invoices/INV-2026-0004.pdf
     *   /storage/invoices/INV-2026-0004.pdf                   → invoices/INV-2026-0004.pdf
     *   https://api.okelcor.com/storage/invoices/INV-….pdf   → invoices/INV-2026-0004.pdf
     *   http://localhost:8000/storage/invoices/INV-….pdf      → invoices/INV-2026-0004.pdf
     */
    private function normalizePdfPath(string $raw): string
    {
        // Anything containing /storage/ — extract the part after it
        if (preg_match('#/storage/(.+)$#', $raw, $m)) {
            return $m[1];
        }

        // Relative path that may have a stray leading slash
        return ltrim($raw, '/');
    }
}

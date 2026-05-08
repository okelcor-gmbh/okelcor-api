<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
                'message' => 'Invoice is not available until the EU Entry Certificate is signed.',
            ], 423);
        }

        if (! $invoice->pdf_url) {
            Log::warning('Invoice download: pdf_url is null', [
                'invoice_id'          => $invoice->id,
                'invoice_customer_id' => $invoice->customer_id,
                'auth_customer_id'    => $customer->id,
                'pdf_url'             => null,
            ]);
            return response()->json(['message' => 'Invoice PDF is not available yet.'], 404);
        }

        $path = storage_path('app/public/' . $invoice->pdf_url);

        if (! file_exists($path)) {
            Log::warning('Invoice download: file missing on disk', [
                'invoice_id'          => $invoice->id,
                'invoice_customer_id' => $invoice->customer_id,
                'auth_customer_id'    => $customer->id,
                'pdf_url'             => $invoice->pdf_url,
            ]);
            return response()->json(['message' => 'Invoice PDF file was not found.'], 404);
        }

        return response()->download($path, $invoice->invoice_number . '.pdf');
    }
}

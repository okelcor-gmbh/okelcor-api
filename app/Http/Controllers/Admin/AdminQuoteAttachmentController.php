<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\QuoteRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminQuoteAttachmentController extends Controller
{
    /**
     * GET /api/v1/admin/quote-attachments/{id}/download
     *
     * Stream a quote request attachment from the private disk.
     * Never exposes underlying storage paths or public URLs.
     */
    public function download(Request $request, int $id): BinaryFileResponse|JsonResponse
    {
        $quote = QuoteRequest::find($id);

        if (! $quote) {
            Log::warning('Quote attachment download: quote not found', [
                'quote_id'  => $id,
                'admin_id'  => $request->user()?->id,
            ]);
            return response()->json(['message' => 'Quote request not found.'], 404);
        }

        if (! $quote->attachment_path) {
            return response()->json(['message' => 'This quote has no attachment.'], 404);
        }

        // Check private disk first (new uploads since P2 hardening)
        $privatePath = Storage::disk('local')->path($quote->attachment_path);

        // Fall back to public disk for attachments uploaded before the migration
        $publicExists = false;
        if (! file_exists($privatePath)) {
            $publicExists = Storage::disk('public')->exists($quote->attachment_path);

            if (! $publicExists) {
                Log::warning('Quote attachment download: file missing on both disks', [
                    'quote_id'        => $quote->id,
                    'attachment_path' => $quote->attachment_path,
                    'admin_id'        => $request->user()?->id,
                ]);
                return response()->json(['message' => 'Attachment file was not found.'], 404);
            }
        }

        $fullPath = $publicExists
            ? Storage::disk('public')->path($quote->attachment_path)
            : $privatePath;

        $filename = $quote->attachment_original_name
            ?? basename($quote->attachment_path);

        return response()->download($fullPath, $filename, [
            'Content-Type' => $quote->attachment_mime ?? 'application/octet-stream',
        ]);
    }
}

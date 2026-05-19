<?php

namespace App\Http\Controllers;

use App\Models\TradeDocument;
use Illuminate\Http\JsonResponse;

class DocumentVerificationController extends Controller
{
    /**
     * GET /api/v1/documents/verify/{number}
     *
     * Public endpoint — no auth required.
     * Returns only safe metadata; never exposes personal data, paths, or download URLs.
     */
    public function verify(string $number): JsonResponse
    {
        $doc = TradeDocument::where('number', $number)->first();

        if (! $doc) {
            return response()->json([
                'valid'   => false,
                'message' => 'Document not found.',
            ], 404);
        }

        $valid = in_array($doc->status, ['issued', 'sent'], true);

        $message = match ($doc->status) {
            'superseded' => 'This document has been superseded. Please request the latest version from Okelcor.',
            'void'       => 'This document has been voided. Please contact Okelcor for assistance.',
            'draft'      => 'This document has not yet been issued.',
            default      => null,
        };

        return response()->json([
            'valid'            => $valid,
            'document_number'  => $doc->number,
            'document_type'    => $doc->type_label ?? ucwords(str_replace('_', ' ', $doc->type)),
            'order_reference'  => $doc->order_ref,
            'issued_at'        => $doc->issued_at?->toIso8601String(),
            'status'           => $doc->status,
            'company'          => 'Okelcor GmbH',
            'message'          => $message,
        ]);
    }
}

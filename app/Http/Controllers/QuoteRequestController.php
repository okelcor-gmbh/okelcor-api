<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQuoteRequestRequest;
use App\Mail\QuoteRequestAcknowledgement;
use App\Mail\QuoteRequestReceived;
use App\Models\Customer;
use App\Models\QuoteRequest;
use App\Services\InquiryQualityService;
use App\Services\TaxService;
use App\Services\VatValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class QuoteRequestController extends Controller
{
    public function __construct(
        private VatValidationService $vatService,
        private TaxService $taxService,
        private InquiryQualityService $qualityService,
    ) {}

    public function store(StoreQuoteRequestRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // ── Quality gate ─────────────────────────────────────────────────────
        $quality = $this->qualityService->score($validated);

        // Hard spam: save record for audit trail, then reject the submission
        if ($quality['review_status'] === 'spam') {
            // Persist spam record (for pattern analysis) — generate minimal ref
            try {
                QuoteRequest::create(array_merge($validated, [
                    'customer_id'    => null,
                    'ref_number'     => $this->generateRef(),
                    'status'         => 'new',
                    'review_status'  => 'spam',
                    'quality_score'  => $quality['quality_score'],
                    'quality_flags'  => $quality['quality_flags'],
                    'ip_address'     => $request->ip(),
                    'vat_number'     => null,
                    'vat_valid'      => null,
                ]));
            } catch (\Throwable $e) {
                Log::warning('[quote_spam_save_failed] Could not persist spam submission', [
                    'error' => $e->getMessage(),
                    'flags' => $quality['quality_flags'],
                ]);
            }

            return response()->json([
                'message' => 'Please provide a clear business inquiry including tire size, quantity, destination country, and your contact details.',
                'code'    => 'low_quality_inquiry',
                'flags'   => $quality['quality_flags'],
            ], 422);
        }

        // ── Continue with normal submission ───────────────────────────────────
        $refNumber = $this->generateRef();

        // Resolve customer from auth token (optional — quote submission is public)
        $customer = $this->resolveCustomerFromToken($request);

        // Customer type: auth token wins; then explicit request field; then infer from company_name
        $customerType = $customer?->customer_type
            ?? ($validated['customer_type'] ?? null)
            ?? (! empty($validated['company_name']) ? 'b2b' : null);

        // Strip VAT for individual (b2c) customers — they don't have a business VAT number
        $vatNumber = ($customerType === 'b2c')
            ? null
            : ($validated['vat_number'] ?? null);
        $vatValid     = null;
        $vatValidBool = null;

        if ($vatNumber) {
            $vatResult    = $this->vatService->validate($vatNumber);
            $vatValid     = $vatResult['valid'] ? 1 : 0;
            $vatValidBool = (bool) $vatResult['valid'];
        }

        // EU VAT enforcement: B2B customers outside Germany must supply a valid VAT number
        if ($this->taxService->requiresEuVat($validated['country'], $customerType)) {
            if (! $vatNumber) {
                return response()->json([
                    'message' => 'A valid EU VAT number is required for business purchases in EU member states.',
                    'errors'  => ['vat_number' => ['A valid EU VAT number is required for business purchases in EU member states.']],
                ], 422);
            }
            if (! $vatValidBool) {
                return response()->json([
                    'message' => 'A valid EU VAT number is required for business purchases in EU member states.',
                    'errors'  => ['vat_number' => ['Your VAT number could not be validated. Please check it and try again.']],
                ], 422);
            }
        }

        $quote = QuoteRequest::create(array_merge(
            $validated,
            [
                'customer_id'          => $customer?->id,
                'ref_number'           => $refNumber,
                'status'               => 'new',
                'review_status'        => $quality['review_status'],
                'quality_score'        => $quality['quality_score'],
                'quality_flags'        => $quality['quality_flags'],
                'qualification_status' => $quality['review_status'],  // mirrors review_status at creation
                'lead_source'          => 'website_quote',
                'ip_address'           => $request->ip(),
                'vat_number'           => $vatNumber,
                'vat_valid'            => $vatValid,
            ]
        ));

        if ($request->hasFile('attachment')) {
            $file     = $request->file('attachment');
            $ext      = strtolower($file->getClientOriginalExtension());
            $filename = Str::uuid() . '.' . $ext;

            Storage::disk('local')->putFileAs('quote-attachments', $file, $filename);

            $quote->update([
                'attachment_path'          => 'quote-attachments/' . $filename,
                'attachment_original_name' => $file->getClientOriginalName(),
                'attachment_mime'          => $file->getMimeType(),
                'attachment_size'          => $file->getSize(),
            ]);
        }

        // Admin notification — send for both qualified and needs_review; never for spam
        $quoteEmail   = config('mail.quote_email');
        $isNeedsReview = $quality['review_status'] === 'needs_review';

        if ($quoteEmail) {
            try {
                Log::info('[quote_email_sending] Dispatching admin notification', [
                    'event'          => 'quote_email_sending',
                    'ref'            => $refNumber,
                    'to'             => $quoteEmail,
                    'review_status'  => $quality['review_status'],
                    'quality_score'  => $quality['quality_score'],
                ]);
                Mail::to($quoteEmail)->send(new QuoteRequestReceived($quote, $isNeedsReview));
                Log::info('[quote_email_sent] Admin notification delivered', [
                    'event'         => 'quote_email_sent',
                    'ref'           => $refNumber,
                    'needs_review'  => $isNeedsReview,
                ]);
            } catch (\Throwable $e) {
                Log::error('[quote_email_failed] Admin notification failed', [
                    'event' => 'quote_email_failed',
                    'ref'   => $refNumber,
                    'to'    => $quoteEmail,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            Log::warning('[quote_email_misconfigured] QUOTE_EMAIL is not set — admin notification skipped', [
                'event' => 'quote_email_misconfigured',
                'ref'   => $refNumber,
            ]);
        }

        // Customer acknowledgement
        try {
            Log::info('[quote_ack_sending] Dispatching customer acknowledgement', [
                'event' => 'quote_ack_sending',
                'ref'   => $refNumber,
                'to'    => $quote->email,
            ]);
            Mail::to($quote->email)->send(new QuoteRequestAcknowledgement($quote));
            Log::info('[quote_ack_sent] Customer acknowledgement delivered', [
                'event' => 'quote_ack_sent',
                'ref'   => $refNumber,
            ]);
        } catch (\Throwable $e) {
            Log::error('[quote_ack_failed] Customer acknowledgement failed', [
                'event' => 'quote_ack_failed',
                'ref'   => $refNumber,
                'error' => $e->getMessage(),
            ]);
        }

        $responseMessage = $isNeedsReview
            ? 'Your inquiry has been received and will be reviewed by our team. We will be in touch shortly.'
            : 'Quote request received. Our team will respond within 1 business day.';

        return response()->json([
            'data' => [
                'ref_number'    => $refNumber,
                'message'       => $responseMessage,
                'review_status' => $quality['review_status'],
            ],
        ], 201);
    }

    private function resolveCustomerFromToken(Request $request): ?Customer
    {
        $raw = $request->bearerToken();
        if (! $raw) {
            return null;
        }

        $token = PersonalAccessToken::findToken($raw);
        if (! $token || $token->tokenable_type !== Customer::class) {
            return null;
        }

        return $token->tokenable;
    }

    private function generateRef(): string
    {
        $timestamp = substr((string) now()->timestamp, -6);
        $rand      = strtoupper(Str::random(3));

        return "OKL-QR-{$timestamp}-{$rand}";
    }
}

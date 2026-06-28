<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQuoteRequestRequest;
use App\Http\Requests\StoreWholesalerLeadRequest;
use App\Mail\QuoteRequestAcknowledgement;
use App\Mail\QuoteRequestReceived;
use App\Models\Customer;
use App\Models\QuoteRequest;
use App\Services\AdminNotificationService;
use App\Services\CustomerNotifier;
use App\Services\InquiryQualityService;
use App\Services\TaxService;
use App\Services\VatValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Arr;
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

        // Separate lead source + conversion attribution from the quote columns.
        $leadSource   = $validated['lead_source'] ?? $validated['source'] ?? 'website_quote';
        $leadMetadata = $this->buildLeadMetadata($validated);
        $validated    = Arr::except($validated, [
            'lead_source', 'source', 'metadata',
            'primary_tyre_interest', 'estimated_monthly_volume', 'landing_page',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'gclid', 'fbclid', 'referrer',
        ]);

        // quantity is optional (landing/ads leads omit it) but the column is
        // NOT NULL — fall back to the stated volume, else a clear placeholder.
        if (empty($validated['quantity'])) {
            $validated['quantity'] = $leadMetadata['estimated_monthly_volume'] ?? 'Not specified';
        }

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
                    'lead_source'    => $leadSource,
                    'lead_metadata'  => $leadMetadata ?: null,
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

        // EU VAT enforcement: B2B customers outside Germany must supply a valid
        // VAT number. Only enforced for the standard website quote form, which
        // collects a VAT field — marketing/ads landing leads capture interest
        // without it and must not be hard-blocked at the inquiry stage.
        if ($leadSource === 'website_quote' && $this->taxService->requiresEuVat($validated['country'], $customerType)) {
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
                'lead_source'          => $leadSource,
                'lead_metadata'        => $leadMetadata ?: null,
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

        // Link to existing customer if guest submission matches a known email (CRM-5)
        if (! $customer) {
            $existingCustomer = Customer::whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($quote->email))])
                ->first();
            if ($existingCustomer) {
                $quote->update(['possible_customer_id' => $existingCustomer->id]);
            }
        }

        // Admin notification + customer acknowledgement (shared with landing leads)
        $this->dispatchInquirySideEffects($quote, $quality);

        $isNeedsReview = $quality['review_status'] === 'needs_review';

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

    /**
     * POST /api/v1/leads/tyre-wholesaler
     *
     * Dedicated intake for the /tyre-wholesaler SEO/ads landing page.
     *
     * Accepts the landing form's own field names (name/company/interest/volume)
     * and normalises them into the standard quote_requests pipeline so the lead
     * flows through CRM-2 (quality scoring), CRM-3 (pipeline defaults) and the
     * CRM-3B notifications exactly like a normal inquiry. Conversion attribution
     * (utm_*, gclid, fbclid, referrer, landing_page) is persisted in
     * lead_metadata. Phone is intentionally optional for this campaign form.
     */
    public function storeWholesalerLead(StoreWholesalerLeadRequest $request): JsonResponse
    {
        $data = $request->validated();

        $interest = $data['interest'] ?? null;        // PCR | TBR | OTR | Value | Mixed
        $volume   = $data['volume'] ?? null;           // less-than-1 | 1-to-5 | 5-plus
        $rawNotes = trim((string) ($data['notes'] ?? ''));

        $volumeLabel = match ($volume) {
            'less-than-1' => 'Less than 1 container / month',
            '1-to-5'      => '1–5 containers / month',
            '5-plus'      => '5+ containers / month',
            default       => null,
        };

        // Guarantee a meaningful, non-empty notes value (column is NOT NULL and
        // CRM-2 scores on it). Synthesise from structured fields when the
        // free-text box was left blank.
        $notes = $rawNotes !== ''
            ? $rawNotes
            : sprintf(
                'Wholesale inquiry via /tyre-wholesaler. Primary interest: %s. Estimated monthly volume: %s. Destination: %s.',
                $interest ?: 'unspecified',
                $volumeLabel ?: 'unspecified',
                $data['country']
            );

        // CRM-2 quality gate — enrich the scored text with the structured
        // selections so a genuine, terse ad lead isn't mistaken for spam.
        $scoringPayload = [
            'notes'        => trim($notes . ' | Interest: ' . ($interest ?? '') . ' | Volume: ' . ($volumeLabel ?? '')),
            'email'        => $data['email'],
            'country'      => $data['country'],
            'company_name' => $data['company_name'] ?? ($data['company'] ?? ''),
            'phone'        => $data['phone'] ?? '',
            'quantity'     => $volumeLabel ?? '',
        ];
        $quality = $this->qualityService->score($scoringPayload);

        $companyName = $data['company_name'] ?? ($data['company'] ?? null);
        $fullName    = $data['full_name'] ?? ($data['name'] ?? '');

        $leadMetadata = array_filter([
            'landing_page'             => $data['landing_page'] ?? '/tyre-wholesaler',
            'primary_tyre_interest'    => $interest,
            'estimated_monthly_volume' => $volume,
            'utm_source'               => $data['utm_source']   ?? null,
            'utm_medium'               => $data['utm_medium']   ?? null,
            'utm_campaign'             => $data['utm_campaign'] ?? null,
            'utm_term'                 => $data['utm_term']     ?? null,
            'utm_content'              => $data['utm_content']  ?? null,
            'gclid'                    => $data['gclid']        ?? null,
            'fbclid'                   => $data['fbclid']       ?? null,
            'referrer'                 => $data['referrer']     ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $refNumber = $this->generateRef();

        // Hard spam: persist for audit, then reject (mirrors store()).
        if ($quality['review_status'] === 'spam') {
            try {
                QuoteRequest::create([
                    'ref_number'    => $refNumber,
                    'full_name'     => $fullName,
                    'company_name'  => $companyName,
                    'email'         => $data['email'],
                    'country'       => $data['country'],
                    'tyre_category' => $interest ? strtolower($interest) : 'mixed',
                    'quantity'      => $volumeLabel ?? 'unspecified',
                    'delivery_location' => $data['country'],
                    'notes'         => $notes,
                    'status'        => 'new',
                    'review_status' => 'spam',
                    'quality_score' => $quality['quality_score'],
                    'quality_flags' => $quality['quality_flags'],
                    'lead_source'   => 'tyre_wholesaler_landing',
                    'lead_metadata' => $leadMetadata,
                    'ip_address'    => $request->ip(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('[wholesaler_lead_spam_save_failed] Could not persist spam landing lead', [
                    'error' => $e->getMessage(),
                    'flags' => $quality['quality_flags'],
                ]);
            }

            return response()->json([
                'message' => 'Please provide a clear business inquiry including your tyre interest, estimated volume, and destination country.',
                'code'    => 'low_quality_inquiry',
                'flags'   => $quality['quality_flags'],
            ], 422);
        }

        $quote = QuoteRequest::create([
            'ref_number'           => $refNumber,
            'full_name'            => $fullName,
            'company_name'         => $companyName,
            'email'                => $data['email'],
            'phone'                => $data['phone'] ?? null,
            'country'              => $data['country'],
            'business_type'        => 'wholesale',
            'tyre_category'        => $interest ? strtolower($interest) : 'mixed',
            'quantity'             => $volumeLabel ?? 'unspecified',
            'delivery_location'    => $data['country'],
            'notes'                => $notes,
            'status'               => 'new',
            'review_status'        => $quality['review_status'],
            'quality_score'        => $quality['quality_score'],
            'quality_flags'        => $quality['quality_flags'],
            'qualification_status' => $quality['review_status'],
            'lead_source'          => 'tyre_wholesaler_landing',
            'lead_metadata'        => $leadMetadata,
            'ip_address'           => $request->ip(),
        ]);

        // Link to an existing customer by email (CRM-5 parity with store()).
        $existingCustomer = Customer::whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($quote->email))])->first();
        if ($existingCustomer) {
            $quote->update(['possible_customer_id' => $existingCustomer->id]);
        }

        $this->dispatchInquirySideEffects($quote, $quality);

        $isNeedsReview = $quality['review_status'] === 'needs_review';

        return response()->json([
            'data' => [
                'ref_number'    => $refNumber,
                'message'       => $isNeedsReview
                    ? 'Thank you — your wholesale inquiry has been received and will be reviewed by our team shortly.'
                    : 'Thank you — your wholesale inquiry has been received. Our team will respond within 1 business day.',
                'review_status' => $quality['review_status'],
            ],
        ], 201);
    }

    /**
     * Fold a submission's conversion-attribution + landing extras into a single
     * lead_metadata bag. Accepts both a nested `metadata` object and flat
     * top-level keys (flat wins). Returns [] when nothing meaningful is present.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function buildLeadMetadata(array $validated): array
    {
        $nested = (isset($validated['metadata']) && is_array($validated['metadata']))
            ? $validated['metadata']
            : [];

        $flat = Arr::only($validated, [
            'landing_page', 'primary_tyre_interest', 'estimated_monthly_volume',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'gclid', 'fbclid', 'referrer',
        ]);

        $merged = array_merge($nested, $flat);

        return array_filter($merged, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Fire post-creation side effects shared by the standard quote form and the
     * landing-page lead intake: CRM-3B "needs review" notification, the admin
     * notification email, and the customer acknowledgement email. Failures are
     * logged but never bubble up — the lead is already safely persisted.
     */
    private function dispatchInquirySideEffects(QuoteRequest $quote, array $quality): void
    {
        $refNumber     = $quote->ref_number;
        $quoteEmail    = config('mail.quote_email');
        $isNeedsReview = $quality['review_status'] === 'needs_review';

        // CRM-3B — in-app alert for inquiries CRM-2 flagged as needing review.
        if ($isNeedsReview) {
            AdminNotificationService::notifyPermission(
                permission:  'quotes.manage',
                type:        'quote_needs_review',
                title:       'Inquiry needs review',
                body:        sprintf(
                    'Inquiry %s from %s was flagged for manual review.',
                    $quote->ref_number,
                    $quote->company_name ?: $quote->full_name
                ),
                actionUrl:   "/admin/quotes/{$quote->id}",
                severity:    'warning',
                relatedType: 'quote_request',
                relatedId:   $quote->id,
                metadata:    ['ref_number' => $quote->ref_number, 'quality_score' => $quality['quality_score']],
            );
        }

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

            // In-app twin for the sender if they have a customer account.
            CustomerNotifier::notifyByEmail(
                $quote->email,
                'quote_received',
                'We received your quote request',
                'Thanks — your request has been received. Our team will get back to you shortly.',
                [
                    'severity'     => 'info',
                    'action_url'   => $quote->ref_number ? "/account/quotes/{$quote->ref_number}" : '/account/quotes',
                    'related_type' => 'quote_request',
                    'related_id'   => $quote->ref_number,
                    'email_sent'   => true,
                    'metadata'     => ['quote_ref' => $quote->ref_number],
                ]
            );
        } catch (\Throwable $e) {
            Log::error('[quote_ack_failed] Customer acknowledgement failed', [
                'event' => 'quote_ack_failed',
                'ref'   => $refNumber,
                'error' => $e->getMessage(),
            ]);
        }
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

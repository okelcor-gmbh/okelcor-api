<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvertQuoteToOrderRequest;
use App\Mail\QuoteConvertedToOrder;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLog;
use App\Models\QuoteRequest;
use App\Services\AdminNotificationService;
use App\Services\CustomerTimelineService;
use App\Services\PromoCodeService;
use App\Services\SecurityEventService;
use App\Services\StripeService;
use App\Services\TaxService;
use App\Services\TradeDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminQuoteRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = QuoteRequest::orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('review_status')) {
            $query->where('review_status', $request->review_status);
        }

        // CRM-3 pipeline filters
        if ($request->filled('qualification_status')) {
            $query->where('qualification_status', $request->qualification_status);
        }

        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->integer('assigned_to'));
        }

        if ($request->boolean('unassigned')) {
            $query->whereNull('assigned_to')
                  ->whereNotIn('qualification_status', ['spam', 'rejected', 'converted', 'closed']);
        }

        if ($request->filled('lead_priority')) {
            $query->where('lead_priority', $request->lead_priority);
        }

        if ($request->filled('lead_customer_type')) {
            $query->where('lead_customer_type', $request->lead_customer_type);
        }

        if ($request->filled('lead_source')) {
            $query->where('lead_source', $request->lead_source);
        }

        if ($request->boolean('follow_up_due')) {
            $query->whereNotNull('follow_up_at')
                  ->where('follow_up_at', '<=', now())
                  ->whereNotIn('qualification_status', ['converted', 'closed', 'spam', 'rejected']);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->integer('customer_id'));
        }

        // CRM-7 proposal filters
        if ($request->filled('proposal_status')) {
            $query->where('proposal_status', $request->proposal_status);
        }

        if ($request->boolean('proposals_pending_conversion')) {
            $query->where('proposal_status', 'accepted')->whereNull('order_id');
        }

        if ($request->filled('customer_email')) {
            $query->where('email', $request->customer_email);
        }

        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($sub) use ($q) {
                $sub->where('ref_number', 'like', "%{$q}%")
                    ->orWhere('full_name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('company_name', 'like', "%{$q}%");
            });
        }

        $perPage   = min((int) $request->input('per_page', 25), 100);
        $paginated = $query->withCount('items')->paginate($perPage);

        return response()->json([
            'data'    => $paginated->map(fn ($r) => $this->formatList($r))->values(),
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
            'message' => 'success',
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $quote = QuoteRequest::with('order', 'items')->findOrFail($id);

        return response()->json([
            'data'    => $this->formatDetail($quote),
            'message' => 'success',
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status' => ['required', Rule::in(['new', 'reviewed', 'quoted', 'closed'])],
        ]);

        $quote = QuoteRequest::findOrFail($id);
        $quote->update(['status' => $request->status]);

        return response()->json([
            'data'    => $this->formatDetail($quote->fresh()->load('order')),
            'message' => 'Quote request updated.',
        ]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status' => ['required', Rule::in(['new', 'reviewed', 'quoted', 'closed'])],
        ]);

        $quote = QuoteRequest::findOrFail($id);
        $quote->update(['status' => $request->status]);

        return response()->json([
            'data'    => $this->formatDetail($quote->fresh()->load('order')),
            'message' => 'Status updated successfully.',
            'meta'    => (object) [],
        ]);
    }

    public function convertToOrder(int $id, ConvertQuoteToOrderRequest $request): JsonResponse
    {
        $quote = QuoteRequest::findOrFail($id);

        // Guard: only convert quotes that have been formally quoted
        if ($quote->status !== 'quoted') {
            return response()->json([
                'message' => 'Only quotes with status "quoted" can be converted to an order.',
            ], 422);
        }

        // CRM-7 Guard: proposal must be accepted (unless pre-CRM-7 quote or super_admin override)
        $proposalStatus = $quote->proposal_status ?? 'none';
        $isSuperAdmin   = $request->user()?->role === 'super_admin';

        if ($proposalStatus !== 'none' && $proposalStatus !== 'accepted') {
            if (! $isSuperAdmin) {
                return response()->json([
                    'message'          => 'Customer must accept the proposal before this lead can become an order.',
                    'code'             => 'proposal_not_accepted',
                    'proposal_status'  => $proposalStatus,
                    'proposal_number'  => $quote->proposal_number,
                ], 409);
            }

            // super_admin override — log it and continue
            Log::warning('Super admin converting quote to order without proposal acceptance', [
                'quote_ref'       => $quote->ref_number,
                'proposal_status' => $proposalStatus,
                'by_admin'        => $request->user()?->id,
            ]);
        }

        // Guard: prevent duplicate conversion
        if ($quote->order_id !== null) {
            return response()->json([
                'message' => 'This quote has already been converted to an order.',
                'data'    => ['order_id' => $quote->order_id],
            ], 409);
        }

        $validated     = $request->validated();
        $delivery      = $validated['delivery'] ?? [];
        $items         = $validated['items'];
        $deliveryCost  = (float) ($validated['delivery_cost'] ?? 0);
        $paymentMethod = $validated['payment_method'] ?? 'bank_transfer';

        // Calculate tax before transaction — all inputs are known at this point
        $country      = $delivery['country'] ?? $quote->country;
        $vatValid     = $quote->vat_valid !== null ? (bool) $quote->vat_valid : null;
        $customerType = $this->inferCustomerType($quote);
        $tax          = app(TaxService::class)->calculate($country, $vatValid, $customerType);

        // Optional promo code — apply same rules as Stripe cart checkout
        $promoCode      = isset($validated['promo_code']) ? strtoupper(trim((string) $validated['promo_code'])) : null;
        $discountAmount = 0.0;
        $discountLabel  = null;

        if ($promoCode) {
            $promotion = app(PromoCodeService::class)->resolve($promoCode);

            if (! $promotion) {
                return response()->json(['message' => 'Invalid or expired promo code.'], 422);
            }

            if ($customerType === 'b2b' && $promotion->customer_type_target === 'b2c') {
                return response()->json([
                    'message' => 'This promotion is only available for personal/B2C customers.',
                ], 422);
            }

            // Map quote items to the shape PromoCodeService expects
            $promoItems = array_map(fn ($i) => [
                'brand'      => $i['brand'],
                'unit_price' => (float) $i['unit_price'],
                'quantity'   => (int) $i['quantity'],
            ], $items);

            $discountAmount = app(PromoCodeService::class)->calculateDiscount($promotion, $promoItems);

            if ($discountAmount <= 0) {
                return response()->json([
                    'message' => 'No items in this order are eligible for promo code ' . $promoCode . '.',
                ], 422);
            }

            $discountLabel = app(PromoCodeService::class)->label($promotion);
        }

        $order = DB::transaction(function () use (
            $quote, $delivery, $items, $deliveryCost, $paymentMethod,
            $validated, $request, $tax,
            $promoCode, $discountAmount, $discountLabel
        ) {
            $subtotal = 0.0;

            $ref = $this->generateRef();

            $order = Order::create([
                'ref'            => $ref,
                'customer_name'  => $quote->full_name,
                'customer_email' => $quote->email,
                'customer_phone' => $delivery['phone'] ?? $quote->phone,
                'address'        => $delivery['address'] ?? $quote->delivery_address,
                'city'           => $delivery['city'] ?? $quote->delivery_city,
                'postal_code'    => $delivery['postal_code'] ?? $quote->delivery_postal_code,
                'country'        => $delivery['country'] ?? $quote->country,
                'payment_method' => $paymentMethod,
                'subtotal'       => 0,  // updated below after items
                'delivery_cost'  => $deliveryCost,
                'discount_amount' => $discountAmount,
                'discount_label' => $discountLabel,
                'promo_code'     => $promoCode,
                'total'          => 0,  // updated below
                'status'         => $paymentMethod === 'bank_transfer' ? 'awaiting_proforma' : 'confirmed',
                'payment_status' => 'pending',
                'mode'           => 'manual',
                'vat_number'     => $quote->vat_number,
                'vat_valid'      => $quote->vat_valid,
                'admin_notes'    => $validated['admin_notes']
                    ?? "Converted from quote {$quote->ref_number}.",
            ]);

            foreach ($items as $item) {
                $lineTotal = (float) $item['unit_price'] * (int) $item['quantity'];
                $subtotal += $lineTotal;

                OrderItem::create([
                    'order_id'   => $order->id,
                    'product_id' => null,
                    'sku'        => $item['sku'] ?? null,
                    'brand'      => $item['brand'],
                    'name'       => $item['name'],
                    'size'       => $item['size'],
                    'unit_price' => (float) $item['unit_price'],
                    'quantity'   => (int) $item['quantity'],
                    'line_total' => $lineTotal,
                ]);
            }

            $taxableBase = $subtotal - $discountAmount + $deliveryCost;
            $taxAmount   = round($taxableBase * $tax['tax_rate'] / 100, 2);
            $total       = $taxableBase + $taxAmount;

            $order->update([
                'subtotal'          => $subtotal,
                'total'             => $total,
                'tax_treatment'     => $tax['tax_treatment'],
                'tax_rate'          => $tax['tax_rate'],
                'tax_amount'        => $taxAmount,
                'is_reverse_charge' => $tax['is_reverse_charge'],
            ]);

            // Link quote to order and mark proposal as converted (CRM-7)
            $quote->update([
                'order_id'        => $order->id,
                'proposal_status' => $quote->proposal_status && $quote->proposal_status !== 'none'
                    ? 'converted'
                    : $quote->proposal_status,
            ]);

            // Audit log
            $this->writeConversionLog($request, $order, $quote->ref_number);

            return $order;
        });

        // Load items once — used by both Stripe session and email
        $order->load('items');

        // Auto-generate order confirmation (AB) for all new orders — proforma must not be issued before customer accepts
        try {
            app(TradeDocumentService::class)->generateOrderConfirmationForOrder($order, $request->user());
        } catch (\Throwable $e) {
            Log::warning('Order confirmation auto-generation failed after quote conversion', [
                'order_ref' => $order->ref,
                'error'     => $e->getMessage(),
            ]);
        }

        // If payment is Stripe, create a Checkout Session for this order
        $checkoutUrl = null;

        if ($paymentMethod === 'stripe') {
            try {
                $stripeResult = app(StripeService::class)->createCheckoutSessionForOrder($order);
                $order->update(['payment_session_id' => $stripeResult['checkout_session_id']]);
                $checkoutUrl = $stripeResult['checkout_url'];

                Log::info('Stripe session created for quote-converted order', [
                    'order_ref'           => $order->ref,
                    'quote_ref'           => $quote->ref_number,
                    'checkout_session_id' => $stripeResult['checkout_session_id'],
                ]);
            } catch (\Throwable $e) {
                Log::error('Stripe session creation failed for quote-converted order', [
                    'order_ref' => $order->ref,
                    'quote_ref' => $quote->ref_number,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        Log::info('Quote converted email sending', [
            'quote_ref'   => $quote->ref_number,
            'order_ref'   => $order->ref,
            'to'          => $quote->email,
            'has_stripe'  => $checkoutUrl !== null,
        ]);

        try {
            Mail::to($quote->email)->send(new QuoteConvertedToOrder($order, $quote, $checkoutUrl));
            Log::info('Quote converted email sent', [
                'quote_ref' => $quote->ref_number,
                'order_ref' => $order->ref,
                'to'        => $quote->email,
            ]);
        } catch (\Throwable $e) {
            Log::error('Quote converted email failed', [
                'quote_ref' => $quote->ref_number,
                'order_ref' => $order->ref,
                'to'        => $quote->email,
                'error'     => $e->getMessage(),
            ]);
        }

        return response()->json([
            'data' => [
                'order_ref'      => $order->ref,
                'quote_ref'      => $quote->ref_number,
                'status'         => $order->status,
                'payment_status' => $order->payment_status,
                'total'          => (float) $order->total,
                'checkout_url'   => $checkoutUrl,
            ],
            'message' => 'Quote converted to order successfully.',
        ], 201);
    }

    // ── GET /admin/quote-requests/summary ────────────────────────────────────

    public function summary(): JsonResponse
    {
        $base  = DB::table('quote_requests');
        $now   = now();
        $active = ['spam', 'rejected', 'converted', 'closed'];

        return response()->json([
            'data' => [
                'new_count'                    => (clone $base)->where('qualification_status', 'new')->count(),
                'needs_review_count'           => (clone $base)->where('qualification_status', 'needs_review')->count(),
                'qualified_count'              => (clone $base)->where('qualification_status', 'qualified')->count(),
                'proposal_sent_count'          => (clone $base)->where('qualification_status', 'proposal_sent')->count(),
                'converted_count'              => (clone $base)->where('qualification_status', 'converted')->count(),
                'spam_count'                   => (clone $base)->where('qualification_status', 'spam')->count(),
                'follow_up_due_count'          => (clone $base)
                    ->whereNotNull('follow_up_at')
                    ->where('follow_up_at', '<=', $now)
                    ->whereNotIn('qualification_status', $active)
                    ->count(),
                'unassigned_count'             => (clone $base)
                    ->whereNull('assigned_to')
                    ->whereNotIn('qualification_status', $active)
                    ->count(),
                'high_priority_count'          => (clone $base)
                    ->whereIn('lead_priority', ['high', 'urgent'])
                    ->whereNotIn('qualification_status', $active)
                    ->count(),
                // CRM-7 proposal counts
                'proposals_draft_count'        => (clone $base)->where('proposal_status', 'draft')->count(),
                'proposals_ready_count'        => (clone $base)->where('proposal_status', 'ready')->count(),
                'proposals_sent_count'         => (clone $base)->where('proposal_status', 'sent')->count(),
                'proposals_accepted_count'     => (clone $base)->where('proposal_status', 'accepted')->count(),
                'proposals_rejected_count'     => (clone $base)->where('proposal_status', 'rejected')->count(),
                'proposals_expired_count'      => (clone $base)->where('proposal_status', 'expired')->count(),
                'proposals_pending_conversion' => (clone $base)
                    ->where('proposal_status', 'accepted')
                    ->whereNull('order_id')
                    ->count(),
            ],
            'message' => 'success',
        ]);
    }

    // ── POST /admin/quote-requests/{id}/assign ────────────────────────────────

    public function assign(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'assigned_to'  => ['required', 'integer', 'exists:admin_users,id'],
            'follow_up_at' => ['nullable', 'date'],
        ]);

        $quote = QuoteRequest::findOrFail($id);
        $previousAssignee = $quote->assigned_to;

        $quote->update([
            'assigned_to'  => $data['assigned_to'],
            'assigned_at'  => now(),
            'follow_up_at' => $data['follow_up_at'] ?? $quote->follow_up_at,
        ]);

        Log::info('[lead_assigned] Lead assigned to admin', [
            'event'      => 'lead_assigned',
            'quote_ref'  => $quote->ref_number,
            'assigned_to' => $data['assigned_to'],
            'by_admin'   => $request->user()?->id,
        ]);

        if ($data['assigned_to'] !== $previousAssignee) {
            AdminNotificationService::notify(
                $data['assigned_to'],
                'lead_assigned',
                'New lead assigned to you',
                sprintf('Quote %s from %s', $quote->ref_number, $quote->company_name ?: $quote->full_name),
                "/admin/quotes/{$quote->id}"
            );
        }

        return response()->json([
            'success' => true,
            'data'    => $this->formatDetail($quote->fresh()->load('order')),
            'message' => 'Lead assigned.',
        ]);
    }

    // ── POST /admin/quote-requests/{id}/qualification ─────────────────────────

    public function updateQualification(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'qualification_status' => ['nullable', Rule::in([
                'new', 'needs_review', 'qualified', 'proposal_sent',
                'customer_invited', 'converted', 'rejected', 'spam', 'closed',
            ])],
            'lead_priority'        => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'lead_customer_type'   => ['nullable', Rule::in([
                'private_buyer', 'dealer', 'workshop', 'fleet', 'exporter', 'unknown',
            ])],
            'follow_up_at'         => ['nullable', 'date'],
            'qualification_reason' => ['nullable', 'string', 'max:1000'],
            'internal_notes'       => ['nullable', 'string', 'max:5000'],
        ]);

        $quote  = QuoteRequest::findOrFail($id);
        $update = array_filter($data, fn ($v) => $v !== null);

        $quote->update($update);

        Log::info('[lead_qualification_updated] Lead qualification updated', [
            'event'      => 'lead_qualification_updated',
            'quote_ref'  => $quote->ref_number,
            'changes'    => array_keys($update),
            'by_admin'   => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->formatDetail($quote->fresh()->load('order')),
            'message' => 'Lead qualification updated.',
        ]);
    }

    // ── POST /admin/quote-requests/{id}/notes ─────────────────────────────────

    public function updateNotes(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'internal_notes' => ['required', 'string', 'max:5000'],
        ]);

        $quote = QuoteRequest::findOrFail($id);
        $quote->update(['internal_notes' => $data['internal_notes']]);

        Log::info('[lead_note_updated] Lead internal notes updated', [
            'event'     => 'lead_note_updated',
            'quote_ref' => $quote->ref_number,
            'by_admin'  => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Notes updated.',
        ]);
    }

    // ── POST /admin/quote-requests/{id}/convert-to-customer ──────────────────

    public function convertToCustomer(Request $request, int $id): JsonResponse
    {
        $quote = QuoteRequest::findOrFail($id);

        if ($quote->qualification_status === 'converted') {
            // Resolve the linked customer for the frontend to display/navigate
            $linked = $quote->customer_id
                ? Customer::find($quote->customer_id)
                : Customer::where('email', $quote->email)->first();

            return response()->json([
                'code'            => 'already_converted',
                'message'         => 'This lead has already been converted to a customer.',
                'customer_id'     => $linked?->id ?? $quote->customer_id,
                'customer_exists' => $linked !== null,
                'customer'        => $linked ? $this->formatCustomerSummary($linked) : null,
            ], 409);
        }

        $existing = Customer::where('email', $quote->email)->first();

        if ($existing) {
            // Link quote to existing customer
            $quote->update([
                'customer_id'          => $existing->id,
                'qualification_status' => 'converted',
            ]);

            SecurityEventService::log(
                'lead_converted_to_customer', $existing->id,
                $request->ip(), $request->userAgent(),
                "Lead {$quote->ref_number} linked to existing customer by admin", 'info'
            );

            Log::info('[lead_converted_to_customer] Lead linked to existing customer', [
                'event'       => 'lead_converted_to_customer',
                'quote_ref'   => $quote->ref_number,
                'customer_id' => $existing->id,
                'action'      => 'linked',
                'by_admin'    => $request->user()?->id,
            ]);

            // CRM-8 timeline
            CustomerTimelineService::record(
                $existing->id, 'lead_converted', 'Lead linked to customer',
                "Lead {$quote->ref_number} linked to this existing customer.",
                ['quote_ref' => $quote->ref_number, 'quote_request_id' => $quote->id],
                $request->user()?->id
            );

            return response()->json([
                'success'     => true,
                'action'      => 'linked',
                'customer_id' => $existing->id,
                'customer'    => $this->formatCustomerSummary($existing),
                'message'     => 'Lead linked to existing customer account.',
            ]);
        }

        // Create new customer from quote data (pending_review — admin must invite separately)
        [$firstName, $lastName] = $this->splitFullName($quote->full_name);

        // Map lead_customer_type → customer_segment
        $segmentMap = [
            'private_buyer' => 'private_buyer',
            'dealer'        => 'dealer',
            'workshop'      => 'workshop',
            'fleet'         => 'fleet',
            'exporter'      => 'exporter',
        ];
        $segment = $segmentMap[$quote->lead_customer_type ?? 'unknown'] ?? 'unknown';

        $customer = Customer::create([
            'first_name'          => $firstName,
            'last_name'           => $lastName,
            'email'               => $quote->email,
            'password'            => Hash::make(Str::random(32)),
            'phone'               => $quote->phone,
            'country'             => $quote->country,
            'company_name'        => $quote->company_name,
            'vat_number'          => $quote->vat_number,
            'customer_type'       => $quote->company_name ? 'b2b' : 'b2c',
            'onboarding_status'   => 'pending_review',
            'is_active'           => false,
            'must_reset_password' => true,
            // CRM-4: default access — inquiry_only until admin explicitly upgrades
            'customer_segment'    => $segment,
            'access_level'        => 'inquiry_only',
            'approved_for_quotes' => true,
        ]);

        $quote->update([
            'customer_id'          => $customer->id,
            'qualification_status' => 'converted',
        ]);

        SecurityEventService::log(
            'lead_converted_to_customer', $customer->id,
            $request->ip(), $request->userAgent(),
            "Customer created from lead {$quote->ref_number} by admin — pending_review", 'info'
        );

        Log::info('[lead_converted_to_customer] New customer created from lead', [
            'event'       => 'lead_converted_to_customer',
            'quote_ref'   => $quote->ref_number,
            'customer_id' => $customer->id,
            'action'      => 'created',
            'by_admin'    => $request->user()?->id,
        ]);

        // CRM-8 timeline — new buyer enters the lifecycle pending approval.
        CustomerTimelineService::record(
            $customer->id, 'customer_created', 'Customer created',
            "Customer account created from lead {$quote->ref_number} (pending review).",
            ['quote_ref' => $quote->ref_number, 'source' => 'lead_conversion'],
            $request->user()?->id
        );
        CustomerTimelineService::record(
            $customer->id, 'lead_converted', 'Lead converted to customer',
            "Lead {$quote->ref_number} converted to a customer account.",
            ['quote_ref' => $quote->ref_number, 'quote_request_id' => $quote->id],
            $request->user()?->id
        );

        return response()->json([
            'success'     => true,
            'action'      => 'created',
            'customer_id' => $customer->id,
            'customer'    => $this->formatCustomerSummary($customer),
            'message'     => 'Customer account created (pending_review). Use the invite action to send an activation email.',
        ], 201);
    }

    // ── POST /admin/quote-requests/{id}/qualify ──────────────────────────────

    public function qualify(Request $request, int $id): JsonResponse
    {
        $quote = QuoteRequest::findOrFail($id);

        $quote->update([
            'review_status'        => 'qualified',
            'qualification_status' => 'qualified',
            'reviewed_by'          => $request->user()?->id,
            'reviewed_at'          => now(),
            'rejection_reason'     => null,
        ]);

        Log::info('[quote_review_qualify] Admin qualified inquiry', [
            'quote_ref' => $quote->ref_number,
            'admin_id'  => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->formatDetail($quote->fresh()->load('order')),
            'message' => 'Inquiry marked as qualified.',
        ]);
    }

    // ── POST /admin/quote-requests/{id}/reject ───────────────────────────────

    public function rejectInquiry(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $quote = QuoteRequest::findOrFail($id);

        $quote->update([
            'review_status'        => 'rejected',
            'qualification_status' => 'rejected',
            'reviewed_by'          => $request->user()?->id,
            'reviewed_at'          => now(),
            'rejection_reason'     => $data['reason'] ?? null,
        ]);

        Log::info('[quote_review_reject] Admin rejected inquiry', [
            'quote_ref' => $quote->ref_number,
            'admin_id'  => $request->user()?->id,
            'reason'    => $data['reason'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->formatDetail($quote->fresh()->load('order')),
            'message' => 'Inquiry marked as rejected.',
        ]);
    }

    // ── POST /admin/quote-requests/{id}/spam ─────────────────────────────────

    public function markSpam(Request $request, int $id): JsonResponse
    {
        $quote = QuoteRequest::findOrFail($id);

        $quote->update([
            'review_status'        => 'spam',
            'qualification_status' => 'spam',
            'reviewed_by'          => $request->user()?->id,
            'reviewed_at'          => now(),
        ]);

        Log::info('[quote_review_spam] Admin marked inquiry as spam', [
            'quote_ref' => $quote->ref_number,
            'admin_id'  => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->formatDetail($quote->fresh()->load('order')),
            'message' => 'Inquiry marked as spam.',
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function inferCustomerType(QuoteRequest $quote): ?string
    {
        // Company name on the quote is the strongest signal for B2B
        if ($quote->company_name) {
            return 'b2b';
        }

        // Fall back to the linked customer account's type if one exists
        if ($quote->customer_id) {
            $quote->loadMissing('customer');
            return $quote->customer?->customer_type;
        }

        return null;
    }

    private function generateRef(): string
    {
        $timestamp = strtoupper(base_convert(substr((string) now()->timestamp, -5), 10, 36));
        $rand      = strtoupper(Str::random(3));

        return "OKL-{$timestamp}{$rand}";
    }

    private function writeConversionLog(Request $request, Order $order, string $quoteRef): void
    {
        try {
            $admin = $request->user();

            OrderLog::create([
                'order_id'         => $order->id,
                'order_ref'        => $order->ref,
                'admin_user_id'    => $admin?->id,
                'admin_user_email' => $admin?->email,
                'action'           => 'status_changed',
                'old_value'        => null,
                'new_value'        => 'confirmed',
                'notes'            => "Created from quote {$quoteRef}.",
                'ip_address'       => $request->ip(),
                'created_at'       => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('OrderLog write failed on quote conversion', [
                'order_ref'  => $order->ref,
                'quote_ref'  => $quoteRef,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Formatters
    // -------------------------------------------------------------------------

    private function formatList(QuoteRequest $r): array
    {
        return [
            'id'                       => $r->id,
            'ref_number'               => $r->ref_number,
            'full_name'                => $r->full_name,
            'contact_person'           => $r->contact_person,
            'company_name'             => $r->company_name,
            'email'                    => $r->email,
            'phone'                    => $r->phone,
            'tyre_category'            => $r->tyre_category,
            'tyre_condition'           => $r->tyre_condition,
            'country'                  => $r->country,
            'quantity'                 => $r->quantity,
            'tyre_items'               => $r->tyre_items,
            'incoterm'                 => $r->incoterm,
            'delivery_address'         => $r->delivery_address,
            'delivery_city'            => $r->delivery_city,
            'delivery_postal_code'     => $r->delivery_postal_code,
            'status'                   => $r->status,
            // Quality (CRM-2)
            'review_status'            => $r->review_status ?? 'new',
            'quality_score'            => $r->quality_score,
            'quality_flags'            => $r->quality_flags ?? [],
            'reviewed_at'              => $r->reviewed_at?->toIso8601String(),
            'rejection_reason'         => $r->rejection_reason,
            // Pipeline (CRM-3)
            'qualification_status'     => $r->qualification_status ?? 'new',
            'lead_priority'            => $r->lead_priority ?? 'normal',
            'lead_source'              => $r->lead_source,
            'lead_customer_type'       => $r->lead_customer_type ?? 'unknown',
            'assigned_to'              => $r->assigned_to,
            'assigned_at'              => $r->assigned_at?->toIso8601String(),
            'follow_up_at'             => $r->follow_up_at?->toIso8601String(),
            'follow_up_overdue'        => $r->follow_up_at && $r->follow_up_at->isPast()
                                          && ! in_array($r->qualification_status ?? 'new', ['converted', 'closed', 'spam', 'rejected'], true),
            'created_at'               => $r->created_at?->toIso8601String(),
            'order_id'                 => $r->order_id,
            'has_attachment'           => (bool) $r->attachment_path,
            'attachment_url'           => $r->attachment_path ? url('/api/v1/admin/quote-attachments/' . $r->id . '/download') : null,
            'attachment_name'          => $r->attachment_original_name,
            'attachment_original_name' => $r->attachment_original_name,
            'attachment_size'          => $r->attachment_size,
            'attachment_mime'          => $r->attachment_mime,
            // Quote items (CRM-7 Fix 2)
            'quote_items_count'        => $r->items_count ?? $r->items()->count(),
            // Proposal (CRM-7)
            'proposal_status'          => $r->proposal_status ?? 'none',
            'proposal_number'          => $r->proposal_number,
            'proposal_total'           => $r->proposal_total ? (float) $r->proposal_total : null,
            'proposal_currency'        => $r->proposal_currency ?? 'EUR',
            'proposal_sent_at'         => $r->proposal_sent_at?->toIso8601String(),
            'proposal_accepted_at'     => $r->proposal_accepted_at?->toIso8601String(),
            'proposal_rejected_at'     => $r->proposal_rejected_at?->toIso8601String(),
            'proposal_expires_at'      => $r->proposal_expires_at?->toIso8601String(),
        ];
    }

    private function formatDetail(QuoteRequest $r): array
    {
        $r->loadMissing('order');

        return [
            // Identity
            'id'                   => $r->id,
            'ref_number'           => $r->ref_number,
            'status'               => $r->status,
            'created_at'           => $r->created_at?->toIso8601String(),
            'updated_at'           => $r->updated_at?->toIso8601String(),

            // Contact
            'full_name'            => $r->full_name,
            'contact_person'       => $r->contact_person,
            'company_name'         => $r->company_name,
            'company_address'      => $r->company_address,
            'company_city'         => $r->company_city,
            'company_postal_code'  => $r->company_postal_code,
            'email'                => $r->email,
            'phone'                => $r->phone,
            'country'              => $r->country,
            'business_type'        => $r->business_type,
            'vat_number'           => $r->vat_number,
            'vat_valid'            => $r->vat_valid !== null ? (bool) $r->vat_valid : null,

            // Product request
            'tyre_category'        => $r->tyre_category,
            'brand_preference'     => $r->brand_preference,
            'tyre_size'            => $r->tyre_size,       // legacy
            'quantity'             => $r->quantity,         // legacy
            'tyre_condition'       => $r->tyre_condition,
            'used_tyre_grade'      => $r->used_tyre_grade,
            'used_tyre_notes'      => $r->used_tyre_notes,
            'tyre_items'           => $r->tyre_items,

            // Delivery & logistics
            'budget_range'         => $r->budget_range,
            'delivery_location'    => $r->delivery_location,
            'delivery_timeline'    => $r->delivery_timeline,
            'delivery_address'     => $r->delivery_address,
            'delivery_city'        => $r->delivery_city,
            'delivery_postal_code' => $r->delivery_postal_code,
            'incoterm'             => $r->incoterm,
            'incoterm_type'        => $r->incoterm_type,

            // Notes
            'notes'                => $r->notes,
            'admin_notes'          => $r->admin_notes,

            // Quality (CRM-2)
            'review_status'        => $r->review_status ?? 'new',
            'quality_score'        => $r->quality_score,
            'quality_flags'        => $r->quality_flags ?? [],
            'reviewed_by'          => $r->reviewed_by,
            'reviewed_at'          => $r->reviewed_at?->toIso8601String(),
            'rejection_reason'     => $r->rejection_reason,
            // Pipeline (CRM-3)
            'qualification_status' => $r->qualification_status ?? 'new',
            'qualification_reason' => $r->qualification_reason,
            'lead_priority'        => $r->lead_priority ?? 'normal',
            'lead_source'          => $r->lead_source,
            'lead_customer_type'   => $r->lead_customer_type ?? 'unknown',
            'assigned_to'          => $r->assigned_to,
            'assigned_at'          => $r->assigned_at?->toIso8601String(),
            'follow_up_at'         => $r->follow_up_at?->toIso8601String(),
            'follow_up_overdue'    => $r->follow_up_at && $r->follow_up_at->isPast()
                                      && ! in_array($r->qualification_status ?? 'new', ['converted', 'closed', 'spam', 'rejected'], true),
            'internal_notes'       => $r->internal_notes,

            // CRM-5: possible existing customer match (guest submissions)
            'possible_customer_id' => $r->possible_customer_id,

            // Linked order
            'order_id'             => $r->order_id,
            'order_ref'            => $r->order?->ref,

            // Attachment
            'has_attachment'           => (bool) $r->attachment_path,
            'attachment_url'           => $r->attachment_path ? url('/api/v1/admin/quote-attachments/' . $r->id . '/download') : null,
            'attachment_name'          => $r->attachment_original_name,
            'attachment_original_name' => $r->attachment_original_name,
            'attachment_size'          => $r->attachment_size,
            'attachment_mime'          => $r->attachment_mime,

            // Quote items (CRM-7 Fix 2) — admin-curated line items for proposals/orders
            'quote_items'                => $r->relationLoaded('items')
                ? $r->items->map(fn ($i) => $this->formatQuoteItem($i))->values()
                : [],
            'quote_items_count'          => $r->relationLoaded('items')
                ? $r->items->count()
                : ($r->items_count ?? 0),

            // Proposal (CRM-7)
            'proposal_status'            => $r->proposal_status ?? 'none',
            'proposal_number'            => $r->proposal_number,
            'proposal_items'             => $r->proposal_items ?? [],
            'proposal_total'             => $r->proposal_total ? (float) $r->proposal_total : null,
            'proposal_currency'          => $r->proposal_currency ?? 'EUR',
            'proposal_sent_at'           => $r->proposal_sent_at?->toIso8601String(),
            'proposal_accepted_at'       => $r->proposal_accepted_at?->toIso8601String(),
            'proposal_rejected_at'       => $r->proposal_rejected_at?->toIso8601String(),
            'proposal_expires_at'        => $r->proposal_expires_at?->toIso8601String(),
            'proposal_voided_at'         => $r->proposal_voided_at?->toIso8601String(),
            'proposal_voided_by'         => $r->proposal_voided_by,
            'proposal_void_reason'       => $r->proposal_void_reason,
            'proposal_rejection_reason'  => $r->proposal_rejection_reason,
            'proposal_acceptance_note'   => $r->proposal_acceptance_note,
            'has_proposal_pdf'           => (bool) $r->getRawOriginal('proposal_pdf_path'),
            'proposal_expired'           => $r->proposal_expires_at && $r->proposal_expires_at->isPast()
                                            && ($r->proposal_status ?? 'none') === 'sent',
            'proposal_download_url'      => $r->getRawOriginal('proposal_pdf_path')
                                            ? url('/api/v1/admin/quote-requests/' . $r->id . '/proposal/download')
                                            : null,
        ];
    }

    /** @return array{string, string} */
    private function splitFullName(string $fullName): array
    {
        $parts     = explode(' ', trim($fullName), 2);
        $firstName = $parts[0] ?? $fullName;
        $lastName  = $parts[1] ?? '-';
        return [$firstName, $lastName];
    }

    private function formatQuoteItem(\App\Models\QuoteRequestItem $i): array
    {
        return [
            'id'          => $i->id,
            'brand'       => $i->brand,
            'model'       => $i->model,
            'size'        => $i->size,
            'season'      => $i->season,
            'load_index'  => $i->load_index,
            'speed_index' => $i->speed_index,
            'condition'   => $i->condition,
            'quantity'    => $i->quantity,
            'unit_price'  => $i->unit_price !== null ? (float) $i->unit_price : null,
            'line_total'  => $i->line_total,
            'currency'    => $i->currency,
            'notes'       => $i->notes,
            'sort_order'  => $i->sort_order,
        ];
    }

    private function formatCustomerSummary(Customer $c): array
    {
        return [
            'id'                => $c->id,
            'email'             => $c->email,
            'full_name'         => $c->first_name . ' ' . $c->last_name,
            'company_name'      => $c->company_name,
            'onboarding_status' => $c->onboarding_status ?? 'active',
            'is_active'         => (bool) $c->is_active,
        ];
    }
}

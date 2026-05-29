<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvertQuoteToOrderRequest;
use App\Mail\QuoteConvertedToOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLog;
use App\Models\QuoteRequest;
use App\Services\PromoCodeService;
use App\Services\StripeService;
use App\Services\TaxService;
use App\Services\TradeDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->integer('customer_id'));
        }

        if ($request->filled('customer_email')) {
            $query->where('email', $request->customer_email);
        }

        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($sub) use ($q) {
                $sub->where('ref_number', 'like', "%{$q}%")
                    ->orWhere('full_name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        $perPage   = min((int) $request->input('per_page', 25), 100);
        $paginated = $query->paginate($perPage);

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
        $quote = QuoteRequest::with('order')->findOrFail($id);

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

            // Link quote to order
            $quote->update(['order_id' => $order->id]);

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

    // ── POST /admin/quote-requests/{id}/qualify ──────────────────────────────

    public function qualify(Request $request, int $id): JsonResponse
    {
        $quote = QuoteRequest::findOrFail($id);

        $quote->update([
            'review_status' => 'qualified',
            'reviewed_by'   => $request->user()?->id,
            'reviewed_at'   => now(),
            'rejection_reason' => null,
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
            'review_status'    => 'rejected',
            'reviewed_by'      => $request->user()?->id,
            'reviewed_at'      => now(),
            'rejection_reason' => $data['reason'] ?? null,
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
            'review_status' => 'spam',
            'reviewed_by'   => $request->user()?->id,
            'reviewed_at'   => now(),
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
            // Quality / review
            'review_status'            => $r->review_status ?? 'new',
            'quality_score'            => $r->quality_score,
            'quality_flags'            => $r->quality_flags ?? [],
            'reviewed_at'              => $r->reviewed_at?->toIso8601String(),
            'rejection_reason'         => $r->rejection_reason,
            'created_at'               => $r->created_at?->toIso8601String(),
            'order_id'                 => $r->order_id,
            'has_attachment'           => (bool) $r->attachment_path,
            'attachment_url'           => $r->attachment_path ? url('/api/v1/admin/quote-attachments/' . $r->id . '/download') : null,
            'attachment_name'          => $r->attachment_original_name,
            'attachment_original_name' => $r->attachment_original_name,
            'attachment_size'          => $r->attachment_size,
            'attachment_mime'          => $r->attachment_mime,
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

            // Quality / review
            'review_status'        => $r->review_status ?? 'new',
            'quality_score'        => $r->quality_score,
            'quality_flags'        => $r->quality_flags ?? [],
            'reviewed_by'          => $r->reviewed_by,
            'reviewed_at'          => $r->reviewed_at?->toIso8601String(),
            'rejection_reason'     => $r->rejection_reason,

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
        ];
    }
}

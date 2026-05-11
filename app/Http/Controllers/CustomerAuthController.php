<?php

namespace App\Http\Controllers;

use App\Mail\CustomerEmailVerification;
use App\Mail\CustomerPasswordReset;
use App\Models\BlockedEntity;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LoginHistory;
use App\Models\Order;
use App\Models\QuoteRequest;
use App\Services\InvoiceService;
use App\Services\SecurityEventService;
use App\Services\TaxService;
use App\Services\VatValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CustomerAuthController extends Controller
{
    public function __construct(
        private VatValidationService $vatService,
        private TaxService $taxService,
    ) {}

    // -------------------------------------------------------------------------
    // POST /api/v1/auth/register
    // -------------------------------------------------------------------------
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_type' => 'required|in:b2c,b2b',
            'first_name'    => ['required', 'string', 'max:100'],
            'last_name'     => ['required', 'string', 'max:100'],
            'email'         => ['required', 'email', 'max:255', 'unique:customers,email'],
            'password'      => ['required', 'confirmed', Password::min(8)],
            'phone'         => ['nullable', 'string', 'max:50'],
            'country'       => ['nullable', 'string', 'max:100'],
            'company_name'  => [
                $request->input('customer_type') === 'b2b' ? 'required' : 'nullable',
                'string', 'max:200',
            ],
            'vat_number'    => ['nullable', 'string', 'max:20'],
            'industry'      => ['nullable', 'string', 'max:100'],
        ]);

        $vatVerified = false;
        if (! empty($data['vat_number'])) {
            $result      = $this->vatService->validate($data['vat_number']);
            $vatVerified = $result['valid'];
        }

        $customer = Customer::create([
            ...$data,
            'password'     => Hash::make($data['password']),
            'vat_verified' => $vatVerified,
        ]);

        try {
            $this->sendVerificationEmail($customer);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Verification email failed for customer ' . $customer->id . ': ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Registration successful. Please check your email to verify your account.',
        ], 201);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/auth/login
    // -------------------------------------------------------------------------
    public function login(Request $request): JsonResponse
    {
        $data      = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $ip        = $request->ip();
        $userAgent = $request->userAgent();

        // Block banned IPs (requires blocked_entities table — skip if migration pending)
        try {
            if (BlockedEntity::isBlocked('ip', $ip)) {
                return response()->json(['message' => 'Access denied.'], 403);
            }
        } catch (\Throwable) {}

        $customer    = Customer::where('email', $data['email'])->first();
        $validCreds  = $customer && Hash::check($data['password'], $customer->password);

        // Log every attempt (requires login_history table — skip if migration pending)
        if ($customer) {
            try {
                LoginHistory::create([
                    'customer_id' => $customer->id,
                    'success'     => $validCreds,
                    'ip_address'  => $ip,
                    'user_agent'  => $userAgent,
                    'created_at'  => now(),
                ]);
            } catch (\Throwable) {}
        }

        if (! $validCreds) {
            try {
                SecurityEventService::log(
                    'failed_login', $customer?->id, $ip, $userAgent,
                    'Failed login attempt — wrong password', 'warning'
                );

                if ($customer) {
                    $customer->increment('failed_login_count');
                    $count = $customer->fresh()->failed_login_count;

                    // 5 consecutive failures → lock
                    if ($count >= 5 && $customer->status === 'active') {
                        $customer->update(['status' => 'locked', 'is_active' => false]);
                        SecurityEventService::log(
                            'account_lockout', $customer->id, $ip, $userAgent,
                            "Account locked after {$count} consecutive failed login attempts", 'critical'
                        );
                    }

                    // 10+ failures in the past hour → auto-suspend
                    $recentFailed = LoginHistory::where('customer_id', $customer->id)
                        ->where('success', false)
                        ->where('created_at', '>=', now()->subHour())
                        ->count();

                    if ($recentFailed >= 10 && in_array($customer->fresh()->status, ['active', 'locked'])) {
                        $customer->update(['status' => 'suspended', 'is_active' => false]);
                        $customer->tokens()->delete();
                        SecurityEventService::log(
                            'suspicious_activity', $customer->id, $ip, $userAgent,
                            "Account suspended after {$recentFailed} failed logins within 1 hour", 'critical'
                        );
                    }
                }
            } catch (\Throwable) {}

            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        // Status gate (requires status column — falls back to is_active if migration pending)
        try {
            if ($customer->status !== 'active') {
                $message = match ($customer->status) {
                    'banned'    => 'Your account has been banned.',
                    'suspended' => 'Your account has been suspended. Please contact support.',
                    'locked'    => 'Your account is locked due to too many failed attempts. Please contact support.',
                    default     => 'Your account is deactivated.',
                };
                return response()->json(['message' => $message], 403);
            }
        } catch (\Throwable) {
            if (! $customer->is_active) {
                return response()->json(['message' => 'Your account is deactivated.'], 403);
            }
        }

        if (! $customer->email_verified_at) {
            return response()->json([
                'message'        => 'Please verify your email first.',
                'email_verified' => false,
            ], 403);
        }

        if ($customer->must_reset_password) {
            return response()->json([
                'must_reset' => true,
                'message'    => 'Please reset your password to continue.',
            ], 403);
        }

        // Successful login — update tracking, clear failure counter
        try {
            $customer->update([
                'last_login_at'      => now(),
                'last_login_ip'      => $ip,
                'failed_login_count' => 0,
            ]);
        } catch (\Throwable) {}

        $token = $customer->createToken('customer-auth')->plainTextToken;

        return response()->json([
            'data' => [
                'token'    => $token,
                'customer' => $this->formatCustomer($customer),
            ],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/auth/logout  (protected)
    // -------------------------------------------------------------------------
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/auth/record-login  (protected)
    // -------------------------------------------------------------------------
    public function recordLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'last_login_at'       => ['nullable', 'date'],
            'last_login_ip'       => ['nullable', 'string', 'max:45'],
            'last_login_location' => ['nullable', 'string', 'max:100'],
            'user_agent'          => ['nullable', 'string', 'max:500'],
        ]);

        $customer  = $request->user();
        $loginAt   = isset($data['last_login_at']) ? now()->parse($data['last_login_at']) : now();
        $ip        = $data['last_login_ip'] ?? $request->ip();
        $location  = $data['last_login_location'] ?? null;
        $userAgent = $data['user_agent'] ?? $request->userAgent();

        try {
            $customer->update([
                'last_login_at'       => $loginAt,
                'last_login_ip'       => $ip,
                'last_login_location' => $location,
                'failed_login_count'  => 0,
            ]);
        } catch (\Throwable) {}

        try {
            LoginHistory::create([
                'customer_id' => $customer->id,
                'success'     => true,
                'ip_address'  => $ip,
                'user_agent'  => $userAgent,
                'location'    => $location,
                'created_at'  => $loginAt,
            ]);
        } catch (\Throwable) {}

        return response()->json(['message' => 'ok']);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/auth/resend-verification
    // -------------------------------------------------------------------------
    public function resendVerification(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $customer = Customer::where('email', $request->email)->first();

        // Always return success to avoid email enumeration
        if ($customer && ! $customer->email_verified_at) {
            $this->sendVerificationEmail($customer);
        }

        return response()->json(['message' => 'If that account exists and is unverified, a verification email has been sent.']);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/auth/verify-email/{id}/{hash}
    // -------------------------------------------------------------------------
    public function verifyEmail(Request $request, int $id, string $hash): \Illuminate\Http\RedirectResponse
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'https://okelcor.com'), '/');

        if (! $request->hasValidSignature()) {
            return redirect($frontendUrl . '/login?verified=false&error=invalid_link');
        }

        $customer = Customer::find($id);

        if (! $customer || ! hash_equals(sha1($customer->email), $hash)) {
            return redirect($frontendUrl . '/login?verified=false&error=invalid_link');
        }

        if (! $customer->email_verified_at) {
            $customer->update(['email_verified_at' => now()]);
        }

        return redirect($frontendUrl . '/login?verified=true');
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/auth/forgot-password
    // -------------------------------------------------------------------------
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $customer = Customer::where('email', $request->email)->first();

        if ($customer) {
            $token = Str::random(64);

            DB::table('password_reset_tokens')->upsert(
                [
                    'email'      => $customer->email,
                    'token'      => Hash::make($token),
                    'created_at' => now(),
                ],
                ['email'],
                ['token', 'created_at']
            );

            $frontendUrl = rtrim(config('app.frontend_url', 'https://okelcor.com'), '/');
            $resetUrl    = $frontendUrl . '/reset-password?token=' . $token . '&email=' . urlencode($customer->email);

            Mail::to($customer->email)->send(new CustomerPasswordReset($customer, $resetUrl));
        }

        return response()->json(['message' => 'If that email is registered, a password reset link has been sent.']);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/auth/reset-password
    // -------------------------------------------------------------------------
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'                 => ['required', 'string'],
            'email'                 => ['required', 'email'],
            'password'              => ['required', 'confirmed', Password::min(8)],
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $data['email'])
            ->first();

        if (! $record) {
            return response()->json(['message' => 'Invalid or expired reset token.'], 422);
        }

        // Check 60-minute expiry
        if (now()->diffInMinutes($record->created_at) > 60) {
            DB::table('password_reset_tokens')->where('email', $data['email'])->delete();
            return response()->json(['message' => 'Reset token has expired. Please request a new one.'], 422);
        }

        if (! Hash::check($data['token'], $record->token)) {
            return response()->json(['message' => 'Invalid or expired reset token.'], 422);
        }

        $customer = Customer::where('email', $data['email'])->first();

        if (! $customer) {
            return response()->json(['message' => 'Invalid or expired reset token.'], 422);
        }

        $customer->update([
            'password'            => Hash::make($data['password']),
            'must_reset_password' => false,
        ]);

        DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

        SecurityEventService::log(
            'password_reset', $customer->id, null, null,
            'Password reset completed', 'info'
        );

        return response()->json(['message' => 'Password reset successfully. You can now log in.']);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/auth/me  (protected)
    // -------------------------------------------------------------------------
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data'    => $this->formatCustomer($request->user()),
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // PUT /api/v1/auth/profile  (protected)
    // -------------------------------------------------------------------------
    public function updateProfile(Request $request): JsonResponse
    {
        $customer = $request->user();

        $data = $request->validate([
            'first_name'   => ['sometimes', 'string', 'max:100'],
            'last_name'    => ['sometimes', 'string', 'max:100'],
            'phone'        => ['nullable', 'string', 'max:50'],
            'country'      => ['nullable', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:200'],
            'vat_number'   => ['nullable', 'string', 'max:20'],
            'industry'     => ['nullable', 'string', 'max:100'],
        ]);

        // Re-validate VAT if number changed
        if (array_key_exists('vat_number', $data) && $data['vat_number'] !== $customer->vat_number) {
            if (! empty($data['vat_number'])) {
                $result               = $this->vatService->validate($data['vat_number']);
                $data['vat_verified'] = $result['valid'];
            } else {
                $data['vat_verified'] = false;
            }
        }

        // EU VAT enforcement: B2B customers outside Germany must keep a valid VAT number.
        // Effective values after the pending update (fall back to current DB values if not changing).
        $effectiveCountry     = $data['country'] ?? $customer->country;
        $effectiveVatNumber   = array_key_exists('vat_number', $data) ? $data['vat_number'] : $customer->vat_number;
        $effectiveVatVerified = array_key_exists('vat_verified', $data) ? $data['vat_verified'] : (bool) $customer->vat_verified;

        if ($this->taxService->requiresEuVat($effectiveCountry, $customer->customer_type)) {
            if (empty($effectiveVatNumber)) {
                return response()->json([
                    'message' => 'A valid EU VAT number is required for business accounts in EU member states.',
                    'errors'  => ['vat_number' => ['A valid EU VAT number is required for business accounts in EU member states.']],
                ], 422);
            }
            if (! $effectiveVatVerified) {
                return response()->json([
                    'message' => 'A valid EU VAT number is required for business accounts in EU member states.',
                    'errors'  => ['vat_number' => ['Your VAT number could not be validated. Please enter a valid EU VAT number.']],
                ], 422);
            }
        }

        $customer->update($data);

        return response()->json([
            'data'    => $this->formatCustomer($customer->fresh()),
            'message' => 'Profile updated successfully.',
        ]);
    }

    // -------------------------------------------------------------------------
    // PUT /api/v1/auth/change-password  (protected)
    // -------------------------------------------------------------------------
    public function changePassword(Request $request): JsonResponse
    {
        $customer = $request->user();

        $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'confirmed', Password::min(8)],
        ]);

        if (! Hash::check($request->current_password, $customer->password)) {
            return response()->json([
                'message' => 'The current password is incorrect.',
                'errors'  => ['current_password' => ['The current password is incorrect.']],
            ], 422);
        }

        $customer->update(['password' => Hash::make($request->password)]);

        return response()->json(['message' => 'Password changed successfully.']);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/auth/quotes  (protected)
    // -------------------------------------------------------------------------
    public function quotes(Request $request): JsonResponse
    {
        $quotes = $request->user()
            ->quoteRequests()
            ->with('order')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($q) => [
                'id'              => $q->id,
                'ref'             => $q->ref_number,
                'created_at'      => $q->created_at->toIso8601String(),
                'status'          => $this->mapQuoteStatus($q->status),
                'product_details' => trim(implode(' — ', array_filter([
                    $q->brand_preference,
                    $q->tyre_size,
                ]))),
                'quantity'        => $q->quantity,
                'notes'           => $q->notes,
                // Linked order — all null until admin converts the quote
                'order_id'        => $q->order_id,
                'order_ref'       => $q->order?->ref,
                'order_total'     => $q->order ? (float) $q->order->total : null,
                'payment_method'  => $q->order?->payment_method,
                'payment_status'  => $q->order?->payment_status,
                'order_status'    => $q->order?->status,
            ]);

        return response()->json(['data' => $quotes]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/auth/quotes/{ref}  (protected)
    // -------------------------------------------------------------------------
    public function quoteDetail(Request $request, string $ref): JsonResponse
    {
        $customer = $request->user();

        $quote = QuoteRequest::with('order.items')
            ->where('ref_number', $ref)
            ->first();

        if (! $quote) {
            return response()->json(['message' => 'Quote not found.'], 404);
        }

        // Ownership: customer_id match OR email match (covers guest quotes linked by email)
        $ownsById    = $quote->customer_id !== null && $quote->customer_id === $customer->id;
        $ownsByEmail = strtolower($quote->email) === strtolower($customer->email);

        if (! $ownsById && ! $ownsByEmail) {
            return response()->json(['message' => 'Quote not found.'], 404);
        }

        $order = $quote->order;

        return response()->json([
            'data' => [
                'id'                   => $quote->id,
                'ref'                  => $quote->ref_number,
                'created_at'           => $quote->created_at->toIso8601String(),
                'status'               => $this->mapQuoteStatus($quote->status),
                'product_details'      => trim(implode(' — ', array_filter([
                    $quote->brand_preference,
                    $quote->tyre_size,
                ]))),
                'tyre_category'        => $quote->tyre_category,
                'brand_preference'     => $quote->brand_preference,
                'tyre_size'            => $quote->tyre_size,
                'quantity'             => $quote->quantity,
                'tyre_condition'       => $quote->tyre_condition,
                'used_tyre_grade'      => $quote->used_tyre_grade,
                'used_tyre_notes'      => $quote->used_tyre_notes,
                'tyre_items'           => $quote->tyre_items,
                'incoterm'             => $quote->incoterm,
                'incoterm_type'        => $quote->incoterm_type,
                'budget_range'         => $quote->budget_range,
                'delivery_location'    => $quote->delivery_location,
                'delivery_address'     => $quote->delivery_address,
                'delivery_city'        => $quote->delivery_city,
                'delivery_postal_code' => $quote->delivery_postal_code,
                'country'              => $quote->country,
                'notes'                => $quote->notes,
                'admin_notes'          => $quote->admin_notes,
                'has_attachment'       => (bool) $quote->attachment_path,
                'attachment_name'      => $quote->attachment_original_name,
                'attachment_size'      => $quote->attachment_size,
                'attachment_mime'      => $quote->attachment_mime,
                'attachment_url'       => $quote->attachment_path
                    ? url(Storage::url($quote->attachment_path))
                    : null,
                // Linked order — all null until admin converts the quote
                'order_id'             => $quote->order_id,
                'order_ref'            => $order?->ref,
                'order_status'         => $order?->status,
                'payment_method'       => $order?->payment_method,
                'payment_status'       => $order?->payment_status,
                'subtotal'             => $order ? (float) $order->subtotal : null,
                'delivery_cost'        => $order ? (float) $order->delivery_cost : null,
                'tax_rate'             => $order ? (float) $order->tax_rate : null,
                'tax_amount'           => $order ? (float) $order->tax_amount : null,
                'tax_treatment'        => $order?->tax_treatment,
                'is_reverse_charge'    => $order !== null ? (bool) $order->is_reverse_charge : null,
                'order_total'          => $order ? (float) $order->total : null,
                'order_items'          => $order
                    ? $order->items->map(fn ($item) => [
                        'sku'        => $item->sku,
                        'brand'      => $item->brand,
                        'name'       => $item->name,
                        'size'       => $item->size,
                        'unit_price' => (float) $item->unit_price,
                        'quantity'   => $item->quantity,
                        'line_total' => (float) $item->line_total,
                    ])->values()
                    : null,
            ],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/auth/invoices  (protected)
    // -------------------------------------------------------------------------
    public function invoices(Request $request): JsonResponse
    {
        $customer = $request->user();

        // Lazy invoice creation: covers the case where the customer paid before
        // their account existed (Stripe webhook fired with no Customer record) so
        // InvoiceService skipped creation. Now that they are authenticated we can
        // create any missing invoices and link them to the account.
        try {
            $customerOrderRefs = Order::where('customer_email', $customer->email)->pluck('ref');

            // Repair invoices linked to this customer's orders but with a stale
            // customer_id (created before the right Customer account existed, or
            // created from an imported/duplicate Customer row).
            $repaired = Invoice::whereIn('order_ref', $customerOrderRefs)
                ->where('customer_id', '!=', $customer->id)
                ->update(['customer_id' => $customer->id]);

            if ($repaired > 0) {
                Log::info('Repaired stale invoice customer_id', [
                    'customer_id' => $customer->id,
                    'count'       => $repaired,
                ]);
            }

            $existingOrderRefs = Invoice::whereIn('order_ref', $customerOrderRefs)
                ->pluck('order_ref');

            $ordersNeedingInvoice = Order::where('customer_email', $customer->email)
                ->where('payment_status', 'paid')
                ->where('status', '!=', 'cancelled')
                ->whereNotIn('ref', $existingOrderRefs)
                ->with('items')
                ->get();

            if ($ordersNeedingInvoice->isNotEmpty()) {
                $svc = app(InvoiceService::class);
                foreach ($ordersNeedingInvoice as $order) {
                    $svc->createForOrder($order);
                }
                Log::info('Lazy invoice creation completed', [
                    'customer_id' => $customer->id,
                    'created'     => $ordersNeedingInvoice->count(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Lazy invoice creation failed in GET /auth/invoices', [
                'customer_id' => $customer->id,
                'error'       => $e->getMessage(),
            ]);
        }

        $invoices = $customer
            ->invoices()
            ->whereNotNull('released_at')
            ->orderByDesc('issued_at')
            ->get()
            ->map(fn ($inv) => [
                'id'                 => $inv->id,
                'invoice_number'     => $inv->invoice_number,
                'issued_at'          => $inv->issued_at->toIso8601String(),
                'due_at'             => $inv->due_at?->toIso8601String(),
                'released_at'        => $inv->released_at?->toIso8601String(),
                'amount'             => (float) $inv->amount,
                'status'             => $inv->status,
                'order_ref'          => $inv->order_ref,
                'tax_treatment'      => $inv->tax_treatment,
                'is_reverse_charge'  => (bool) $inv->is_reverse_charge,
                'download_available' => (bool) $inv->pdf_url,
                'pdf_url'            => $inv->pdf_url
                    ? route('invoices.download', $inv->id)
                    : null,
            ]);

        return response()->json(['data' => $invoices]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------
    private function sendVerificationEmail(Customer $customer): void
    {
        // APP_URL must be the API URL, not the frontend.
        // forceRootUrl + forceScheme ensure signed links always point to this
        // backend and always use HTTPS even when behind an HTTP-only reverse proxy.
        $apiRoot = rtrim(config('app.url'), '/');
        URL::forceRootUrl($apiRoot);
        URL::forceScheme('https');

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHours(24),
            ['id' => $customer->id, 'hash' => sha1($customer->email)]
        );

        URL::forceRootUrl(null);
        URL::forceScheme(null);

        Mail::to($customer->email)->send(new CustomerEmailVerification($customer, $url));
    }

    private function mapQuoteStatus(string $status): string
    {
        return match ($status) {
            'new'      => 'pending',
            'reviewed' => 'reviewed',
            'quoted'   => 'approved',
            'closed'   => 'rejected',
            default    => $status,
        };
    }

    private function formatCustomer(Customer $c): array
    {
        return [
            'id'            => $c->id,
            'customer_type' => $c->customer_type,
            'first_name'    => $c->first_name,
            'last_name'     => $c->last_name,
            'full_name'     => $c->full_name,
            'email'         => $c->email,
            'phone'         => $c->phone,
            'country'       => $c->country,
            'company_name'  => $c->company_name,
            'vat_number'    => $c->vat_number,
            'vat_verified'  => $c->vat_verified,
            'industry'      => $c->industry,
            'email_verified' => (bool) $c->email_verified_at,
        ];
    }
}

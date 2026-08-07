<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        // ── Admin auth ────────────────────────────────────────────────────────

        // Admin login: 5/min per IP+email — outer request guard (in addition to
        // the per-failure inline counter already in AuthController)
        RateLimiter::for('admin-login', function (Request $request) {
            return Limit::perMinute(5)
                ->by($request->ip() . '|' . strtolower($request->input('email', '')));
        });

        // Admin 2FA verification + setup: 10 per 5 minutes per IP
        RateLimiter::for('admin-2fa', function (Request $request) {
            return Limit::perMinutes(5, 10)->by($request->ip());
        });

        // ── Partner app auth ──────────────────────────────────────────────────

        // Partner login: 5 per minute per IP+phone, AND 20 per minute per IP.
        //
        // Both limits are needed and neither is redundant. Keyed on phone
        // alone, an attacker with a pool of IPs still gets unlimited attempts
        // against one account. Keyed on IP alone, an attacker who rotates the
        // phone number gets unlimited attempts across many accounts — which is
        // the realistic attack against a 6-digit numeric secret. The per-phone
        // lockout in PartnerAuthController sits behind both as the account-level
        // backstop, since a distributed attacker defeats any IP-based limit.
        RateLimiter::for('partner-login', function (Request $request) {
            $phone = preg_replace('/\D+/', '', (string) $request->input('phone', ''));

            return [
                Limit::perMinute(5)->by($request->ip() . '|' . $phone),
                Limit::perMinute(20)->by('partner-login-ip|' . $request->ip()),
            ];
        });

        // Partner sale writes: generous, because a returning offline device
        // legitimately flushes a whole backlog at once. Per authenticated user,
        // falling back to IP for the unauthenticated case.
        RateLimiter::for('partner-sync', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?? $request->ip());
        });

        // ── Customer auth ─────────────────────────────────────────────────────

        // Customer register / login: 10 per IP per minute
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Email-sending auth endpoints (forgot-password, resend-verification):
        // 5 per IP per minute — each request dispatches an email
        RateLimiter::for('auth-email', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Password reset (token submission): 5 per 15 minutes per IP
        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinutes(15, 5)->by($request->ip());
        });

        // Customer Pay Now (Stripe session creation): 10 per customer per minute
        RateLimiter::for('checkout', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?? $request->ip());
        });

        // ── Public forms ──────────────────────────────────────────────────────

        // Quote requests: 10 per IP per hour
        RateLimiter::for('quote-form', function (Request $request) {
            return Limit::perHour(10)->by($request->ip());
        });

        // Contact / manual orders / newsletter: 10 per IP per hour
        RateLimiter::for('public-form', function (Request $request) {
            return Limit::perHour(10)->by($request->ip());
        });

        // Order-confirmation acceptance via emailed token: 20 per IP per minute
        // Higher than auth because the token is already the secret — brute-force
        // is blocked by the 64-char token space, not by this limiter
        RateLimiter::for('acceptance-links', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        // ── Public content reads ──────────────────────────────────────────────

        // General public API (products, articles, categories, etc.): 120/min per IP
        RateLimiter::for('api-public', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        // Document verification (public trade-doc lookup): 60/min per IP
        RateLimiter::for('public-doc-verify', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        // Search: 30 per IP per minute
        RateLimiter::for('search', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        // VAT validation: 10 per IP per minute (external VIES API call)
        RateLimiter::for('vat', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Container / shipment tracking: 30 per IP per minute
        RateLimiter::for('tracking', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        // ── Payments ──────────────────────────────────────────────────────────

        // Stripe checkout session + tax preview: 20 per IP per minute
        RateLimiter::for('payments', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        // ── Admin operations ──────────────────────────────────────────────────

        // Sensitive admin write/destructive operations (user management,
        // bulk imports, security actions): 30/min per authenticated admin
        RateLimiter::for('admin-sensitive', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?? $request->ip());
        });

        // eBay sync API calls (hits eBay Sell API): 10/min per authenticated admin
        RateLimiter::for('ebay-sync', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?? $request->ip());
        });

        // Article image uploads (body, cover, OG): 20/min per authenticated admin
        RateLimiter::for('article-upload', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?? $request->ip());
        });
    }
}

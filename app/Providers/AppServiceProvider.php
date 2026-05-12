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
        // Quote requests: 5 per IP per hour (spec §4.6)
        RateLimiter::for('quote-form', function (Request $request) {
            return Limit::perHour(5)->by($request->ip());
        });

        // Contact / Manual orders / Newsletter: 10 per IP per hour
        RateLimiter::for('public-form', function (Request $request) {
            return Limit::perHour(10)->by($request->ip());
        });

        // Search: 30 per IP per minute
        RateLimiter::for('search', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        // VAT validation: 10 per IP per minute
        RateLimiter::for('vat', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Payments (Stripe checkout + tax-preview): 20 per IP per minute
        RateLimiter::for('payments', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        // Customer auth — register / login / password reset: 10 per IP per minute
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Email-sending auth endpoints (forgot-password, resend-verification):
        // stricter — 5 per IP per minute — each request dispatches an email
        RateLimiter::for('auth-email', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Customer Pay Now (Stripe session creation): 10 per customer per minute
        // Keyed by authenticated customer ID so per-account not per-IP
        RateLimiter::for('checkout', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?? $request->ip());
        });

        // Container / shipment tracking: 30 per IP per minute
        // Guards the external DHL + ShipsGo API calls
        RateLimiter::for('tracking', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });
    }
}

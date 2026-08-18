<?php

namespace App\Services;

use App\Mail\CustomerEmailVerification;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Building and sending the "verify your email" link.
 *
 * Extracted from CustomerAuthController because it stopped being only the
 * registration flow's business. An admin approving a buyer needs to send the
 * same link — and until now could not, which is the fault this service exists
 * to close: the approval email told the customer to verify their address and
 * gave them no way to do it, while the link from registration had expired
 * twenty-four hours after they signed up.
 */
class CustomerEmailVerifier
{
    /**
     * How long a verification link stays good.
     *
     * Short by design for a self-service signup, and the reason approval has to
     * send a fresh one rather than assume the original still works: a B2B
     * account is often approved days after it is registered, by which time the
     * link in the customer's inbox is long dead.
     */
    public const LINK_TTL_HOURS = 24;

    /**
     * A signed verification URL pointing at this API.
     *
     * APP_URL must be the API, not the frontend. forceRootUrl + forceScheme
     * make the signature hold when the app sits behind a reverse proxy that
     * terminates TLS — without them the link is signed for http:// and every
     * click fails the signature check.
     */
    public function link(Customer $customer): string
    {
        $apiRoot = rtrim(config('app.url'), '/');

        URL::forceRootUrl($apiRoot);
        URL::forceScheme('https');

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHours(self::LINK_TTL_HOURS),
            ['id' => $customer->id, 'hash' => sha1($customer->email)]
        );

        URL::forceRootUrl(null);
        URL::forceScheme(null);

        return $url;
    }

    /**
     * Send it. Never throws — every caller is doing something else more
     * important (approving an account, completing a registration) and a mail
     * failure must not roll that back. Returns whether it went, so the caller
     * can say so rather than implying it did.
     */
    public function send(Customer $customer): bool
    {
        try {
            Mail::to($customer->email)->send(new CustomerEmailVerification($customer, $this->link($customer)));

            return true;
        } catch (\Throwable $e) {
            Log::error('Customer verification email failed', [
                'customer_id' => $customer->id,
                'email'       => $customer->email,
                'error'       => $e->getMessage(),
            ]);

            return false;
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\MarketingContact;
use Illuminate\Http\RedirectResponse;

class MarketingContactController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /api/v1/marketing-contacts/unsubscribe/{token} — public, no auth
    // -------------------------------------------------------------------------
    public function unsubscribe(string $token): RedirectResponse
    {
        $contact = MarketingContact::where('unsubscribe_token', $token)->first();

        if ($contact) {
            $contact->update(['status' => 'unsubscribed']);
        }

        return redirect()->away('https://okelcor.com/?unsubscribed=1');
    }
}

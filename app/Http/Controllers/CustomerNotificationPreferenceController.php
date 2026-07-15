<?php

namespace App\Http\Controllers;

use App\Services\CustomerNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer notification delivery preferences (email / in-app toggles).
 *
 *   GET /auth/customer/notification-preferences
 *   PUT /auth/customer/notification-preferences   (partial allowed)
 *
 * Compliance: email_orders (operational/legal) is forced on — the client
 * renders that toggle locked, and the backend re-asserts it on save.
 * email_marketing defaults off (GDPR opt-in).
 */
class CustomerNotificationPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => CustomerNotifier::preferencesFor($request->user()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'inapp_enabled'    => ['sometimes', 'boolean'],
            'email_enabled'    => ['sometimes', 'boolean'],
            'email_orders'     => ['sometimes', 'boolean'],
            'email_documents'  => ['sometimes', 'boolean'],
            'email_quotes'     => ['sometimes', 'boolean'],
            'email_account'    => ['sometimes', 'boolean'],
            'email_marketing'  => ['sometimes', 'boolean'],
            'whatsapp_enabled' => ['sometimes', 'boolean'],
        ]);

        $customer = $request->user();

        // Merge partial update over current resolved preferences.
        $prefs = array_merge(CustomerNotifier::preferencesFor($customer), $validated);

        // Operational/legal comms can never be turned off.
        $prefs['email_orders'] = true;

        $customer->update(['notification_preferences' => $prefs]);

        return response()->json([
            'data' => CustomerNotifier::preferencesFor($customer->fresh()),
        ]);
    }
}

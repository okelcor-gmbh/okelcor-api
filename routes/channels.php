<?php

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\LiveChatSession;
use Illuminate\Support\Facades\Broadcast;

/**
 * Live chat broadcast channels (see FRONTEND_NOTE_admin-mobile-app-v2-premium.md,
 * Pillar 1). $user here is whatever Sanctum's polymorphic token resolves to
 * — either an AdminUser (mobile app / admin panel) or a Customer (website
 * chat widget / customer portal) — so every callback below type-checks
 * which one it got rather than assuming a single guard.
 */

// Every active admin can listen for new pending chat requests — the queue
// itself, not any one conversation's content. Push notifications (with
// Accept/Decline actions) are the primary route-to-an-admin mechanism;
// this channel is what lets the "Today" screen/queue view update live.
Broadcast::channel('admin.chat-queue', function ($user) {
    return $user instanceof AdminUser && $user->is_active;
});

// One conversation's messages — only the customer who owns it, or the
// admin actually assigned to it (never every admin, unlike the queue
// channel above).
Broadcast::channel('chat-session.{sessionId}', function ($user, int $sessionId) {
    $session = LiveChatSession::find($sessionId);
    if (! $session) {
        return false;
    }

    if ($user instanceof Customer) {
        return $user->id === $session->customer_id;
    }

    if ($user instanceof AdminUser) {
        return $user->id === $session->admin_id;
    }

    return false;
});

<?php

namespace App\Services;

use App\Events\LiveChatMessageSent;
use App\Events\LiveChatSessionRequested;
use App\Events\LiveChatSessionStatusChanged;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\CustomerCommunication;
use App\Models\LiveChatMessage;
use App\Models\LiveChatSession;
use Illuminate\Support\Str;

/**
 * Live chat business logic (see FRONTEND_NOTE_admin-mobile-app-v2-premium.md,
 * Pillar 1). Messages live in live_chat_messages only while a session is
 * open, for fast real-time delivery over Pusher; on close, the full
 * transcript is rolled up into a single CustomerCommunication row
 * (channel: 'live_chat') so it joins that customer's unified history
 * exactly like an e-mail or WhatsApp thread — no permanent parallel
 * record for anything beyond the in-progress conversation.
 */
class LiveChatService
{
    /**
     * Reuses an existing pending/active session for this customer rather
     * than creating a duplicate — a page refresh on the widget must not
     * spawn a second concurrent conversation.
     */
    public function startSession(Customer $customer): LiveChatSession
    {
        $existing = LiveChatSession::where('customer_id', $customer->id)
            ->whereIn('status', ['pending', 'active'])
            ->first();

        if ($existing) {
            return $existing;
        }

        $session = LiveChatSession::create(['customer_id' => $customer->id, 'status' => 'pending']);

        broadcast(new LiveChatSessionRequested($session));

        $availableAdminIds = AdminUser::where('is_active', true)->where('available_for_chat', true)->pluck('id')->all();
        if ($availableAdminIds) {
            $customerName = $customer->company_name ?: trim($customer->first_name . ' ' . $customer->last_name);
            app(ExpoPushService::class)->sendToAdmins(
                $availableAdminIds,
                'New live chat request',
                $customerName . ' is waiting to chat.',
                "/admin/chat-sessions/{$session->id}",
                category: 'live_chat_request',
                data: ['related_type' => 'live_chat_session', 'related_id' => $session->id],
            );
        }

        return $session;
    }

    public function sendCustomerMessage(LiveChatSession $session, Customer $customer, string $body): LiveChatMessage
    {
        return $this->sendMessage($session, 'customer', $customer->id, $body);
    }

    public function sendAdminMessage(LiveChatSession $session, AdminUser $admin, string $body): LiveChatMessage
    {
        return $this->sendMessage($session, 'admin', $admin->id, $body);
    }

    private function sendMessage(LiveChatSession $session, string $senderType, int $senderId, string $body): LiveChatMessage
    {
        $message = LiveChatMessage::create([
            'session_id'  => $session->id,
            'sender_type' => $senderType,
            'sender_id'   => $senderId,
            'body'        => $body,
        ]);

        $session->update(['last_message_at' => now()]);

        broadcast(new LiveChatMessageSent($message));

        return $message;
    }

    /**
     * First admin to accept wins — claims the session. Returns false (no
     * change made) if it was already claimed by someone else, so the
     * caller can tell the admin "someone else already took this."
     */
    public function accept(LiveChatSession $session, AdminUser $admin): bool
    {
        if (! $session->isPending()) {
            return false;
        }

        $session->update([
            'admin_id'    => $admin->id,
            'status'      => 'active',
            'accepted_at' => now(),
        ]);

        broadcast(new LiveChatSessionStatusChanged($session));

        return true;
    }

    public function close(LiveChatSession $session, string $closedReason): void
    {
        if ($session->isClosed()) {
            return;
        }

        $communication = $this->rollUpIntoCommunication($session);

        $session->update([
            'status'           => 'closed',
            'closed_at'        => now(),
            'closed_reason'    => $closedReason,
            'communication_id' => $communication?->id,
        ]);

        broadcast(new LiveChatSessionStatusChanged($session));
    }

    private function rollUpIntoCommunication(LiveChatSession $session): ?CustomerCommunication
    {
        $messages = $session->messages;
        if ($messages->isEmpty()) {
            return null;
        }

        $customer = $session->customer;
        $adminName = $session->admin?->display_name ?? $session->admin?->name ?? 'Okelcor team';

        $bodyHtml = $messages->map(function (LiveChatMessage $m) use ($adminName) {
            $label = $m->sender_type === 'customer' ? 'Customer' : $adminName;
            return '<p><strong>' . e($label) . ':</strong> ' . nl2br(e($m->body)) . '</p>';
        })->implode('');

        return CustomerCommunication::create([
            'customer_id'  => $customer?->id,
            'admin_user_id' => $session->admin_id,
            'type'         => 'email', // reuses the same thread-view type the rest of the communications UI already renders
            'direction'    => 'inbound',
            'channel'      => 'live_chat',
            'subject'      => 'Live chat — ' . $session->created_at->format('Y-m-d H:i'),
            'body'         => $bodyHtml,
            'message_id'   => Str::uuid()->toString() . '@okelcor.com',
            'status'       => 'completed',
            'completed_at' => now(),
        ]);
    }
}

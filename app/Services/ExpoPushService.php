<?php

namespace App\Services;

use App\Models\AdminPushToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends push notifications to the admin/ops companion mobile app (see
 * FRONTEND_NOTE_admin-mobile-app.md) via Expo's push service — one HTTPS
 * API for both iOS and Android, no separate APNs/FCM certificate
 * management. No API key needed for Expo's basic push send.
 *
 * Called from AdminNotificationService::notifyUser() — every existing
 * in-app notification (customer replies, security alerts, follow-ups, AI
 * insights, etc.) now also reaches a phone automatically, with zero
 * changes needed anywhere those notifications are already triggered from.
 *
 * Degrades silently: an admin with no registered device (the common case
 * until the mobile app is actually installed) costs one cheap indexed
 * lookup and nothing else; any Expo API failure is logged and swallowed —
 * a push failure must never break the underlying business action that
 * triggered the notification.
 */
class ExpoPushService
{
    private const API_URL = 'https://exp.host/--/api/v2/push/send';
    private const CHUNK_SIZE = 100; // Expo's documented max per request

    public function sendToAdmin(int $adminUserId, string $title, ?string $body, ?string $actionUrl = null): void
    {
        $this->sendToAdmins([$adminUserId], $title, $body, $actionUrl);
    }

    /**
     * @param  int[]  $adminUserIds
     */
    public function sendToAdmins(array $adminUserIds, string $title, ?string $body, ?string $actionUrl = null): void
    {
        if (! $adminUserIds) {
            return;
        }

        $tokens = AdminPushToken::whereIn('admin_id', $adminUserIds)->pluck('token');
        if ($tokens->isEmpty()) {
            return;
        }

        foreach ($tokens->chunk(self::CHUNK_SIZE) as $chunk) {
            $this->send($chunk, $title, $body, $actionUrl);
        }
    }

    private function send(Collection $tokens, string $title, ?string $body, ?string $actionUrl): void
    {
        $messages = $tokens->map(fn ($token) => [
            'to'    => $token,
            'title' => $title,
            'body'  => $body ?? '',
            'data'  => ['action_url' => $actionUrl],
            'sound' => 'default',
        ])->values()->all();

        try {
            $response = Http::timeout(10)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post(self::API_URL, $messages);

            if (! $response->ok()) {
                Log::warning('[expo_push_send_failed] Expo API returned an error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return;
            }

            $this->pruneDeadTokens($response->json('data') ?? [], $tokens->values()->all());
        } catch (\Throwable $e) {
            Log::warning('[expo_push_send_failed] Could not reach Expo push API', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Expo's send response includes a per-message ticket; a
     * DeviceNotRegistered error means the app was uninstalled or the token
     * is otherwise dead — remove it so future sends don't keep paying for
     * a doomed request.
     */
    private function pruneDeadTokens(array $tickets, array $tokensSent): void
    {
        foreach ($tickets as $index => $ticket) {
            $isDead = ($ticket['status'] ?? null) === 'error'
                && ($ticket['details']['error'] ?? null) === 'DeviceNotRegistered';

            if ($isDead && isset($tokensSent[$index])) {
                AdminPushToken::where('token', $tokensSent[$index])->delete();
            }
        }
    }
}

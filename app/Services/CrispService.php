<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around Crisp's REST API v1 (api.crisp.chat) — proxies the
 * same four operations the existing Next.js admin panel already does
 * directly, for the mobile app (which can't hold Crisp credentials itself
 * — see config/services.php's crisp block for why).
 *
 * Auth: HTTP Basic (`identifier:key` from a Crisp private plugin) plus the
 * `X-Crisp-Tier: plugin` header Crisp requires on every plugin-authenticated
 * call. Verify the exact request/response shapes below against Crisp's
 * current API reference (docs.crisp.chat/references/rest-api/v1/) once
 * real credentials are in place — third-party API shapes drift, and this
 * was built without the ability to test against a live account.
 */
class CrispService
{
    public function isConfigured(): bool
    {
        return (bool) (config('services.crisp.website_id') && config('services.crisp.identifier') && config('services.crisp.key'));
    }

    public function listConversations(int $page = 1): array
    {
        $response = $this->request()->get($this->url("website/{$this->websiteId()}/conversations/{$page}"));

        return $this->unwrap($response);
    }

    public function getMessages(string $sessionId): array
    {
        $response = $this->request()->get($this->url("website/{$this->websiteId()}/conversation/{$sessionId}/messages"));

        return $this->unwrap($response);
    }

    public function sendMessage(string $sessionId, string $content): array
    {
        $response = $this->request()->post($this->url("website/{$this->websiteId()}/conversation/{$sessionId}/message"), [
            'type'    => 'text',
            'content' => $content,
            'from'    => 'operator',
            'origin'  => 'chat',
        ]);

        return $this->unwrap($response);
    }

    public function resolveConversation(string $sessionId): void
    {
        $response = $this->request()->patch($this->url("website/{$this->websiteId()}/conversation/{$sessionId}/state"), [
            'state' => 'resolved',
        ]);

        $this->unwrap($response);
    }

    private function request(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Crisp is not configured (missing website_id/identifier/key).');
        }

        return Http::withBasicAuth(config('services.crisp.identifier'), config('services.crisp.key'))
            ->withHeaders(['X-Crisp-Tier' => 'plugin'])
            ->timeout(15);
    }

    private function unwrap(Response $response): array
    {
        if (! $response->ok()) {
            throw new \RuntimeException('Crisp API error ' . $response->status() . ': ' . $response->body());
        }

        return $response->json('data') ?? [];
    }

    private function url(string $path): string
    {
        return rtrim(config('services.crisp.base_url'), '/') . '/' . ltrim($path, '/');
    }

    private function websiteId(): string
    {
        return config('services.crisp.website_id');
    }
}

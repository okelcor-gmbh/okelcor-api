<?php

namespace Tests\Feature;

use App\Services\MicrosoftGraphMailService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pure HTTP-client service (OAuth2 client-credentials + Graph REST calls) —
 * no database involved, runs under the default sqlite testing environment.
 * Genuinely more testable than the IMAP approach this replaced, since it's
 * just HTTP end to end.
 */
class MicrosoftGraphMailServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.mail_inbound.tenant_id'     => 'tenant-123',
            'services.mail_inbound.client_id'     => 'client-abc',
            'services.mail_inbound.client_secret' => 'secret-xyz',
            'services.mail_inbound.address'       => 'support@okelcor.com',
        ]);
        Cache::flush();
    }

    public function test_reports_not_configured_when_credentials_missing(): void
    {
        config(['services.mail_inbound.client_secret' => null]);
        $this->assertFalse((new MicrosoftGraphMailService())->isConfigured());
    }

    public function test_reports_configured_when_credentials_present(): void
    {
        $this->assertTrue((new MicrosoftGraphMailService())->isConfigured());
    }

    public function test_fetch_unread_messages_requests_token_then_messages(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok_123', 'expires_in' => 3600], 200),
            'graph.microsoft.com/*'       => Http::response(['value' => [['id' => 'msg1', 'subject' => 'Hi']]], 200),
        ]);

        $result = (new MicrosoftGraphMailService())->fetchUnreadMessages();

        $this->assertArrayNotHasKey('error', $result);
        $this->assertCount(1, $result['messages']);
        $this->assertSame('msg1', $result['messages'][0]['id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'login.microsoftonline.com/tenant-123/oauth2/v2.0/token')
                && $request['grant_type'] === 'client_credentials'
                && $request['client_id'] === 'client-abc'
                && $request['client_secret'] === 'secret-xyz'
                && $request['scope'] === 'https://graph.microsoft.com/.default';
        });

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'graph.microsoft.com/v1.0/users/support@okelcor.com/mailFolders/inbox/messages')
                && $request->hasHeader('Authorization', 'Bearer tok_123');
        });
    }

    public function test_access_token_is_cached_across_calls(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok_cached', 'expires_in' => 3600], 200),
            'graph.microsoft.com/*'       => Http::response(['value' => []], 200),
        ]);

        $service = new MicrosoftGraphMailService();
        $service->fetchUnreadMessages();
        $service->fetchUnreadMessages();

        // Only one token request across both calls — the second call reused the cached token.
        Http::assertSentCount(3); // 1 token + 2 message fetches
    }

    public function test_mark_as_read_patches_isread_true(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok_123', 'expires_in' => 3600], 200),
            'graph.microsoft.com/*'       => Http::response([], 200),
        ]);

        $result = (new MicrosoftGraphMailService())->markAsRead('msg1');

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request) {
            return $request->method() === 'PATCH'
                && str_contains($request->url(), 'graph.microsoft.com/v1.0/users/support@okelcor.com/messages/msg1')
                && $request['isRead'] === true;
        });
    }

    public function test_returns_error_when_token_request_fails(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['error_description' => 'invalid_client'], 401),
        ]);

        $result = (new MicrosoftGraphMailService())->fetchUnreadMessages();

        $this->assertSame('invalid_client', $result['error']);
    }

    public function test_returns_error_when_message_fetch_fails(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok_123', 'expires_in' => 3600], 200),
            'graph.microsoft.com/*'       => Http::response(['error' => ['message' => 'Forbidden']], 403),
        ]);

        $result = (new MicrosoftGraphMailService())->fetchUnreadMessages();

        $this->assertSame('Forbidden', $result['error']);
    }

    public function test_returns_error_when_not_configured_without_making_a_request(): void
    {
        config(['services.mail_inbound.tenant_id' => null]);
        Http::fake();

        $result = (new MicrosoftGraphMailService())->fetchUnreadMessages();

        $this->assertArrayHasKey('error', $result);
        Http::assertNothingSent();
    }
}

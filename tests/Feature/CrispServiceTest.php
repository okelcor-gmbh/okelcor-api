<?php

namespace Tests\Feature;

use App\Services\CrispService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pure HTTP-client service — no database involved, so (unlike most of this
 * suite) this runs fine under the default sqlite testing environment.
 */
class CrispServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.crisp.website_id' => 'site-123',
            'services.crisp.identifier' => 'test-identifier',
            'services.crisp.key'        => 'test-key',
            'services.crisp.base_url'   => 'https://api.crisp.chat/v1',
        ]);
    }

    public function test_reports_not_configured_when_credentials_missing(): void
    {
        config(['services.crisp.key' => null]);
        $this->assertFalse((new CrispService())->isConfigured());
    }

    public function test_reports_configured_when_credentials_present(): void
    {
        $this->assertTrue((new CrispService())->isConfigured());
    }

    public function test_list_conversations_hits_the_correct_url_with_basic_auth_and_tier_header(): void
    {
        Http::fake(['api.crisp.chat/*' => Http::response(['data' => [['session_id' => 'abc']]], 200)]);

        $result = (new CrispService())->listConversations(2);

        $this->assertSame([['session_id' => 'abc']], $result);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.crisp.chat/v1/website/site-123/conversations/2'
                && $request->hasHeader('X-Crisp-Tier', 'plugin')
                && str_starts_with($request->header('Authorization')[0] ?? '', 'Basic ');
        });
    }

    public function test_send_message_posts_text_from_operator(): void
    {
        Http::fake(['api.crisp.chat/*' => Http::response(['data' => ['fingerprint' => 1]], 200)]);

        (new CrispService())->sendMessage('sess-1', 'Hello there');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.crisp.chat/v1/website/site-123/conversation/sess-1/message'
            && $request['type'] === 'text'
            && $request['content'] === 'Hello there'
            && $request['from'] === 'operator');
    }

    public function test_resolve_conversation_patches_state(): void
    {
        Http::fake(['api.crisp.chat/*' => Http::response(['data' => []], 200)]);

        (new CrispService())->resolveConversation('sess-1');

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && $request->url() === 'https://api.crisp.chat/v1/website/site-123/conversation/sess-1/state'
            && $request['state'] === 'resolved');
    }

    public function test_throws_when_crisp_returns_an_error_status(): void
    {
        Http::fake(['api.crisp.chat/*' => Http::response('Server Error', 500)]);

        $this->expectException(\RuntimeException::class);
        (new CrispService())->getMessages('sess-1');
    }

    public function test_throws_when_not_configured(): void
    {
        config(['services.crisp.key' => null]);

        $this->expectException(\RuntimeException::class);
        (new CrispService())->listConversations();
    }
}

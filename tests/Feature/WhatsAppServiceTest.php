<?php

namespace Tests\Feature;

use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pure HTTP-client service — no database involved, so (unlike most of this
 * suite) this runs fine under the default sqlite testing environment.
 */
class WhatsAppServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.phone_number_id' => '1234567890',
            'services.whatsapp.access_token'    => 'test-token',
            'services.whatsapp.api_version'     => 'v20.0',
            'services.whatsapp.base_url'        => 'https://graph.facebook.com',
        ]);
    }

    public function test_reports_not_configured_when_credentials_missing(): void
    {
        config(['services.whatsapp.access_token' => null]);
        $this->assertFalse((new WhatsAppService())->isConfigured());
    }

    public function test_reports_configured_when_credentials_present(): void
    {
        $this->assertTrue((new WhatsAppService())->isConfigured());
    }

    public function test_send_text_posts_correct_payload_and_returns_message_id(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ABC123']]], 200),
        ]);

        $result = (new WhatsAppService())->sendText('+233 24 123 4567', 'Hello there');

        $this->assertSame('wamid.ABC123', $result['message_id']);
        $this->assertArrayNotHasKey('error', $result);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://graph.facebook.com/v20.0/1234567890/messages'
                && $request['to'] === '233241234567'          // normalized, no +/spaces
                && $request['type'] === 'text'
                && $request['text']['body'] === 'Hello there'
                && $request->hasHeader('Authorization', 'Bearer test-token');
        });
    }

    public function test_send_template_includes_body_components(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.XYZ']]], 200)]);

        (new WhatsAppService())->sendTemplate('233241234567', 'okelcor_order_shipped', 'en_US', ['AB-1042', 'GLS']);

        Http::assertSent(function ($request) {
            return $request['type'] === 'template'
                && $request['template']['name'] === 'okelcor_order_shipped'
                && $request['template']['language']['code'] === 'en_US'
                && $request['template']['components'][0]['parameters'][0]['text'] === 'AB-1042'
                && $request['template']['components'][0]['parameters'][1]['text'] === 'GLS';
        });
    }

    public function test_send_document_includes_link_filename_caption(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.DOC']]], 200)]);

        (new WhatsAppService())->sendDocument('233241234567', 'https://api.okelcor.com/doc.pdf', 'Proposal.pdf', 'Your proposal');

        Http::assertSent(function ($request) {
            return $request['type'] === 'document'
                && $request['document']['link'] === 'https://api.okelcor.com/doc.pdf'
                && $request['document']['filename'] === 'Proposal.pdf'
                && $request['document']['caption'] === 'Your proposal';
        });
    }

    public function test_returns_error_on_api_failure_without_throwing(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Recipient phone number not in allowed list', 'code' => 131030]], 400),
        ]);

        $result = (new WhatsAppService())->sendText('233241234567', 'Hi');

        $this->assertSame('Recipient phone number not in allowed list', $result['error']);
        $this->assertSame(131030, $result['error_code']);
    }

    public function test_returns_error_when_not_configured_without_making_a_request(): void
    {
        config(['services.whatsapp.access_token' => null]);
        Http::fake(); // any request would fail the test via assertNothingSent below

        $result = (new WhatsAppService())->sendText('233241234567', 'Hi');

        $this->assertArrayHasKey('error', $result);
        Http::assertNothingSent();
    }

    public function test_normalize_recipient_strips_non_digits(): void
    {
        $service = new WhatsAppService();
        $this->assertSame('233241234567', $service->normalizeRecipient('+233 24 123 4567'));
        $this->assertSame('233241234567', $service->normalizeRecipient('(233) 24-123-4567'));
    }

    public function test_within_customer_service_window(): void
    {
        $service = new WhatsAppService();
        $this->assertTrue($service->withinCustomerServiceWindow(now()->subHours(2)));
        $this->assertFalse($service->withinCustomerServiceWindow(now()->subHours(25)));
        $this->assertFalse($service->withinCustomerServiceWindow(null));
    }
}

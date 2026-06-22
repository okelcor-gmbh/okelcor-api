<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\QuoteRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * /tyre-wholesaler SEO landing-page lead intake (backend).
 *
 * Verifies the dedicated POST /api/v1/leads/tyre-wholesaler endpoint maps the
 * landing form into the standard quote pipeline, sets lead_source, persists
 * conversion attribution in lead_metadata, and keeps CRM-2 / CRM-3 behaviour.
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=<test_db> \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=WholesalerLanding
 */
class WholesalerLandingLeadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $connection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($connection !== 'mysql') {
            $this->markTestSkipped('Landing lead tests require a MySQL connection (legacy migrations are MySQL-only).');
        }

        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name'     => 'Ada Buyer',
            'company'  => 'Lagos Tyre Importers Ltd',
            'email'    => 'ada@lagos-tyres.com',
            'country'  => 'Nigeria',
            'interest' => 'PCR',
            'volume'   => '1-to-5',
            'notes'    => 'Looking to import 205/55R16 passenger tyres in container volumes to Lagos.',
        ], $overrides);
    }

    public function test_valid_lead_is_created_and_mapped(): void
    {
        $resp = $this->postJson('/api/v1/leads/tyre-wholesaler', $this->validPayload([
            'landing_page' => '/tyre-wholesaler',
            'utm_source'   => 'google',
            'utm_medium'   => 'cpc',
            'utm_campaign' => 'tyre-wholesale-q2',
            'gclid'        => 'TeSt-GcLiD-123',
            'fbclid'       => 'fb-456',
            'referrer'     => 'https://www.google.com/',
        ]));

        $resp->assertCreated()
            ->assertJsonPath('data.review_status', fn ($s) => in_array($s, ['qualified', 'needs_review'], true));

        $quote = QuoteRequest::where('email', 'ada@lagos-tyres.com')->firstOrFail();

        $this->assertSame('Ada Buyer', $quote->full_name);
        $this->assertSame('Lagos Tyre Importers Ltd', $quote->company_name);
        $this->assertSame('Nigeria', $quote->country);
        $this->assertSame('pcr', $quote->tyre_category);
        $this->assertSame('Nigeria', $quote->delivery_location);
        $this->assertSame('tyre_wholesaler_landing', $quote->lead_source);
        $this->assertSame($quote->review_status, $quote->qualification_status); // CRM-3 default
        $this->assertNotSame('spam', $quote->review_status);
    }

    public function test_attribution_is_persisted_in_lead_metadata(): void
    {
        $this->postJson('/api/v1/leads/tyre-wholesaler', $this->validPayload([
            'utm_source'   => 'google',
            'utm_campaign' => 'tyre-wholesale-q2',
            'gclid'        => 'TeSt-GcLiD-123',
            'fbclid'       => 'fb-456',
            'referrer'     => 'https://www.google.com/',
        ]))->assertCreated();

        $meta = QuoteRequest::where('email', 'ada@lagos-tyres.com')->firstOrFail()->lead_metadata;

        $this->assertSame('google', $meta['utm_source']);
        $this->assertSame('tyre-wholesale-q2', $meta['utm_campaign']);
        $this->assertSame('TeSt-GcLiD-123', $meta['gclid']);
        $this->assertSame('fb-456', $meta['fbclid']);
        $this->assertSame('https://www.google.com/', $meta['referrer']);
        $this->assertSame('/tyre-wholesaler', $meta['landing_page']);
        $this->assertSame('PCR', $meta['primary_tyre_interest']);
        $this->assertSame('1-to-5', $meta['estimated_monthly_volume']);
    }

    public function test_phone_is_optional(): void
    {
        // No phone field at all — must still succeed (campaign form omits it).
        $this->postJson('/api/v1/leads/tyre-wholesaler', $this->validPayload())
            ->assertCreated();

        $this->assertDatabaseHas('quote_requests', [
            'email'       => 'ada@lagos-tyres.com',
            'lead_source' => 'tyre_wholesaler_landing',
        ]);
    }

    public function test_notes_are_synthesised_when_blank(): void
    {
        $this->postJson('/api/v1/leads/tyre-wholesaler', $this->validPayload(['notes' => null]))
            ->assertCreated();

        $quote = QuoteRequest::where('email', 'ada@lagos-tyres.com')->firstOrFail();
        $this->assertNotEmpty($quote->notes);
        $this->assertStringContainsStringIgnoringCase('wholesale inquiry', $quote->notes);
    }

    public function test_canonical_field_names_are_accepted(): void
    {
        $this->postJson('/api/v1/leads/tyre-wholesaler', [
            'full_name'    => 'Canon Name',
            'company_name' => 'Canon Co',
            'email'        => 'canon@biz-tyres.com',
            'country'      => 'Ghana',
            'interest'     => 'TBR',
            'volume'       => '5-plus',
            'notes'        => 'Need 295/80R22.5 truck tyres, regular monthly shipments to Accra.',
        ])->assertCreated();

        $quote = QuoteRequest::where('email', 'canon@biz-tyres.com')->firstOrFail();
        $this->assertSame('Canon Name', $quote->full_name);
        $this->assertSame('Canon Co', $quote->company_name);
        $this->assertSame('tbr', $quote->tyre_category);
    }

    public function test_disposable_email_is_rejected_as_spam_but_stored(): void
    {
        $resp = $this->postJson('/api/v1/leads/tyre-wholesaler', $this->validPayload([
            'email' => 'throwaway@mailinator.com',
        ]));

        $resp->assertStatus(422)->assertJsonPath('code', 'low_quality_inquiry');

        // Spam is still persisted for audit, flagged, never emailed to the customer.
        $quote = QuoteRequest::where('email', 'throwaway@mailinator.com')->firstOrFail();
        $this->assertSame('spam', $quote->review_status);
        $this->assertSame('tyre_wholesaler_landing', $quote->lead_source);
    }

    public function test_invalid_interest_or_volume_is_rejected(): void
    {
        $this->postJson('/api/v1/leads/tyre-wholesaler', $this->validPayload(['interest' => 'Bicycle']))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('interest');

        $this->postJson('/api/v1/leads/tyre-wholesaler', $this->validPayload(['volume' => 'loads']))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('volume');
    }

    // ── Shared /quote-requests endpoint (the path the frontend actually uses) ──

    /**
     * The landing form posts to the existing /quote-requests endpoint with the
     * mapped payload (no quantity / tyre rows). It must succeed and persist
     * lead_source + attribution.
     */
    public function test_quote_requests_accepts_landing_payload_without_quantity(): void
    {
        $resp = $this->postJson('/api/v1/quote-requests', [
            'full_name'        => 'Ada Buyer',
            'company_name'     => 'Lagos Tyre Importers Ltd',
            'email'            => 'ada@lagos-tyres.com',
            'country'          => 'Nigeria',
            'delivery_location' => 'Nigeria',
            'tyre_category'    => 'PCR Tyres',
            'notes'            => 'Looking to import 205/55R16 passenger tyres in container volumes to Lagos.',
            'business_type'    => 'Wholesale / Distribution',
            'lead_source'      => 'tyre_wholesaler_landing',
            'source'           => 'tyre_wholesaler_landing',
            'primary_tyre_interest'    => 'PCR',
            'estimated_monthly_volume' => '1-to-5',
            'metadata' => [
                'landing_page'             => '/tyre-wholesaler',
                'primary_tyre_interest'    => 'PCR',
                'estimated_monthly_volume' => '1-to-5',
                'utm_source'               => 'google',
                'gclid'                    => 'TeSt-GcLiD-123',
            ],
            'utm_source' => 'google',
            'gclid'      => 'TeSt-GcLiD-123',
            'fbclid'     => 'fb-456',
            'referrer'   => 'https://www.google.com/',
        ]);

        $resp->assertCreated();

        $quote = QuoteRequest::where('email', 'ada@lagos-tyres.com')->firstOrFail();
        $this->assertSame('tyre_wholesaler_landing', $quote->lead_source);
        $this->assertSame('PCR Tyres', $quote->tyre_category);
        $this->assertNotEmpty($quote->quantity);                 // NOT-NULL-safe fallback applied
        $this->assertSame('google', $quote->lead_metadata['utm_source']);
        $this->assertSame('TeSt-GcLiD-123', $quote->lead_metadata['gclid']);
        $this->assertSame('fb-456', $quote->lead_metadata['fbclid']);
        $this->assertSame('1-to-5', $quote->lead_metadata['estimated_monthly_volume']);

        // Attribution keys must NOT leak into real columns / break the insert.
        $this->assertArrayNotHasKey('utm_source', $quote->getAttributes());
    }

    /**
     * An EU-based landing lead (no VAT field) must not be hard-blocked by EU VAT
     * enforcement — that gate only applies to the standard website quote form.
     */
    public function test_eu_landing_lead_is_not_blocked_by_vat_enforcement(): void
    {
        $this->postJson('/api/v1/quote-requests', [
            'full_name'         => 'Hans Müller',
            'company_name'      => 'Bayern Reifen GmbH',
            'email'             => 'hans@bayern-reifen.de',
            'country'           => 'France',
            'delivery_location' => 'France',
            'tyre_category'     => 'TBR Tyres',
            'notes'             => 'Interested in 295/80R22.5 truck tyres, monthly container volumes.',
            'lead_source'       => 'tyre_wholesaler_landing',
        ])->assertCreated();

        $this->assertDatabaseHas('quote_requests', [
            'email'       => 'hans@bayern-reifen.de',
            'lead_source' => 'tyre_wholesaler_landing',
        ]);
    }

    /**
     * The standard website quote form is unaffected: lead_source defaults to
     * website_quote and EU VAT enforcement still applies.
     */
    public function test_standard_website_quote_still_enforces_eu_vat(): void
    {
        $resp = $this->postJson('/api/v1/quote-requests', [
            'full_name'         => 'Hans Müller',
            'company_name'      => 'Bayern Reifen GmbH',
            'email'             => 'hans@bayern-reifen.de',
            'country'           => 'France',
            'delivery_location' => 'Paris, France',
            'tyre_category'     => 'TBR',
            'quantity'          => '500',
            'notes'             => 'Interested in 295/80R22.5 truck tyres, monthly container volumes.',
        ]);

        // No VAT number → blocked for an EU B2B website submission.
        $resp->assertStatus(422)->assertJsonValidationErrorFor('vat_number');
    }

    public function test_links_to_existing_customer_by_email(): void
    {
        $customer = Customer::create([
            'customer_type' => 'b2b',
            'first_name'    => 'Ada',
            'last_name'     => 'Buyer',
            'email'         => 'ada@lagos-tyres.com',
            'password'      => Hash::make('secret-pass-123'),
            'country'       => 'Nigeria',
            'company_name'  => 'Lagos Tyre Importers Ltd',
        ]);

        $this->postJson('/api/v1/leads/tyre-wholesaler', $this->validPayload())->assertCreated();

        $quote = QuoteRequest::where('email', 'ada@lagos-tyres.com')->firstOrFail();
        $this->assertSame($customer->id, $quote->possible_customer_id);
    }
}

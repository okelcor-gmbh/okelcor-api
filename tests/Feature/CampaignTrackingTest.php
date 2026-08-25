<?php

namespace Tests\Feature;

use App\Jobs\SendBulkEmailCampaignJob;
use App\Mail\BulkCampaignEmail;
use App\Models\AdminUser;
use App\Models\BulkEmailCampaign;
use App\Models\BulkEmailCampaignRecipient;
use App\Models\MarketingContact;
use App\Services\BulkEmailService;
use App\Services\CampaignTrackingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Campaign engagement tracking — the boss's marketer feedback tracker.
 * Open pixel + signed click redirects feed per-campaign open/completion
 * rates and the per-marketer scoreboard.
 */
class CampaignTrackingTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();
        foreach (['bulk_email_campaign_recipients', 'bulk_email_campaigns', 'marketing_contact_markets', 'marketing_contacts', 'admin_security_events', 'admin_users'] as $t) {
            Schema::dropIfExists($t);
        }

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->boolean('is_active')->default(true);
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('admin_security_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 100);
            $table->string('severity', 20)->default('info');
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('admin_email')->nullable();
            $table->string('admin_role', 50)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('description', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('first_name', 100)->nullable();
            $table->string('market', 50)->nullable();
            $table->string('status', 20)->default('unknown');
            $table->string('unsubscribe_token', 64)->unique()->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_contact_markets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('marketing_contacts')->cascadeOnDelete();
            $table->string('market', 50);
            $table->timestamps();
        });

        Schema::create('bulk_email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('subject', 255);
            $table->longText('body_html');
            $table->longText('body_text')->nullable();
            $table->json('blocks')->nullable();
            $table->json('theme')->nullable();
            $table->json('filters')->nullable();
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('admin_users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bulk_email_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('bulk_email_campaigns')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('marketing_contacts')->cascadeOnDelete();
            $table->string('email', 255);
            $table->string('status', 20)->default('pending');
            $table->string('tracking_token', 64)->nullable()->unique();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->timestamp('clicked_at')->nullable();
            $table->unsignedInteger('click_count')->default(0);
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();

        MarketingContact::forgetMultipleMarketsSupport();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['bulk_email_campaign_recipients', 'bulk_email_campaigns', 'marketing_contact_markets', 'marketing_contacts', 'admin_security_events', 'admin_users'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::enableForeignKeyConstraints();

        MarketingContact::forgetMultipleMarketsSupport();

        parent::tearDown();
    }

    private function admin(string $role = 'marketing'): AdminUser
    {
        return AdminUser::create([
            'name' => 'Marketer ' . (++$this->seq), 'email' => 'm' . $this->seq . uniqid() . '@okelcor.test',
            'role' => $role, 'password' => Hash::make('secret-pass-123'),
            'is_active' => true, 'two_factor_confirmed_at' => now(),
        ]);
    }

    private function campaignWithRecipient(string $bodyHtml = '<p>Hi</p><a href="https://okelcor.com/shop">Shop</a>'): array
    {
        $contact = MarketingContact::create([
            'email' => 'buyer' . uniqid() . '@example.com', 'first_name' => 'Ana',
            'market' => 'croatia', 'status' => 'subscribed', 'unsubscribe_token' => Str::random(20),
        ]);

        $campaign = app(BulkEmailService::class)->createCampaign(
            subject: 'Test', bodyHtml: $bodyHtml, filters: [], createdBy: $this->admin()->id,
        );

        return [$campaign, $campaign->recipients()->first(), $contact];
    }

    public function test_recipients_get_tracking_tokens_at_snapshot_time(): void
    {
        [, $recipient] = $this->campaignWithRecipient();

        $this->assertNotNull($recipient->tracking_token);
        $this->assertSame(40, strlen($recipient->tracking_token));
    }

    public function test_the_sent_email_carries_the_pixel_and_signed_links_but_never_a_tracked_unsubscribe(): void
    {
        Mail::fake();

        [$campaign, $recipient, $contact] = $this->campaignWithRecipient(
            '<p>Hi [[FIRST_NAME]]</p><a href="https://okelcor.com/shop">Shop</a>'
            . '<a href="[[UNSUBSCRIBE_URL]]">Unsubscribe</a>'
        );

        (new SendBulkEmailCampaignJob($campaign->id))->handle();

        $token = $recipient->tracking_token;

        Mail::assertSent(BulkCampaignEmail::class, function (BulkCampaignEmail $mail) use ($token, $contact) {
            return str_contains($mail->bodyHtml, "/api/v1/campaign/open/{$token}.gif")
                && str_contains($mail->bodyHtml, "/api/v1/campaign/click/{$token}?u=")
                // The unsubscribe link stays direct — opting out must not
                // route through the tracker.
                && str_contains($mail->bodyHtml, "/marketing-contacts/unsubscribe/{$contact->unsubscribe_token}");
        });
    }

    public function test_the_pixel_records_opens_and_counts_repeats(): void
    {
        [, $recipient] = $this->campaignWithRecipient();
        $token = $recipient->tracking_token;

        $this->get("/api/v1/campaign/open/{$token}.gif")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/gif');
        $this->get("/api/v1/campaign/open/{$token}.gif")->assertOk();

        $recipient->refresh();
        $this->assertNotNull($recipient->opened_at);
        $this->assertSame(2, $recipient->open_count);

        // An unknown token still returns a pixel — never an error to a reader.
        $this->get('/api/v1/campaign/open/not-a-real-token.gif')->assertOk();
    }

    public function test_a_signed_click_redirects_and_counts_as_completion_and_open(): void
    {
        [, $recipient] = $this->campaignWithRecipient();
        $token   = $recipient->tracking_token;
        $tracker = app(CampaignTrackingService::class);
        $target  = 'https://okelcor.com/shop';

        $this->get("/api/v1/campaign/click/{$token}?u=" . urlencode($target) . '&s=' . $tracker->sign($token, $target))
            ->assertRedirect($target);

        $recipient->refresh();
        $this->assertNotNull($recipient->clicked_at);
        $this->assertSame(1, $recipient->click_count);
        $this->assertNotNull($recipient->opened_at, 'a click proves an open');

        // A tampered signature must NOT redirect to the attacker's target.
        $response = $this->get("/api/v1/campaign/click/{$token}?u=" . urlencode('https://evil.example') . '&s=bad');
        $this->assertStringNotContainsString('evil.example', (string) $response->headers->get('Location'));
        $this->assertSame(1, $recipient->fresh()->click_count);
    }

    public function test_the_scoreboard_scores_campaigns_and_marketers(): void
    {
        [$campaign, $recipient] = $this->campaignWithRecipient();

        // 1 delivered recipient who opened and clicked → 100% both → score 100.
        $recipient->forceFill([
            'status' => 'sent', 'opened_at' => now(), 'open_count' => 3,
            'clicked_at' => now(), 'click_count' => 1,
        ])->save();
        $campaign->update(['status' => 'completed', 'sent_count' => 1]);

        // An old, untracked campaign must report as untracked, not as zero.
        $old = BulkEmailCampaign::create([
            'subject' => 'Pre-tracking', 'body_html' => '<p>x</p>', 'filters' => [],
            'total_recipients' => 5, 'sent_count' => 5, 'status' => 'completed',
            'created_by' => $campaign->created_by,
        ]);

        $response = $this->actingAs($this->admin('marketing'), 'sanctum')
            ->getJson('/api/v1/admin/bulk-emails/scoreboard')
            ->assertOk();

        $rows = collect($response->json('data.campaigns'))->keyBy('id');

        $this->assertEquals(100, $rows[$campaign->id]['open_rate']);
        $this->assertEquals(100, $rows[$campaign->id]['completion_rate']);
        $this->assertSame(100, $rows[$campaign->id]['score']);
        $this->assertFalse($rows[$old->id]['tracked']);
        $this->assertNull($rows[$old->id]['score']);

        $marketer = $response->json('data.marketers.0');
        $this->assertSame(1, $marketer['campaigns']);
        $this->assertSame(100, $marketer['score']);

        // Roles without marketing.manage cannot read the scoreboard.
        $this->actingAs($this->admin('viewer'), 'sanctum')
            ->getJson('/api/v1/admin/bulk-emails/scoreboard')
            ->assertForbidden();
    }
}

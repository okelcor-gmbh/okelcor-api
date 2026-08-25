<?php

namespace Tests\Feature;

use App\Jobs\SendBulkEmailCampaignJob;
use App\Mail\BulkCampaignEmail;
use App\Models\AdminUser;
use App\Models\BulkEmailCampaign;
use App\Models\MarketingContact;
use App\Services\ArticleHtmlSanitizer;
use App\Services\BulkEmailService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regressions from the marketer's three reports (Aug 2026):
 *
 *  1. A test send to a single address 500'd instead of sending, or instead
 *     of explaining what was wrong with the payload.
 *  2. Sending a market campaign showed the marketer an error while the
 *     emails demonstrably went out — the sync queue driver ran the whole
 *     send inside the HTTP request, which outlived the web server timeout.
 *  3. The admin panel's system-health section 403'd for every role below
 *     super_admin since security.view was hardened.
 *
 * Same table-per-test pattern as BulkEmailCampaignTest (no RefreshDatabase —
 * the full migration set includes MySQL-only legacy migrations).
 */
class CampaignSendReliabilityTest extends TestCase
{
    private int $adminSeq = 0;

    private const FULL_DOCUMENT_BODY = <<<'HTML'
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml">
<head><meta charset="utf-8"><style>.email-container{width:600px}</style></head>
<body style="margin:0">
<!--[if mso]><table role="presentation"><tr><td><![endif]-->
<table class="email-container" width="600" cellpadding="0" cellspacing="0">
  <tr><td bgcolor="#0B1C2C"><span style="color:#ffffff">Okelcor GmbH</span></td></tr>
  <tr><td><a href="[[UNSUBSCRIBE_URL]]">Unsubscribe</a></td></tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</body>
</html>
HTML;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();

        foreach ([
            'bulk_email_campaign_recipients',
            'bulk_email_campaigns',
            'marketing_contact_markets',
            'marketing_contacts',
            'admin_security_events',
            'admin_users',
        ] as $table) {
            Schema::dropIfExists($table);
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
            $table->string('last_name', 100)->nullable();
            $table->string('company', 150)->nullable();
            $table->string('country', 100)->nullable();
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
            $table->unique(['contact_id', 'market']);
        });

        Schema::create('bulk_email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('subject', 255);
            $table->longText('body_html');
            $table->json('blocks')->nullable();
            $table->json('theme')->nullable();
            $table->longText('body_text')->nullable();
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
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'contact_id']);
        });

        Schema::enableForeignKeyConstraints();

        MarketingContact::forgetMultipleMarketsSupport();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'bulk_email_campaign_recipients',
            'bulk_email_campaigns',
            'marketing_contact_markets',
            'marketing_contacts',
            'admin_security_events',
            'admin_users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();

        MarketingContact::forgetMultipleMarketsSupport();

        parent::tearDown();
    }

    private function admin(string $role = 'order_manager'): AdminUser
    {
        return AdminUser::create([
            'name'                    => 'Test Admin ' . (++$this->adminSeq),
            'email'                   => 'admin' . uniqid() . '@test.com',
            'role'                    => $role,
            'password'                => Hash::make('secret-pass-123'),
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function contact(string $email, string $market = 'croatia'): MarketingContact
    {
        return MarketingContact::create([
            'email'             => $email,
            'market'            => $market,
            'status'            => 'subscribed',
            'unsubscribe_token' => Str::random(20),
        ]);
    }

    // -------------------------------------------------------------------------
    // 1. Test send to a single address
    // -------------------------------------------------------------------------

    public function test_test_send_accepts_a_pasted_full_document_with_a_null_blocks_field(): void
    {
        Mail::fake();

        // `blocks: null` is what a client serializes when the block editor was
        // never used — it must not fail the `array` rule.
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/bulk-emails/test-send', [
                'to'        => 'marketer@okelcor.com',
                'subject'   => 'Croatia campaign check',
                'blocks'    => null,
                'theme'     => null,
                'body_html' => self::FULL_DOCUMENT_BODY,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Test email sent to marketer@okelcor.com.');

        Mail::assertSent(BulkCampaignEmail::class, function (BulkCampaignEmail $mail) {
            return $mail->hasTo('marketer@okelcor.com')
                && str_starts_with($mail->subjectLine, '[TEST] ');
        });
    }

    public function test_test_send_with_empty_blocks_and_no_body_is_a_422_not_a_500(): void
    {
        Mail::fake();

        // An empty block list with no pasted body used to be able to reach
        // the render path and 500 on the missing `body_html` key. Whichever
        // layer catches it (validation treats `[]` as absent, and
        // sanitizePastedBody backstops it), the marketer must get a 422 that
        // names the problem, never a generic 500.
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/bulk-emails/test-send', [
                'to'      => 'marketer@okelcor.com',
                'subject' => 'Empty campaign',
                'blocks'  => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body_html']);

        Mail::assertNothingSent();
    }

    public function test_an_unprocessable_pasted_body_is_a_422_on_every_campaign_endpoint(): void
    {
        Mail::fake();

        $this->mock(ArticleHtmlSanitizer::class)
            ->shouldReceive('sanitize')
            ->andThrow(new \RuntimeException('purifier failed'));

        $payload = ['body_html' => '<p>whatever</p>'];
        $admin   = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/bulk-emails/test-send', $payload + [
                'to' => 'marketer@okelcor.com', 'subject' => 'S',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'body_unprocessable');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/bulk-emails/preview', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'body_unprocessable');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/bulk-emails', $payload + ['subject' => 'S'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'body_unprocessable');

        Mail::assertNothingSent();
        $this->assertSame(0, BulkEmailCampaign::count());
    }

    // -------------------------------------------------------------------------
    // 2. Market campaign send must answer before the emails go out
    // -------------------------------------------------------------------------

    public function test_a_market_campaign_on_the_sync_driver_is_sent_after_the_response(): void
    {
        config(['queue.default' => 'sync']);
        Bus::fake([SendBulkEmailCampaignJob::class]);

        $this->contact('buyer@zagreb-tyres.hr');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/bulk-emails', [
                'subject'   => 'Croatia market launch',
                'body_html' => '<p>Pozdrav</p>',
                'filters'   => ['market' => 'croatia'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.total_recipients', 1);

        // The whole point: on sync the send may not run inside the request,
        // or a market-sized list times the request out at the web server
        // while the emails still go — the marketer sees an error for a send
        // that succeeded.
        Bus::assertDispatchedAfterResponse(SendBulkEmailCampaignJob::class);
    }

    public function test_a_real_queue_driver_still_queues_the_job_normally(): void
    {
        config(['queue.default' => 'database']);
        Bus::fake([SendBulkEmailCampaignJob::class]);

        $this->contact('buyer@zagreb-tyres.hr');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/bulk-emails', [
                'subject'   => 'Croatia market launch',
                'body_html' => '<p>Pozdrav</p>',
                'filters'   => ['market' => 'croatia'],
            ])
            ->assertCreated();

        Bus::assertDispatched(SendBulkEmailCampaignJob::class);
        Bus::assertNotDispatchedAfterResponse(SendBulkEmailCampaignJob::class);
    }

    public function test_a_deleted_contact_costs_one_recipient_not_the_rest_of_the_campaign(): void
    {
        Mail::fake();

        $kept = $this->contact('kept@zagreb-tyres.hr');
        $this->contact('gone@zagreb-tyres.hr');

        $campaign = app(BulkEmailService::class)->createCampaign(
            subject: 'Croatia',
            bodyHtml: '<p>Hi</p>',
            filters: ['market' => 'croatia'],
            createdBy: $this->admin()->id,
        );

        // Orphan one recipient row the way a hard-deleted contact would on an
        // FK-less production table. (The test schema's FK would cascade, so
        // it is suspended for the surgery.)
        Schema::disableForeignKeyConstraints();
        DB::table('marketing_contacts')->where('email', 'gone@zagreb-tyres.hr')->delete();
        Schema::enableForeignKeyConstraints();

        (new SendBulkEmailCampaignJob($campaign->id))->handle();

        $campaign->refresh();

        $this->assertSame('completed', $campaign->status);
        $this->assertSame(1, $campaign->sent_count);
        $this->assertSame(1, $campaign->failed_count);
        $this->assertDatabaseHas('bulk_email_campaign_recipients', [
            'email'  => 'kept@zagreb-tyres.hr',
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('bulk_email_campaign_recipients', [
            'email'  => 'gone@zagreb-tyres.hr',
            'status' => 'failed',
        ]);

        Mail::assertSent(BulkCampaignEmail::class, 1);
    }

    // -------------------------------------------------------------------------
    // 3. System health access + the queue check that names this outage
    // -------------------------------------------------------------------------

    public function test_an_admin_can_load_system_health_again(): void
    {
        $this->actingAs($this->admin('admin'), 'sanctum')
            ->getJson('/api/v1/admin/system/health')
            ->assertOk()
            ->assertJsonStructure(['data' => ['overall', 'summary', 'groups' => ['queue']]]);

        $this->actingAs($this->admin('admin'), 'sanctum')
            ->getJson('/api/v1/admin/system/errors')
            ->assertOk();

        $this->actingAs($this->admin('super_admin'), 'sanctum')
            ->getJson('/api/v1/admin/system/health')
            ->assertOk();
    }

    public function test_roles_without_system_view_still_cannot_load_system_health(): void
    {
        foreach (['marketing', 'order_manager', 'viewer'] as $role) {
            $this->actingAs($this->admin($role), 'sanctum')
                ->getJson('/api/v1/admin/system/health')
                ->assertForbidden();
        }
    }

    public function test_the_sync_queue_driver_is_flagged_on_the_health_report(): void
    {
        config(['queue.default' => 'sync']);

        $response = $this->actingAs($this->admin('admin'), 'sanctum')
            ->getJson('/api/v1/admin/system/health')
            ->assertOk();

        $driverCheck = collect($response->json('data.groups.queue'))
            ->firstWhere('key', 'queue_driver');

        $this->assertNotNull($driverCheck, 'queue_driver check missing from the queue group');
        $this->assertSame('warning', $driverCheck['status']);
        $this->assertStringContainsString('sync', $driverCheck['message']);
    }
}

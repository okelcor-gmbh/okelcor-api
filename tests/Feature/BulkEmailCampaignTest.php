<?php

namespace Tests\Feature;

use App\Jobs\SendBulkEmailCampaignJob;
use App\Mail\BulkCampaignEmail;
use App\Models\AdminUser;
use App\Models\BulkEmailCampaign;
use App\Models\MarketingContact;
use App\Services\MarketingContactImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Marketing contact import + bulk email campaigns.
 *
 * Does NOT use RefreshDatabase: the full migration set includes a MySQL-only
 * legacy migration (`ALTER TABLE ... MODIFY COLUMN`) that sqlite can't run.
 * Instead this creates only the tables these tests touch, same pattern as
 * NewsletterSubscriptionTest.
 */
class BulkEmailCampaignTest extends TestCase
{
    private int $adminSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['bulk_email_campaign_recipients', 'bulk_email_campaigns', 'marketing_contacts', 'admin_users'] as $table) {
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

        Schema::create('marketing_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('company', 150)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('vat_id', 50)->nullable();
            $table->string('labels', 255)->nullable();
            $table->string('source', 100)->nullable();
            $table->string('status', 20)->default('unknown');
            $table->string('unsubscribe_token', 64)->unique()->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bulk_email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('subject', 255);
            $table->longText('body_html');
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
    }

    protected function tearDown(): void
    {
        foreach (['bulk_email_campaign_recipients', 'bulk_email_campaigns', 'marketing_contacts', 'admin_users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function admin(string $role = 'order_manager'): AdminUser
    {
        $admin = AdminUser::create([
            'name'                    => 'Test Admin ' . (++$this->adminSeq),
            'email'                   => 'admin' . uniqid() . '@test.com',
            'role'                    => $role,
            'password'                => Hash::make('secret-pass-123'),
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);

        return $admin;
    }

    // -------------------------------------------------------------------------
    // Import
    // -------------------------------------------------------------------------

    public function test_import_skips_invalid_emails_and_maps_status(): void
    {
        $csv = "First Name,Last Name,Email 1,Phone 1,Company,Address 1 - Country,VAT ID,Labels,Source,Email subscriber status\n"
            . "Jane,Doe,jane@example.com,+49123,Acme GmbH,DE,DE123,VIP,Form,Subscribed\n"
            . "No,Email,,,,,,,,\n"
            . "Bob,Smith,bob@example.com,,,US,,,Site,Unsubscribed\n"
            . "Carl,Jones,carl@example.com,,,US,,,Site,Never subscribed\n";

        $path = tempnam(sys_get_temp_dir(), 'contacts') . '.csv';
        file_put_contents($path, $csv);

        $result = (new MarketingContactImportService())->import($path);

        $this->assertSame(3, $result['imported']);
        $this->assertSame(1, $result['skipped_no_email']);
        $this->assertSame(1, $result['subscribed']);
        $this->assertSame(1, $result['unsubscribed']);

        $this->assertSame('subscribed', MarketingContact::where('email', 'jane@example.com')->value('status'));
        $this->assertSame('unsubscribed', MarketingContact::where('email', 'bob@example.com')->value('status'));
        $this->assertSame('unknown', MarketingContact::where('email', 'carl@example.com')->value('status'));
        $this->assertNotEmpty(MarketingContact::where('email', 'jane@example.com')->value('unsubscribe_token'));

        unlink($path);
    }

    public function test_reimport_does_not_resubscribe_an_unsubscribed_contact(): void
    {
        MarketingContact::create([
            'email'             => 'bob@example.com',
            'status'            => 'unsubscribed',
            'unsubscribe_token' => 'existing-token',
        ]);

        $csv = "First Name,Last Name,Email 1,Phone 1,Company,Address 1 - Country,VAT ID,Labels,Source,Email subscriber status\n"
            . "Bob,Smith,bob@example.com,,,US,,,Site,Subscribed\n";

        $path = tempnam(sys_get_temp_dir(), 'contacts') . '.csv';
        file_put_contents($path, $csv);

        (new MarketingContactImportService())->import($path);

        $this->assertSame('unsubscribed', MarketingContact::where('email', 'bob@example.com')->value('status'));

        unlink($path);
    }

    public function test_import_accepts_alternate_3_column_header_format(): void
    {
        // Real-world example: UAE contacts export uses "Company name",
        // "Bussines type", "Email" instead of the Wix header set.
        $csv = "Company name ,Bussines type ,Email\n"
            . "ABC Cargo,Logistics,abc@abccargo.ae\n"
            . "Abrar Tyres,Supplier,info@abrartyres.com\n";

        $path = tempnam(sys_get_temp_dir(), 'contacts') . '.csv';
        file_put_contents($path, $csv);

        $result = (new MarketingContactImportService())->import($path);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(0, $result['skipped_no_email']);

        $contact = MarketingContact::where('email', 'abc@abccargo.ae')->first();
        $this->assertNotNull($contact);
        $this->assertSame('ABC Cargo', $contact->company);
        $this->assertSame('Logistics', $contact->labels);
        $this->assertSame('unknown', $contact->status);

        unlink($path);
    }

    public function test_import_throws_when_no_email_column_present(): void
    {
        $csv = "Name,Phone\nJane,123\n";

        $path = tempnam(sys_get_temp_dir(), 'contacts') . '.csv';
        file_put_contents($path, $csv);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No email column found');

        try {
            (new MarketingContactImportService())->import($path);
        } finally {
            unlink($path);
        }
    }

    // -------------------------------------------------------------------------
    // Permissions
    // -------------------------------------------------------------------------

    public function test_role_without_marketing_permission_is_forbidden(): void
    {
        $this->actingAs($this->admin('viewer'), 'sanctum')
            ->getJson('/api/v1/admin/marketing-contacts')
            ->assertStatus(403);
    }

    public function test_order_manager_can_list_contacts(): void
    {
        MarketingContact::create(['email' => 'a@example.com', 'status' => 'subscribed', 'unsubscribe_token' => 't1']);

        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->getJson('/api/v1/admin/marketing-contacts')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // -------------------------------------------------------------------------
    // Campaign creation
    // -------------------------------------------------------------------------

    public function test_creating_campaign_excludes_unsubscribed_and_dispatches_job(): void
    {
        Queue::fake();

        MarketingContact::create(['email' => 'sub@example.com', 'status' => 'subscribed', 'unsubscribe_token' => 't1']);
        MarketingContact::create(['email' => 'unknown@example.com', 'status' => 'unknown', 'unsubscribe_token' => 't2']);
        MarketingContact::create(['email' => 'gone@example.com', 'status' => 'unsubscribed', 'unsubscribe_token' => 't3']);

        $response = $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/bulk-emails', [
                'subject'   => 'Hello contacts',
                'body_html' => '<p>Hi there</p><script>alert(1)</script>',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.total_recipients', 2)
            ->assertJsonPath('data.status', 'queued');

        $campaign = BulkEmailCampaign::first();
        $this->assertSame(2, $campaign->recipients()->count());
        $this->assertDatabaseMissing('bulk_email_campaign_recipients', ['email' => 'gone@example.com']);

        // Script tag must be stripped by the HTML sanitizer.
        $this->assertStringNotContainsString('<script>', $campaign->body_html);

        Queue::assertPushed(SendBulkEmailCampaignJob::class, fn ($job) => $job->campaignId === $campaign->id);
    }

    public function test_creating_campaign_with_no_matching_recipients_returns_422(): void
    {
        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/bulk-emails', [
                'subject'   => 'Hello',
                'body_html' => '<p>Hi</p>',
            ])
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Sending job
    // -------------------------------------------------------------------------

    public function test_send_job_emails_pending_recipients_and_marks_campaign_completed(): void
    {
        Mail::fake();

        $contact = MarketingContact::create(['email' => 'sub@example.com', 'status' => 'subscribed', 'unsubscribe_token' => 'tok-123']);

        $campaign = BulkEmailCampaign::create([
            'subject'          => 'Promo',
            'body_html'        => '<p>Deal</p>',
            'total_recipients' => 1,
            'status'           => 'queued',
            'created_by'       => $this->admin()->id,
        ]);

        $campaign->recipients()->create([
            'contact_id' => $contact->id,
            'email'      => $contact->email,
            'status'     => 'pending',
        ]);

        (new SendBulkEmailCampaignJob($campaign->id))->handle();

        $campaign->refresh();
        $this->assertSame('completed', $campaign->status);
        $this->assertSame(1, $campaign->sent_count);
        $this->assertSame(0, $campaign->failed_count);
        $this->assertSame('sent', $campaign->recipients()->first()->status);

        Mail::assertSent(BulkCampaignEmail::class, function ($mail) use ($contact) {
            return $mail->hasTo($contact->email)
                && str_contains($mail->unsubscribeUrl, $contact->unsubscribe_token);
        });
    }

    // -------------------------------------------------------------------------
    // Unsubscribe
    // -------------------------------------------------------------------------

    public function test_unsubscribe_endpoint_flips_status(): void
    {
        $contact = MarketingContact::create(['email' => 'sub@example.com', 'status' => 'subscribed', 'unsubscribe_token' => 'unique-tok']);

        $this->get('/api/v1/marketing-contacts/unsubscribe/unique-tok')
            ->assertRedirect();

        $this->assertSame('unsubscribed', $contact->fresh()->status);
    }
}

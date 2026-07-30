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
use Illuminate\Support\Str;
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

        // Disabled around the drop/create dance: if a RefreshDatabase-based
        // test ran earlier in the same process, the full migrated schema is
        // still present and would block dropping these tables in isolation
        // (e.g. other tables' FKs into admin_users beyond this test's own).
        Schema::disableForeignKeyConstraints();

        foreach ([
            'bulk_email_campaign_recipients',
            'bulk_email_campaigns',
            'marketing_contact_markets',
            'marketing_contacts',
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

        Schema::create('marketing_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('company', 150)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('market', 50)->nullable();
            $table->string('vat_id', 50)->nullable();
            $table->string('labels', 255)->nullable();
            $table->string('source', 100)->nullable();
            $table->string('status', 20)->default('unknown');
            $table->string('unsubscribe_token', 64)->unique()->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_contact_markets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('marketing_contacts')->cascadeOnDelete();
            $table->string('market', 50);
            $table->timestamps();
            $table->unique(['contact_id', 'market']);
            $table->index('market');
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

        Schema::enableForeignKeyConstraints();

        // The membership table is created/dropped inside this process, so the
        // model's memoized table check has to be invalidated both ways.
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
            'admin_users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();

        MarketingContact::forgetMultipleMarketsSupport();

        parent::tearDown();
    }

    /**
     * Creates a contact already holding several markets — the state that was
     * impossible before `marketing_contact_markets` existed.
     */
    private function contactInMarkets(string $email, array $markets, array $attributes = []): MarketingContact
    {
        $contact = MarketingContact::create(array_merge([
            'email'             => $email,
            'market'            => $markets[0],
            'unsubscribe_token' => Str::random(20),
        ], $attributes));

        $contact->addMarkets($markets);

        return $contact->fresh();
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
    // Market segmentation
    // -------------------------------------------------------------------------

    public function test_import_applies_the_default_market_to_every_row(): void
    {
        $csv = "Email,Company\nkontakt@kupigume.hr,KUPI GUME\ninfo@cijenaguma.hr,CijenaGuma\n";
        $path = tempnam(sys_get_temp_dir(), 'contacts') . '.csv';
        file_put_contents($path, $csv);

        (new MarketingContactImportService())->import($path, 'croatia');

        $this->assertSame('croatia', MarketingContact::where('email', 'kontakt@kupigume.hr')->value('market'));
        $this->assertSame('croatia', MarketingContact::where('email', 'info@cijenaguma.hr')->value('market'));

        unlink($path);
    }

    public function test_import_prefers_a_market_column_in_the_csv_over_the_default(): void
    {
        $csv = "Email,Market\nfirst@example.com,Asia\nsecond@example.com,\n";
        $path = tempnam(sys_get_temp_dir(), 'contacts') . '.csv';
        file_put_contents($path, $csv);

        (new MarketingContactImportService())->import($path, 'croatia');

        $this->assertSame('Asia', MarketingContact::where('email', 'first@example.com')->value('market'));
        $this->assertSame('croatia', MarketingContact::where('email', 'second@example.com')->value('market'));

        unlink($path);
    }

    public function test_admin_can_manually_add_a_contact_to_a_market(): void
    {
        $response = $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/marketing-contacts', [
                'email'   => 'New.Lead@Example.com',
                'market'  => 'Croatia',
                'company' => 'Zagreb Tyres d.o.o.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'new.lead@example.com')
            ->assertJsonPath('data.market', 'croatia');

        $this->assertDatabaseHas('marketing_contacts', ['email' => 'new.lead@example.com', 'market' => 'croatia']);
    }

    public function test_manual_add_rejects_a_duplicate_email(): void
    {
        MarketingContact::create(['email' => 'dup@example.com', 'market' => 'asia', 'unsubscribe_token' => 't1']);

        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/marketing-contacts', ['email' => 'dup@example.com', 'market' => 'croatia'])
            ->assertStatus(422);
    }

    public function test_duplicate_add_reports_the_market_the_contact_is_already_in(): void
    {
        $existing = MarketingContact::create(['email' => 'dup@example.com', 'market' => 'test', 'unsubscribe_token' => 't1']);

        $response = $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/marketing-contacts', ['email' => 'Dup@Example.com', 'market' => 'Germany']);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'contact_exists')
            ->assertJsonPath('data.existing_contact.id', $existing->id)
            ->assertJsonPath('data.existing_contact.market', 'test')
            ->assertJsonPath('data.can_move', true)
            ->assertJsonPath('data.target_market', 'germany');

        // Still shaped like a normal validation failure for existing clients.
        $this->assertNotEmpty($response->json('errors.email'));
    }

    public function test_duplicate_add_into_the_same_market_is_not_offered_as_a_move(): void
    {
        MarketingContact::create(['email' => 'dup@example.com', 'market' => 'germany', 'unsubscribe_token' => 't1']);

        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/marketing-contacts', ['email' => 'dup@example.com', 'market' => 'Germany'])
            ->assertStatus(422)
            ->assertJsonPath('data.can_move', false);
    }

    // -------------------------------------------------------------------------
    // Move between markets
    // -------------------------------------------------------------------------

    public function test_move_market_by_contact_ids(): void
    {
        $a = MarketingContact::create(['email' => 'a@example.com', 'market' => 'test', 'unsubscribe_token' => 't1']);
        $b = MarketingContact::create(['email' => 'b@example.com', 'market' => 'test', 'unsubscribe_token' => 't2']);
        $c = MarketingContact::create(['email' => 'c@example.com', 'market' => 'asia', 'unsubscribe_token' => 't3']);

        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/marketing-contacts/move-market', [
                'contact_ids' => [$a->id, $b->id],
                'to_market'   => 'Germany',
            ])
            ->assertOk()
            ->assertJsonPath('data.moved', 2)
            ->assertJsonPath('data.to_market', 'germany');

        $this->assertSame('germany', $a->fresh()->market);
        $this->assertSame('germany', $b->fresh()->market);
        $this->assertSame('asia', $c->fresh()->market, 'unselected contact must not move');
    }

    public function test_move_market_by_email(): void
    {
        $contact = MarketingContact::create(['email' => 'marketer@example.com', 'market' => 'test', 'unsubscribe_token' => 't1']);

        $response = $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/marketing-contacts/move-market', [
                'emails'    => ['Marketer@Example.com', 'nobody@example.com'],
                'to_market' => 'germany',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.moved', 1)
            ->assertJsonPath('data.not_found', ['nobody@example.com']);

        $this->assertSame('germany', $contact->fresh()->market);
        $this->assertDatabaseMissing('marketing_contacts', ['email' => 'nobody@example.com']);
    }

    public function test_move_market_empties_a_whole_market(): void
    {
        MarketingContact::create(['email' => 'a@example.com', 'market' => 'test', 'unsubscribe_token' => 't1']);
        MarketingContact::create(['email' => 'b@example.com', 'market' => 'test', 'unsubscribe_token' => 't2']);
        MarketingContact::create(['email' => 'c@example.com', 'market' => 'asia', 'unsubscribe_token' => 't3']);

        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/marketing-contacts/move-market', [
                'from_market' => 'TEST',
                'to_market'   => 'germany',
            ])
            ->assertOk()
            ->assertJsonPath('data.moved', 2);

        $this->assertSame(0, MarketingContact::where('market', 'test')->count());
        $this->assertSame(2, MarketingContact::where('market', 'germany')->count());
        $this->assertSame(1, MarketingContact::where('market', 'asia')->count());
    }

    public function test_move_market_preserves_unsubscribed_status(): void
    {
        $contact = MarketingContact::create([
            'email'             => 'optout@example.com',
            'market'            => 'test',
            'status'            => 'unsubscribed',
            'unsubscribe_token' => 't1',
        ]);

        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/marketing-contacts/move-market', [
                'contact_ids' => [$contact->id],
                'to_market'   => 'germany',
            ])
            ->assertOk();

        $fresh = $contact->fresh();
        $this->assertSame('germany', $fresh->market);
        $this->assertSame('unsubscribed', $fresh->status, 'a move must never re-subscribe an opted-out contact');
        $this->assertSame('t1', $fresh->unsubscribe_token);
    }

    public function test_move_market_counts_contacts_already_in_the_target(): void
    {
        $a = MarketingContact::create(['email' => 'a@example.com', 'market' => 'germany', 'unsubscribe_token' => 't1']);
        $b = MarketingContact::create(['email' => 'b@example.com', 'market' => 'test', 'unsubscribe_token' => 't2']);

        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/marketing-contacts/move-market', [
                'contact_ids' => [$a->id, $b->id],
                'to_market'   => 'germany',
            ])
            ->assertOk()
            ->assertJsonPath('data.moved', 1)
            ->assertJsonPath('data.already_in_place', 1);
    }

    public function test_move_market_requires_a_selector(): void
    {
        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/marketing-contacts/move-market', ['to_market' => 'germany'])
            ->assertStatus(422);
    }

    public function test_move_market_requires_marketing_permission(): void
    {
        $contact = MarketingContact::create(['email' => 'a@example.com', 'market' => 'test', 'unsubscribe_token' => 't1']);

        $this->actingAs($this->admin('viewer'), 'sanctum')
            ->postJson('/api/v1/admin/marketing-contacts/move-market', [
                'contact_ids' => [$contact->id],
                'to_market'   => 'germany',
            ])
            ->assertStatus(403);

        $this->assertSame('test', $contact->fresh()->market);
    }

    public function test_move_with_from_market_keeps_the_contacts_other_markets(): void
    {
        $contact = $this->contactInMarkets('multi@example.com', ['test', 'asia']);

        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/marketing-contacts/move-market', [
                'contact_ids' => [$contact->id],
                'from_market' => 'test',
                'to_market'   => 'germany',
            ])
            ->assertOk()
            ->assertJsonPath('data.moved', 1);

        // Left `test` for `germany`, but `asia` was never part of the move.
        $this->assertEqualsCanonicalizing(['asia', 'germany'], $contact->fresh()->marketNames());
    }

    public function test_move_without_from_market_replaces_all_markets(): void
    {
        $contact = $this->contactInMarkets('multi@example.com', ['test', 'asia']);

        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/marketing-contacts/move-market', [
                'contact_ids' => [$contact->id],
                'to_market'   => 'germany',
            ])
            ->assertOk();

        $this->assertSame(['germany'], $contact->fresh()->marketNames());
    }

    // -------------------------------------------------------------------------
    // Belonging to several markets at once
    // -------------------------------------------------------------------------

    public function test_add_to_market_keeps_the_existing_market(): void
    {
        $contact = MarketingContact::create(['email' => 'her@example.com', 'market' => 'test', 'unsubscribe_token' => 't1']);

        $response = $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/marketing-contacts/add-to-market', [
                'contact_ids' => [$contact->id],
                'to_market'   => 'Germany',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.added', 1)
            ->assertJsonPath('data.to_market', 'germany');

        // The whole point of the feature: in TEST *and* Germany at once.
        $this->assertSame(['test', 'germany'], $contact->fresh()->marketNames());

        // Primary market unchanged — adding a market must not silently move it.
        $this->assertSame('test', $contact->fresh()->market);
    }

    public function test_add_to_market_is_idempotent(): void
    {
        $contact = $this->contactInMarkets('her@example.com', ['test', 'germany']);

        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/marketing-contacts/add-to-market', [
                'contact_ids' => [$contact->id],
                'to_market'   => 'germany',
            ])
            ->assertOk()
            ->assertJsonPath('data.added', 0)
            ->assertJsonPath('data.already_in_place', 1);

        $this->assertSame(['test', 'germany'], $contact->fresh()->marketNames());
    }

    public function test_add_to_market_by_email_and_whole_market(): void
    {
        $a = MarketingContact::create(['email' => 'a@example.com', 'market' => 'asia', 'unsubscribe_token' => 't1']);
        $b = MarketingContact::create(['email' => 'b@example.com', 'market' => 'asia', 'unsubscribe_token' => 't2']);
        $c = MarketingContact::create(['email' => 'c@example.com', 'market' => 'test', 'unsubscribe_token' => 't3']);

        // Whole `asia` market plus one contact picked out by email.
        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/marketing-contacts/add-to-market', [
                'from_market' => 'asia',
                'emails'      => ['C@Example.com'],
                'to_market'   => 'germany',
            ])
            ->assertOk()
            ->assertJsonPath('data.added', 3);

        foreach ([$a, $b, $c] as $contact) {
            $this->assertContains('germany', $contact->fresh()->marketNames());
        }
        $this->assertSame(['asia', 'germany'], $a->fresh()->marketNames());
        $this->assertSame(['test', 'germany'], $c->fresh()->marketNames());
    }

    public function test_contact_payload_exposes_all_markets_and_a_primary(): void
    {
        $this->contactInMarkets('multi@example.com', ['test', 'germany']);

        $response = $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->getJson('/api/v1/admin/marketing-contacts');

        $response->assertOk()
            ->assertJsonPath('data.0.market', 'test')
            ->assertJsonPath('data.0.markets', ['test', 'germany']);
    }

    public function test_a_contact_in_two_markets_appears_in_both_market_lists(): void
    {
        $this->contactInMarkets('multi@example.com', ['test', 'germany']);
        MarketingContact::create(['email' => 'solo@example.com', 'market' => 'asia', 'unsubscribe_token' => 't9']);

        foreach (['test', 'germany'] as $market) {
            $response = $this->actingAs($this->admin('order_manager'), 'sanctum')
                ->getJson("/api/v1/admin/marketing-contacts?market={$market}");

            $response->assertOk()->assertJsonCount(1, 'data');
            $this->assertSame('multi@example.com', $response->json('data.0.email'), "missing from {$market} list");
        }
    }

    public function test_markets_endpoint_counts_a_shared_contact_under_each_market(): void
    {
        $this->contactInMarkets('multi@example.com', ['test', 'germany']);
        MarketingContact::create(['email' => 'solo@example.com', 'market' => 'germany', 'unsubscribe_token' => 't9']);

        $response = $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->getJson('/api/v1/admin/marketing-contacts/markets');

        $response->assertOk();
        $data = collect($response->json('data'))->keyBy('market');
        $this->assertSame(1, $data['test']['contact_count']);
        $this->assertSame(2, $data['germany']['contact_count']);
    }

    public function test_can_create_a_contact_in_several_markets_at_once(): void
    {
        $response = $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/marketing-contacts', [
                'email'   => 'new@example.com',
                'markets' => ['Germany', 'TEST'],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.markets', ['germany', 'test'])
            ->assertJsonPath('data.market', 'germany');
    }

    public function test_duplicate_add_offers_both_add_and_move(): void
    {
        $existing = $this->contactInMarkets('dup@example.com', ['test']);

        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/marketing-contacts', ['email' => 'dup@example.com', 'market' => 'Germany'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'contact_exists')
            ->assertJsonPath('data.existing_markets', ['test'])
            ->assertJsonPath('data.can_add_market', true)
            ->assertJsonPath('data.can_move', true)
            ->assertJsonPath('data.existing_contact.id', $existing->id);
    }

    public function test_remove_from_market_leaves_the_contact_in_its_others(): void
    {
        $contact = $this->contactInMarkets('multi@example.com', ['test', 'germany']);

        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/marketing-contacts/remove-from-market', [
                'contact_ids' => [$contact->id],
                'market'      => 'test',
            ])
            ->assertOk()
            ->assertJsonPath('data.removed', 1);

        $fresh = $contact->fresh();
        $this->assertSame(['germany'], $fresh->marketNames());
        // Primary market followed the removal instead of dangling on `test`.
        $this->assertSame('germany', $fresh->market);
        $this->assertDatabaseHas('marketing_contacts', ['email' => 'multi@example.com']);
    }

    public function test_remove_from_market_refuses_to_strip_a_contacts_last_market(): void
    {
        $contact = MarketingContact::create(['email' => 'solo@example.com', 'market' => 'test', 'unsubscribe_token' => 't1']);

        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/marketing-contacts/remove-from-market', [
                'contact_ids' => [$contact->id],
                'market'      => 'test',
            ])
            ->assertOk()
            ->assertJsonPath('data.removed', 0)
            ->assertJsonPath('data.skipped_last_market', ['solo@example.com']);

        $this->assertSame(['test'], $contact->fresh()->marketNames());
    }

    public function test_remove_from_market_clears_a_whole_market_when_no_contacts_named(): void
    {
        $shared = $this->contactInMarkets('multi@example.com', ['test', 'germany']);
        $solo   = MarketingContact::create(['email' => 'solo@example.com', 'market' => 'test', 'unsubscribe_token' => 't2']);

        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/marketing-contacts/remove-from-market', ['market' => 'test'])
            ->assertOk()
            ->assertJsonPath('data.removed', 1)
            ->assertJsonPath('data.skipped_last_market', ['solo@example.com']);

        $this->assertSame(['germany'], $shared->fresh()->marketNames());
        $this->assertSame(['test'], $solo->fresh()->marketNames(), 'the only-market contact must survive');
    }

    public function test_campaign_reaches_a_contact_via_a_secondary_market(): void
    {
        Queue::fake();

        // In `test` primarily, added to `germany` — a germany campaign must
        // include them, which a primary-column-only filter would have missed.
        $this->contactInMarkets('multi@example.com', ['test', 'germany'], ['status' => 'subscribed']);
        MarketingContact::create(['email' => 'other@example.com', 'market' => 'asia', 'status' => 'subscribed', 'unsubscribe_token' => 't9']);

        $response = $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/bulk-emails', [
                'subject'   => 'Hallo Deutschland',
                'body_html' => '<p>Hi</p>',
                'filters'   => ['market' => 'germany'],
            ]);

        $response->assertCreated()->assertJsonPath('data.total_recipients', 1);

        $this->assertDatabaseHas('bulk_email_campaign_recipients', ['email' => 'multi@example.com']);
        $this->assertDatabaseMissing('bulk_email_campaign_recipients', ['email' => 'other@example.com']);
    }

    public function test_campaign_targeting_two_markets_emails_a_shared_contact_only_once(): void
    {
        Queue::fake();

        $this->contactInMarkets('multi@example.com', ['test', 'germany'], ['status' => 'subscribed']);

        $response = $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/bulk-emails', [
                'subject'   => 'Both markets',
                'body_html' => '<p>Hi</p>',
                'filters'   => ['markets' => ['test', 'germany']],
            ]);

        $response->assertCreated()->assertJsonPath('data.total_recipients', 1);

        $this->assertSame(1, \DB::table('bulk_email_campaign_recipients')
            ->where('email', 'multi@example.com')->count());
    }

    /**
     * Deploy-order safety: this code can reach production before
     * `marketing_contact_markets` is migrated. Everything must degrade to the
     * old single-column behaviour instead of 500-ing the contact list.
     */
    public function test_contacts_still_work_when_the_membership_table_is_missing(): void
    {
        MarketingContact::create(['email' => 'a@example.com', 'market' => 'asia', 'unsubscribe_token' => 't1']);

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('marketing_contact_markets');
        Schema::enableForeignKeyConstraints();
        MarketingContact::forgetMultipleMarketsSupport();

        $admin = $this->admin('order_manager');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/marketing-contacts?market=asia')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.markets', ['asia']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/marketing-contacts/markets')
            ->assertOk()
            ->assertJsonPath('data.0.market', 'asia');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/marketing-contacts/stats')
            ->assertOk();

        // A move still relocates the contact via the single column.
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/marketing-contacts/move-market', [
                'from_market' => 'asia',
                'to_market'   => 'germany',
            ])
            ->assertOk();

        $this->assertSame('germany', MarketingContact::where('email', 'a@example.com')->value('market'));

        // Campaign filtering keeps working off the column.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/bulk-emails/recipient-count?market=germany')
            ->assertOk()
            ->assertJsonPath('data.count', 1);
    }

    /**
     * Runs the real migration file against real SQL, from the pre-migration
     * state, and asserts every existing contact comes out holding exactly the
     * market it already had. The backfill is the one part of this feature that
     * touches live production rows, so it's proved rather than assumed.
     */
    public function test_migration_backfills_one_membership_per_existing_contact(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('marketing_contact_markets');
        Schema::enableForeignKeyConstraints();
        MarketingContact::forgetMultipleMarketsSupport();

        // Pre-migration state: markets live only in the single column.
        MarketingContact::create(['email' => 'a@example.com', 'market' => 'asia', 'unsubscribe_token' => 't1']);
        MarketingContact::create(['email' => 'b@example.com', 'market' => 'croatia', 'unsubscribe_token' => 't2']);
        MarketingContact::create(['email' => 'c@example.com', 'market' => null, 'unsubscribe_token' => 't3']);

        $migration = require base_path('database/migrations/2026_07_30_000001_create_marketing_contact_markets_table.php');
        $migration->up();
        MarketingContact::forgetMultipleMarketsSupport();

        $this->assertSame(['asia'], MarketingContact::where('email', 'a@example.com')->first()->marketNames());
        $this->assertSame(['croatia'], MarketingContact::where('email', 'b@example.com')->first()->marketNames());
        $this->assertSame([], MarketingContact::where('email', 'c@example.com')->first()->marketNames());
        $this->assertSame(2, \DB::table('marketing_contact_markets')->count());

        // Idempotent: a re-run must not duplicate memberships or fail on the
        // unique index.
        $migration->up();
        $this->assertSame(2, \DB::table('marketing_contact_markets')->count());
    }

    public function test_reimport_adds_a_market_instead_of_moving_the_contact(): void
    {
        $contact = MarketingContact::create(['email' => 'jane@example.com', 'market' => 'asia', 'unsubscribe_token' => 't1']);
        $contact->addMarkets(['asia']);

        $csv = "First Name,Last Name,Email 1\n"
            . "Jane,Doe,jane@example.com\n";

        $path = tempnam(sys_get_temp_dir(), 'contacts') . '.csv';
        file_put_contents($path, $csv);

        (new MarketingContactImportService())->import($path, 'germany');

        // An unrelated Germany import must not quietly relocate an Asia contact.
        $this->assertSame(['asia', 'germany'], $contact->fresh()->marketNames());
        $this->assertSame('asia', $contact->fresh()->market);

        unlink($path);
    }

    public function test_admin_can_update_a_contacts_market(): void
    {
        $contact = MarketingContact::create(['email' => 'move@example.com', 'market' => 'asia', 'unsubscribe_token' => 't1']);

        $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->patchJson("/api/v1/admin/marketing-contacts/{$contact->id}", ['market' => 'Croatia Market'])
            ->assertOk()
            ->assertJsonPath('data.market', 'croatia-market');

        $this->assertSame('croatia-market', $contact->fresh()->market);
    }

    public function test_index_filters_by_market(): void
    {
        MarketingContact::create(['email' => 'a@example.com', 'market' => 'asia', 'unsubscribe_token' => 't1']);
        MarketingContact::create(['email' => 'b@example.com', 'market' => 'croatia', 'unsubscribe_token' => 't2']);

        $response = $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->getJson('/api/v1/admin/marketing-contacts?market=croatia');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('b@example.com', $response->json('data.0.email'));
    }

    public function test_markets_endpoint_lists_distinct_markets_with_counts(): void
    {
        MarketingContact::create(['email' => 'a@example.com', 'market' => 'asia', 'unsubscribe_token' => 't1']);
        MarketingContact::create(['email' => 'b@example.com', 'market' => 'asia', 'unsubscribe_token' => 't2']);
        MarketingContact::create(['email' => 'c@example.com', 'market' => 'croatia', 'unsubscribe_token' => 't3']);

        $response = $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->getJson('/api/v1/admin/marketing-contacts/markets');

        $response->assertOk();
        $data = collect($response->json('data'))->keyBy('market');
        $this->assertSame(2, $data['asia']['contact_count']);
        $this->assertSame(1, $data['croatia']['contact_count']);
    }

    public function test_campaign_creation_scoped_to_one_market_excludes_the_other(): void
    {
        Queue::fake();

        MarketingContact::create(['email' => 'asia@example.com', 'status' => 'subscribed', 'market' => 'asia', 'unsubscribe_token' => 't1']);
        MarketingContact::create(['email' => 'croatia@example.com', 'status' => 'subscribed', 'market' => 'croatia', 'unsubscribe_token' => 't2']);

        $response = $this->actingAs($this->admin('order_manager'), 'sanctum')
            ->postJson('/api/v1/admin/bulk-emails', [
                'subject'   => 'Croatia only',
                'body_html' => '<p>Hi</p>',
                'filters'   => ['market' => 'croatia'],
            ]);

        $response->assertStatus(201)->assertJsonPath('data.total_recipients', 1);

        $campaign = BulkEmailCampaign::first();
        $this->assertDatabaseHas('bulk_email_campaign_recipients', ['campaign_id' => $campaign->id, 'email' => 'croatia@example.com']);
        $this->assertDatabaseMissing('bulk_email_campaign_recipients', ['campaign_id' => $campaign->id, 'email' => 'asia@example.com']);
    }

    // -------------------------------------------------------------------------
    // Full-document campaign bodies (a designed template pasted in whole)
    // -------------------------------------------------------------------------

    public function test_full_html_document_body_is_not_double_wrapped_and_unsubscribe_token_is_replaced(): void
    {
        Mail::fake();

        $contact = MarketingContact::create(['email' => 'sub@example.com', 'status' => 'subscribed', 'unsubscribe_token' => 'tok-abc']);

        $campaign = BulkEmailCampaign::create([
            'subject'          => 'Designed campaign',
            'body_html'        => "<!DOCTYPE html>\n<html><body><p>Hi</p><a href=\"[[UNSUBSCRIBE_URL]]\">Unsubscribe</a></body></html>",
            'total_recipients' => 1,
            'status'           => 'queued',
            'created_by'       => $this->admin()->id,
        ]);
        $campaign->recipients()->create(['contact_id' => $contact->id, 'email' => $contact->email, 'status' => 'pending']);

        (new SendBulkEmailCampaignJob($campaign->id))->handle();

        Mail::assertSent(BulkCampaignEmail::class, function ($mail) use ($contact) {
            $rendered = $mail->render();
            return str_contains($rendered, $contact->unsubscribe_token)
                && ! str_contains($rendered, '[[UNSUBSCRIBE_URL]]')
                && substr_count($rendered, '<html') === 1;
        });
    }

    public function test_plain_snippet_body_still_gets_the_standard_wrapper_and_footer(): void
    {
        Mail::fake();

        $contact = MarketingContact::create(['email' => 'sub@example.com', 'status' => 'subscribed', 'unsubscribe_token' => 'tok-xyz']);

        $campaign = BulkEmailCampaign::create([
            'subject'          => 'Plain campaign',
            'body_html'        => '<p>Just a snippet</p>',
            'total_recipients' => 1,
            'status'           => 'queued',
            'created_by'       => $this->admin()->id,
        ]);
        $campaign->recipients()->create(['contact_id' => $contact->id, 'email' => $contact->email, 'status' => 'pending']);

        (new SendBulkEmailCampaignJob($campaign->id))->handle();

        Mail::assertSent(BulkCampaignEmail::class, function ($mail) use ($contact) {
            $rendered = $mail->render();
            return str_contains($rendered, 'You are receiving this email from Okelcor')
                && str_contains($rendered, $contact->unsubscribe_token);
        });
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

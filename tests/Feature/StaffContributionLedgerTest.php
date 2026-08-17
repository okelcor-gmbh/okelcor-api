<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\BulkEmailCampaign;
use App\Models\CustomerCommunication;
use App\Models\FinanceInvoice;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\OrderSignoff;
use App\Models\PartnerSaleAudit;
use App\Models\StaffActivity;
use App\Models\StaffContribution;
use App\Support\AdminPermissions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The contribution ledger: work the system watched, and work only the person
 * knows about.
 *
 * Minimal-schema sqlite harness, same pattern as OrderSignoffAndBoardTest and
 * CampaignDraftTest, so this runs in CI rather than behind the MySQL gate. The
 * ledger's own migration is run from its real file rather than hand-built — the
 * unique index on (source_type, source_id, action) is what makes the backfill
 * re-runnable, so a hand-written table would be testing a different schema from
 * the one that ships.
 */
class StaffContributionLedgerTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();

        foreach ([
            'staff_contributions', 'staff_activities', 'partner_sale_audits',
            'bulk_email_campaigns', 'finance_invoices', 'customer_communications',
            'order_signoffs', 'trade_documents', 'order_logs', 'orders',
            'personal_access_tokens', 'admin_users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->string('job_title', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('ref', 30)->unique();
            $table->string('source', 20)->default('website');
            $table->string('customer_email')->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency', 3)->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('payment_status', 30)->default('unpaid');
            $table->timestamps();
        });

        Schema::create('order_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('order_ref', 30);
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->string('admin_user_email')->nullable();
            $table->string('action', 60);
            $table->string('old_value', 100)->nullable();
            $table->string('new_value', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('trade_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('order_ref', 30)->nullable();
            $table->string('type', 30);
            $table->string('number', 50)->nullable();
            $table->string('status', 30)->default('draft');
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_signoffs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('order_ref', 30);
            $table->string('slot', 20);
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->string('admin_role', 30)->nullable();
            $table->string('admin_name', 120)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->string('revoke_reason', 500)->nullable();
            $table->boolean('active')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_communications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('quote_request_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->string('type', 30)->nullable();
            $table->string('direction', 20)->nullable();
            $table->string('channel', 30)->nullable();
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->string('status', 30)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bulk_email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('subject')->nullable();
            $table->text('body_html')->nullable();
            $table->string('status', 30)->default('draft');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('partner_sale_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('partner_sale_id');
            $table->string('action', 40);
            $table->string('actor_type', 30)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label')->nullable();
            $table->text('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $this->runMigration('2026_08_13_000003_create_finance_invoices_table');
        $this->runMigration('2026_08_14_000001_add_file_and_origin_to_finance_invoices');
        $this->runMigration('2026_08_17_000001_create_staff_activity_tables');
        $this->runMigration('2026_08_17_000002_add_job_title_to_admin_users_table');

        Schema::enableForeignKeyConstraints();

        // Both models memoise whether their table exists, and the harness
        // builds them after the container boots.
        StaffActivity::forgetLedgerCheck();
        StaffContribution::forgetLogCheck();
        FinanceInvoice::forgetRegisterCheck();
        \App\Services\StaffActivityRecorder::forgetJobTitleCheck();
    }

    private function runMigration(string $name): void
    {
        (require database_path("migrations/{$name}.php"))->up();
    }

    // ── harness ───────────────────────────────────────────────────────────

    private function admin(string $role = 'order_manager', ?string $jobTitle = null): AdminUser
    {
        $this->seq++;

        return AdminUser::create([
            'name'                    => ucfirst($role) . ' ' . $this->seq,
            'job_title'               => $jobTitle,
            'email'                   => "{$role}{$this->seq}@okelcor.com",
            'password'                => Hash::make('secret-password'),
            'role'                    => $role,
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function headers(AdminUser $admin): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken];
    }

    private function order(): Order
    {
        $this->seq++;

        return Order::create([
            'ref'            => 'OKL-S' . str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
            'customer_email' => "buyer{$this->seq}@acme.de",
            'total'          => 5000,
            'currency'       => 'EUR',
            'status'         => 'confirmed',
            'payment_status' => 'paid',
        ]);
    }

    private function log(AdminUser $admin, string $action, ?Order $order = null): OrderLog
    {
        $order ??= $this->order();

        return OrderLog::create([
            'order_id'      => $order->id,
            'order_ref'     => $order->ref,
            'admin_user_id' => $admin->id,
            'action'        => $action,
            'created_at'    => now(),
        ]);
    }

    // ── the migration ─────────────────────────────────────────────────────

    public function test_the_migration_applies_against_real_sql_and_is_idempotent(): void
    {
        $this->assertTrue(Schema::hasTable('staff_activities'));
        $this->assertTrue(Schema::hasTable('staff_contributions'));

        // Re-running must not throw — the guards are what make a re-deploy safe.
        $this->runMigration('2026_08_17_000001_create_staff_activity_tables');

        $this->assertTrue(Schema::hasTable('staff_activities'));
        $this->assertTrue(Schema::hasColumn('staff_activities', 'admin_name'));
        $this->assertTrue(Schema::hasColumn('staff_contributions', 'review_note'));
    }

    // ── capture ───────────────────────────────────────────────────────────

    public function test_an_order_log_writes_a_ledger_row_for_the_admin_who_wrote_it(): void
    {
        $admin = $this->admin('order_manager');
        $log   = $this->log($admin, 'status_changed');

        $activity = StaffActivity::where('source_type', 'order_log')->where('source_id', $log->id)->first();

        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->admin_user_id);
        $this->assertSame('orders', $activity->category);
        $this->assertSame('status_changed', $activity->action);
        $this->assertSame($log->order_ref, $activity->subject_label);

        // The snapshot is the point — the report must not be rewritten the day
        // this person changes role.
        $this->assertSame($admin->name, $activity->admin_name);
        $this->assertSame('order_manager', $activity->admin_role);
    }

    public function test_work_with_nobody_behind_it_is_not_recorded(): void
    {
        $order = $this->order();

        OrderLog::create([
            'order_id'   => $order->id,
            'order_ref'  => $order->ref,
            'action'     => 'status_changed',
            'created_at' => now(),
        ]);

        $this->assertSame(0, StaffActivity::count());
    }

    public function test_a_customer_decision_is_never_credited_to_staff(): void
    {
        $admin = $this->admin('order_manager');

        // Even with an admin stamped on it — the acceptance is the customer's
        // act, and a future change that starts stamping an admin on these must
        // not silently credit that person with it.
        $this->log($admin, 'order_confirmation_accepted');

        $this->assertSame(0, StaffActivity::count());
    }

    public function test_order_log_actions_land_in_the_category_a_reader_would_expect(): void
    {
        $admin = $this->admin('order_manager');

        $this->log($admin, 'document_sent');
        $this->log($admin, 'signoff_given');
        $this->log($admin, 'totals_repaired');
        $this->log($admin, 'tracking_updated');

        $this->assertSame('documents', StaffActivity::where('action', 'document_sent')->value('category'));
        $this->assertSame('finance', StaffActivity::where('action', 'signoff_given')->value('category'));
        $this->assertSame('finance', StaffActivity::where('action', 'totals_repaired')->value('category'));

        // Unmapped actions fall to `orders`, which is the order lifecycle —
        // a future action lands somewhere a reader expects rather than in a
        // bucket called "other".
        $this->assertSame('orders', StaffActivity::where('action', 'tracking_updated')->value('category'));
    }

    public function test_a_superseded_document_keeps_its_ledger_row(): void
    {
        $admin = $this->admin('order_manager');
        $order = $this->order();

        $document = \App\Models\TradeDocument::create([
            'order_id'  => $order->id,
            'order_ref' => $order->ref,
            'type'      => 'commercial_invoice',
            'number'    => 'CI-2026-0001',
            'status'    => 'issued',
            'issued_by' => $admin->id,
            'issued_at' => now(),
        ]);

        $this->assertSame(1, StaffActivity::where('source_type', 'trade_document')->count());

        $document->update(['status' => 'superseded']);

        // The invoice register drops it; the ledger does not. Withdrawing a
        // document does not undo the work of having raised it, and a month that
        // loses its entries when a customer asks for a correction reads as
        // though nothing was done.
        $this->assertSame(1, StaffActivity::where('source_type', 'trade_document')->count());
    }

    public function test_a_signoff_records_the_name_and_role_held_at_the_time(): void
    {
        $admin = $this->admin('finance');
        $order = $this->order();

        OrderSignoff::create([
            'order_id'      => $order->id,
            'order_ref'     => $order->ref,
            'slot'          => 'finance',
            'admin_user_id' => $admin->id,
            'admin_role'    => 'finance',
            'admin_name'    => 'Petra Vogel',
            'signed_at'     => now(),
            'active'        => true,
        ]);

        $activity = StaffActivity::where('source_type', 'order_signoff')->first();

        $this->assertNotNull($activity);
        $this->assertSame('finance', $activity->category);
        // Taken from the sign-off's own snapshot, not looked up live.
        $this->assertSame('Petra Vogel', $activity->admin_name);
    }

    public function test_only_outbound_customer_messages_count_as_work(): void
    {
        $admin = $this->admin('support');

        CustomerCommunication::create([
            'admin_user_id' => $admin->id,
            'direction'     => 'inbound',
            'channel'       => 'email',
            'subject'       => 'Customer asking about an order',
        ]);

        $this->assertSame(0, StaffActivity::where('category', 'support')->count());

        CustomerCommunication::create([
            'admin_user_id' => $admin->id,
            'direction'     => 'outbound',
            'channel'       => 'email',
            'subject'       => 'Re: your order',
        ]);

        $this->assertSame(1, StaffActivity::where('category', 'support')->count());
    }

    public function test_a_partners_own_entry_is_not_staff_work(): void
    {
        $admin = $this->admin('admin');

        PartnerSaleAudit::record(1, 'created', 'partner_user', 99, 'A Partner', null, null);
        $this->assertSame(0, StaffActivity::where('category', 'partners')->count());

        PartnerSaleAudit::record(1, 'verified', 'admin_user', $admin->id, $admin->name, null, null);
        $this->assertSame(1, StaffActivity::where('category', 'partners')->count());
    }

    public function test_an_auto_registered_invoice_is_not_counted_as_someones_work(): void
    {
        $admin = $this->admin('finance');

        FinanceInvoice::create([
            'system'          => 'okelcor',
            'external_number' => 'CI-2026-0009',
            'issued_on'       => now()->toDateString(),
            'recorded_by'     => $admin->id,
        ]);

        // Nobody typed this one. Counting it would credit finance twice — once
        // here and once through the order log that raised the invoice.
        $this->assertSame(0, StaffActivity::where('source_type', 'finance_invoice')->count());

        FinanceInvoice::create([
            'system'          => 'sevdesk',
            'external_number' => 'RE-2026-0044',
            'issued_on'       => now()->toDateString(),
            'recorded_by'     => $admin->id,
        ]);

        $this->assertSame(1, StaffActivity::where('source_type', 'finance_invoice')->count());
    }

    public function test_saving_the_same_source_twice_does_not_double_the_ledger(): void
    {
        $admin = $this->admin('order_manager');
        $log   = $this->log($admin, 'status_changed');

        $log->update(['new_value' => 'shipped']);
        $log->update(['new_value' => 'delivered']);

        $this->assertSame(1, StaffActivity::where('source_type', 'order_log')->count());
        $this->assertSame('delivered', StaffActivity::first()->metadata['new_value']);
    }

    public function test_the_ledger_is_inert_before_its_migration_runs(): void
    {
        Schema::dropIfExists('staff_activities');
        StaffActivity::forgetLedgerCheck();

        $admin = $this->admin('order_manager');

        // The whole point of the availability check: confirming an order must
        // keep working between the code deploying and the migration running.
        $log = $this->log($admin, 'status_changed');

        $this->assertNotNull($log->id);
    }

    // ── reading it ────────────────────────────────────────────────────────

    public function test_everyone_can_read_their_own_record(): void
    {
        // A viewer holds no other permission in the system, and still gets this.
        $viewer = $this->admin('viewer');
        $this->log($viewer, 'status_changed');

        $this->getJson('/api/v1/admin/staff/activity', $this->headers($viewer))
            ->assertOk()
            ->assertJsonPath('meta.is_self', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.verified', true);
    }

    public function test_reading_a_colleagues_record_needs_the_team_permission(): void
    {
        $viewer  = $this->admin('viewer');
        $manager = $this->admin('order_manager');

        $this->getJson("/api/v1/admin/staff/activity?admin_user_id={$manager->id}", $this->headers($viewer))
            ->assertStatus(403)
            ->assertJsonPath('code', 'staff_view_team_required');

        $this->getJson("/api/v1/admin/staff/activity?admin_user_id={$viewer->id}", $this->headers($manager))
            ->assertOk()
            ->assertJsonPath('meta.is_self', false);
    }

    public function test_the_summary_keeps_recorded_and_self_reported_apart(): void
    {
        $admin = $this->admin('order_manager');
        $this->log($admin, 'document_sent');

        StaffContribution::create([
            'admin_user_id' => $admin->id,
            'category'      => 'social_media',
            'title'         => 'LinkedIn post on TBR stock',
            'performed_on'  => now()->toDateString(),
            'status'        => StaffContribution::STATUS_PENDING,
        ]);

        $response = $this->getJson('/api/v1/admin/staff/summary', $this->headers($admin))->assertOk();

        $response->assertJsonPath('data.recorded.total', 1);
        $response->assertJsonPath('data.self_reported.total', 1);
        $response->assertJsonPath('data.self_reported.pending', 1);

        // The promise made to the team, asserted rather than assumed: there is
        // no combined figure anywhere in the payload.
        $data = $response->json('data');
        $this->assertArrayNotHasKey('total', $data);
        $this->assertArrayHasKey('note', $data);
    }

    public function test_the_summary_carries_every_category_including_the_empty_ones(): void
    {
        $admin = $this->admin('order_manager');
        $this->log($admin, 'document_sent');

        $categories = $this->getJson('/api/v1/admin/staff/summary', $this->headers($admin))
            ->assertOk()
            ->json('data.recorded.by_category');

        // "Nothing in marketing" and "marketing is missing from this list" look
        // identical when the empty row is simply absent.
        $this->assertCount(count(StaffActivity::CATEGORIES), $categories);
        $this->assertSame(0, collect($categories)->firstWhere('category', 'marketing')['total']);
        $this->assertSame(1, collect($categories)->firstWhere('category', 'documents')['total']);
    }

    public function test_the_member_picker_shows_only_yourself_without_the_team_permission(): void
    {
        $viewer = $this->admin('viewer');
        $this->admin('order_manager');

        $this->getJson('/api/v1/admin/staff/members', $this->headers($viewer))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.can_view_team', false)
            ->assertJsonPath('meta.can_verify', false);

        $boss = $this->admin('super_admin');

        $this->getJson('/api/v1/admin/staff/members', $this->headers($boss))
            ->assertOk()
            ->assertJsonPath('meta.can_view_team', true)
            ->assertJsonPath('meta.can_verify', true);
    }

    // ── the manual log ────────────────────────────────────────────────────

    public function test_a_person_can_log_edit_and_remove_their_own_work(): void
    {
        $admin = $this->admin('support');

        $id = $this->postJson('/api/v1/admin/staff/contributions', [
            'category'     => 'supplier',
            'title'        => 'Called Zhengxin about Q4 TBR allocation',
            'performed_on' => now()->toDateString(),
            'minutes'      => 45,
        ], $this->headers($admin))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.self_reported', true)
            ->assertJsonPath('data.can_edit', true)
            // No artifact behind a phone call — recorded anyway, and the
            // reviewer is told.
            ->assertJsonPath('data.has_evidence', false)
            ->json('data.id');

        $this->patchJson("/api/v1/admin/staff/contributions/{$id}", [
            'title' => 'Called Zhengxin about Q4 TBR and OTR allocation',
        ], $this->headers($admin))->assertOk();

        $this->deleteJson("/api/v1/admin/staff/contributions/{$id}", [], $this->headers($admin))->assertOk();

        $this->assertSame(0, StaffContribution::count());
    }

    public function test_work_cannot_be_logged_for_a_future_date(): void
    {
        $admin = $this->admin('support');

        $this->postJson('/api/v1/admin/staff/contributions', [
            'category'     => 'training',
            'title'        => 'A course I have not attended yet',
            'performed_on' => now()->addWeek()->toDateString(),
        ], $this->headers($admin))
            ->assertStatus(422)
            ->assertJsonValidationErrors('performed_on');
    }

    public function test_you_cannot_touch_someone_elses_entry(): void
    {
        $mine   = $this->admin('support');
        $theirs = $this->admin('support');

        $contribution = StaffContribution::create([
            'admin_user_id' => $mine->id,
            'category'      => 'other',
            'title'         => 'Something I did',
            'performed_on'  => now()->toDateString(),
        ]);

        $this->patchJson("/api/v1/admin/staff/contributions/{$contribution->id}", [
            'title' => 'Something THEY did',
        ], $this->headers($theirs))->assertStatus(403);

        $this->deleteJson("/api/v1/admin/staff/contributions/{$contribution->id}", [], $this->headers($theirs))
            ->assertStatus(403);
    }

    public function test_a_reviewed_entry_can_no_longer_be_rewritten(): void
    {
        $author   = $this->admin('support');
        $reviewer = $this->admin('admin');

        $contribution = StaffContribution::create([
            'admin_user_id' => $author->id,
            'category'      => 'trade_fair',
            'title'         => 'Reifen Essen stand duty',
            'performed_on'  => now()->toDateString(),
        ]);

        $this->postJson("/api/v1/admin/staff/contributions/{$contribution->id}/review", [
            'decision' => 'verified',
        ], $this->headers($reviewer))->assertOk()->assertJsonPath('data.status', 'verified');

        // Rewording it now would change what the reviewer agreed to.
        $this->patchJson("/api/v1/admin/staff/contributions/{$contribution->id}", [
            'title' => 'Reifen Essen — three days, not one',
        ], $this->headers($author))
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_reviewed');

        $this->deleteJson("/api/v1/admin/staff/contributions/{$contribution->id}", [], $this->headers($author))
            ->assertStatus(409);
    }

    public function test_nobody_countersigns_their_own_claim(): void
    {
        $boss = $this->admin('super_admin');

        $contribution = StaffContribution::create([
            'admin_user_id' => $boss->id,
            'category'      => 'internal',
            'title'         => 'A thing I say I did',
            'performed_on'  => now()->toDateString(),
        ]);

        $this->postJson("/api/v1/admin/staff/contributions/{$contribution->id}/review", [
            'decision' => 'verified',
        ], $this->headers($boss))
            ->assertStatus(422)
            ->assertJsonPath('code', 'self_review');
    }

    public function test_a_rejection_has_to_say_why(): void
    {
        $author   = $this->admin('support');
        $reviewer = $this->admin('admin');

        $contribution = StaffContribution::create([
            'admin_user_id' => $author->id,
            'category'      => 'other',
            'title'         => 'Unclear entry',
            'performed_on'  => now()->toDateString(),
        ]);

        $this->postJson("/api/v1/admin/staff/contributions/{$contribution->id}/review", [
            'decision' => 'rejected',
        ], $this->headers($reviewer))
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');

        $this->postJson("/api/v1/admin/staff/contributions/{$contribution->id}/review", [
            'decision' => 'rejected',
            'note'     => 'This is already covered by the order log for OKL-1042.',
        ], $this->headers($reviewer))
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.review_note', 'This is already covered by the order log for OKL-1042.');
    }

    public function test_reviewing_needs_the_verify_permission(): void
    {
        $author  = $this->admin('support');
        $manager = $this->admin('order_manager');

        $contribution = StaffContribution::create([
            'admin_user_id' => $author->id,
            'category'      => 'other',
            'title'         => 'An entry',
            'performed_on'  => now()->toDateString(),
        ]);

        // order_manager holds staff.view_team but not staff.verify — seeing
        // someone's work and agreeing it happened are different acts.
        $this->assertTrue(AdminPermissions::can('order_manager', 'staff.view_team'));
        $this->assertFalse(AdminPermissions::can('order_manager', 'staff.verify'));

        $this->postJson("/api/v1/admin/staff/contributions/{$contribution->id}/review", [
            'decision' => 'verified',
        ], $this->headers($manager))->assertStatus(403);
    }

    public function test_evidence_is_stored_privately_and_served_through_the_route(): void
    {
        Storage::fake('local');

        $admin = $this->admin('support');

        $id = $this->postJson('/api/v1/admin/staff/contributions', [
            'category'     => 'social_media',
            'title'        => 'Instagram reel on the Croatia campaign',
            'performed_on' => now()->toDateString(),
            'file'         => UploadedFile::fake()->create('reel-stats.pdf', 40, 'application/pdf'),
        ], $this->headers($admin))
            ->assertCreated()
            ->assertJsonPath('data.has_file', true)
            ->assertJsonPath('data.has_evidence', true)
            ->json('data.id');

        // The stored path is never exposed — the file is reached through a
        // route that checks who is asking.
        $this->assertArrayNotHasKey('file_path', StaffContribution::find($id)->toArray());

        $stranger = $this->admin('viewer');

        $this->getJson("/api/v1/admin/staff/contributions/{$id}/file", $this->headers($stranger))
            ->assertStatus(403);
    }

    public function test_the_contribution_list_shows_only_your_own_without_the_team_permission(): void
    {
        $viewer  = $this->admin('viewer');
        $manager = $this->admin('order_manager');

        foreach ([$viewer, $manager] as $person) {
            StaffContribution::create([
                'admin_user_id' => $person->id,
                'category'      => 'internal',
                'title'         => 'Entry by ' . $person->name,
                'performed_on'  => now()->toDateString(),
            ]);
        }

        // Asking for somebody else's is not a filter that fails — it is simply
        // not applied, and the caller still sees only their own.
        $this->getJson("/api/v1/admin/staff/contributions?admin_user_id={$manager->id}", $this->headers($viewer))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.logged_by.id', $viewer->id);

        $this->getJson('/api/v1/admin/staff/contributions', $this->headers($manager))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    // ── the backfill ──────────────────────────────────────────────────────

    public function test_the_backfill_survey_writes_nothing_and_the_fix_is_rerunnable(): void
    {
        $admin = $this->admin('order_manager');
        $order = $this->order();

        // Written while the ledger is absent, so the hooks record nothing and
        // there is genuine history for the backfill to find — the situation on
        // production the moment this ships.
        Schema::dropIfExists('staff_activities');
        StaffActivity::forgetLedgerCheck();

        foreach (['status_changed', 'document_sent', 'payment_status_changed'] as $action) {
            $this->log($admin, $action, $order);
        }

        $this->runMigration('2026_08_17_000001_create_staff_activity_tables');
        StaffActivity::forgetLedgerCheck();

        $this->assertSame(0, StaffActivity::count());

        $this->artisan('staff:backfill-ledger')->assertSuccessful();
        $this->assertSame(0, StaffActivity::count(), 'The survey must not write anything.');

        $this->artisan('staff:backfill-ledger --fix')->assertSuccessful();
        $this->assertSame(3, StaffActivity::count());

        // Keyed on (source_type, source_id, action), so a second run cannot
        // double anybody's month.
        $this->artisan('staff:backfill-ledger --fix')->assertSuccessful();
        $this->assertSame(3, StaffActivity::count());
    }

    public function test_the_backfill_refuses_before_its_migration(): void
    {
        Schema::dropIfExists('staff_activities');
        StaffActivity::forgetLedgerCheck();

        $this->artisan('staff:backfill-ledger --fix')->assertFailed();
    }

    public function test_every_role_can_reach_its_own_page(): void
    {
        // The guarantee that makes the feature acceptable: there is no role
        // that can be measured but cannot look.
        foreach (AdminPermissions::ROLES as $role) {
            $this->assertTrue(
                AdminPermissions::can($role, 'staff.self'),
                "Role {$role} cannot open its own contribution record."
            );
        }
    }

    // ── who gets recorded, and what they are called ───────────────────────

    public function test_super_admins_work_is_recorded_like_everybody_elses(): void
    {
        // The ledger keys on admin_user_id and never looks at the role. Asserted
        // rather than assumed, because "am I in this too?" is the first question
        // anyone running the system asks, and the honest answer has to be
        // demonstrable.
        $boss = $this->admin('super_admin', 'Managing Director');
        $dev  = $this->admin('super_admin', 'System Administrator');

        $this->log($boss, 'status_changed');
        $this->log($dev, 'document_sent');

        $this->assertSame(1, StaffActivity::where('admin_user_id', $boss->id)->count());
        $this->assertSame(1, StaffActivity::where('admin_user_id', $dev->id)->count());
    }

    public function test_every_role_that_can_act_is_recorded(): void
    {
        foreach (AdminPermissions::ROLES as $role) {
            $person = $this->admin($role);
            $this->log($person, 'status_changed');

            $this->assertSame(
                1,
                StaffActivity::where('admin_user_id', $person->id)->count(),
                "Work by a {$role} was not recorded."
            );
        }
    }

    public function test_the_ledger_records_the_job_not_the_permission_set(): void
    {
        // The real shape of this team: an order manager who holds `admin`
        // because she also needs customers, campaigns and quote requests.
        $edinah = $this->admin('admin', 'Order Manager');

        $this->log($edinah, 'document_sent');

        $activity = StaffActivity::first();

        $this->assertSame('Order Manager', $activity->admin_job_title);
        // The role is still kept — it says what she may open — but it is not
        // what describes her.
        $this->assertSame('admin', $activity->admin_role);
    }

    public function test_someone_with_no_title_falls_back_to_a_readable_role(): void
    {
        $person = $this->admin('order_manager');

        $this->assertFalse($person->hasJobTitle());
        $this->assertSame('Order Manager', $person->jobTitle());

        $this->log($person, 'status_changed');

        // Never blank — a report with an empty column reads as broken.
        $this->assertSame('Order Manager', StaffActivity::first()->admin_job_title);
    }

    public function test_the_job_title_is_a_snapshot_not_a_live_lookup(): void
    {
        $person = $this->admin('admin', 'Order Manager');
        $this->log($person, 'document_sent');

        $person->update(['job_title' => 'Head of Operations']);

        // Last month's record still says what she was then. Reading it live
        // would rewrite her history the day she is promoted.
        $this->assertSame('Order Manager', StaffActivity::first()->admin_job_title);
    }

    public function test_job_titles_are_applied_by_email_and_not_overwritten_by_accident(): void
    {
        $person = $this->admin('admin');
        config(['staff.job_titles' => [strtoupper($person->email) => 'Order Manager']]);

        // Matched case-insensitively — an e-mail is the same person whatever
        // case it was typed in.
        $this->artisan('staff:sync-job-titles')->assertSuccessful();
        $this->assertSame('Order Manager', $person->fresh()->jobTitle());

        config(['staff.job_titles' => [$person->email => 'Something Else']]);
        $this->artisan('staff:sync-job-titles')->assertSuccessful();

        // A title set in the panel outranks the seed.
        $this->assertSame('Order Manager', $person->fresh()->jobTitle());

        $this->artisan('staff:sync-job-titles --force')->assertSuccessful();
        $this->assertSame('Something Else', $person->fresh()->jobTitle());
    }

    public function test_a_job_title_can_be_set_for_one_person_directly(): void
    {
        $person = $this->admin('admin');

        // Passed as an option value rather than in the command string: a title
        // with a space in it is the normal case, and the string form splits on
        // it (as a shell would without quotes).
        $this->artisan('staff:sync-job-titles', ['--set' => "{$person->email}=Operations Manager"])
            ->assertSuccessful();

        $this->assertSame('Operations Manager', $person->fresh()->jobTitle());
    }

    // ── the team report ──────────────────────────────────────────────────

    public function test_the_team_report_needs_the_team_permission(): void
    {
        $viewer = $this->admin('viewer');

        $this->getJson('/api/v1/admin/staff/team-report', $this->headers($viewer))
            ->assertStatus(403);
    }

    public function test_the_team_report_lists_people_by_job_and_never_ranks_them(): void
    {
        $boss   = $this->admin('super_admin', 'Managing Director');
        $edinah = $this->admin('admin', 'Order Manager');

        // Deliberately giving the second person more work than the first.
        foreach (range(1, 3) as $ignored) {
            $this->log($edinah, 'document_sent');
        }
        $this->log($boss, 'status_changed');

        $data = $this->getJson('/api/v1/admin/staff/team-report', $this->headers($boss))
            ->assertOk()
            ->json('data');

        $names = array_column($data['people'], 'name');
        $sorted = $names;
        sort($sorted);

        // Alphabetical, not "most active first". A league table is a claim the
        // data cannot support.
        $this->assertSame($sorted, $names);

        $row = collect($data['people'])->firstWhere('admin_user_id', $edinah->id);
        $this->assertSame('Order Manager', $row['job_title']);
        $this->assertSame('admin', $row['role']);
        $this->assertSame(3, $row['recorded']['total']);

        // Recorded and self-reported stay apart here too.
        $this->assertArrayHasKey('self_reported', $row);
        $this->assertArrayNotHasKey('total', $row);

        // The caveats travel with the payload — this report gets forwarded.
        $this->assertNotEmpty($data['caveats']);
    }

    public function test_the_digest_dry_run_sends_nothing(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $person = $this->admin('admin', 'Order Manager');
        $this->log($person, 'document_sent');

        config(['staff.digest.recipients' => ['solomon@okelcor.com']]);

        $this->artisan('staff:digest --dry-run')->assertSuccessful();

        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }

    public function test_the_digest_emails_each_configured_recipient(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $person = $this->admin('admin', 'Order Manager');
        $this->log($person, 'document_sent');

        config(['staff.digest.recipients' => ['solomon@okelcor.com', 'operations@okelcor.com']]);

        $this->artisan('staff:digest')->assertSuccessful();

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\StaffContributionDigest::class, 2);
    }

    public function test_the_digest_says_so_rather_than_failing_when_nobody_is_configured(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        config(['staff.digest.recipients' => []]);

        // A scheduled command that fails every night on an unconfigured server
        // is noise that trains people to ignore the log.
        $this->artisan('staff:digest')->assertSuccessful();

        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }

    public function test_the_digest_can_be_stood_down_from_config(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        config([
            'staff.digest.recipients' => ['solomon@okelcor.com'],
            'staff.digest.enabled'    => false,
        ]);

        $this->artisan('staff:digest')->assertSuccessful();

        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }
}

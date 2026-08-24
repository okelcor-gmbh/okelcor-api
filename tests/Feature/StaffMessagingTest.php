<?php

namespace Tests\Feature;

use App\Mail\StaffMessageEmail;
use App\Models\AdminNotification;
use App\Models\AdminUser;
use App\Models\CustomerCommunication;
use App\Models\StaffMessage;
use App\Models\StaffMessageRecipient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Staff-to-staff messaging + forwarding a customer e-mail to a colleague.
 *
 * Does NOT use RefreshDatabase — the full migration set includes a MySQL-only
 * legacy migration sqlite cannot run. Creates only the tables these tests
 * touch, the same pattern as PartnerSalesLogTest and BulkEmailCampaignTest,
 * so these actually execute rather than skipping behind the MySQL gate.
 */
class StaffMessagingTest extends TestCase
{
    private AdminUser $ada;
    private AdminUser $ben;
    private AdminUser $chi;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        Schema::disableForeignKeyConstraints();

        foreach ([
            'staff_message_recipients',
            'staff_messages',
            'customer_communications',
            'quote_requests',
            'customers',
            'admin_push_tokens',
            'admin_notifications',
            'personal_access_tokens',
            'admin_users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->string('job_title')->nullable();
            $table->text('email_signature')->nullable();
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

        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_user_id');
            $table->string('type', 64);
            $table->string('severity', 16)->default('info');
            $table->string('title');
            $table->text('body')->nullable();
            $table->text('message')->nullable();
            $table->string('action_url')->nullable();
            $table->string('link')->nullable();
            $table->string('related_type', 64)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->text('metadata')->nullable();
            $table->timestamps();
        });

        // AdminNotificationService fans out to Expo push after writing the
        // notification; without this table that call throws and the service
        // swallows it. Created so these tests exercise the real path.
        Schema::create('admin_push_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_user_id');
            $table->string('token');
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('company_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        Schema::create('quote_requests', function (Blueprint $table) {
            $table->id();
            $table->string('full_name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_communications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('quote_request_id')->nullable();
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->string('type', 20)->default('email');
            $table->string('direction', 20)->default('inbound');
            $table->string('channel', 20)->nullable();
            $table->string('subject', 300)->nullable();
            $table->longText('body')->nullable();
            $table->text('cc')->nullable();
            $table->text('attachments')->nullable();
            $table->string('message_id')->nullable();
            $table->string('in_reply_to')->nullable();
            $table->string('status', 20)->nullable();
            $table->timestamp('staff_read_at')->nullable();
            $table->timestamp('customer_read_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('metadata')->nullable();
            $table->timestamps();
        });

        $this->createStaffMessageTables();

        $this->ada = $this->admin('Ada Okafor', 'ada@okelcor.com', 'admin');
        $this->ben = $this->admin('Ben Adeyemi', 'ben@okelcor.com', 'order_manager');
        $this->chi = $this->admin('Chi Nwosu', 'chi@okelcor.com', 'editor');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function createStaffMessageTables(): void
    {
        Schema::create('staff_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('thread_id')->index();
            $table->unsignedBigInteger('sender_admin_id')->nullable();
            $table->string('sender_label', 191)->nullable();
            $table->string('subject', 300);
            $table->longText('body');
            $table->text('attachments')->nullable();
            $table->unsignedBigInteger('in_reply_to_id')->nullable();
            $table->unsignedBigInteger('forwarded_from_communication_id')->nullable();
            $table->unsignedBigInteger('forwarded_from_customer_id')->nullable();
            $table->unsignedBigInteger('forwarded_from_quote_request_id')->nullable();
            $table->timestamps();
        });

        Schema::create('staff_message_recipients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_message_id');
            $table->unsignedBigInteger('admin_user_id');
            $table->string('kind', 8)->default('to');
            $table->timestamp('read_at')->nullable();
            $table->string('email_status', 16)->nullable();
            $table->text('email_error')->nullable();
            $table->timestamps();
            $table->unique(['staff_message_id', 'admin_user_id']);
        });
    }

    private function admin(string $name, string $email, string $role): AdminUser
    {
        return AdminUser::create([
            'name'                    => $name,
            'display_name'            => $name,
            'email'                   => $email,
            'password'                => Hash::make('secret-password'),
            'role'                    => $role,
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Sanctum memoises the resolved user on the guard instance for the
     * lifetime of the test method, so a second request as a different admin
     * would silently reuse the first one without this.
     */
    private function headers(AdminUser $admin): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer ' . $admin->createToken('test')->plainTextToken];
    }

    private function sendFromAdaToBen(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/admin/staff-messages', array_merge([
            'to'      => [$this->ben->id],
            'subject' => 'Container 4412 paperwork',
            'body'    => '<p>Can you chase the bill of lading today?</p>',
        ], $overrides), $this->headers($this->ada));
    }

    // ── compose ───────────────────────────────────────────────────────────

    public function test_an_admin_sends_a_message_to_a_colleague(): void
    {
        $response = $this->sendFromAdaToBen();

        $response->assertStatus(201)
            ->assertJsonPath('data.subject', 'Container 4412 paperwork')
            ->assertJsonPath('data.sender.name', 'Ada Okafor')
            ->assertJsonPath('data.recipients.0.name', 'Ben Adeyemi')
            ->assertJsonPath('data.recipients.0.kind', 'to');

        $this->assertDatabaseCount('staff_messages', 1);
        $this->assertDatabaseHas('staff_message_recipients', [
            'admin_user_id' => $this->ben->id,
            'kind'          => 'to',
            'email_status'  => 'sent',
        ]);
    }

    public function test_the_colleague_also_gets_a_real_email(): void
    {
        $this->sendFromAdaToBen();

        Mail::assertSent(StaffMessageEmail::class, function (StaffMessageEmail $mail) {
            return $mail->hasTo('ben@okelcor.com')
                && $mail->sender->email === 'ada@okelcor.com';
        });
    }

    public function test_the_email_reply_to_is_the_sender_not_the_inbound_capture_address(): void
    {
        // InboundEmailProcessor drops anything sent from an okelcor.com
        // address, so a plus-addressed reply-to here would send a colleague's
        // Outlook reply into a black hole. It must point at the sender.
        config(['services.mail_inbound.enabled' => true]);
        config(['services.mail_inbound.address' => 'reply@reply.okelcor.com']);

        $this->sendFromAdaToBen();

        Mail::assertSent(StaffMessageEmail::class, function (StaffMessageEmail $mail) {
            $replyTo = $mail->envelope()->replyTo;

            return count($replyTo) === 1 && $replyTo[0]->address === 'ada@okelcor.com';
        });
    }

    public function test_the_colleague_gets_a_notification_and_it_is_not_deduped_away(): void
    {
        $this->sendFromAdaToBen();
        $this->sendFromAdaToBen(['subject' => 'And one more thing']);

        // Two separate messages from the same person on the same day are two
        // events. The service's default dedupe key would have collapsed them.
        $this->assertSame(2, AdminNotification::where('admin_user_id', $this->ben->id)
            ->where('type', 'staff_message_received')->count());
    }

    public function test_the_sender_is_stripped_from_their_own_recipient_list(): void
    {
        $response = $this->sendFromAdaToBen([
            'to' => [$this->ben->id],
            'cc' => [$this->ada->id],
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseMissing('staff_message_recipients', [
            'admin_user_id' => $this->ada->id,
        ]);
    }

    public function test_a_message_to_nobody_but_yourself_is_rejected(): void
    {
        $this->postJson('/api/v1/admin/staff-messages', [
            'to'      => [$this->ada->id],
            'subject' => 'Note to self',
            'body'    => '<p>Remember the thing.</p>',
        ], $this->headers($this->ada))
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_recipients');
    }

    public function test_a_deactivated_colleague_cannot_be_written_to(): void
    {
        $this->ben->update(['is_active' => false]);

        $this->sendFromAdaToBen()
            ->assertStatus(422)
            ->assertJsonPath('code', 'unknown_recipient');

        $this->assertDatabaseCount('staff_messages', 0);
    }

    public function test_the_body_is_sanitized_before_it_is_stored(): void
    {
        $this->sendFromAdaToBen([
            'body' => '<p>Hello</p><script>alert(1)</script><a href="javascript:alert(2)">x</a>',
        ])->assertStatus(201);

        $stored = StaffMessage::first()->body;

        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);
        $this->assertStringContainsString('Hello', $stored);
    }

    public function test_the_senders_signature_is_appended(): void
    {
        $this->ada->update(['email_signature' => '<p>Ada Okafor — Logistics</p>']);

        $this->sendFromAdaToBen()->assertStatus(201);

        $this->assertStringContainsString('Ada Okafor — Logistics', StaffMessage::first()->body);
    }

    // ── visibility ────────────────────────────────────────────────────────

    public function test_the_recipient_sees_it_in_their_inbox_and_the_sender_in_sent(): void
    {
        $this->sendFromAdaToBen();

        $this->getJson('/api/v1/admin/staff-messages?box=inbox', $this->headers($this->ben))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.unread', true);

        $this->getJson('/api/v1/admin/staff-messages?box=sent', $this->headers($this->ada))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Ada's own inbox stays empty — her copy lives in Sent.
        $this->getJson('/api/v1/admin/staff-messages?box=inbox', $this->headers($this->ada))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_an_uninvolved_admin_cannot_read_the_message(): void
    {
        $this->sendFromAdaToBen();
        $id = StaffMessage::first()->id;

        $this->getJson("/api/v1/admin/staff-messages/{$id}", $this->headers($this->chi))
            ->assertStatus(404);

        $this->getJson('/api/v1/admin/staff-messages?box=inbox', $this->headers($this->chi))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_an_uninvolved_admin_cannot_download_the_attachment(): void
    {
        Storage::fake('local');

        $this->sendFromAdaToBen([
            'attachments' => [UploadedFile::fake()->create('bol.pdf', 20, 'application/pdf')],
        ])->assertStatus(201);

        $id = StaffMessage::first()->id;

        $this->get("/api/v1/admin/staff-messages/{$id}/attachments/0/download", $this->headers($this->chi))
            ->assertStatus(404);

        $this->get("/api/v1/admin/staff-messages/{$id}/attachments/0/download", $this->headers($this->ben))
            ->assertOk();
    }

    public function test_marking_read_clears_the_unread_count(): void
    {
        $this->sendFromAdaToBen();
        $id = StaffMessage::first()->id;

        $this->getJson('/api/v1/admin/staff-messages/unread-count', $this->headers($this->ben))
            ->assertOk()->assertJsonPath('data.unread', 1);

        $this->postJson("/api/v1/admin/staff-messages/{$id}/read", [], $this->headers($this->ben))
            ->assertOk()->assertJsonPath('data.unread', 0);

        $this->assertNotNull(StaffMessageRecipient::first()->read_at);
    }

    public function test_the_sender_marking_their_own_sent_message_read_is_a_no_op_not_a_404(): void
    {
        $this->sendFromAdaToBen();
        $id = StaffMessage::first()->id;

        $this->postJson("/api/v1/admin/staff-messages/{$id}/read", [], $this->headers($this->ada))
            ->assertOk();
    }

    public function test_delivery_status_of_the_email_copy_is_visible_only_to_the_sender(): void
    {
        $this->sendFromAdaToBen();
        $id = StaffMessage::first()->id;

        $this->getJson("/api/v1/admin/staff-messages/{$id}", $this->headers($this->ada))
            ->assertOk()->assertJsonPath('data.message.recipients.0.email_status', 'sent');

        $this->getJson("/api/v1/admin/staff-messages/{$id}", $this->headers($this->ben))
            ->assertOk()->assertJsonPath('data.message.recipients.0.email_status', null);
    }

    // ── replies ───────────────────────────────────────────────────────────

    public function test_a_reply_joins_the_same_thread(): void
    {
        $this->sendFromAdaToBen();
        $original = StaffMessage::first();

        $this->postJson("/api/v1/admin/staff-messages/{$original->id}/reply", [
            'body' => '<p>Chasing it now.</p>',
        ], $this->headers($this->ben))
            ->assertStatus(201)
            ->assertJsonPath('data.subject', 'Re: Container 4412 paperwork')
            ->assertJsonPath('data.thread_id', $original->thread_id);

        $this->getJson("/api/v1/admin/staff-messages/{$original->id}", $this->headers($this->ada))
            ->assertOk()
            ->assertJsonCount(2, 'data.thread');
    }

    public function test_a_reply_cannot_pull_in_someone_who_was_never_on_the_thread(): void
    {
        $this->sendFromAdaToBen();
        $original = StaffMessage::first();

        // Chi is passed in the body and must be ignored entirely — recipients
        // come from the parent message, never from the request.
        $this->postJson("/api/v1/admin/staff-messages/{$original->id}/reply", [
            'body' => '<p>Adding Chi.</p>',
            'to'   => [$this->chi->id],
        ], $this->headers($this->ben))->assertStatus(201);

        $reply = StaffMessage::orderByDesc('id')->first();

        $this->assertFalse(
            $reply->recipients->contains(fn ($r) => $r->admin_user_id === $this->chi->id),
            'Chi was never on the thread and must not have been added by a reply.'
        );

        $this->getJson("/api/v1/admin/staff-messages/{$reply->id}", $this->headers($this->chi))
            ->assertStatus(404);
    }

    public function test_an_uninvolved_admin_cannot_reply(): void
    {
        $this->sendFromAdaToBen();
        $id = StaffMessage::first()->id;

        $this->postJson("/api/v1/admin/staff-messages/{$id}/reply", [
            'body' => '<p>Butting in.</p>',
        ], $this->headers($this->chi))->assertStatus(404);
    }

    public function test_reply_all_keeps_everyone_who_was_already_on_it(): void
    {
        $this->postJson('/api/v1/admin/staff-messages', [
            'to'      => [$this->ben->id],
            'cc'      => [$this->chi->id],
            'subject' => 'Port delay',
            'body'    => '<p>Heads up.</p>',
        ], $this->headers($this->ada))->assertStatus(201);

        $original = StaffMessage::first();

        $this->postJson("/api/v1/admin/staff-messages/{$original->id}/reply", [
            'body'      => '<p>Noted.</p>',
            'reply_all' => true,
        ], $this->headers($this->ben))->assertStatus(201);

        $reply = StaffMessage::orderByDesc('id')->first();
        $ids   = $reply->recipients->pluck('admin_user_id')->sort()->values()->all();

        // Ada (original sender) and Chi (was CC'd); Ben is replying so is not
        // his own recipient.
        $this->assertSame(
            collect([$this->ada->id, $this->chi->id])->sort()->values()->all(),
            $ids
        );
    }

    // ── forwarding ────────────────────────────────────────────────────────

    private function customerEmail(array $overrides = []): CustomerCommunication
    {
        $customerId = \DB::table('customers')->insertGetId([
            'company_name' => 'Baltic Tyres OÜ',
            'email'        => 'ops@baltictyres.ee',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return CustomerCommunication::create(array_merge([
            'customer_id' => $customerId,
            'type'        => 'email',
            'direction'   => 'inbound',
            'channel'     => 'email',
            'subject'     => 'Damaged pallet on order 10233',
            'body'        => '<p>Two tyres arrived cut. Photos attached.</p>',
            'status'      => 'received',
        ], $overrides));
    }

    public function test_a_customer_email_can_be_forwarded_to_a_colleague(): void
    {
        $comm = $this->customerEmail();

        $this->postJson("/api/v1/admin/communications/{$comm->id}/forward", [
            'to'   => [$this->ben->id],
            'note' => '<p>Ben, can you handle the claim?</p>',
        ], $this->headers($this->ada))
            ->assertStatus(201)
            ->assertJsonPath('data.subject', 'Fwd: Damaged pallet on order 10233')
            ->assertJsonPath('data.is_forward', true)
            ->assertJsonPath('data.forwarded_from.communication_id', $comm->id);

        $forward = StaffMessage::first();

        $this->assertStringContainsString('can you handle the claim', $forward->body);
        $this->assertStringContainsString('Forwarded message', $forward->body);
        $this->assertStringContainsString('Two tyres arrived cut', $forward->body);
        $this->assertStringContainsString('Baltic Tyres', $forward->body);
    }

    public function test_a_forward_carries_its_own_copy_of_the_attachments(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('communications/2026/08/original.pdf', 'photo-bytes');

        $comm = $this->customerEmail([
            'attachments' => [[
                'name' => 'damage.pdf',
                'path' => 'communications/2026/08/original.pdf',
                'mime' => 'application/pdf',
                'size' => 11,
            ]],
        ]);

        $this->postJson("/api/v1/admin/communications/{$comm->id}/forward", [
            'to'   => [$this->ben->id],
            'note' => '<p>See attached.</p>',
        ], $this->headers($this->ada))->assertStatus(201);

        $forward = StaffMessage::first();
        $this->assertCount(1, $forward->attachments);

        // A real copy at a new path, so deleting the original communication
        // does not empty the forward.
        $this->assertNotSame('communications/2026/08/original.pdf', $forward->attachments[0]['path']);
        Storage::disk('local')->assertExists($forward->attachments[0]['path']);

        Storage::disk('local')->delete('communications/2026/08/original.pdf');
        Storage::disk('local')->assertExists($forward->attachments[0]['path']);
    }

    public function test_a_missing_attachment_file_does_not_sink_the_forward(): void
    {
        Storage::fake('local');

        $comm = $this->customerEmail([
            'attachments' => [[
                'name' => 'gone.pdf',
                'path' => 'communications/2026/08/gone.pdf',
                'mime' => 'application/pdf',
                'size' => 10,
            ]],
        ]);

        $this->postJson("/api/v1/admin/communications/{$comm->id}/forward", [
            'to'   => [$this->ben->id],
            'note' => '<p>Body still matters.</p>',
        ], $this->headers($this->ada))->assertStatus(201);

        $this->assertStringContainsString('Body still matters', StaffMessage::first()->body);
    }

    public function test_forwarding_requires_permission_to_read_the_communication(): void
    {
        $comm = $this->customerEmail();

        // `editor` does not hold crm.view.
        $this->postJson("/api/v1/admin/communications/{$comm->id}/forward", [
            'to' => [$this->ben->id],
        ], $this->headers($this->chi))->assertStatus(403);

        $this->assertDatabaseCount('staff_messages', 0);
    }

    public function test_forwarding_an_unknown_communication_is_a_404(): void
    {
        $this->postJson('/api/v1/admin/communications/99999/forward', [
            'to' => [$this->ben->id],
        ], $this->headers($this->ada))->assertStatus(404);
    }

    // ── directory ─────────────────────────────────────────────────────────

    public function test_the_directory_lists_colleagues_without_exposing_credentials(): void
    {
        $response = $this->getJson('/api/v1/admin/staff-messages/directory', $this->headers($this->ben));

        $response->assertOk()->assertJsonCount(2, 'data');

        $emails = collect($response->json('data'))->pluck('email')->all();
        $this->assertContains('ada@okelcor.com', $emails);
        $this->assertNotContains('ben@okelcor.com', $emails, 'You are not in your own directory.');

        $this->assertArrayNotHasKey('password', $response->json('data.0'));
        $this->assertArrayNotHasKey('two_factor_confirmed_at', $response->json('data.0'));
    }

    public function test_the_directory_is_open_to_every_role_not_just_super_admin(): void
    {
        // Listing admin accounts otherwise sits behind admins.manage
        // (super_admin only) — an order manager could not have seen a single
        // colleague's name to write to.
        $this->getJson('/api/v1/admin/staff-messages/directory', $this->headers($this->chi))
            ->assertOk();
    }

    public function test_a_deactivated_colleague_is_not_in_the_directory(): void
    {
        $this->chi->update(['is_active' => false]);

        $response = $this->getJson('/api/v1/admin/staff-messages/directory', $this->headers($this->ada));

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('ben@okelcor.com', $response->json('data.0.email'));
    }

    // ── delivery failure ──────────────────────────────────────────────────

    public function test_a_failed_email_still_leaves_the_message_in_the_panel(): void
    {
        // The panel thread is the artefact; the e-mail is a copy of it. A
        // mail failure must not lose the message.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP refused'));

        $this->sendFromAdaToBen()
            ->assertStatus(201)
            ->assertJsonPath('meta.email_failures.0', $this->ben->id);

        $this->assertDatabaseCount('staff_messages', 1);
        $this->assertDatabaseHas('staff_message_recipients', [
            'admin_user_id' => $this->ben->id,
            'email_status'  => 'failed',
        ]);

        $this->getJson('/api/v1/admin/staff-messages?box=inbox', $this->headers($this->ben))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ── migration ─────────────────────────────────────────────────────────

    public function test_the_migration_applies_against_real_sql_and_is_idempotent(): void
    {
        Schema::dropIfExists('staff_message_recipients');
        Schema::dropIfExists('staff_messages');

        $migration = require database_path('migrations/2026_08_24_000001_create_staff_messages_tables.php');

        $migration->up();

        $this->assertTrue(Schema::hasTable('staff_messages'));
        $this->assertTrue(Schema::hasTable('staff_message_recipients'));
        $this->assertTrue(Schema::hasColumn('staff_messages', 'forwarded_from_communication_id'));
        $this->assertTrue(Schema::hasColumn('staff_message_recipients', 'email_status'));

        // Re-running must be a no-op, not a "table already exists" failure.
        $migration->up();

        $this->assertTrue(Schema::hasTable('staff_messages'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\AdminUser;
use App\Models\StaffMessage;
use App\Models\StaffMessageRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Internal staff-to-staff messaging — compose, inbox, threading, read
 * tracking, visibility, and the recipients directory.
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=okelcor_cms_test \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=StaffMessaging
 */
class StaffMessagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $connection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($connection !== 'mysql') {
            $this->markTestSkipped('These tests require a MySQL connection (legacy migrations are MySQL-only).');
        }

        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    private function admin(string $role = 'order_manager', array $overrides = []): AdminUser
    {
        return AdminUser::create(array_merge([
            'name'                    => 'Jane Ops',
            'first_name'              => 'Jane',
            'last_name'               => 'Ops',
            'email'                   => 'jane' . uniqid() . '@okelcor.test',
            'role'                    => $role,
            'password'                => Hash::make('secret-pass-123'),
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ], $overrides));
    }

    // ── Compose / deliver ────────────────────────────────────────────────────

    public function test_staff_can_send_message_to_colleagues(): void
    {
        $sender = $this->admin();
        $to     = $this->admin('finance', ['name' => 'Fred Finance']);
        $cc     = $this->admin('support', ['name' => 'Sam Support']);

        $response = $this->actingAs($sender, 'sanctum')
            ->postJson('/api/v1/admin/staff-messages', [
                'to'      => [$to->id],
                'cc'      => [$cc->id],
                'subject' => 'Q3 numbers',
                'body'    => '<p>Please review <b>before Friday</b></p><script>bad()</script>',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.subject', 'Q3 numbers');

        $message = StaffMessage::firstOrFail();
        $this->assertStringNotContainsString('script', $message->body);
        $this->assertStringContainsString('before Friday', $message->body);

        $this->assertDatabaseHas('staff_message_recipients', [
            'staff_message_id'        => $message->id,
            'recipient_admin_user_id' => $to->id,
            'kind'                    => 'to',
        ]);
        $this->assertDatabaseHas('staff_message_recipients', [
            'staff_message_id'        => $message->id,
            'recipient_admin_user_id' => $cc->id,
            'kind'                    => 'cc',
        ]);

        // Delivery channel: the notification bell.
        $this->assertDatabaseHas('admin_notifications', [
            'admin_user_id' => $to->id,
            'type'          => 'staff_message_received',
            'related_type'  => 'staff_message',
            'related_id'    => $message->id,
        ]);
    }

    public function test_signature_is_appended_when_set(): void
    {
        $sender = $this->admin();
        $sender->update(['email_signature' => '<p>Jane Ops — Okelcor</p>']);
        $to = $this->admin('finance');

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/v1/admin/staff-messages', [
                'to'      => [$to->id],
                'subject' => 'With signature',
                'body'    => '<p>Body text</p>',
            ])
            ->assertCreated();

        $this->assertStringContainsString('Jane Ops — Okelcor', StaffMessage::firstOrFail()->body);
    }

    public function test_inactive_recipient_is_rejected(): void
    {
        $sender   = $this->admin();
        $inactive = $this->admin('finance', ['is_active' => false]);

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/v1/admin/staff-messages', [
                'to'      => [$inactive->id],
                'subject' => 'Hello',
                'body'    => '<p>Hi</p>',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_recipients');

        $this->assertSame(0, StaffMessage::count());
    }

    public function test_every_role_can_use_messaging(): void
    {
        $viewer = $this->admin('viewer');
        $to     = $this->admin('finance');

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/v1/admin/staff-messages', [
                'to'      => [$to->id],
                'subject' => 'From the viewer',
                'body'    => '<p>Even viewers can talk</p>',
            ])
            ->assertCreated();
    }

    // ── Inbox / read tracking ────────────────────────────────────────────────

    public function test_inbox_shows_unread_and_mark_read_clears_it(): void
    {
        $sender = $this->admin();
        $to     = $this->admin('finance');

        $this->actingAs($sender, 'sanctum')->postJson('/api/v1/admin/staff-messages', [
            'to'      => [$to->id],
            'subject' => 'Unread test',
            'body'    => '<p>Read me</p>',
        ])->assertCreated();

        $inbox = $this->actingAs($to, 'sanctum')->getJson('/api/v1/admin/staff-messages/inbox');
        $inbox->assertOk()
            ->assertJsonPath('data.0.subject', 'Unread test')
            ->assertJsonPath('data.0.unread', true)
            ->assertJsonPath('meta.unread_count', 1);

        $id = $inbox->json('data.0.id');

        $this->actingAs($to, 'sanctum')
            ->postJson("/api/v1/admin/staff-messages/{$id}/read")
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 0);

        $this->actingAs($to, 'sanctum')
            ->getJson('/api/v1/admin/staff-messages/inbox?unread=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_sender_inbox_does_not_contain_own_message(): void
    {
        $sender = $this->admin();
        $to     = $this->admin('finance');

        $this->actingAs($sender, 'sanctum')->postJson('/api/v1/admin/staff-messages', [
            'to'      => [$to->id],
            'subject' => 'Outgoing',
            'body'    => '<p>Hi</p>',
        ])->assertCreated();

        $this->actingAs($sender, 'sanctum')
            ->getJson('/api/v1/admin/staff-messages/inbox')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($sender, 'sanctum')
            ->getJson('/api/v1/admin/staff-messages/sent')
            ->assertOk()
            ->assertJsonPath('data.0.subject', 'Outgoing')
            ->assertJsonPath('data.0.recipient_count', 1);
    }

    // ── Threading ────────────────────────────────────────────────────────────

    public function test_reply_threads_and_prefixes_subject(): void
    {
        $a = $this->admin();
        $b = $this->admin('finance');

        $first = $this->actingAs($a, 'sanctum')->postJson('/api/v1/admin/staff-messages', [
            'to'      => [$b->id],
            'subject' => 'Thread start',
            'body'    => '<p>First</p>',
        ])->json('data.id');

        $reply = $this->actingAs($b, 'sanctum')->postJson('/api/v1/admin/staff-messages', [
            'to'             => [$a->id],
            'subject'        => 'Thread start',
            'body'           => '<p>Second</p>',
            'in_reply_to_id' => $first,
        ]);

        $reply->assertCreated()
            ->assertJsonPath('data.subject', 'Re: Thread start')
            ->assertJsonPath('data.thread_root_id', $first);

        $show = $this->actingAs($a, 'sanctum')
            ->getJson("/api/v1/admin/staff-messages/{$first}")
            ->assertOk();

        $this->assertCount(2, $show->json('meta.thread'));
    }

    // ── Visibility ───────────────────────────────────────────────────────────

    public function test_third_party_cannot_see_message_or_attachment(): void
    {
        $sender   = $this->admin();
        $to       = $this->admin('finance');
        $outsider = $this->admin('admin');

        $id = $this->actingAs($sender, 'sanctum')->postJson('/api/v1/admin/staff-messages', [
            'to'          => [$to->id],
            'subject'     => 'Private',
            'body'        => '<p>Between us</p>',
            'attachments' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
        ])->json('data.id');

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/v1/admin/staff-messages/{$id}")
            ->assertNotFound();

        $this->actingAs($outsider, 'sanctum')
            ->get("/api/v1/admin/staff-messages/{$id}/attachments/0/download")
            ->assertNotFound();

        $this->actingAs($to, 'sanctum')
            ->get("/api/v1/admin/staff-messages/{$id}/attachments/0/download")
            ->assertOk();
    }

    // ── Recipients directory ─────────────────────────────────────────────────

    public function test_recipients_lists_active_colleagues_and_signature_state(): void
    {
        $me       = $this->admin();
        $active   = $this->admin('finance', ['name' => 'Fred Finance']);
        $inactive = $this->admin('support', ['is_active' => false]);

        $response = $this->actingAs($me, 'sanctum')
            ->getJson('/api/v1/admin/staff-messages/recipients')
            ->assertOk()
            ->assertJsonPath('meta.signature_set', false);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($active->id));
        $this->assertTrue($ids->contains($me->id));
        $this->assertFalse($ids->contains($inactive->id));

        $me->update(['email_signature' => '<p>Sig</p>']);

        $this->actingAs($me, 'sanctum')
            ->getJson('/api/v1/admin/staff-messages/recipients')
            ->assertJsonPath('meta.signature_set', true)
            ->assertJsonPath('meta.signature_html', '<p>Sig</p>');
    }
}

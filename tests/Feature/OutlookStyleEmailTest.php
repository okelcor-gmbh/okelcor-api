<?php

namespace Tests\Feature;

use App\Mail\CustomerAdHocEmail;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\CustomerCommunication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Outlook-style compose/reply — signature save, admin compose/send, portal
 * reply, threading, and permission gating.
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=okelcor_cms_test \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=OutlookStyleEmail
 */
class OutlookStyleEmailTest extends TestCase
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

    private function admin(string $role = 'order_manager'): AdminUser
    {
        return AdminUser::create([
            'name'                    => 'Jane Ops',
            'first_name'              => 'Jane',
            'last_name'               => 'Ops',
            'email'                   => 'jane' . uniqid() . '@okelcor.test',
            'role'                    => $role,
            'password'                => Hash::make('secret-pass-123'),
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function customer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'customer_type'     => 'b2b',
            'first_name'        => 'Acme',
            'last_name'         => 'Buyer',
            'email'             => 'buyer' . uniqid() . '@acme-tyres.com',
            'password'          => Hash::make('secret-pass-123'),
            'country'           => 'DE',
            'company_name'      => 'Acme Tyres GmbH',
            'is_active'         => true,
            'onboarding_status' => 'active',
        ], $overrides));
    }

    // ── Signature ──────────────────────────────────────────────────────────

    public function test_admin_can_save_sanitized_signature(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/profile/signature', [
                'signature_html' => '<p>Jane Ops</p><script>alert(1)</script>',
            ])
            ->assertOk()
            ->assertJsonPath('data.email_signature', '<p>Jane Ops</p>');

        $this->assertSame('<p>Jane Ops</p>', $admin->fresh()->email_signature);
    }

    // ── Compose / send ───────────────────────────────────────────────────────

    public function test_admin_can_send_ad_hoc_email_to_customer(): void
    {
        Mail::fake();
        $admin    = $this->admin();
        $customer = $this->customer();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/customers/{$customer->id}/communications/send-email", [
                'subject' => 'Following up',
                'body'    => '<p>Hello <b>there</b></p><script>bad()</script>',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'sent');
        $response->assertJsonPath('data.channel', 'email');

        Mail::assertSent(CustomerAdHocEmail::class, function ($mail) use ($customer) {
            return $mail->hasTo($customer->email) && str_contains($mail->bodyHtml, 'Hello');
        });

        $this->assertDatabaseHas('customer_communications', [
            'customer_id' => $customer->id,
            'type'        => 'email',
            'direction'   => 'outbound',
            'channel'     => 'email',
            'status'      => 'sent',
        ]);

        $comm = CustomerCommunication::where('customer_id', $customer->id)->firstOrFail();
        $this->assertStringNotContainsString('script', $comm->body);
        $this->assertNotNull($comm->message_id);
    }

    /**
     * Regression test — the signature used to only ever be appended inside
     * the e-mail's own Blade template at send time, never saved into the
     * CustomerCommunication record, so a sent message displayed
     * signature-less inside the admin panel even though the real e-mail
     * had it. Both the sent e-mail and the stored record must now agree.
     */
    public function test_signature_appears_in_both_the_sent_email_and_the_stored_record(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $admin->update(['email_signature' => '<p>Best regards,<br>Jane Ops</p>']);
        $customer = $this->customer();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/customers/{$customer->id}/communications/send-email", [
                'subject' => 'Following up',
                'body'    => '<p>Here is the update you asked for.</p>',
            ])->assertCreated();

        Mail::assertSent(CustomerAdHocEmail::class, fn ($mail) => str_contains($mail->bodyHtml, 'Jane Ops'));

        $comm = CustomerCommunication::where('customer_id', $customer->id)->firstOrFail();
        $this->assertStringContainsString('Jane Ops', $comm->body);
        $this->assertStringContainsString('Here is the update you asked for.', $comm->body);
    }

    public function test_reply_prefixes_subject_and_sets_in_reply_to(): void
    {
        Mail::fake();
        $admin    = $this->admin();
        $customer = $this->customer();

        $first = CustomerCommunication::create([
            'customer_id'   => $customer->id,
            'admin_user_id' => $admin->id,
            'type'          => 'email',
            'direction'     => 'outbound',
            'channel'       => 'email',
            'subject'       => 'Original subject',
            'body'          => 'Hi',
            'message_id'    => 'abc123@okelcor.com',
            'status'        => 'sent',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/customers/{$customer->id}/communications/send-email", [
                'subject'        => 'Original subject',
                'body'           => '<p>Following up</p>',
                'in_reply_to_id' => $first->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.subject', 'Re: Original subject')
            ->assertJsonPath('data.in_reply_to', 'abc123@okelcor.com');
    }

    public function test_rejects_more_than_five_cc_addresses(): void
    {
        $admin    = $this->admin();
        $customer = $this->customer();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/customers/{$customer->id}/communications/send-email", [
                'subject' => 'Hi',
                'body'    => '<p>Hi</p>',
                'cc'      => ['a@x.com', 'b@x.com', 'c@x.com', 'd@x.com', 'e@x.com', 'f@x.com'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cc']);
    }

    public function test_rejects_disallowed_attachment_type(): void
    {
        $admin    = $this->admin();
        $customer = $this->customer();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/customers/{$customer->id}/communications/send-email", [
                'subject'       => 'Hi',
                'body'          => '<p>Hi</p>',
                'attachments'   => [UploadedFile::fake()->create('malware.exe', 10)],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['attachments.0']);
    }

    public function test_missing_recipient_email_returns_422(): void
    {
        $admin    = $this->admin();
        $customer = $this->customer(['email' => 'placeholder@okelcor.test']);
        // Simulate a customer record with no usable e-mail — direct DB write
        // to bypass the model's own required-field expectations in this test.
        \Illuminate\Support\Facades\DB::table('customers')->where('id', $customer->id)->update(['email' => '']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/customers/{$customer->id}/communications/send-email", [
                'subject' => 'Hi',
                'body'    => '<p>Hi</p>',
            ])
            ->assertStatus(422)
            ->assertJson(['code' => 'missing_recipient_email']);
    }

    public function test_role_without_crm_update_permission_is_forbidden(): void
    {
        // 'editor' — valid admin_users.role ENUM value, lacks crm.update.
        $customer = $this->customer();

        $this->actingAs($this->admin('editor'), 'sanctum')
            ->postJson("/api/v1/admin/customers/{$customer->id}/communications/send-email", [
                'subject' => 'Hi',
                'body'    => '<p>Hi</p>',
            ])
            ->assertForbidden();
    }

    public function test_admin_can_mark_communication_read(): void
    {
        $admin    = $this->admin();
        $customer = $this->customer();

        $comm = CustomerCommunication::create([
            'customer_id' => $customer->id,
            'type'        => 'email',
            'direction'   => 'inbound',
            'channel'     => 'portal',
            'subject'     => 'Question',
            'body'        => 'Hi',
            'status'      => 'completed',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/communications/{$comm->id}/read")
            ->assertOk()
            ->assertJsonPath('data.staff_read_at', fn ($v) => $v !== null);
    }

    // ── Customer portal side ──────────────────────────────────────────────

    public function test_customer_sees_only_own_email_type_communications(): void
    {
        $customer = $this->customer();
        $other    = $this->customer();

        CustomerCommunication::create([
            'customer_id' => $customer->id, 'type' => 'email', 'direction' => 'outbound',
            'channel' => 'email', 'subject' => 'Mine', 'body' => 'Hi', 'status' => 'sent',
        ]);
        CustomerCommunication::create([
            'customer_id' => $customer->id, 'type' => 'note', 'direction' => 'internal',
            'subject' => 'Internal note', 'body' => 'Not for customer eyes', 'status' => 'completed',
        ]);
        CustomerCommunication::create([
            'customer_id' => $other->id, 'type' => 'email', 'direction' => 'outbound',
            'channel' => 'email', 'subject' => 'Not mine', 'body' => 'Hi', 'status' => 'sent',
        ]);

        $token = $customer->createToken('portal')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/customer/communications');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.subject', 'Mine');
    }

    public function test_customer_can_reply_and_staff_is_notified(): void
    {
        $customer = $this->customer();

        $original = CustomerCommunication::create([
            'customer_id' => $customer->id, 'type' => 'email', 'direction' => 'outbound',
            'channel' => 'email', 'subject' => 'Your quote', 'body' => 'Hi',
            'message_id' => 'orig@okelcor.com', 'status' => 'sent',
        ]);

        // A crm.view-permitted admin exists to receive the fan-out notification.
        $this->admin('order_manager');

        $token = $customer->createToken('portal')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/auth/customer/communications/{$original->id}/reply", [
                'body' => '<p>Thanks, sounds good</p>',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.direction', 'inbound');
        $response->assertJsonPath('data.channel', 'portal');
        $response->assertJsonPath('data.subject', 'Re: Your quote');
        $response->assertJsonPath('data.in_reply_to', 'orig@okelcor.com');

        $this->assertDatabaseHas('admin_notifications', [
            'type' => 'customer_message_reply',
        ]);
    }

    public function test_customer_can_mark_outbound_message_read(): void
    {
        $customer = $this->customer();

        $comm = CustomerCommunication::create([
            'customer_id' => $customer->id, 'type' => 'email', 'direction' => 'outbound',
            'channel' => 'email', 'subject' => 'Hi', 'body' => 'Hi', 'status' => 'sent',
        ]);

        $token = $customer->createToken('portal')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/auth/customer/communications/{$comm->id}/read")
            ->assertOk();

        $this->assertNotNull($comm->fresh()->customer_read_at);
    }

    public function test_customer_cannot_reply_to_another_customers_message(): void
    {
        $customer = $this->customer();
        $other    = $this->customer();

        $otherComm = CustomerCommunication::create([
            'customer_id' => $other->id, 'type' => 'email', 'direction' => 'outbound',
            'channel' => 'email', 'subject' => 'Not yours', 'body' => 'Hi', 'status' => 'sent',
        ]);

        $token = $customer->createToken('portal')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/auth/customer/communications/{$otherComm->id}/reply", ['body' => 'Hi'])
            ->assertStatus(404);
    }
}

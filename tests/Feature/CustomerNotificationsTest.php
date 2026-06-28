<?php

namespace Tests\Feature;

use App\Http\Controllers\CustomerNotificationController;
use App\Http\Controllers\CustomerNotificationPreferenceController;
use App\Http\Controllers\Admin\AdminCustomerAccessRequestController;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\CustomerAccessRequest;
use App\Models\CustomerNotification;
use App\Services\CustomerNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Customer Portal Notifications — "Email = Inbox" (backend).
 *
 * Mirrors the CRM-3B test harness: runs the full migration set on MySQL
 * (RefreshDatabase) and drives the service + controllers directly.
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=okelcor_cms_test \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=CustomerNotifications
 */
class CustomerNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $connection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($connection !== 'mysql') {
            $this->markTestSkipped('Customer notification tests require a MySQL connection (legacy migrations are MySQL-only).');
        }

        parent::setUp();
    }

    private int $adminSeq = 0;

    private function customer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'customer_type'     => 'b2b',
            'first_name'        => 'Acme',
            'last_name'         => 'Buyer',
            'email'             => 'buyer' . uniqid() . '@acme-tyres.com',
            'password'          => Hash::make('secret-pass-123'),
            'phone'             => '+49 30 1234567',
            'country'           => 'DE',
            'company_name'      => 'Acme Tyres GmbH',
            'is_active'         => true,
            'onboarding_status' => 'active',
        ], $overrides));
    }

    private function admin(string $role = 'super_admin'): AdminUser
    {
        return AdminUser::create([
            'name'      => 'Admin ' . (++$this->adminSeq),
            'email'     => 'admin' . uniqid() . '@okelcor.test',
            'role'      => $role,
            'password'  => Hash::make('secret-pass-123'),
            'is_active' => true,
        ]);
    }

    private function customerRequest(Customer $customer, array $input = [], string $method = 'POST'): Request
    {
        $req = Request::create('/', $method, $input);
        $req->setUserResolver(fn () => $customer);
        return $req;
    }

    private function adminRequest(AdminUser $admin, array $input = []): Request
    {
        $req = Request::create('/', 'POST', $input);
        $req->setUserResolver(fn () => $admin);
        return $req;
    }

    // ── Schema ─────────────────────────────────────────────────────────────────

    public function test_migration_creates_table_and_preferences_column(): void
    {
        $this->assertTrue(Schema::hasTable('customer_notifications'));
        foreach (['customer_id', 'type', 'title', 'body', 'severity', 'action_url', 'related_type', 'related_id', 'read_at', 'dismissed_at', 'email_sent_at', 'metadata'] as $col) {
            $this->assertTrue(Schema::hasColumn('customer_notifications', $col), "missing column {$col}");
        }
        $this->assertTrue(Schema::hasColumn('customers', 'notification_preferences'));
    }

    // ── Service: create / dedupe / counts / read / dismiss ──────────────────────

    public function test_notify_creates_row_with_email_timestamp(): void
    {
        $c = $this->customer();

        $n = CustomerNotifier::notify($c, 'payment_milestone', 'Deposit received for AB-1', 'We got your deposit.', [
            'severity'     => 'success',
            'action_url'   => '/account/orders/AB-1',
            'related_type' => 'order',
            'related_id'   => 'AB-1',
            'email_sent'   => true,
            'metadata'     => ['stage' => 'deposit_paid'],
        ]);

        $this->assertNotNull($n);
        $this->assertSame('success', $n->severity);
        $this->assertNotNull($n->email_sent_at);
        $this->assertSame('AB-1', $n->related_id);
        $this->assertSame(1, CustomerNotifier::unreadCount($c));
    }

    public function test_dedupe_suppresses_duplicate_unread_and_refreshes_email_sent_at(): void
    {
        $c = $this->customer();

        $a = CustomerNotifier::notify($c, 'payment_milestone', 'Deposit due', null, [
            'related_type' => 'order', 'related_id' => 'AB-9', 'metadata' => ['stage' => 'deposit_requested'],
        ]);
        // Same logical event, now emailed (a resend) — must NOT spawn a 2nd row.
        $b = CustomerNotifier::notify($c, 'payment_milestone', 'Deposit due (resend)', null, [
            'related_type' => 'order', 'related_id' => 'AB-9', 'email_sent' => true, 'metadata' => ['stage' => 'deposit_requested'],
        ]);

        $this->assertNotNull($a);
        $this->assertSame($a->id, $b->id, 'Resend must return the same row, not a duplicate.');
        $this->assertSame(1, CustomerNotification::where('customer_id', $c->id)->count());
        $this->assertNotNull($b->fresh()->email_sent_at, 'Resend should refresh email_sent_at on the existing row.');
    }

    public function test_dedupe_distinguishes_stage(): void
    {
        $c = $this->customer();

        CustomerNotifier::notify($c, 'payment_milestone', 'Deposit', null, [
            'related_type' => 'order', 'related_id' => 'AB-5', 'metadata' => ['stage' => 'deposit_paid'],
        ]);
        CustomerNotifier::notify($c, 'payment_milestone', 'Balance', null, [
            'related_type' => 'order', 'related_id' => 'AB-5', 'metadata' => ['stage' => 'balance_due'],
        ]);

        $this->assertSame(2, CustomerNotification::where('customer_id', $c->id)->count());
    }

    public function test_mark_read_all_and_dismiss(): void
    {
        $c = $this->customer();
        $one = CustomerNotifier::notify($c, 'welcome', 'One', null, ['related_id' => '1']);
        $two = CustomerNotifier::notify($c, 'welcome', 'Two', null, ['related_id' => '2']);

        $this->assertSame(2, CustomerNotifier::unreadCount($c));

        CustomerNotifier::markRead($one->id, $c);
        $this->assertSame(1, CustomerNotifier::unreadCount($c));

        CustomerNotifier::markAllRead($c);
        $this->assertSame(0, CustomerNotifier::unreadCount($c));

        CustomerNotifier::dismiss($two->id, $c);
        $this->assertNotNull($two->fresh()->dismissed_at);
    }

    public function test_actions_are_scoped_to_owner(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        $n = CustomerNotifier::notify($owner, 'welcome', 'X', null, ['related_id' => '1']);

        $this->assertNull(CustomerNotifier::markRead($n->id, $other));
        $this->assertNull(CustomerNotifier::dismiss($n->id, $other));
        $this->assertSame(1, CustomerNotifier::unreadCount($owner));
    }

    public function test_action_url_must_be_relative(): void
    {
        $c = $this->customer();

        $bad = CustomerNotifier::notify($c, 'announcement', 'Ad', null, ['action_url' => 'https://evil.example/x']);
        $this->assertNull($bad->action_url, 'Absolute URLs must be rejected.');

        $rel = CustomerNotifier::notify($c, 'announcement', 'Ad2', null, ['action_url' => 'account/news', 'related_id' => '2']);
        $this->assertSame('/account/news', $rel->action_url, 'Relative path normalised with leading slash.');
    }

    // ── Preferences ─────────────────────────────────────────────────────────────

    public function test_default_preferences_marketing_off_orders_on(): void
    {
        $c = $this->customer();
        $prefs = CustomerNotifier::preferencesFor($c);

        $this->assertTrue($prefs['email_orders']);
        $this->assertFalse($prefs['email_marketing']);
        $this->assertTrue(CustomerNotifier::wantsEmail($c, 'order_placed'));
        $this->assertTrue(CustomerNotifier::wantsEmail($c, 'security_alert'));
        $this->assertFalse(CustomerNotifier::wantsEmail($c, 'announcement'));
    }

    public function test_preferences_gate_email_except_forced_groups(): void
    {
        $c = $this->customer();

        // Opt out of documents + quotes; keep marketing default off.
        $c->update(['notification_preferences' => [
            'email_documents' => false,
            'email_quotes'    => false,
        ]]);

        $this->assertFalse(CustomerNotifier::wantsEmail($c, 'document_ready'));
        $this->assertFalse(CustomerNotifier::wantsEmail($c, 'quote_ready'));
        // Orders + security still forced on regardless.
        $this->assertTrue(CustomerNotifier::wantsEmail($c, 'payment_milestone'));
        $this->assertTrue(CustomerNotifier::wantsEmail($c, 'security_alert'));
    }

    public function test_update_preferences_endpoint_forces_orders_on(): void
    {
        $c = $this->customer();
        $controller = new CustomerNotificationPreferenceController();

        $resp = $controller->update($this->customerRequest($c, [
            'email_orders'    => false,   // client tries to disable — must be ignored
            'email_marketing' => true,
        ], 'PUT'))->getData(true);

        $this->assertTrue($resp['data']['email_orders'], 'email_orders is forced on.');
        $this->assertTrue($resp['data']['email_marketing']);
        $this->assertTrue($c->fresh()->notification_preferences['email_orders']);
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    public function test_index_excludes_dismissed_and_reports_unread_count(): void
    {
        $c = $this->customer();
        $a = CustomerNotifier::notify($c, 'welcome', 'A', null, ['related_id' => '1']);
        CustomerNotifier::notify($c, 'welcome', 'B', null, ['related_id' => '2']);
        CustomerNotifier::dismiss($a->id, $c);

        $controller = new CustomerNotificationController();
        $payload = $controller->index($this->customerRequest($c, [], 'GET'))->getData(true);

        $this->assertCount(1, $payload['data'], 'Dismissed notifications excluded from list.');
        $this->assertSame(1, $payload['unread_count']);
        $this->assertSame(15, $payload['meta']['per_page']);
    }

    public function test_unread_filter_and_count_endpoint(): void
    {
        $c = $this->customer();
        $read = CustomerNotifier::notify($c, 'welcome', 'R', null, ['related_id' => '1']);
        CustomerNotifier::notify($c, 'welcome', 'U', null, ['related_id' => '2']);
        CustomerNotifier::markRead($read->id, $c);

        $controller = new CustomerNotificationController();

        $unread = $controller->index($this->customerRequest($c, ['unread' => 1], 'GET'))->getData(true);
        $this->assertSame(1, $unread['meta']['total']);

        $count = $controller->unreadCount($this->customerRequest($c, [], 'GET'))->getData(true);
        $this->assertSame(1, $count['unread_count']);
    }

    public function test_mark_read_endpoint_404_for_other_customer(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        $n = CustomerNotifier::notify($owner, 'welcome', 'X', null, ['related_id' => '1']);

        $resp = (new CustomerNotificationController())->markRead($this->customerRequest($other), $n->id);
        $this->assertSame(404, $resp->getStatusCode());
    }

    // ── notifyByEmail (order/quote flows) ───────────────────────────────────────

    public function test_notify_by_email_resolves_account_and_skips_guests(): void
    {
        $c = $this->customer(['email' => 'known@acme-tyres.com']);

        $hit = CustomerNotifier::notifyByEmail('known@acme-tyres.com', 'order_placed', 'Order received', null, ['related_id' => 'AB-1']);
        $miss = CustomerNotifier::notifyByEmail('guest@nobody.test', 'order_placed', 'Order received', null, ['related_id' => 'AB-2']);

        $this->assertNotNull($hit);
        $this->assertSame($c->id, $hit->customer_id);
        $this->assertNull($miss, 'Guest email with no account produces no notification.');
    }

    // ── Trigger: access request approval (in-app twin) ─────────────────────────

    public function test_access_request_approval_notifies_customer(): void
    {
        $manager  = $this->admin('admin');
        $customer = $this->customer();

        $accessRequest = CustomerAccessRequest::create([
            'customer_id'      => $customer->id,
            'requested_access' => 'checkout',
            'status'           => 'pending',
            'reason'           => 'Need to order directly.',
        ]);

        (new AdminCustomerAccessRequestController())
            ->approve($this->adminRequest($manager, ['notes' => 'ok']), $accessRequest->id);

        $this->assertDatabaseHas('customer_notifications', [
            'customer_id'  => $customer->id,
            'type'         => 'access_request_update',
            'related_type' => 'access_request',
            'related_id'   => (string) $accessRequest->id,
        ]);
    }
}

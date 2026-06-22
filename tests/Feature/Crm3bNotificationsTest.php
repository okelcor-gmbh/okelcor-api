<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\AdminQuoteRequestController;
use App\Http\Controllers\Admin\AdminWorkQueueController;
use App\Http\Controllers\CustomerAccessRequestController;
use App\Models\AdminNotification;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\QuoteRequest;
use App\Services\AdminNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * CRM-3B — Admin Notification Center & Assignment Work Queue (backend).
 *
 * Mirrors the CRM-8 test harness: runs the full migration set on MySQL
 * (RefreshDatabase) and drives the services + controllers directly, bypassing
 * the admin 2FA HTTP middleware while exercising real business logic.
 *
 * Run with:
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=<test_db> \
 *   DB_USERNAME=root DB_PASSWORD= php artisan test --filter=Crm3b
 */
class Crm3bNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $connection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($connection !== 'mysql') {
            $this->markTestSkipped('CRM-3B tests require a MySQL connection (legacy migrations are MySQL-only).');
        }

        parent::setUp();
    }

    private int $adminSeq = 0;

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

    private function quote(array $overrides = []): QuoteRequest
    {
        return QuoteRequest::create(array_merge([
            'ref_number'           => 'QR-' . uniqid(),
            'full_name'            => 'Acme Buyer',
            'company_name'         => 'Acme Tyres GmbH',
            'email'                => 'buyer' . uniqid() . '@acme-tyres.com',
            'country'              => 'DE',
            'tyre_category'        => 'pcr',
            'quantity'             => '100',
            'delivery_location'    => 'Berlin, DE',
            'notes'                => 'Test inquiry',
            'status'               => 'new',
            'qualification_status' => 'new',
        ], $overrides));
    }

    private function adminRequest(AdminUser $admin, array $input = []): Request
    {
        $req = Request::create('/', 'POST', $input);
        $req->setUserResolver(fn () => $admin);
        return $req;
    }

    private function customerRequest(Customer $customer, array $input = []): Request
    {
        $req = Request::create('/', 'POST', $input);
        $req->setUserResolver(fn () => $customer);
        return $req;
    }

    // ── Schema ────────────────────────────────────────────────────────────────

    public function test_migration_adds_crm3b_columns(): void
    {
        foreach (['severity', 'body', 'action_url', 'related_type', 'related_id', 'dismissed_at', 'metadata'] as $col) {
            $this->assertTrue(
                Schema::hasColumn('admin_notifications', $col),
                "admin_notifications is missing column {$col}"
            );
        }
    }

    // ── Lead assignment trigger ────────────────────────────────────────────────

    public function test_assigning_lead_creates_notification_for_assignee(): void
    {
        $actor    = $this->admin();
        $assignee = $this->admin('order_manager');
        $quote    = $this->quote();

        $controller = new AdminQuoteRequestController();
        $controller->assign($this->adminRequest($actor, ['assigned_to' => $assignee->id]), $quote->id);

        $this->assertDatabaseHas('admin_notifications', [
            'admin_user_id' => $assignee->id,
            'type'          => 'lead_assigned',
            'related_type'  => 'quote_request',
            'related_id'    => $quote->id,
        ]);
        $this->assertSame(1, AdminNotification::where('admin_user_id', $assignee->id)->count());
    }

    public function test_reassigning_lead_notifies_new_owner(): void
    {
        $actor = $this->admin();
        $first = $this->admin('order_manager');
        $second = $this->admin('order_manager');
        $quote = $this->quote();

        $controller = new AdminQuoteRequestController();
        $controller->assign($this->adminRequest($actor, ['assigned_to' => $first->id]), $quote->id);
        $controller->assign($this->adminRequest($actor, ['assigned_to' => $second->id]), $quote->id);

        $this->assertSame(1, AdminNotification::where('admin_user_id', $first->id)->where('type', 'lead_assigned')->count());
        $this->assertSame(1, AdminNotification::where('admin_user_id', $second->id)->where('type', 'lead_assigned')->count());
    }

    public function test_no_duplicate_notification_on_same_assignment(): void
    {
        $actor    = $this->admin();
        $assignee = $this->admin('order_manager');
        $quote    = $this->quote();

        $controller = new AdminQuoteRequestController();
        // Same assignee twice — controller guard + service dedupe must both hold.
        $controller->assign($this->adminRequest($actor, ['assigned_to' => $assignee->id]), $quote->id);
        $controller->assign($this->adminRequest($actor, ['assigned_to' => $assignee->id]), $quote->id);

        $this->assertSame(1, AdminNotification::where('admin_user_id', $assignee->id)->where('type', 'lead_assigned')->count());
    }

    // ── Service: dedupe / counts / read / dismiss ──────────────────────────────

    public function test_dedupe_suppresses_duplicate_unread(): void
    {
        $admin = $this->admin();

        $a = AdminNotificationService::notifyUser($admin->id, 'lead_assigned', 'A', null, '/x', 'info', 'quote_request', 99);
        $b = AdminNotificationService::notifyUser($admin->id, 'lead_assigned', 'A again', null, '/x', 'info', 'quote_request', 99);

        $this->assertNotNull($a);
        $this->assertNull($b, 'Second unread notification with same dedupe key must be suppressed.');
        $this->assertSame(1, AdminNotification::where('admin_user_id', $admin->id)->count());
    }

    public function test_dedupe_allows_new_after_read(): void
    {
        $admin = $this->admin();

        $a = AdminNotificationService::notifyUser($admin->id, 'lead_assigned', 'A', null, '/x', 'info', 'quote_request', 99);
        AdminNotificationService::markRead($a->id, $admin);

        // Default dedupe only checks unread, so a fresh one is allowed once read.
        $b = AdminNotificationService::notifyUser($admin->id, 'lead_assigned', 'A', null, '/x', 'info', 'quote_request', 99);
        $this->assertNotNull($b);

        // includeRead=true suppresses even against read rows.
        $c = AdminNotificationService::notifyUser($admin->id, 'lead_assigned', 'A', null, '/x', 'info', 'quote_request', 99, [], null, true);
        $this->assertNull($c);
    }

    public function test_unread_count_and_mark_read(): void
    {
        $admin = $this->admin();

        AdminNotificationService::notifyUser($admin->id, 'lead_assigned', 'One', null, null, 'info', 'quote_request', 1);
        $two = AdminNotificationService::notifyUser($admin->id, 'lead_assigned', 'Two', null, null, 'info', 'quote_request', 2);

        $this->assertSame(2, AdminNotificationService::unreadCount($admin));

        AdminNotificationService::markRead($two->id, $admin);
        $this->assertSame(1, AdminNotificationService::unreadCount($admin));

        AdminNotificationService::markAllRead($admin);
        $this->assertSame(0, AdminNotificationService::unreadCount($admin));
    }

    public function test_mark_read_is_scoped_to_owner(): void
    {
        $owner   = $this->admin();
        $other   = $this->admin();
        $n = AdminNotificationService::notifyUser($owner->id, 'lead_assigned', 'X', null, null, 'info', 'quote_request', 5);

        // Another admin cannot read someone else's notification.
        $this->assertNull(AdminNotificationService::markRead($n->id, $other));
        $this->assertSame(1, AdminNotificationService::unreadCount($owner));
    }

    public function test_dismiss_removes_from_unread_and_list(): void
    {
        $admin = $this->admin();
        $n = AdminNotificationService::notifyUser($admin->id, 'lead_assigned', 'X', null, null, 'info', 'quote_request', 7);

        AdminNotificationService::dismiss($n->id, $admin);

        $this->assertSame(0, AdminNotificationService::unreadCount($admin));

        $resp = (new AdminNotificationController())->index($this->adminRequest($admin));
        $payload = $resp->getData(true);
        $this->assertCount(0, $payload['data'], 'Dismissed notifications must not appear in the feed.');
    }

    // ── Endpoints ───────────────────────────────────────────────────────────────

    public function test_index_unread_filter_and_unread_count_endpoint(): void
    {
        $admin = $this->admin();
        $read = AdminNotificationService::notifyUser($admin->id, 'lead_assigned', 'R', null, null, 'info', 'quote_request', 1);
        AdminNotificationService::notifyUser($admin->id, 'lead_assigned', 'U', null, null, 'warning', 'quote_request', 2);
        AdminNotificationService::markRead($read->id, $admin);

        $controller = new AdminNotificationController();

        $all = $controller->index($this->adminRequest($admin))->getData(true);
        $this->assertSame(2, $all['meta']['total']);
        $this->assertSame(1, $all['meta']['unread_count']);

        $unreadReq = Request::create('/', 'GET', ['unread' => 1]);
        $unreadReq->setUserResolver(fn () => $admin);
        $unread = $controller->index($unreadReq)->getData(true);
        $this->assertSame(1, $unread['meta']['total']);

        $count = $controller->unreadCount($this->adminRequest($admin))->getData(true);
        $this->assertSame(1, $count['unread_count']);
    }

    // ── Customer access request trigger ────────────────────────────────────────

    public function test_customer_access_request_notifies_managers(): void
    {
        $manager  = $this->admin('admin');            // holds customers.manage
        $support  = $this->admin('editor');           // does NOT hold customers.manage
        $customer = $this->customer();

        $controller = new CustomerAccessRequestController();
        $controller->store($this->customerRequest($customer, [
            'requested_access' => 'checkout',
            'reason'           => 'Need to place orders directly.',
        ]));

        $this->assertDatabaseHas('admin_notifications', [
            'admin_user_id' => $manager->id,
            'type'          => 'customer_access_requested',
            'related_type'  => 'customer',
            'related_id'    => $customer->id,
        ]);
        $this->assertSame(0, AdminNotification::where('admin_user_id', $support->id)->count());
    }

    // ── Permission fan-out ──────────────────────────────────────────────────────

    public function test_notify_permission_targets_only_eligible_active_admins(): void
    {
        $superAdmin = $this->admin('super_admin');
        $admin      = $this->admin('admin');
        $inactive   = $this->admin('admin');
        $inactive->update(['is_active' => false]);
        $ineligible = $this->admin('editor');

        $created = AdminNotificationService::notifyPermission(
            'customers.manage', 'customer_approval_needed', 'New buyer', 'pending', '/admin/customer-approvals', 'warning', 'customer', 1
        );

        // super_admin + admin only; inactive admin and editor excluded.
        $this->assertSame(2, $created);
        $this->assertSame(1, AdminNotification::where('admin_user_id', $superAdmin->id)->count());
        $this->assertSame(1, AdminNotification::where('admin_user_id', $admin->id)->count());
        $this->assertSame(0, AdminNotification::where('admin_user_id', $inactive->id)->count());
        $this->assertSame(0, AdminNotification::where('admin_user_id', $ineligible->id)->count());
    }

    // ── Follow-up due command ─────────────────────────────────────────────────

    public function test_due_followups_command_notifies_owner_and_dedupes(): void
    {
        $owner = $this->admin('order_manager');
        $quote = $this->quote([
            'assigned_to'  => $owner->id,
            'follow_up_at' => now()->subDay(),
        ]);

        $this->artisan('admin:notifications:due-followups')->assertSuccessful();

        $this->assertSame(1, AdminNotification::where('admin_user_id', $owner->id)->where('type', 'follow_up_due')->count());

        // Re-running the same day must not create a second notification.
        $this->artisan('admin:notifications:due-followups')->assertSuccessful();
        $this->assertSame(1, AdminNotification::where('admin_user_id', $owner->id)->where('type', 'follow_up_due')->count());
    }

    public function test_due_followups_command_ignores_completed_and_closed(): void
    {
        $owner = $this->admin('order_manager');
        $this->quote(['assigned_to' => $owner->id, 'follow_up_at' => now()->subDay(), 'follow_up_completed_at' => now()]);
        $this->quote(['assigned_to' => $owner->id, 'follow_up_at' => now()->subDay(), 'qualification_status' => 'converted']);

        $this->artisan('admin:notifications:due-followups')->assertSuccessful();

        $this->assertSame(0, AdminNotification::where('admin_user_id', $owner->id)->count());
    }

    // ── Work queue ───────────────────────────────────────────────────────────────

    public function test_my_work_returns_assigned_lead_and_due_followup(): void
    {
        $owner = $this->admin('order_manager');
        $this->quote(['assigned_to' => $owner->id]); // assigned lead, no follow-up
        $this->quote(['assigned_to' => $owner->id, 'follow_up_at' => now()->subDay()]); // overdue follow-up

        $resp = (new AdminWorkQueueController())->index($this->adminRequest($owner));
        $data = $resp->getData(true)['data'];

        $this->assertCount(2, $data['assigned_leads']);
        $this->assertCount(1, $data['due_follow_ups']);
        $this->assertSame('urgent', $data['due_follow_ups'][0]['priority']);
    }

    public function test_my_work_hides_customer_queues_without_permission(): void
    {
        $support = $this->admin('editor'); // no customers.manage
        $this->customer(['onboarding_status' => 'pending_review', 'is_active' => false]);

        $resp = (new AdminWorkQueueController())->index($this->adminRequest($support));
        $meta = $resp->getData(true)['meta'];

        $this->assertFalse($meta['can_manage_customers']);
        $this->assertSame(0, $meta['counts']['pending_approvals']);
    }
}

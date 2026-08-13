<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\FinanceInvoice;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\OrderSignoff;
use App\Services\OperationsSummaryService;
use App\Services\OrderSignoffService;
use App\Support\AdminPermissions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Dual sign-off on an order confirmation, the operations board, and the
 * separation of eBay orders from the rest.
 *
 * Minimal-schema sqlite harness, same pattern as CampaignDraftTest and
 * InDesignCampaignImportTest, so this runs in CI rather than behind the MySQL
 * gate.
 */
class OrderSignoffAndBoardTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();

        foreach ([
            'order_signoffs', 'finance_invoices', 'order_logs', 'invoices',
            'orders', 'personal_access_tokens', 'admin_users',
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
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('delivery_cost', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency', 3)->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('payment_status', 30)->default('unpaid');
            $table->string('payment_stage', 40)->nullable();
            $table->string('payment_session_id')->nullable();
            $table->string('customer_acceptance_status', 30)->nullable();
            $table->timestamps();
        });

        Schema::create('order_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
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

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('invoice_number', 50)->nullable();
            $table->string('order_ref', 30)->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('status', 30)->default('issued');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });

        // The real migrations, run against real SQL — the sign-off unique index
        // is the thing enforcing "one live signature per slot", so a hand-built
        // table would be testing a different schema from the one that ships.
        $this->runMigration('2026_08_13_000002_create_order_signoffs_table');
        $this->runMigration('2026_08_13_000003_create_finance_invoices_table');

        Schema::enableForeignKeyConstraints();

        // The service memoises whether its table exists, and the harness
        // creates it after the container boots.
        OrderSignoffService::forgetTableCheck();
    }

    private function runMigration(string $name): void
    {
        (require database_path("migrations/{$name}.php"))->up();
    }

    // ── harness ───────────────────────────────────────────────────────────

    private function admin(string $role): AdminUser
    {
        $this->seq++;

        return AdminUser::create([
            'name'                    => ucfirst($role) . ' ' . $this->seq,
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

    private function order(array $attributes = []): Order
    {
        $this->seq++;

        // created_at is not fillable, so backdating has to be forced after the
        // insert — passing it to create() is silently ignored, which made the
        // grandfathering test pass a today-dated order off as an old one.
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $order = Order::create(array_merge([
            'ref'            => 'OKL-T' . str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
            'source'         => 'website',
            'customer_name'  => 'Acme GmbH',
            'customer_email' => 'buyer' . $this->seq . '@acme.de',
            'total'          => 10000,
            'subtotal'       => 10000,
            'currency'       => 'EUR',
            'status'         => 'confirmed',
            'payment_status' => 'unpaid',
        ], $attributes));

        if ($createdAt !== null) {
            $order->forceFill(['created_at' => $createdAt])->saveQuietly();
        }

        return $order->fresh();
    }

    private function service(): OrderSignoffService
    {
        return app(OrderSignoffService::class);
    }

    // ── the role that made this possible ──────────────────────────────────

    public function test_the_finance_role_exists_and_holds_only_its_half(): void
    {
        // The blocker for the whole feature: admin_users.role was an ENUM that
        // could not store a finance role, so the finance signature had nobody
        // to give it. Widened in the migration alongside this.
        $this->assertContains('finance', AdminPermissions::ROLES);

        $this->assertTrue(AdminPermissions::can('finance', 'orders.signoff_finance'));
        $this->assertFalse(AdminPermissions::can('finance', 'orders.signoff_ops'));

        $this->assertTrue(AdminPermissions::can('order_manager', 'orders.signoff_ops'));
        $this->assertFalse(AdminPermissions::can('order_manager', 'orders.signoff_finance'));

        // A control any single administrator can satisfy alone is not a
        // separation of duties. `admin` deliberately holds neither.
        $this->assertFalse(AdminPermissions::can('admin', 'orders.signoff_ops'));
        $this->assertFalse(AdminPermissions::can('admin', 'orders.signoff_finance'));

        // And the role has to be storable, which is the part that was broken.
        $finance = $this->admin('finance');
        $this->assertSame('finance', $finance->fresh()->role);
    }

    // ── two signatures, two people ────────────────────────────────────────

    public function test_two_different_people_sign_and_the_confirmation_unlocks(): void
    {
        $order = $this->order();
        $ops   = $this->admin('order_manager');
        $fin   = $this->admin('finance');

        $this->withHeaders($this->headers($ops))
            ->postJson("/api/v1/admin/orders/{$order->id}/signoffs", ['slot' => 'ops', 'note' => 'Stock confirmed'])
            ->assertStatus(201)
            ->assertJsonPath('data.complete', false)
            ->assertJsonPath('data.status', 'partial');

        $this->withHeaders($this->headers($fin))
            ->postJson("/api/v1/admin/orders/{$order->id}/signoffs", ['slot' => 'finance'])
            ->assertStatus(201)
            ->assertJsonPath('data.complete', true)
            ->assertJsonPath('data.status', 'complete');

        $this->assertTrue($this->service()->isComplete($order->fresh()));
    }

    public function test_one_person_cannot_sign_both_halves(): void
    {
        // The whole point. super_admin holds both permissions as break-glass,
        // so without this the control is satisfiable alone — which is a
        // checkbox, not a separation of duties.
        $order = $this->order();
        $root  = $this->admin('super_admin');

        $this->withHeaders($this->headers($root))
            ->postJson("/api/v1/admin/orders/{$order->id}/signoffs", ['slot' => 'ops'])
            ->assertStatus(201);

        $this->withHeaders($this->headers($root))
            ->postJson("/api/v1/admin/orders/{$order->id}/signoffs", ['slot' => 'finance'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'same_person');

        $this->assertFalse($this->service()->isComplete($order->fresh()));
    }

    public function test_a_role_cannot_sign_the_half_it_does_not_hold(): void
    {
        $order = $this->order();

        $this->withHeaders($this->headers($this->admin('order_manager')))
            ->postJson("/api/v1/admin/orders/{$order->id}/signoffs", ['slot' => 'finance'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'not_entitled');

        $this->withHeaders($this->headers($this->admin('finance')))
            ->postJson("/api/v1/admin/orders/{$order->id}/signoffs", ['slot' => 'ops'])
            ->assertStatus(403);
    }

    public function test_reading_the_panel_does_not_sign_anything(): void
    {
        // The obvious way to answer "may I sign?" is to call the writer and see
        // if it succeeds, which would leave a signature behind every time
        // anyone opened an order.
        $order = $this->order();
        $ops   = $this->admin('order_manager');

        $response = $this->withHeaders($this->headers($ops))
            ->getJson("/api/v1/admin/orders/{$order->id}/signoffs")
            ->assertOk();

        $this->assertSame(['ops'], $response->json('data.you_may_sign'));
        $this->assertSame(0, $response->json('data.signed_count'));
        $this->assertSame(0, OrderSignoff::count());
    }

    public function test_a_withdrawn_signature_stays_on_the_record(): void
    {
        $order = $this->order();
        $ops   = $this->admin('order_manager');

        $this->withHeaders($this->headers($ops))
            ->postJson("/api/v1/admin/orders/{$order->id}/signoffs", ['slot' => 'ops'])
            ->assertStatus(201);

        $this->withHeaders($this->headers($ops))
            ->deleteJson("/api/v1/admin/orders/{$order->id}/signoffs/ops", ['reason' => 'Price was wrong'])
            ->assertOk()
            ->assertJsonPath('data.signed_count', 0);

        // Deleted from the live set, not from history — a sign-off that can be
        // quietly removed is one nobody can rely on afterwards.
        $this->assertSame(1, OrderSignoff::count());
        $this->assertCount(1, $this->service()->state($order->fresh())['history']);
        $this->assertTrue($this->service()->state($order->fresh())['history'][0]['revoked']);

        // And the slot is free again, so it can be signed properly.
        $this->withHeaders($this->headers($ops))
            ->postJson("/api/v1/admin/orders/{$order->id}/signoffs", ['slot' => 'ops'])
            ->assertStatus(201);
    }

    public function test_withdrawing_a_signature_needs_a_reason(): void
    {
        $order = $this->order();
        $ops   = $this->admin('order_manager');

        $this->withHeaders($this->headers($ops))
            ->postJson("/api/v1/admin/orders/{$order->id}/signoffs", ['slot' => 'ops']);

        $this->withHeaders($this->headers($ops))
            ->deleteJson("/api/v1/admin/orders/{$order->id}/signoffs/ops", [])
            ->assertStatus(422);
    }

    public function test_changing_the_money_withdraws_both_signatures(): void
    {
        // Approving €10,000 and then sending a confirmation for €10,500 is
        // worse than no approval at all, because it carries evidence that two
        // people agreed to it.
        $order = $this->order();

        $this->service()->sign($order, $this->admin('order_manager'), 'ops');
        $this->service()->sign($order, $this->admin('finance'), 'finance');

        $this->assertTrue($this->service()->isComplete($order->fresh()));

        $response = $this->withHeaders($this->headers($this->admin('admin')))
            ->patchJson("/api/v1/admin/orders/{$order->id}/financials", [
                'delivery_fee' => 500,
                'reason'       => 'Freight quote came in higher',
            ])
            ->assertOk();

        $this->assertSame(2, $response->json('data.signoffs_withdrawn'));
        $this->assertStringContainsString('withdrawn', $response->json('message'));
        $this->assertFalse($this->service()->isComplete($order->fresh()));

        // The reason is on the record, so the signatories can see why they are
        // being asked again.
        $history = $this->service()->state($order->fresh())['history'];
        $this->assertStringContainsString('10000 to 10500', $history[0]['revoke_reason']);
    }

    public function test_an_unchanged_total_leaves_the_signatures_alone(): void
    {
        // An edit that does not move the money must not make the order manager
        // chase two signatures again — a control that fires on nothing gets
        // routed around.
        $order = $this->order(['delivery_cost' => 0]);

        $this->service()->sign($order, $this->admin('order_manager'), 'ops');
        $this->service()->sign($order, $this->admin('finance'), 'finance');

        $this->withHeaders($this->headers($this->admin('admin')))
            ->patchJson("/api/v1/admin/orders/{$order->id}/financials", [
                'delivery_fee' => 0,
                'reason'       => 'Re-saved with no change',
            ])
            ->assertOk()
            ->assertJsonPath('data.signoffs_withdrawn', 0);

        $this->assertTrue($this->service()->isComplete($order->fresh()));
    }

    public function test_orders_raised_before_the_rule_are_not_retrospectively_blocked(): void
    {
        // Shipping a control that freezes every open order on production is how
        // a control gets switched off on day one.
        config(['orders.signoff.applies_from' => '2026-08-13']);

        $old = $this->order(['created_at' => '2026-07-01 09:00:00']);
        $new = $this->order(['created_at' => '2026-08-20 09:00:00']);

        $this->assertFalse($this->service()->applies($old->fresh()));
        $this->assertSame('not_required', $this->service()->state($old->fresh())['status']);

        $this->assertTrue($this->service()->applies($new->fresh()));
        $this->assertSame('awaiting', $this->service()->state($new->fresh())['status']);
    }

    public function test_the_gate_can_be_stood_down_from_config(): void
    {
        config(['orders.signoff.required' => false]);

        $order = $this->order(['created_at' => now()]);

        $this->assertFalse($this->service()->applies($order));
        $this->assertTrue($this->service()->guardSend($order, $this->admin('order_manager'))['ok']);
    }

    public function test_sending_is_refused_until_both_have_signed(): void
    {
        config(['orders.signoff.applies_from' => null]);

        $order = $this->order();
        $ops   = $this->admin('order_manager');

        $refusal = $this->service()->guardSend($order, $ops);

        $this->assertFalse($refusal['ok']);
        $this->assertSame('signoff_incomplete', $refusal['code']);
        $this->assertStringContainsString('Operations and Finance', $refusal['message']);

        $this->service()->sign($order, $ops, 'ops');
        $this->service()->sign($order, $this->admin('finance'), 'finance');

        $this->assertTrue($this->service()->guardSend($order->fresh(), $ops)['ok']);
    }

    public function test_a_bypass_needs_the_permission_and_a_reason_and_leaves_a_mark(): void
    {
        config(['orders.signoff.applies_from' => null]);

        $order = $this->order();

        // An order manager cannot let themselves through.
        $this->assertFalse(
            $this->service()->guardSend($order, $this->admin('order_manager'), true, 'Customer is waiting')['ok']
        );

        $root = $this->admin('super_admin');

        // Even the holder needs to say why.
        $noReason = $this->service()->guardSend($order, $root, true, '   ');
        $this->assertFalse($noReason['ok']);
        $this->assertSame('bypass_reason_required', $noReason['code']);

        $this->assertTrue($this->service()->guardSend($order, $root, true, 'Board approved verbally, minuted')['ok']);

        // The escape hatch exists so the business is never stuck, and leaves a
        // mark so it is never quietly routine.
        $log = OrderLog::where('order_id', $order->id)->where('action', 'signoff_bypassed')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Board approved verbally', $log->notes);
    }

    // ── the audit trail this project keeps losing ─────────────────────────

    public function test_every_order_log_action_written_in_app_is_a_declared_one(): void
    {
        // Three separate times now, shipped code has written an action the
        // column's ENUM rejected — silently, because every OrderLog write sits
        // behind a try/catch. The payment milestone history does not exist on
        // production for any order because of it, and those rows cannot be
        // reconstructed. OrderLog::ACTIONS is now the list the ENUM is built
        // from; this is what stops the two drifting again.
        $found = [];

        // Only actual OrderLog writes. A blanket search for `'action' => '…'`
        // also finds Log::info context keys and JSON response fields — the
        // first run of this test failed on `'action' => 'linked'` in a
        // Log::info call, which is not an audit row at all. A check that cries
        // wolf gets deleted, so it matches the two shapes this codebase
        // actually writes audit rows with.
        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $path   = $file->getRelativePathname();

            // OrderLog::create([... 'action' => 'x' ...])
            foreach (explode('OrderLog::create(', $source) as $i => $chunk) {
                if ($i === 0) {
                    continue;
                }

                if (preg_match("/'action'\s*=>\s*'([a-z_]+)'/", substr($chunk, 0, 700), $m)) {
                    $found[$m[1]][] = $path;
                }
            }

            // ->writeLog($request, $order, 'x', …) and the same shape for the
            // trade-document controller's own helper.
            preg_match_all(
                "/(?:writeLog|logOrderAction)\(\s*\\$[a-zA-Z]+\s*,\s*\\$[a-zA-Z]+\s*,\s*'([a-z_]+)'/",
                $source,
                $matches
            );

            foreach ($matches[1] as $action) {
                $found[$action][] = $path;
            }
        }

        $this->assertNotEmpty($found, 'The scan found no action literals at all — it has stopped working.');

        foreach ($found as $action => $files) {
            $this->assertContains(
                $action,
                OrderLog::ACTIONS,
                "'{$action}' is written in " . implode(', ', array_unique($files))
                . " but is not in OrderLog::ACTIONS, so the column will reject it and the audit row will be lost silently."
            );
        }
    }

    public function test_the_feature_is_inert_until_its_migration_has_run(): void
    {
        // Not theoretical. The order-item edit path calls into this service, so
        // before the guard existed every item correction 500'd on a missing
        // table between the code deploying and the migration running — caught
        // by OrderTotalFromItemsTest, which knows nothing about sign-off. A new
        // control must not be able to break an old feature by arriving first.
        $order = $this->order();

        Schema::drop('order_signoffs');
        OrderSignoffService::forgetTableCheck();

        try {
            $this->assertFalse($this->service()->recordingAvailable());
            $this->assertFalse($this->service()->applies($order));
            $this->assertSame('not_required', $this->service()->state($order)['status']);

            // And nothing is gated, because signing is impossible — refusing to
            // send until two people sign when neither can would be an outage
            // dressed as a control.
            $this->assertTrue($this->service()->guardSend($order, $this->admin('order_manager'))['ok']);

            $this->assertSame(
                'not_available',
                $this->service()->canSign($order, $this->admin('order_manager'), 'ops')['code']
            );

            // The order list still renders — it reaches isInTransit() and
            // channel() on every row and must not touch the missing table.
            $this->withHeaders($this->headers($this->admin('order_manager')))
                ->getJson('/api/v1/admin/orders')
                ->assertOk();
        } finally {
            $this->runMigration('2026_08_13_000002_create_order_signoffs_table');
            OrderSignoffService::forgetTableCheck();
        }
    }

    // ── the board ─────────────────────────────────────────────────────────

    public function test_the_board_splits_ebay_from_everything_else(): void
    {
        $this->order(['source' => 'website', 'total' => 6000, 'status' => 'confirmed', 'customer_email' => 'a@x.de']);
        $this->order(['source' => 'manual',  'total' => 4000, 'status' => 'delivered', 'customer_email' => 'b@x.de']);
        $this->order(['source' => 'ebay',    'total' => 250,  'status' => 'confirmed', 'customer_email' => 'c@x.de']);

        $board = app(OperationsSummaryService::class)->build();

        $normal = collect($board['channels'])->firstWhere('channel', 'normal');
        $ebay   = collect($board['channels'])->firstWhere('channel', 'ebay');

        $this->assertSame(2, $normal['orders_sent']);
        $this->assertSame(10000.0, $normal['amount']);
        $this->assertSame(2, $normal['clients']);

        $this->assertSame(1, $ebay['orders_sent']);
        $this->assertSame(250.0, $ebay['amount']);

        $this->assertSame(3, $board['total']['orders_sent']);
        $this->assertSame(10250.0, $board['total']['amount']);
    }

    public function test_one_buyer_on_two_channels_is_one_client(): void
    {
        // Summing the per-channel counts would report two, which is the sort of
        // number two departments then spend a morning disagreeing about.
        $this->order(['source' => 'website', 'status' => 'confirmed', 'customer_email' => 'same@buyer.de']);
        $this->order(['source' => 'ebay',    'status' => 'confirmed', 'customer_email' => 'SAME@buyer.de']);

        $board = app(OperationsSummaryService::class)->build();

        $this->assertSame(1, collect($board['channels'])->firstWhere('channel', 'normal')['clients']);
        $this->assertSame(1, collect($board['channels'])->firstWhere('channel', 'ebay')['clients']);
        $this->assertSame(1, $board['total']['clients']);
    }

    public function test_cancelled_orders_and_stripe_test_checkouts_are_not_business(): void
    {
        $this->order(['status' => 'confirmed', 'total' => 1000]);
        $this->order(['status' => 'cancelled', 'total' => 9999]);
        $this->order(['status' => 'confirmed', 'total' => 5555, 'payment_session_id' => 'cs_test_abc123']);

        $normal = collect(app(OperationsSummaryService::class)->build()['channels'])
            ->firstWhere('channel', 'normal');

        $this->assertSame(1, $normal['orders_sent']);
        $this->assertSame(1000.0, $normal['amount']);
    }

    public function test_money_in_another_currency_is_named_rather_than_converted(): void
    {
        // Converting at today's rate would make a historic month's revenue
        // change every time the board is opened.
        $this->order(['total' => 1000, 'currency' => 'EUR', 'status' => 'confirmed']);
        $this->order(['total' => 800,  'currency' => 'USD', 'status' => 'confirmed']);

        $normal = collect(app(OperationsSummaryService::class)->build()['channels'])
            ->firstWhere('channel', 'normal');

        $this->assertSame(1000.0, $normal['amount']);
        $this->assertSame([['currency' => 'USD', 'amount' => 800.0, 'orders' => 1]], $normal['amount_other_currencies']);
    }

    public function test_in_transit_is_paid_and_shipped_and_nothing_else(): void
    {
        $paidShipped     = $this->order(['status' => 'shipped',   'payment_status' => 'paid']);
        $unpaidShipped   = $this->order(['status' => 'shipped',   'payment_status' => 'unpaid']);
        $paidDelivered   = $this->order(['status' => 'delivered', 'payment_status' => 'paid']);
        $depositShipped  = $this->order(['status' => 'shipped',   'payment_stage' => 'deposit_paid']);

        $this->assertTrue($paidShipped->isInTransit());
        $this->assertFalse($unpaidShipped->isInTransit());
        $this->assertFalse($paidDelivered->isInTransit());
        $this->assertTrue($depositShipped->isInTransit());

        // The scope and the accessor have to agree, or the board's count and
        // the order's badge tell different stories about the same order.
        $this->assertSame(2, Order::query()->inTransit()->count());
    }

    // ── invoices: ours against the finance system's ───────────────────────

    public function test_the_two_invoice_counts_produce_a_variance(): void
    {
        $order = $this->order(['ref' => 'OKL-INV1']);

        \App\Models\Invoice::create([
            'invoice_number' => 'INV-2026-0001',
            'order_ref'      => 'OKL-INV1',
            'amount'         => 10000,
            'issued_at'      => now(),
        ]);
        \App\Models\Invoice::create([
            'invoice_number' => 'INV-2026-0002',
            'order_ref'      => $this->order()->ref,
            'amount'         => 2000,
            'issued_at'      => now(),
        ]);

        FinanceInvoice::create([
            'system'          => 'sevdesk',
            'external_number' => 'SD-114',
            'order_ref'       => 'OKL-INV1',
            'amount'          => 10000,
            'issued_on'       => now()->toDateString(),
            'channel'         => 'normal',
        ]);

        $normal = collect(app(OperationsSummaryService::class)->build()['channels'])
            ->firstWhere('channel', 'normal');

        $this->assertSame(2, $normal['website_invoices']);
        $this->assertSame(1, $normal['finance_invoices']);
        // The number anyone acts on. Two counts without it is a mismatch
        // sitting on screen looking like two facts.
        $this->assertSame(1, $normal['invoice_variance']);

        unset($order);
    }

    public function test_the_reconciliation_names_both_sides_of_the_gap(): void
    {
        $this->order(['ref' => 'OKL-M1']);

        \App\Models\Invoice::create([
            'invoice_number' => 'INV-2026-0009',
            'order_ref'      => 'OKL-M1',
            'amount'         => 5000,
            'issued_at'      => now(),
        ]);

        // Same order, different money — invisible from the counts, and a worse
        // finding than one side simply missing a row.
        FinanceInvoice::create([
            'system' => 'sevdesk', 'external_number' => 'SD-200', 'order_ref' => 'OKL-M1',
            'amount' => 5500, 'issued_on' => now()->toDateString(), 'channel' => 'normal',
        ]);

        // Booked against an order this system has never heard of.
        FinanceInvoice::create([
            'system' => 'sevdesk', 'external_number' => 'SD-201', 'order_ref' => 'OKL-GHOST',
            'amount' => 900, 'issued_on' => now()->toDateString(), 'channel' => 'normal',
        ]);

        $data = $this->withHeaders($this->headers($this->admin('finance')))
            ->getJson('/api/v1/admin/operations/invoice-reconciliation')
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['available']);
        $this->assertSame(1, $data['counts']['matched']);
        $this->assertSame(1, $data['counts']['amount_mismatch']);
        $this->assertSame(0, $data['counts']['only_here']);
        $this->assertSame(1, $data['counts']['only_in_finance']);

        $this->assertSame('SD-201', $data['only_in_finance'][0]['external_number']);
        $this->assertFalse($data['only_in_finance'][0]['order_known_here']);
        $this->assertFalse($data['matched'][0]['amount_matches']);
    }

    public function test_the_same_finance_invoice_cannot_be_entered_twice(): void
    {
        // A duplicate would make the two sides of the board agree when they do
        // not, which is the exact failure it exists to catch.
        $finance = $this->admin('finance');

        $payload = [
            'external_number' => 'SD-500',
            'issued_on'       => now()->toDateString(),
            'amount'          => 1200,
        ];

        $this->withHeaders($this->headers($finance))
            ->postJson('/api/v1/admin/finance-invoices', $payload)
            ->assertStatus(201);

        $this->withHeaders($this->headers($finance))
            ->postJson('/api/v1/admin/finance-invoices', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.external_number.0', 'This invoice number has already been entered.');

        $this->assertSame(1, FinanceInvoice::count());
    }

    public function test_recording_finance_invoices_needs_the_finance_permission(): void
    {
        $this->withHeaders($this->headers($this->admin('editor')))
            ->postJson('/api/v1/admin/finance-invoices', [
                'external_number' => 'SD-600',
                'issued_on'       => now()->toDateString(),
            ])
            ->assertStatus(403);

        // Order managers read the board — the point is that operations and
        // finance look at the same two numbers — but do not type sevDesk's side.
        $this->withHeaders($this->headers($this->admin('order_manager')))
            ->getJson('/api/v1/admin/finance-invoices')
            ->assertOk();
    }

    // ── the order list ────────────────────────────────────────────────────

    public function test_the_order_list_can_be_split_by_channel_without_hiding_anything_by_default(): void
    {
        $this->order(['source' => 'website']);
        $this->order(['source' => 'ebay']);
        $this->order(['source' => 'ebay']);

        $headers = $this->headers($this->admin('order_manager'));

        // Default is unchanged. Silently dropping rows from every existing
        // consumer of this endpoint to achieve a split that belongs in the UI
        // would be a data change dressed up as a feature.
        $all = $this->withHeaders($headers)->getJson('/api/v1/admin/orders')->assertOk();
        $this->assertSame(3, $all->json('meta.total'));
        $this->assertSame('all', $all->json('meta.channel'));

        // And the counts are always there, so the Orders page can say "2 eBay
        // orders, view separately" rather than the split being something the
        // user has to already know about.
        $this->assertSame(1, $all->json('meta.channel_counts.normal'));
        $this->assertSame(2, $all->json('meta.channel_counts.ebay'));

        $this->assertSame(1, $this->withHeaders($headers)
            ->getJson('/api/v1/admin/orders?channel=normal')->assertOk()->json('meta.total'));

        $ebay = $this->withHeaders($headers)->getJson('/api/v1/admin/orders?channel=ebay')->assertOk();
        $this->assertSame(2, $ebay->json('meta.total'));
        $this->assertSame('ebay', $ebay->json('data.0.channel'));
    }

    public function test_the_order_list_can_show_only_what_is_in_transit(): void
    {
        $this->order(['status' => 'shipped', 'payment_status' => 'paid']);
        $this->order(['status' => 'pending']);

        $response = $this->withHeaders($this->headers($this->admin('order_manager')))
            ->getJson('/api/v1/admin/orders?in_transit=1')
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertTrue($response->json('data.0.in_transit'));
    }

    public function test_the_board_is_readable_by_finance_and_operations_alike(): void
    {
        foreach (['order_manager', 'finance', 'admin'] as $role) {
            $this->withHeaders($this->headers($this->admin($role)))
                ->getJson('/api/v1/admin/operations/summary')
                ->assertOk()
                ->assertJsonStructure(['data' => ['period', 'channels', 'total', 'definitions']]);
        }

        // Every column says how it was counted, next to the number. Seven
        // figures two departments will argue over are worthless if "orders
        // sent" means something different to the reader than to the query.
        $definitions = $this->withHeaders($this->headers($this->admin('finance')))
            ->getJson('/api/v1/admin/operations/summary')
            ->json('data.definitions');

        foreach (['orders_sent', 'amount', 'clients', 'orders_confirmed',
                  'website_invoices', 'finance_invoices', 'invoice_variance', 'in_transit'] as $key) {
            $this->assertArrayHasKey($key, $definitions);
        }
    }
}

<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Customer;
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
            'trade_documents', 'order_items', 'order_shipment_events', 'eu_declarations',
            'customers', 'orders', 'personal_access_tokens', 'admin_users',
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
            $table->string('country')->nullable();
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

        // The client drill-down joins orders to accounts by e-mail — orders
        // carry no customer foreign key.
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('company_name')->nullable();
            $table->string('buyer_tier', 30)->nullable();
            $table->string('onboarding_status', 30)->nullable();
            $table->timestamps();
        });

        // The order DETAIL endpoint eager-loads these; the sign-off block it
        // now carries is reached through it.
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('name')->nullable();
            $table->string('brand')->nullable();
            $table->string('size')->nullable();
            $table->string('sku')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('order_shipment_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->date('event_date')->nullable();
            $table->string('location')->nullable();
            $table->string('status_label')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('eu_declarations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->timestamps();
        });

        // The order list aggregates document state onto each row, so the
        // in-transit queue can answer "has the paperwork gone out?" without a
        // request per row.
        Schema::create('trade_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('order_ref', 30);
            $table->string('type', 30);
            $table->string('number', 50)->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        // The real migrations, run against real SQL — the sign-off unique index
        // is the thing enforcing "one live signature per slot", so a hand-built
        // table would be testing a different schema from the one that ships.
        $this->runMigration('2026_08_13_000002_create_order_signoffs_table');
        $this->runMigration('2026_08_13_000003_create_finance_invoices_table');
        $this->runMigration('2026_08_14_000001_add_file_and_origin_to_finance_invoices');

        Schema::enableForeignKeyConstraints();

        // The service memoises whether its table exists, and the harness
        // creates it after the container boots.
        OrderSignoffService::forgetTableCheck();
        \App\Models\FinanceInvoice::forgetRegisterCheck();
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

    public function test_the_order_page_answers_which_button_to_offer(): void
    {
        // Frontend reported making a second request to /signoffs for this one
        // question, which is the thing embedding the block was meant to save.
        // It cannot be derived client-side: the same-person rule needs the
        // signatory's user id, and the payload carries a display name.
        $order = $this->order();
        $ops   = $this->admin('order_manager');

        $this->service()->sign($order, $ops, 'ops');

        $signoff = $this->withHeaders($this->headers($ops))
            ->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk()
            ->json('data.signoff');

        // Already signed as ops, and not entitled to finance.
        $this->assertSame([], $signoff['you_may_sign']);
        // But may take their own signature back — no same-person rule applies
        // to withdrawal, which is exactly what you need when you spot an error.
        $this->assertSame(['ops'], $signoff['you_may_revoke']);

        $finance = $this->admin('finance');

        $asFinance = $this->withHeaders($this->headers($finance))
            ->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk()
            ->json('data.signoff');

        $this->assertSame(['finance'], $asFinance['you_may_sign']);
        // Cannot withdraw a signature in a slot they do not hold — frontend
        // found Withdraw offered on every signed slot regardless of role, the
        // exact permissions puzzle this exists to prevent.
        $this->assertSame([], $asFinance['you_may_revoke']);
    }

    public function test_the_detail_block_and_the_signoffs_endpoint_agree(): void
    {
        // One being a superset of the other is how the two drift.
        $order = $this->order();
        $ops   = $this->admin('order_manager');

        $embedded = $this->withHeaders($this->headers($ops))
            ->getJson("/api/v1/admin/orders/{$order->id}")->json('data.signoff');

        $dedicated = $this->withHeaders($this->headers($ops))
            ->getJson("/api/v1/admin/orders/{$order->id}/signoffs")->json('data');

        $this->assertSame($embedded, $dedicated);
    }

    public function test_the_order_list_says_whether_the_paperwork_has_gone_out(): void
    {
        // Without this the in-transit queue's "documents sent?" column is one
        // request per row, or a column asserting something it has not been told.
        $order = $this->order(['status' => 'shipped', 'payment_status' => 'paid']);

        \DB::table('trade_documents')->insert([
            ['order_id' => $order->id, 'order_ref' => $order->ref, 'type' => 'commercial_invoice',
             'status' => 'sent', 'sent_at' => '2026-08-10 09:00:00', 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => $order->id, 'order_ref' => $order->ref, 'type' => 'packing_list',
             'status' => 'issued', 'sent_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $row = $this->withHeaders($this->headers($this->admin('order_manager')))
            ->getJson('/api/v1/admin/orders?in_transit=1')
            ->assertOk()
            ->json('data.0');

        $this->assertSame(2, $row['documents_count']);
        $this->assertSame(1, $row['documents_sent_count']);
        $this->assertStringStartsWith('2026-08-10', $row['last_document_sent_at']);
    }

    public function test_support_can_read_orders_because_the_panel_already_offers_it(): void
    {
        // Frontend found the admin panel showing Orders to support while the
        // API refused it, so the page 403'd. Granting is the right half to
        // move — read only.
        $this->order();

        $this->withHeaders($this->headers($this->admin('support')))
            ->getJson('/api/v1/admin/orders')
            ->assertOk();

        $this->assertFalse(AdminPermissions::can('support', 'orders.update'));
        $this->assertFalse(AdminPermissions::can('support', 'orders.signoff_ops'));
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

    // ── clients, opened up (Session 86) ───────────────────────────────────

    public function test_the_clients_figure_opens_into_the_people_behind_it(): void
    {
        // A count on a board is something to trust or doubt. Names with what
        // they spent is something to act on — and the only way anyone checks
        // the figure without asking a developer to run a query.
        Customer::create(['email' => 'big@acme.de', 'company_name' => 'Acme GmbH', 'buyer_tier' => 'wholesale']);

        $this->order(['customer_email' => 'big@acme.de', 'customer_name' => 'Acme', 'total' => 8000, 'status' => 'confirmed']);
        $this->order(['customer_email' => 'BIG@acme.de', 'customer_name' => 'Acme', 'total' => 2000, 'status' => 'delivered']);
        $this->order(['customer_email' => 'small@shop.fr', 'customer_name' => 'Petit', 'total' => 500, 'status' => 'confirmed']);
        $this->order(['customer_email' => 'never@lead.com', 'total' => 9999, 'status' => 'pending']);

        $response = $this->withHeaders($this->headers($this->admin('order_manager')))
            ->getJson('/api/v1/admin/operations/clients')
            ->assertOk();

        $clients = $response->json('data');

        // Two clients, not three: a pending order is not confirmed business,
        // which is the board's own definition and has to stay the same one.
        $this->assertCount(2, $clients);
        $this->assertSame(2, $response->json('meta.total'));

        // Sorted by spend, and the two casings of one address are one client.
        $this->assertSame('big@acme.de', $clients[0]['email']);
        $this->assertSame(2, $clients[0]['orders_count']);
        $this->assertEquals(10000, $clients[0]['amount']);

        // Linked to the account when there is one, so the UI gets a real link.
        $this->assertTrue($clients[0]['has_account']);
        $this->assertSame('Acme GmbH', $clients[0]['company']);

        // And null rather than invented when there is not — plenty of
        // confirmed orders belong to buyers who never registered.
        $this->assertFalse($clients[1]['has_account']);
        $this->assertNull($clients[1]['customer_id']);
    }

    public function test_the_client_count_matches_the_board_exactly(): void
    {
        // Two definitions of "client" that disagree by one is precisely what
        // two departments spend a morning on.
        $this->order(['customer_email' => 'a@x.de', 'status' => 'confirmed']);
        $this->order(['customer_email' => 'b@x.de', 'status' => 'shipped']);
        $this->order(['customer_email' => 'c@x.de', 'status' => 'cancelled']);

        $headers = $this->headers($this->admin('order_manager'));

        $board = $this->withHeaders($headers)->getJson('/api/v1/admin/operations/summary')
            ->assertOk()->json('data.total.clients');

        $drill = $this->withHeaders($headers)->getJson('/api/v1/admin/operations/clients')
            ->assertOk()->json('meta.total');

        $this->assertSame($board, $drill);
        $this->assertSame(2, $board);
    }

    public function test_a_client_opens_into_their_orders(): void
    {
        $this->order(['customer_email' => 'buyer@acme.de', 'ref' => 'OKL-CL1', 'total' => 3000, 'status' => 'shipped', 'payment_status' => 'paid']);
        $this->order(['customer_email' => 'buyer@acme.de', 'ref' => 'OKL-CL2', 'total' => 1000, 'status' => 'confirmed']);

        $data = $this->withHeaders($this->headers($this->admin('order_manager')))
            ->getJson('/api/v1/admin/operations/clients/detail?email=BUYER@acme.de')
            ->assertOk()
            ->json('data');

        $this->assertSame('buyer@acme.de', $data['email']);
        $this->assertSame(2, $data['totals']['orders_count']);
        $this->assertEquals(4000, $data['totals']['amount']);
        // The number that tells an order manager there is paperwork to send.
        $this->assertSame(1, $data['totals']['in_transit']);
        $this->assertCount(2, $data['orders']);
    }

    public function test_an_address_with_no_orders_in_the_period_says_so(): void
    {
        $this->withHeaders($this->headers($this->admin('order_manager')))
            ->getJson('/api/v1/admin/operations/clients/detail?email=nobody@nowhere.com')
            ->assertStatus(404)
            ->assertJsonPath('code', 'no_orders_in_period');
    }

    // ── the transaction report ────────────────────────────────────────────

    public function test_the_report_returns_a_gap_free_series_ready_to_plot(): void
    {
        $this->order(['status' => 'confirmed', 'total' => 1000, 'created_at' => '2026-06-10 10:00:00', 'customer_email' => 'a@x.de']);
        $this->order(['status' => 'confirmed', 'total' => 2000, 'created_at' => '2026-08-05 10:00:00', 'customer_email' => 'b@x.de']);

        $data = $this->withHeaders($this->headers($this->admin('order_manager')))
            ->getJson('/api/v1/admin/operations/report?from=2026-06-01&to=2026-08-31&granularity=month')
            ->assertOk()
            ->json('data');

        // June, July, August — July is a zero, not a missing point. "We sold
        // nothing in July" and "July is missing from this chart" look identical
        // when the empty bucket is simply absent.
        $this->assertCount(3, $data['periods']);
        $this->assertSame(['Jun 2026', 'Jul 2026', 'Aug 2026'], $data['series']['labels']);
        $this->assertSame([1, 0, 1], collect($data['series']['datasets'])->firstWhere('metric', 'orders_sent')['data']);
        $this->assertEquals([1000, 0, 2000], collect($data['series']['datasets'])->firstWhere('metric', 'amount')['data']);

        // Every plotted metric has a dataset of the same length as the axis.
        foreach ($data['series']['datasets'] as $dataset) {
            $this->assertCount(3, $dataset['data'], $dataset['metric'] . ' is not the length of the axis');
        }
    }

    public function test_the_report_says_what_changed_between_the_last_two_periods(): void
    {
        $this->order(['status' => 'confirmed', 'total' => 1000, 'created_at' => '2026-07-10 10:00:00', 'customer_email' => 'a@x.de']);
        $this->order(['status' => 'confirmed', 'total' => 1500, 'created_at' => '2026-08-05 10:00:00', 'customer_email' => 'b@x.de']);
        $this->order(['status' => 'confirmed', 'total' => 500,  'created_at' => '2026-08-06 10:00:00', 'customer_email' => 'c@x.de']);

        $change = $this->withHeaders($this->headers($this->admin('order_manager')))
            ->getJson('/api/v1/admin/operations/report?from=2026-07-01&to=2026-08-31')
            ->assertOk()
            ->json('data.change');

        $this->assertSame('Jul 2026', $change['from']);
        $this->assertSame('Aug 2026', $change['to']);
        $this->assertSame(1, $change['metrics']['orders_sent']['previous']);
        $this->assertSame(2, $change['metrics']['orders_sent']['current']);
        $this->assertSame('up', $change['metrics']['orders_sent']['direction']);
        $this->assertEquals(100, $change['metrics']['orders_sent']['percent']);
    }

    public function test_a_change_from_zero_is_reported_as_undefined_not_as_a_percentage(): void
    {
        // A percentage change from nothing is not a large number, it is an
        // undefined one, and rendering it as +100% reads as a fact.
        $this->order(['status' => 'confirmed', 'total' => 1000, 'created_at' => '2026-08-05 10:00:00', 'customer_email' => 'a@x.de']);

        $change = $this->withHeaders($this->headers($this->admin('order_manager')))
            ->getJson('/api/v1/admin/operations/report?from=2026-07-01&to=2026-08-31')
            ->assertOk()
            ->json('data.change');

        $this->assertNull($change['metrics']['orders_sent']['percent']);
        $this->assertSame(1, $change['metrics']['orders_sent']['delta']);
    }

    public function test_the_report_does_not_sum_clients_across_periods(): void
    {
        // One buyer ordering in two months is one client, not two.
        $this->order(['status' => 'confirmed', 'customer_email' => 'repeat@acme.de', 'created_at' => '2026-07-10 10:00:00']);
        $this->order(['status' => 'confirmed', 'customer_email' => 'repeat@acme.de', 'created_at' => '2026-08-10 10:00:00']);

        $data = $this->withHeaders($this->headers($this->admin('order_manager')))
            ->getJson('/api/v1/admin/operations/report?from=2026-07-01&to=2026-08-31')
            ->assertOk()
            ->json('data');

        $clients = collect($data['series']['datasets'])->firstWhere('metric', 'clients')['data'];

        $this->assertSame([1, 1], $clients);
        $this->assertSame(1, $data['totals']['clients'], 'The total must be counted over the range, not summed.');
    }

    // ── the invoice register ──────────────────────────────────────────────

    public function test_an_invoice_this_system_raises_registers_itself(): void
    {
        // The reconciliation compared two differently-shaped things, and
        // anything that needs translating to be compared is where a mismatch
        // hides.
        $order = $this->order(['ref' => 'OKL-REG1', 'total' => 4000]);

        \App\Models\Invoice::create([
            'invoice_number' => 'INV-2026-0100',
            'order_ref'      => 'OKL-REG1',
            'amount'         => 4000,
            'issued_at'      => now(),
        ]);

        $row = FinanceInvoice::where('system', 'okelcor')->where('external_number', 'INV-2026-0100')->first();

        $this->assertNotNull($row, 'A tax invoice must register itself.');
        $this->assertSame('OKL-REG1', $row->order_ref);
        $this->assertSame('4000.00', (string) $row->amount);
        $this->assertSame('invoice', $row->source_type);

        unset($order);
    }

    public function test_an_invoice_sent_to_a_customer_registers_even_though_it_has_no_invoice_row(): void
    {
        // The category that appeared on neither side of the reconciliation
        // while being, to the customer, an invoice.
        $order = $this->order(['ref' => 'OKL-REG2', 'total' => 7500]);

        \App\Models\TradeDocument::create([
            'order_id'  => $order->id,
            'order_ref' => 'OKL-REG2',
            'type'      => 'commercial_invoice',
            'number'    => 'CI-2026-0007',
            'status'    => 'issued',
            'issued_at' => now(),
        ]);

        $row = FinanceInvoice::where('system', 'okelcor')->where('external_number', 'CI-2026-0007')->first();

        $this->assertNotNull($row);
        $this->assertSame('trade_document', $row->source_type);
        $this->assertSame('7500.00', (string) $row->amount);
        // Deliberately null: a commercial invoice is not the tax invoice, and
        // putting its number here would match it against a sevDesk row for a
        // different document.
        $this->assertNull($row->invoice_number);
    }

    public function test_a_packing_list_is_not_an_invoice(): void
    {
        // Registering one would inflate our side with documents finance would
        // never have raised, which reads as a discrepancy that is not one.
        $order = $this->order(['ref' => 'OKL-REG3']);

        \App\Models\TradeDocument::create([
            'order_id' => $order->id, 'order_ref' => 'OKL-REG3', 'type' => 'packing_list',
            'number' => 'PL-2026-0001', 'status' => 'issued', 'issued_at' => now(),
        ]);

        $this->assertSame(0, FinanceInvoice::where('system', 'okelcor')->count());
    }

    public function test_a_superseded_document_stops_being_counted(): void
    {
        // A document that was withdrawn is not an invoice that was issued.
        $order = $this->order(['ref' => 'OKL-REG4', 'total' => 100]);

        $document = \App\Models\TradeDocument::create([
            'order_id' => $order->id, 'order_ref' => 'OKL-REG4', 'type' => 'commercial_invoice',
            'number' => 'CI-2026-0009', 'status' => 'issued', 'issued_at' => now(),
        ]);

        $this->assertSame(1, FinanceInvoice::where('system', 'okelcor')->count());

        $document->update(['status' => 'superseded']);

        $this->assertSame(0, FinanceInvoice::where('system', 'okelcor')->count());
    }

    public function test_re_saving_an_invoice_does_not_register_it_twice(): void
    {
        $order = $this->order(['ref' => 'OKL-REG5', 'total' => 200]);

        $invoice = \App\Models\Invoice::create([
            'invoice_number' => 'INV-2026-0200', 'order_ref' => 'OKL-REG5',
            'amount' => 200, 'issued_at' => now(),
        ]);

        $invoice->update(['amount' => 250]);

        $rows = FinanceInvoice::where('external_number', 'INV-2026-0200')->get();

        $this->assertCount(1, $rows, 'One invoice must not be reported as two.');
        $this->assertSame('250.00', (string) $rows->first()->amount);

        unset($order);
    }

    public function test_an_auto_registered_row_cannot_be_hand_edited_or_deleted(): void
    {
        // Deleting it would only mean it reappears the next time the invoice
        // behind it is saved, and meanwhile the reconciliation reports a gap
        // that is not real.
        $order = $this->order(['ref' => 'OKL-REG6', 'total' => 300]);

        \App\Models\Invoice::create([
            'invoice_number' => 'INV-2026-0300', 'order_ref' => 'OKL-REG6',
            'amount' => 300, 'issued_at' => now(),
        ]);

        $row     = FinanceInvoice::where('external_number', 'INV-2026-0300')->first();
        $headers = $this->headers($this->admin('finance'));

        $this->withHeaders($headers)
            ->patchJson("/api/v1/admin/finance-invoices/{$row->id}", ['amount' => 1])
            ->assertStatus(409)
            ->assertJsonPath('code', 'auto_registered');

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/admin/finance-invoices/{$row->id}")
            ->assertStatus(409);

        unset($order);
    }

    public function test_finance_cannot_hand_create_a_row_on_our_side_of_the_comparison(): void
    {
        $this->withHeaders($this->headers($this->admin('finance')))
            ->postJson('/api/v1/admin/finance-invoices', [
                'system'          => 'okelcor',
                'external_number' => 'FAKE-1',
                'issued_on'       => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('system');
    }

    public function test_finance_can_attach_and_download_the_sevdesk_document(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');

        $headers = $this->headers($this->admin('finance'));

        $created = $this->withHeaders($headers)->post('/api/v1/admin/finance-invoices', [
            'external_number' => 'SD-900',
            'issued_on'       => now()->toDateString(),
            'amount'          => 1200,
            'file'            => \Illuminate\Http\UploadedFile::fake()->create('sd-900.pdf', 40, 'application/pdf'),
        ])->assertStatus(201);

        // Recorded in one request — finance has the PDF in front of them when
        // they type the number, and a separate "now attach it" step is a step
        // that gets skipped.
        $this->assertTrue($created->json('data.has_file'));
        $this->assertSame('sd-900.pdf', $created->json('data.file_name'));

        $this->withHeaders($headers)
            ->get('/api/v1/admin/finance-invoices/' . $created->json('data.id') . '/download')
            ->assertOk();
    }

    public function test_an_invoice_with_no_document_says_so_rather_than_failing(): void
    {
        $headers = $this->headers($this->admin('finance'));

        $created = $this->withHeaders($headers)->postJson('/api/v1/admin/finance-invoices', [
            'external_number' => 'SD-901',
            'issued_on'       => now()->toDateString(),
        ])->assertStatus(201);

        $this->assertFalse($created->json('data.has_file'));

        $this->withHeaders($headers)
            ->getJson('/api/v1/admin/finance-invoices/' . $created->json('data.id') . '/download')
            ->assertStatus(404);
    }

    public function test_the_register_is_inert_until_its_migration_runs(): void
    {
        // Registration runs off model events on the money path. An invoice
        // that generated correctly must not fail because a reporting row could
        // not be written.
        Schema::drop('finance_invoices');
        FinanceInvoice::forgetRegisterCheck();

        try {
            $order = $this->order(['ref' => 'OKL-REG7', 'total' => 400]);

            $invoice = \App\Models\Invoice::create([
                'invoice_number' => 'INV-2026-0400', 'order_ref' => 'OKL-REG7',
                'amount' => 400, 'issued_at' => now(),
            ]);

            $this->assertNotNull($invoice->fresh(), 'Raising an invoice must survive the register being absent.');

            unset($order);
        } finally {
            $this->runMigration('2026_08_13_000003_create_finance_invoices_table');
            $this->runMigration('2026_08_14_000001_add_file_and_origin_to_finance_invoices');
            FinanceInvoice::forgetRegisterCheck();
        }
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

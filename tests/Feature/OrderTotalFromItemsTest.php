<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Order totals must always agree with the line items shown beneath them.
 *
 * Reported by an order manager: an order for 2,000 tyres at €7.50 showed a
 * €15,000 line item and a €30,000 order total. The order had been recorded
 * without items (total typed in by hand), which stores that figure in BOTH
 * `subtotal` and `total` as a stand-in for line items that do not exist yet.
 * Adding the first item then applied the line as a delta on top of that
 * stand-in, and the same money was counted twice.
 *
 * Minimal-schema sqlite harness — the full migration set contains a
 * MySQL-only legacy migration, same reason as BulkEmailCampaignTest.
 */
class OrderTotalFromItemsTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();

        foreach (['order_logs', 'order_items', 'orders', 'personal_access_tokens', 'admin_users'] as $table) {
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
            $table->string('ref')->unique();
            $table->string('source')->default('website');
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('delivery_cost', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->string('status')->default('pending');
            $table->string('payment_status')->default('pending');
            $table->string('payment_stage')->nullable();
            $table->string('mode')->default('manual');
            $table->timestamp('financials_locked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('brand')->nullable();
            $table->string('name');
            $table->string('size')->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->integer('quantity');
            $table->decimal('line_total', 12, 2);
        });

        Schema::create('order_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('order_ref')->nullable();
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->string('admin_user_email')->nullable();
            $table->string('action');
            $table->string('old_value', 100)->nullable();
            $table->string('new_value', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    private function headers(string $role = 'order_manager'): array
    {
        $this->seq++;

        $admin = AdminUser::create([
            'name'                    => 'Ops ' . $this->seq,
            'email'                   => "ops{$this->seq}@okelcor.test",
            'password'                => Hash::make('secret-password'),
            'role'                    => $role,
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);

        // `auth:sanctum` memoises the resolved user on the guard instance and
        // that instance survives between requests inside one test method.
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken];
    }

    private function order(array $overrides = []): Order
    {
        $this->seq++;

        return Order::create(array_merge([
            'ref'            => 'OKL-TEST-' . $this->seq,
            'source'         => 'admin_manual',
            'customer_name'  => 'Acme Buyer',
            'customer_email' => "buyer{$this->seq}@acme-tyres.com",
            'subtotal'       => 0,
            'delivery_cost'  => 0,
            'total'          => 0,
            'status'         => 'confirmed',
            'payment_status' => 'pending',
            'mode'           => 'manual',
        ], $overrides));
    }

    private function item(Order $order, array $overrides = []): OrderItem
    {
        return OrderItem::create(array_merge([
            'order_id'   => $order->id,
            'sku'        => 'TYRE-1',
            'brand'      => 'Continental',
            'name'       => '205/55 R16',
            'size'       => '205/55 R16',
            'unit_price' => 80,
            'quantity'   => 10,
            'line_total' => 800,
        ], $overrides));
    }

    // ── the reported bug ──────────────────────────────────────────────────

    public function test_adding_the_first_item_to_an_itemless_order_does_not_double_the_total(): void
    {
        // Recorded by hand with a total and no line items — the exact shape
        // AdminOrderController::store writes when `items` is omitted.
        $order = $this->order(['subtotal' => 15000, 'total' => 15000]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/items", [
            'name'       => 'Premium brand (PCR) used tyre',
            'size'       => 'R14C-R16C, R15-R22: 3mm-8mm thread',
            'unit_price' => 7.50,
            'quantity'   => 2000,
            'reason'     => 'Itemising the order that was recorded as a lump sum.',
        ], $this->headers())->assertCreated();

        $order->refresh();

        // 2000 x 7.50 = 15,000 — not 30,000.
        $this->assertSame(15000.0, (float) $order->subtotal);
        $this->assertSame(15000.0, (float) $order->total);
    }

    public function test_the_hand_typed_total_is_not_stacked_on_top_of_partial_itemisation(): void
    {
        $order = $this->order(['subtotal' => 15000, 'total' => 15000]);
        $headers = $this->headers();

        // Itemising in two passes. After the first line the order is worth
        // what has actually been itemised, not that plus the lump sum.
        $this->postJson("/api/v1/admin/orders/{$order->id}/items", [
            'name' => 'Batch A', 'unit_price' => 7.50, 'quantity' => 1000,
            'reason' => 'Itemising the recorded lump sum, first batch.',
        ], $headers)->assertCreated();

        $this->assertSame(7500.0, (float) $order->fresh()->total);

        $this->postJson("/api/v1/admin/orders/{$order->id}/items", [
            'name' => 'Batch B', 'unit_price' => 7.50, 'quantity' => 1000,
            'reason' => 'Itemising the recorded lump sum, second batch.',
        ], $headers)->assertCreated();

        $this->assertSame(15000.0, (float) $order->fresh()->total);
    }

    // ── the ordinary paths must keep working ──────────────────────────────

    public function test_delivery_and_other_non_line_charges_survive_an_item_change(): void
    {
        // €800 of tyres plus €150 delivery already baked into the total.
        $order = $this->order(['subtotal' => 800, 'delivery_cost' => 150, 'total' => 950]);
        $item  = $this->item($order);

        $this->patchJson("/api/v1/admin/orders/{$order->id}/items/{$item->id}", [
            'unit_price' => 75,
            'reason'     => 'Wrong price quoted — corrected to the agreed rate.',
        ], $this->headers())->assertOk();

        $order->refresh();

        // Items fell 800 → 750; the €150 delivery is untouched, not absorbed.
        $this->assertSame(750.0, (float) $order->subtotal);
        $this->assertSame(900.0, (float) $order->total);
    }

    public function test_adding_and_removing_items_tracks_the_line_items(): void
    {
        $order   = $this->order(['subtotal' => 800, 'total' => 800]);
        $item    = $this->item($order);
        $headers = $this->headers();

        $this->postJson("/api/v1/admin/orders/{$order->id}/items", [
            'name' => 'Extra tyre', 'unit_price' => 50, 'quantity' => 2,
            'reason' => 'Client also ordered 2 extra units, missed at entry.',
        ], $headers)->assertCreated();

        $this->assertSame(900.0, (float) $order->fresh()->total);

        $this->deleteJson("/api/v1/admin/orders/{$order->id}/items/{$item->id}", [
            'reason' => 'Duplicate line entered by mistake.',
        ], $headers)->assertOk();

        $this->assertSame(100.0, (float) $order->fresh()->total);
    }

    public function test_the_audit_log_records_the_total_it_actually_moved_to(): void
    {
        $order = $this->order(['subtotal' => 15000, 'total' => 15000]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/items", [
            'name' => 'Premium brand (PCR) used tyre', 'unit_price' => 7.50, 'quantity' => 2000,
            'reason' => 'Itemising the order that was recorded as a lump sum.',
        ], $this->headers())->assertCreated();

        $log = \App\Models\OrderLog::where('order_id', $order->id)->where('action', 'item_added')->first();

        $this->assertNotNull($log);
        // Reads "15000 → 15000", not "15000 → 30000": the log has to agree
        // with the order, or it sends whoever is reconciling down a hole.
        $this->assertStringContainsString('order total: 15000 → 15000', $log->notes);
    }

    // ── the model rule itself ─────────────────────────────────────────────

    public function test_an_order_with_no_items_keeps_its_hand_entered_total(): void
    {
        $order = $this->order(['subtotal' => 15000, 'total' => 15000]);

        $result = $order->recalculateTotalsFromItems();

        $this->assertFalse($result['changed']);
        $this->assertSame(15000.0, (float) $order->fresh()->total);
    }

    public function test_a_drifted_total_is_re_derived_from_the_items(): void
    {
        // The shape the reported order was left in: one €15,000 line, a
        // €30,000 total.
        $order = $this->order(['subtotal' => 30000, 'total' => 30000]);
        $this->item($order, ['unit_price' => 7.50, 'quantity' => 2000, 'line_total' => 15000]);

        $result = $order->recalculateTotalsFromItems();

        $this->assertTrue($result['changed']);
        $this->assertSame(30000.0, $result['total_from']);
        $this->assertSame(15000.0, $result['total_to']);
        $this->assertSame(15000.0, (float) $order->fresh()->subtotal);
    }

    // ── the repair command ────────────────────────────────────────────────

    public function test_the_repair_command_reports_without_writing_until_told_to_fix(): void
    {
        $order = $this->order(['source' => 'admin_manual', 'subtotal' => 30000, 'total' => 30000]);
        $this->item($order, ['unit_price' => 7.50, 'quantity' => 2000, 'line_total' => 15000]);

        $this->artisan('orders:repair-totals')->assertExitCode(0);
        $this->assertSame(30000.0, (float) $order->fresh()->total);

        $this->artisan('orders:repair-totals --fix')->assertExitCode(0);
        $this->assertSame(15000.0, (float) $order->fresh()->total);

        $this->assertDatabaseHas('order_logs', [
            'order_id' => $order->id,
            'action'   => 'totals_repaired',
        ]);
    }

    public function test_a_vat_inclusive_import_is_never_rewritten_from_its_items(): void
    {
        // The shape every Wix-imported order has: `total` is the gross figure
        // the customer actually paid, the line items carry net prices. The
        // total is CORRECT. An earlier version of the repair command treated
        // this as a fault and would have cut 14 live orders by 19%.
        $order = $this->order(['source' => 'website', 'subtotal' => 371.88, 'total' => 371.88]);
        $this->item($order, ['unit_price' => 62.50, 'quantity' => 5, 'line_total' => 312.50]);

        $this->artisan('orders:repair-totals --fix --include-locked')->assertExitCode(0);

        $order->refresh();
        $this->assertSame(371.88, (float) $order->subtotal);
        $this->assertSame(371.88, (float) $order->total);
        $this->assertDatabaseMissing('order_logs', ['order_id' => $order->id, 'action' => 'totals_repaired']);
    }

    public function test_an_order_whose_items_exceed_its_total_is_reported_not_guessed_at(): void
    {
        // Real shape from production (ref 10080): items 816.00 against a
        // recorded 396.00. Not the double count, and no consistent ratio
        // across the affected orders — so no rule here can be trusted.
        $order = $this->order(['source' => 'website', 'subtotal' => 396, 'total' => 396]);
        $this->item($order, ['unit_price' => 102, 'quantity' => 8, 'line_total' => 816]);

        $this->artisan('orders:repair-totals --fix --include-locked')->assertExitCode(0);

        $this->assertSame(396.0, (float) $order->fresh()->total);
    }

    public function test_a_website_order_at_exactly_double_is_still_not_repaired(): void
    {
        // Only `admin_manual` orders can be recorded as a lump sum with no
        // items, so only they can carry the double count. A x2 ratio on any
        // other source is a coincidence, not a diagnosis.
        $order = $this->order(['source' => 'website', 'subtotal' => 1600, 'total' => 1600]);
        $this->item($order);

        $this->artisan('orders:repair-totals --fix --include-locked')->assertExitCode(0);

        $this->assertSame(1600.0, (float) $order->fresh()->total);
    }

    public function test_the_repair_command_leaves_locked_orders_alone_by_default(): void
    {
        // A commercial document has been issued carrying the wrong figure —
        // correcting the order silently would leave the customer holding an
        // invoice that no longer matches, so it takes an explicit flag.
        $order = $this->order([
            'source' => 'admin_manual', 'subtotal' => 30000, 'total' => 30000,
            'financials_locked_at' => now(),
        ]);
        $this->item($order, ['unit_price' => 7.50, 'quantity' => 2000, 'line_total' => 15000]);

        $this->artisan('orders:repair-totals --fix')->assertExitCode(0);
        $this->assertSame(30000.0, (float) $order->fresh()->total);

        $this->artisan('orders:repair-totals --fix --include-locked')->assertExitCode(0);
        $this->assertSame(15000.0, (float) $order->fresh()->total);
    }

    public function test_the_repair_command_does_not_touch_orders_that_already_agree(): void
    {
        $order = $this->order(['subtotal' => 800, 'delivery_cost' => 150, 'total' => 950]);
        $this->item($order);

        $this->artisan('orders:repair-totals --fix')->assertExitCode(0);

        $order->refresh();
        $this->assertSame(800.0, (float) $order->subtotal);
        $this->assertSame(950.0, (float) $order->total);
        $this->assertDatabaseMissing('order_logs', ['order_id' => $order->id, 'action' => 'totals_repaired']);
    }

    public function test_the_survey_of_the_real_production_rows_repairs_only_the_two_lump_sum_orders(): void
    {
        // Every row the first production run of this command reported, with
        // the source it actually carries. 21 orders, and only 2 of them are
        // the double count — the first version would have rewritten all 21.
        $rows = [
            ['10112',     'website',      312.50,   371.88],
            ['10110',     'website',      444.92,   529.45],
            ['10108',     'website',       66.59,    79.24],
            ['10105',     'website',      111.10,   132.21],
            ['10104',     'website',      114.00,   135.66],
            ['10101',     'website',      144.68,   172.17],
            ['10100',     'website',      248.64,   295.88],
            ['10095',     'website',      118.50,   141.02],
            ['10094',     'website',       66.65,    79.31],
            ['10093',     'website',      577.52,   755.98],
            ['10092',     'website',      577.52,   755.98],
            ['10091',     'website',      144.68,   172.17],
            ['10080',     'website',      816.00,   396.00],
            ['10079',     'website',      590.00,   260.00],
            ['10077',     'website',      732.00,   312.00],
            ['10076',     'website',      960.00,   416.00],
            ['10075',     'website',      928.00,   416.00],
            ['10042',     'website',      293.32,   349.05],
            ['10022',     'website',      551.36,   656.12],
            ['AB-1150',   'admin_manual', 8125.00, 16250.00],
            ['AB - 1182', 'admin_manual', 15000.00, 30000.00],
        ];

        foreach ($rows as [$ref, $source, $itemsSum, $stored]) {
            $order = Order::create([
                'ref' => $ref, 'source' => $source,
                'customer_name' => 'Buyer', 'customer_email' => 'b@example.test',
                'subtotal' => $stored, 'delivery_cost' => 0, 'total' => $stored,
                'status' => 'confirmed', 'payment_status' => 'paid', 'mode' => 'manual',
            ]);

            OrderItem::create([
                'order_id' => $order->id, 'sku' => 'X', 'brand' => 'B', 'name' => 'Tyre',
                'size' => '', 'unit_price' => $itemsSum, 'quantity' => 1, 'line_total' => $itemsSum,
            ]);
        }

        $this->artisan('orders:repair-totals --fix --include-locked')->assertExitCode(0);

        // The two lump-sum orders now agree with their items.
        $this->assertSame(8125.00,  (float) Order::where('ref', 'AB-1150')->first()->total);
        $this->assertSame(15000.00, (float) Order::where('ref', 'AB - 1182')->first()->total);

        // Every other order is untouched — including 10112, whose 371.88 the
        // first version of this command overwrote with 312.50.
        foreach ($rows as [$ref, $source, $itemsSum, $stored]) {
            if ($source === 'admin_manual') {
                continue;
            }
            $this->assertSame($stored, (float) Order::where('ref', $ref)->first()->total, "order {$ref} was modified");
        }

        $this->assertSame(2, \App\Models\OrderLog::where('action', 'totals_repaired')->count());
    }

    // ── putting back what the first run got wrong ─────────────────────────

    public function test_restore_writes_explicit_figures_and_logs_why(): void
    {
        $order = $this->order(['source' => 'website', 'subtotal' => 312.50, 'total' => 312.50]);
        $this->item($order, ['unit_price' => 62.50, 'quantity' => 5, 'line_total' => 312.50]);

        $this->artisan('orders:restore-total', [
            'ref' => $order->ref, 'subtotal' => '371.88', 'total' => '371.88',
            '--reason' => 'Undoing a bad automated correction — VAT-inclusive import.',
            '--force' => true,
        ])->assertExitCode(0);

        $order->refresh();
        $this->assertSame(371.88, (float) $order->subtotal);
        $this->assertSame(371.88, (float) $order->total);
        $this->assertDatabaseHas('order_logs', ['order_id' => $order->id, 'action' => 'totals_restored']);
    }

    public function test_restore_refuses_without_a_reason(): void
    {
        $order = $this->order(['subtotal' => 312.50, 'total' => 312.50]);

        $this->artisan('orders:restore-total', [
            'ref' => $order->ref, 'subtotal' => '371.88', 'total' => '371.88', '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(312.50, (float) $order->fresh()->total);
    }
}

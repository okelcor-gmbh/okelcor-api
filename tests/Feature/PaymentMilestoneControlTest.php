<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\TradeDocument;
use App\Services\InvoiceService;
use App\Services\PaymentStateCorrectionService;
use App\Services\TradeDocumentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Asking a customer for money is a decision a person makes.
 *
 * Reported by the order manager, three symptoms of the same theme:
 *
 *  1. An order she recorded by hand marked itself paid. It did not, quite —
 *     POST /admin/orders/{id}/mark-paid demanded payment_method ===
 *     'bank_transfer', which no admin-created order has (the column is NULL on
 *     those), so it 422'd on every one of them. Ticking "paid" on the creation
 *     form was the only route to a paid order, i.e. declaring the money in
 *     before it was.
 *
 *  2. A buyer opened his portal and found a deposit request, a 50% figure and
 *     a payment ladder for an order he had not been asked to pay for and had
 *     not paid. Generating the proforma had sent it — setDepositMilestones()
 *     advanced the stage and emailed him, with nobody deciding to.
 *
 *  3. Separately: the EU entry certificate refused every reverse-charge order
 *     paid on deposit-and-balance terms, because those settle through
 *     payment_stage and never touch payment_status.
 *
 * Minimal-schema sqlite harness — the full migration set contains a MySQL-only
 * legacy migration, same reason as OrderTotalFromItemsTest.
 */
class PaymentMilestoneControlTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');

        Schema::disableForeignKeyConstraints();

        foreach (['eu_declarations', 'trade_documents', 'order_logs', 'order_items', 'orders', 'personal_access_tokens', 'admin_users'] as $table) {
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
            // The creation path writes these; one test here goes through it to
            // hold the "who declared this paid" record in place.
            $table->string('customer_phone')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();
            $table->string('carrier')->nullable();
            $table->string('carrier_type')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('container_number')->nullable();
            $table->date('estimated_delivery')->nullable();
            $table->text('admin_notes')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('delivery_cost', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->string('status')->default('pending');
            $table->string('payment_status')->default('pending');
            $table->string('payment_method', 100)->nullable();
            $table->string('payment_stage')->nullable();
            $table->decimal('deposit_percent', 5, 2)->nullable();
            $table->decimal('deposit_amount', 12, 2)->nullable();
            $table->decimal('balance_amount', 12, 2)->nullable();
            $table->timestamp('deposit_paid_at')->nullable();
            $table->unsignedBigInteger('deposit_confirmed_by')->nullable();
            $table->timestamp('balance_paid_at')->nullable();
            $table->unsignedBigInteger('balance_confirmed_by')->nullable();
            $table->timestamp('shipment_released_at')->nullable();
            $table->unsignedBigInteger('shipment_released_by')->nullable();
            $table->text('shipment_release_note')->nullable();
            $table->timestamp('deposit_requested_email_sent_at')->nullable();
            $table->timestamp('deposit_paid_email_sent_at')->nullable();
            $table->timestamp('balance_due_email_sent_at')->nullable();
            $table->timestamp('balance_paid_email_sent_at')->nullable();
            $table->timestamp('shipment_released_email_sent_at')->nullable();
            $table->boolean('is_reverse_charge')->default(false);
            $table->string('mode')->default('manual');
            $table->timestamp('financials_locked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('sku')->nullable();
            $table->string('name');
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

        // Eager-loaded by the order detail formatter. Only the columns that
        // formatter reads — this harness is deliberately minimal.
        Schema::create('eu_declarations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('order_ref')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_name')->nullable();
            $table->timestamps();
        });

        Schema::create('trade_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('order_ref')->nullable();
            $table->string('type', 30);
            $table->string('type_label')->nullable();
            $table->string('number', 50)->nullable();
            $table->string('status', 20)->default('issued');
            $table->string('pdf_path', 500)->nullable();
            $table->string('file_path', 500)->nullable();
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->unsignedBigInteger('superseded_by_id')->nullable();
            $table->text('supersede_reason')->nullable();
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
            'ref'            => 'OKL-MS-' . $this->seq,
            'source'         => 'admin_manual',
            'customer_name'  => 'Acme Buyer',
            'customer_email' => "buyer{$this->seq}@acme-tyres.com",
            'subtotal'       => 10000,
            'delivery_cost'  => 0,
            'total'          => 10000,
            'status'         => 'confirmed',
            'payment_status' => 'pending',
            'payment_stage'  => 'pending_proforma',
            'mode'           => 'manual',
        ], $overrides));
    }

    /** markPaid creates an invoice; that path is not what these tests are about. */
    private function stubInvoiceService(): void
    {
        $this->mock(InvoiceService::class, function ($mock) {
            $mock->shouldReceive('createForOrder')->andReturn(null);
        });
    }

    // ── 1. an order must not mark itself paid ─────────────────────────────

    public function test_an_admin_recorded_order_can_be_marked_paid_after_the_money_arrives(): void
    {
        $this->stubInvoiceService();

        // payment_method is NULL — exactly what AdminOrderController::store writes.
        $order = $this->order(['payment_method' => null]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/mark-paid", [
            'confirmation'      => true,
            'payment_reference' => 'Wise ref 88213',
        ], $this->headers())->assertOk();

        $order->refresh();

        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('confirmed', $order->status);
    }

    public function test_a_bank_transfer_order_can_still_be_marked_paid(): void
    {
        $this->stubInvoiceService();

        $order = $this->order(['payment_method' => 'bank_transfer']);

        $this->postJson("/api/v1/admin/orders/{$order->id}/mark-paid", [
            'confirmation' => true,
        ], $this->headers())->assertOk();

        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_a_stripe_order_is_left_to_the_gateway(): void
    {
        $order = $this->order(['payment_method' => 'stripe']);

        $this->postJson("/api/v1/admin/orders/{$order->id}/mark-paid", [
            'confirmation' => true,
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('code', 'gateway_managed_payment');

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_an_order_that_is_already_paid_is_not_confirmed_twice(): void
    {
        $order = $this->order(['payment_method' => null, 'payment_status' => 'paid']);

        $this->postJson("/api/v1/admin/orders/{$order->id}/mark-paid", [
            'confirmation' => true,
        ], $this->headers())->assertStatus(409);
    }

    // ── 2. the ladder starts when a person starts it ──────────────────────

    public function test_issuing_a_proforma_calculates_the_split_without_asking_the_customer_for_it(): void
    {
        config(['payment.milestones.auto_start_on_proforma' => false]);

        $order = $this->order(['total' => 10000, 'payment_stage' => 'pending_proforma']);

        $this->runSetDepositMilestones($order);
        $order->refresh();

        // The arithmetic is done and stored — useful, and costs the customer nothing.
        $this->assertSame('5000.00', (string) $order->deposit_amount);
        $this->assertSame('5000.00', (string) $order->balance_amount);

        // But the ladder has not started and nothing was sent.
        $this->assertSame('pending_proforma', $order->payment_stage);
        $this->assertFalse($order->paymentMilestonesActive());
        $this->assertNull($order->deposit_requested_email_sent_at);
        Mail::assertNothingSent();
    }

    public function test_the_old_behaviour_is_one_env_flag_away(): void
    {
        config(['payment.milestones.auto_start_on_proforma' => true]);

        $order = $this->order(['total' => 10000, 'payment_stage' => 'pending_proforma']);

        $this->runSetDepositMilestones($order);

        $this->assertSame('deposit_requested', $order->fresh()->payment_stage);
    }

    public function test_an_admin_starts_the_ladder_explicitly(): void
    {
        $order = $this->order(['total' => 10000]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/payment-milestones/request-deposit", [
            'deposit_percent' => 40,
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.payment_stage', 'deposit_requested')
            ->assertJsonPath('data.payment_milestones_active', true);

        $order->refresh();

        $this->assertSame('4000.00', (string) $order->deposit_amount);
        $this->assertSame('6000.00', (string) $order->balance_amount);
    }

    public function test_requesting_a_deposit_does_not_email_the_customer_unless_asked(): void
    {
        $order = $this->order(['total' => 10000]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/payment-milestones/request-deposit", [
            'deposit_percent' => 50,
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('email_sent', false);

        Mail::assertNothingSent();

        $this->assertStringContainsString(
            'Customer not notified',
            (string) OrderLog::where('order_id', $order->id)->where('action', 'deposit_requested')->value('notes')
        );
    }

    public function test_an_agreed_round_figure_can_be_used_instead_of_a_percentage(): void
    {
        $order = $this->order(['total' => 10000]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/payment-milestones/request-deposit", [
            'deposit_amount' => 3500,
        ], $this->headers())->assertOk();

        $order->refresh();

        $this->assertSame('3500.00', (string) $order->deposit_amount);
        $this->assertSame('6500.00', (string) $order->balance_amount);
        $this->assertSame('35.00', (string) $order->deposit_percent);
    }

    public function test_a_deposit_larger_than_the_order_is_refused(): void
    {
        $order = $this->order(['total' => 10000]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/payment-milestones/request-deposit", [
            'deposit_amount' => 12000,
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('code', 'deposit_exceeds_total');

        $this->assertSame('pending_proforma', $order->fresh()->payment_stage);
    }

    public function test_the_ladder_is_not_started_twice(): void
    {
        $order = $this->order(['payment_stage' => 'deposit_requested']);

        $this->postJson("/api/v1/admin/orders/{$order->id}/payment-milestones/request-deposit", [
            'deposit_percent' => 50,
        ], $this->headers())
            ->assertStatus(409)
            ->assertJsonPath('code', 'invalid_payment_stage');
    }

    public function test_money_that_simply_arrives_can_be_recorded_without_requesting_it_first(): void
    {
        // No proforma, no deposit request — a customer paid against the quote.
        $order = $this->order(['total' => 10000, 'payment_stage' => 'pending_proforma']);

        $this->postJson("/api/v1/admin/orders/{$order->id}/payment-milestones/deposit-paid", [
            'payment_reference' => 'Wise ref 91002',
        ], $this->headers())->assertOk();

        $order->refresh();

        $this->assertSame('deposit_paid', $order->payment_stage);
        $this->assertNotNull($order->deposit_paid_at);
        // The split is backfilled so the balance still reads correctly.
        $this->assertSame('5000.00', (string) $order->deposit_amount);
        $this->assertSame('5000.00', (string) $order->balance_amount);
    }

    public function test_the_milestone_audit_trail_is_written(): void
    {
        $order = $this->order(['total' => 10000]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/payment-milestones/request-deposit", [
            'deposit_percent' => 50,
        ], $this->headers())->assertOk();

        $log = OrderLog::where('order_id', $order->id)->where('action', 'deposit_requested')->first();

        $this->assertNotNull($log, 'A deposit request must leave an audit row.');
        $this->assertSame('pending_proforma', $log->old_value);
        $this->assertSame('deposit_requested', $log->new_value);
        $this->assertNotNull($log->admin_user_email);
    }

    // ── 3. the customer sees a ladder only once there is one ──────────────

    public function test_an_untouched_order_has_no_milestones_to_show(): void
    {
        $this->assertFalse($this->order(['payment_stage' => 'pending_proforma'])->paymentMilestonesActive());
        $this->assertFalse($this->order(['payment_stage' => null])->paymentMilestonesActive());
    }

    public function test_every_started_stage_counts_as_active(): void
    {
        foreach (['deposit_requested', 'deposit_paid', 'balance_due', 'balance_paid', 'shipment_released'] as $stage) {
            $this->assertTrue(
                $this->order(['payment_stage' => $stage])->paymentMilestonesActive(),
                "{$stage} should be visible to the customer."
            );
        }
    }

    // ── 4. the EU entry certificate ───────────────────────────────────────

    public function test_a_milestone_order_paid_in_full_counts_as_paid(): void
    {
        // The regression: these orders never set payment_status, so a check on
        // that column alone refuses a customer who has paid everything owed.
        foreach (['balance_paid', 'shipment_released'] as $stage) {
            $order = $this->order(['payment_status' => 'pending', 'payment_stage' => $stage]);

            $this->assertTrue($order->isFullyPaid(), "{$stage} means the customer owes nothing further.");
        }
    }

    public function test_an_order_still_owing_its_balance_is_not_fully_paid(): void
    {
        foreach (['pending_proforma', 'deposit_requested', 'deposit_paid', 'balance_due'] as $stage) {
            $order = $this->order(['payment_status' => 'pending', 'payment_stage' => $stage]);

            $this->assertFalse($order->isFullyPaid(), "{$stage} still has money outstanding.");
        }
    }

    public function test_a_conventionally_paid_order_is_unaffected(): void
    {
        $this->assertTrue(
            $this->order(['payment_status' => 'paid', 'payment_stage' => 'pending_proforma'])->isFullyPaid()
        );
    }

    // ── 5. document upload — nothing is unfileable ────────────────────────

    public function test_the_upload_dialog_is_fed_by_the_api(): void
    {
        TradeDocument::create([
            'order_id'   => $this->order()->id,
            'order_ref'  => 'OKL-MS-PREV',
            'type'       => 'shipment_document',
            'type_label' => 'Certificate of Origin',
            'status'     => 'issued',
        ]);

        $response = $this->getJson('/api/v1/admin/trade-documents/upload-options', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.file_as_free_text', true);

        $types = collect($response->json('data.document_types'));

        // The catch-all exists, and is the one that wants a typed-in name.
        $other = $types->firstWhere('value', 'other');
        $this->assertNotNull($other, 'There must be an option for a document that is not on the list.');
        $this->assertTrue($other['custom_label_required']);
        $this->assertFalse($other['supersedes']);

        // An official type still replaces its predecessor.
        $this->assertTrue($types->firstWhere('value', 'commercial_invoice')['supersedes']);
        $this->assertFalse($types->firstWhere('value', 'shipment_document')['supersedes']);

        // "File as" offers what the team has already used, and is not a closed list.
        $this->assertContains('Certificate of Origin', $response->json('data.file_as_suggestions'));
    }

    public function test_a_custom_type_files_alongside_its_siblings_instead_of_replacing_them(): void
    {
        $order = $this->order(['payment_stage' => 'deposit_paid']);

        foreach (['Certificate of Origin', 'Fumigation Certificate'] as $i => $label) {
            $this->post(
                "/api/v1/admin/orders/{$order->id}/trade-documents/upload",
                [
                    'file'       => UploadedFile::fake()->create("doc{$i}.pdf", 12, 'application/pdf'),
                    'type'       => 'other',
                    'type_label' => $label,
                ],
                $this->headers()
            )->assertCreated();
        }

        $docs = TradeDocument::where('order_id', $order->id)->where('type', 'other')->get();

        // Two uploads, two live documents. Under the old `!== shipment_document`
        // rule the first would have been superseded by the second.
        $this->assertCount(2, $docs);
        $this->assertCount(2, $docs->where('status', 'issued'));
        $this->assertEqualsCanonicalizing(
            ['Certificate of Origin', 'Fumigation Certificate'],
            $docs->pluck('type_label')->all()
        );
    }

    public function test_an_official_type_still_supersedes_its_predecessor(): void
    {
        $order = $this->order(['payment_stage' => 'deposit_paid']);

        TradeDocument::create([
            'order_id'  => $order->id,
            'order_ref' => $order->ref,
            'type'      => 'commercial_invoice',
            'number'    => 'CI-2026-0001',
            'status'    => 'issued',
        ]);

        $this->post(
            "/api/v1/admin/orders/{$order->id}/trade-documents/upload",
            [
                'file'       => UploadedFile::fake()->create('ci.pdf', 12, 'application/pdf'),
                'type'       => 'commercial_invoice',
                'type_label' => 'Commercial Invoice from the accountant',
            ],
            $this->headers()
        )->assertCreated();

        $this->assertSame(
            'superseded',
            TradeDocument::where('number', 'CI-2026-0001')->value('status')
        );
        $this->assertCount(
            1,
            TradeDocument::where('order_id', $order->id)
                ->where('type', 'commercial_invoice')
                ->where('status', 'issued')
                ->get()
        );
    }

    // ── 5. putting a wrong payment state back (Session 90) ────────────────
    //
    // The order manager reported the same shape of problem a second time: an
    // order confirmed, the deposit not yet in, and the site saying the customer
    // had paid. Session 76 closed the paths that were putting orders there on
    // their own. What it did not close is that nothing could put one back
    // afterwards — every route through the ladder moves forward — so an order
    // that landed wrong for any reason at all stayed wrong until a developer
    // touched the database. That is what "there is no option for us to set it
    // manually" means, and it is the gap these tests hold shut.

    public function test_an_order_showing_paid_can_be_put_back_to_awaiting_deposit(): void
    {
        $order = $this->order([
            'payment_status'  => 'paid',
            'payment_stage'   => 'balance_paid',
            'balance_paid_at' => now()->subDay(),
        ]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/payment-milestones/correct", [
            'payment_stage'        => 'pending_proforma',
            'reset_payment_status' => true,
            'reason'               => 'Deposit has not arrived; this state was never confirmed by anyone.',
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.payment_stage', 'pending_proforma')
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.payment_milestones_active', false);

        $order->refresh();

        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('pending_proforma', $order->payment_stage);

        // The customer portal reads both of these. Either one left behind keeps
        // telling him he has paid.
        $this->assertFalse($order->isFullyPaid());
        $this->assertFalse($order->paymentMilestonesActive());
    }

    public function test_a_rolled_back_stage_takes_its_payment_dates_with_it(): void
    {
        // A date saying the balance arrived on the 3rd is a claim, not a note.
        // Leaving it behind a stage of pending_proforma leaves the claim standing.
        $order = $this->order([
            'payment_status'        => 'paid',
            'payment_stage'         => 'shipment_released',
            'deposit_paid_at'       => now()->subDays(5),
            'balance_paid_at'       => now()->subDays(3),
            'shipment_released_at'  => now()->subDay(),
            'shipment_release_note' => 'Released to the forwarder.',
        ]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/payment-milestones/correct", [
            'payment_stage'        => 'pending_proforma',
            'reset_payment_status' => true,
            'reason'               => 'Nothing has been paid on this order yet.',
        ], $this->headers())->assertOk();

        $order->refresh();

        $this->assertNull($order->deposit_paid_at);
        $this->assertNull($order->balance_paid_at);
        $this->assertNull($order->shipment_released_at);
        $this->assertNull($order->shipment_release_note);
    }

    public function test_a_stage_that_still_stands_keeps_its_date(): void
    {
        // Correcting only the tail of the ladder must not disown the deposit
        // that genuinely did arrive — otherwise the fix costs more than the bug.
        $paidAt = now()->subDays(5)->startOfSecond();

        $order = $this->order([
            'payment_status'   => 'paid',
            'payment_stage'    => 'balance_paid',
            'deposit_paid_at'  => $paidAt,
            'balance_paid_at'  => now()->subDay(),
        ]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/payment-milestones/correct", [
            'payment_stage'        => 'deposit_paid',
            'reset_payment_status' => true,
            'reason'               => 'Deposit is in, the balance is not — balance was marked in error.',
        ], $this->headers())->assertOk();

        $order->refresh();

        $this->assertSame('deposit_paid', $order->payment_stage);
        $this->assertNull($order->balance_paid_at);
        $this->assertNotNull($order->deposit_paid_at);
        $this->assertSame($paidAt->toDateTimeString(), $order->deposit_paid_at->toDateTimeString());
    }

    public function test_a_correction_never_moves_an_order_forward(): void
    {
        // Recording that money arrived belongs to the milestone actions, which
        // notify the customer and stamp who confirmed it. If this could do it
        // too it would become the quick way round both, and the ladder's guards
        // would stop meaning anything.
        $order = $this->order(['payment_stage' => 'deposit_requested']);

        $this->postJson("/api/v1/admin/orders/{$order->id}/payment-milestones/correct", [
            'payment_stage' => 'balance_paid',
            'reason'        => 'Trying to shortcut the ladder.',
        ], $this->headers())
            ->assertStatus(409)
            ->assertJsonPath('code', 'use_the_milestone_actions');

        $this->assertSame('deposit_requested', $order->fresh()->payment_stage);
    }

    public function test_a_stripe_order_is_left_to_the_gateway_here_too(): void
    {
        $order = $this->order([
            'payment_method' => 'stripe',
            'payment_status' => 'paid',
            'payment_stage'  => 'balance_paid',
        ]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/payment-milestones/correct", [
            'payment_stage'        => 'pending_proforma',
            'reset_payment_status' => true,
            'reason'               => 'Trying to hand-edit a gateway payment.',
        ], $this->headers())
            ->assertStatus(409)
            ->assertJsonPath('code', 'gateway_managed_payment');

        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_a_correction_is_refused_without_a_reason(): void
    {
        $order = $this->order(['payment_status' => 'paid', 'payment_stage' => 'balance_paid']);

        $this->postJson("/api/v1/admin/orders/{$order->id}/payment-milestones/correct", [
            'payment_stage' => 'pending_proforma',
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame('balance_paid', $order->fresh()->payment_stage);
    }

    public function test_a_correction_never_emails_the_customer(): void
    {
        // A "your payment status changed" for a payment that never happened is
        // exactly the confusion this whole feature exists to end.
        $order = $this->order(['payment_status' => 'paid', 'payment_stage' => 'balance_paid']);

        $this->postJson("/api/v1/admin/orders/{$order->id}/payment-milestones/correct", [
            'payment_stage'        => 'pending_proforma',
            'reset_payment_status' => true,
            'reason'               => 'Deposit not received.',
        ], $this->headers())->assertOk();

        Mail::assertNothingSent();

        $this->assertNull($order->fresh()->balance_paid_email_sent_at);
    }

    public function test_a_correction_always_leaves_an_audit_row_naming_who_and_why(): void
    {
        $order = $this->order(['payment_status' => 'paid', 'payment_stage' => 'balance_paid']);

        $this->postJson("/api/v1/admin/orders/{$order->id}/payment-milestones/correct", [
            'payment_stage'        => 'pending_proforma',
            'reset_payment_status' => true,
            'reason'               => 'Buyer queried a payment he never made.',
        ], $this->headers())->assertOk();

        $log = OrderLog::where('order_id', $order->id)->where('action', 'payment_state_corrected')->first();

        $this->assertNotNull($log, 'A correction that records nothing is worse than the state it corrects.');
        $this->assertSame('balance_paid / paid', $log->old_value);
        $this->assertSame('pending_proforma / pending', $log->new_value);
        $this->assertStringContainsString('Buyer queried a payment he never made.', (string) $log->notes);
        $this->assertNotNull($log->admin_user_email);
    }

    public function test_the_action_is_one_the_column_accepts(): void
    {
        // The single guard behind the longest-standing High gap in this project:
        // shipped code writing an action the ENUM rejects, the write swallowed,
        // the audit row never created. This one write is deliberately NOT inside
        // a try/catch, so the constant has to carry the value.
        $this->assertContains('payment_state_corrected', OrderLog::ACTIONS);
    }

    public function test_a_role_without_the_permission_cannot_correct_a_payment(): void
    {
        $order = $this->order(['payment_status' => 'paid', 'payment_stage' => 'balance_paid']);

        $this->postJson("/api/v1/admin/orders/{$order->id}/payment-milestones/correct", [
            'payment_stage'        => 'pending_proforma',
            'reset_payment_status' => true,
            'reason'               => 'Should never be applied.',
        ], $this->headers('viewer'))->assertStatus(403);

        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_an_order_already_in_that_state_is_not_written_again(): void
    {
        $order = $this->order(['payment_status' => 'pending', 'payment_stage' => 'deposit_requested']);

        $this->postJson("/api/v1/admin/orders/{$order->id}/payment-milestones/correct", [
            'payment_stage' => 'deposit_requested',
            'reason'        => 'Nothing actually needs changing here.',
        ], $this->headers())
            ->assertStatus(409)
            ->assertJsonPath('code', 'nothing_to_correct');

        $this->assertSame(0, OrderLog::where('order_id', $order->id)->where('action', 'payment_state_corrected')->count());
    }

    // ── 6. finding the orders that are already wrong ──────────────────────

    public function test_the_audit_flags_a_paid_order_with_nothing_behind_it(): void
    {
        $order = $this->order(['payment_status' => 'paid', 'payment_stage' => 'balance_paid']);

        $this->assertNotNull(app(PaymentStateCorrectionService::class)->unevidencedReason($order));
    }

    public function test_the_audit_leaves_alone_an_order_somebody_confirmed(): void
    {
        $order = $this->order([
            'payment_status'  => 'paid',
            'payment_stage'   => 'balance_paid',
            'balance_paid_at' => now()->subDay(),
        ]);

        $this->assertNull(app(PaymentStateCorrectionService::class)->unevidencedReason($order));
    }

    public function test_the_audit_leaves_alone_stripe_and_ebay(): void
    {
        // Both settle outside this database, against a source that genuinely
        // knows. Flagging them would bury the orders that are actually wrong.
        $stripe = $this->order(['payment_status' => 'paid', 'payment_method' => 'stripe']);
        $ebay   = $this->order(['payment_status' => 'paid', 'source' => 'ebay']);

        $service = app(PaymentStateCorrectionService::class);

        $this->assertNull($service->unevidencedReason($stripe));
        $this->assertNull($service->unevidencedReason($ebay));
    }

    public function test_an_unpaid_order_is_never_flagged(): void
    {
        $order = $this->order(['payment_status' => 'pending', 'payment_stage' => 'deposit_requested']);

        $this->assertNull(app(PaymentStateCorrectionService::class)->unevidencedReason($order));
    }

    public function test_an_order_recorded_as_already_paid_says_who_declared_it(): void
    {
        // The prevention half. 'paid' at creation is a person asserting the
        // money is in — right for a paper backlog, wrong for a live order, and
        // until now indistinguishable from a derivation afterwards because it
        // wrote nothing at all. It does not block the backfill workflow; it
        // just stops it being anonymous, which is what lets the audit tell the
        // two apart from here on.
        $this->postJson('/api/v1/admin/orders', [
            'customer_name'  => 'Backlog Buyer',
            'customer_email' => 'backlog@acme-tyres.com',
            'status'         => 'delivered',
            'payment_status' => 'paid',
            'total'          => 8000,
        ], $this->headers())->assertCreated();

        $order = Order::where('customer_email', 'backlog@acme-tyres.com')->firstOrFail();

        $log = OrderLog::where('order_id', $order->id)->where('action', 'payment_status_changed')->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('Declared by the admin', (string) $log->notes);
        $this->assertNull(app(PaymentStateCorrectionService::class)->unevidencedReason($order->fresh()));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * setDepositMilestones() is private and only reachable through
     * generateProformaForOrder(), which renders a PDF and needs the whole
     * document stack. Reflection keeps these two tests on the behaviour that
     * actually changed — whether the stage moves and whether the customer is
     * emailed — rather than on DomPDF.
     */
    private function runSetDepositMilestones(Order $order): void
    {
        $method = new \ReflectionMethod(TradeDocumentService::class, 'setDepositMilestones');
        $method->setAccessible(true);
        $method->invoke(app(TradeDocumentService::class), $order);
    }
}

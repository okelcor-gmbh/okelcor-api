<?php

namespace Tests\Feature;

use App\Mail\ReviewInviteEmail;
use App\Models\AdminUser;
use App\Models\Order;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The review invite (Session 118): one e-mail per order, on the transition
 * into delivered, and only once the business has set a public review URL.
 * The single biggest trust lever in this trade is a review count a buyer
 * can check; the invite is how the count gets built.
 */
class ReviewInviteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();
        foreach (['orders', 'order_logs', 'customer_notifications', 'customers', 'admin_users', 'personal_access_tokens'] as $t) {
            Schema::dropIfExists($t);
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

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('ref')->unique();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('payment_status', 30)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('delivery_cost', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('carrier')->nullable();
            $table->string('carrier_type', 20)->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('container_number', 30)->nullable();
            $table->date('estimated_delivery')->nullable();
            $table->date('eta')->nullable();
            $table->timestamp('review_invite_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('action', 60);
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->string('admin_user_email')->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->string('type', 60);
            $table->string('severity', 20)->default('info');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('action_url')->nullable();
            $table->string('dedupe_key')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();

        config(['reviews.enabled' => true, 'reviews.url' => 'https://www.trustpilot.com/evaluate/okelcor.com']);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['orders', 'order_logs', 'customer_notifications', 'customers', 'admin_users', 'personal_access_tokens'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::enableForeignKeyConstraints();
        parent::tearDown();
    }

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'name' => 'Ops ' . uniqid(),
            'email' => 'ops' . uniqid() . '@okelcor.test',
            'role' => 'order_manager',
            'password' => Hash::make('secret-pass-123'),
            'is_active' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'ref' => 'OKL-' . strtoupper(uniqid()),
            'customer_name' => 'Acme Buyer',
            'customer_email' => 'buyer@acme-tyres.test',
            'status' => 'shipped',
            'total' => 1050,
        ], $overrides));
    }

    public function test_marking_delivered_sends_the_invite_once_ever(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'delivered'])
            ->assertOk();

        Mail::assertSent(ReviewInviteEmail::class, function (ReviewInviteEmail $mail) use ($order) {
            return $mail->hasTo('buyer@acme-tyres.test')
                && $mail->order->id === $order->id
                && str_contains($mail->reviewUrl, 'trustpilot');
        });
        $this->assertNotNull($order->fresh()->review_invite_sent_at);

        // Flip away and back: delivered again must NOT e-mail again.
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'shipped'])
            ->assertOk();
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'delivered'])
            ->assertOk();

        Mail::assertSent(ReviewInviteEmail::class, 1);
    }

    public function test_no_review_url_means_no_invite_and_no_stamp(): void
    {
        // Blank means off — the profile must exist before customers are
        // pointed at it. The stamp stays null so switching the URL on later
        // still catches FUTURE deliveries only, not this one retroactively.
        config(['reviews.url' => '']);
        $order = $this->order();

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'delivered'])
            ->assertOk();

        Mail::assertNothingSent();
        $this->assertNull($order->fresh()->review_invite_sent_at);
    }

    public function test_an_order_without_an_email_is_skipped_quietly(): void
    {
        $order = $this->order(['customer_email' => null]);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'delivered'])
            ->assertOk();

        Mail::assertNothingSent();
    }

    public function test_a_cancellation_never_invites(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'cancelled'])
            ->assertOk();

        Mail::assertNothingSent();
    }
}

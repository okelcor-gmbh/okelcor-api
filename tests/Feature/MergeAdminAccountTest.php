<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * admin:merge-account — the same human switching to a work email must take
 * their work with them, while the audit trail keeps saying what the old
 * account actually did.
 */
class MergeAdminAccountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();
        foreach (['finance_snapshot_items', 'admin_notifications', 'admin_security_events', 'admin_users'] as $t) {
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

        Schema::create('admin_security_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 100);
            $table->string('severity', 20)->default('info');
            $table->foreignId('admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('admin_email')->nullable();
            $table->string('description', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->constrained('admin_users')->cascadeOnDelete();
            $table->string('type', 100);
            $table->string('title', 255);
            $table->timestamps();
        });

        Schema::create('finance_snapshot_items', function (Blueprint $table) {
            $table->id();
            $table->string('category', 40);
            $table->string('person', 100);
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('ref', 50);
            $table->string('status', 30)->default('Pending');
            $table->decimal('amount', 12, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['finance_snapshot_items', 'admin_notifications', 'admin_security_events', 'admin_users'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::enableForeignKeyConstraints();

        parent::tearDown();
    }

    private function user(string $email): AdminUser
    {
        return AdminUser::create([
            'name' => 'John', 'email' => $email, 'role' => 'super_admin',
            'password' => Hash::make('secret-pass-123'), 'is_active' => true,
        ]);
    }

    public function test_work_moves_and_the_audit_trail_does_not(): void
    {
        $old = $this->user('leojohnseyi@gmail.com');
        $new = $this->user('john@vitorra.org');

        DB::table('finance_snapshot_items')->insert([
            ['category' => 'OPEN ORDERS', 'person' => 'John', 'ref' => 'A', 'amount' => 1,
             'assigned_admin_id' => $old->id, 'created_by' => $old->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('admin_notifications')->insert([
            ['admin_user_id' => $old->id, 'type' => 'finance_task_digest', 'title' => 'x', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('admin_security_events')->insert([
            ['type' => 'login', 'admin_id' => $old->id, 'admin_email' => $old->email, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Dry run changes nothing.
        $this->artisan('admin:merge-account', ['from' => $old->email, 'to' => $new->email, '--dry-run' => true])
            ->assertSuccessful();
        $this->assertDatabaseHas('finance_snapshot_items', ['ref' => 'A', 'assigned_admin_id' => $old->id]);

        // The real merge moves work and attribution…
        $this->artisan('admin:merge-account', ['from' => $old->email, 'to' => $new->email])
            ->assertSuccessful();

        $this->assertDatabaseHas('finance_snapshot_items', [
            'ref' => 'A', 'assigned_admin_id' => $new->id, 'created_by' => $new->id,
        ]);
        $this->assertDatabaseHas('admin_notifications', ['admin_user_id' => $new->id]);

        // …and leaves the audit trail saying what actually happened.
        $this->assertDatabaseHas('admin_security_events', ['admin_id' => $old->id]);

        // Idempotent: nothing left to move.
        $this->artisan('admin:merge-account', ['from' => $old->email, 'to' => $new->email])
            ->assertSuccessful();
    }

    public function test_refuses_unknown_or_identical_accounts(): void
    {
        $this->user('john@vitorra.org');

        $this->artisan('admin:merge-account', ['from' => 'nobody@x.y', 'to' => 'john@vitorra.org'])
            ->assertFailed();

        $this->artisan('admin:merge-account', ['from' => 'john@vitorra.org', 'to' => 'john@vitorra.org'])
            ->assertFailed();
    }
}

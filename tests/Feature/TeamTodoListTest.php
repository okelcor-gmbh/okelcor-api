<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Todo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The shared team to-do list (Session 102): anyone can use it, tagging a
 * teammate notifies them and lands the item in their My Work, and the people
 * an item concerns are the people who may move it.
 *
 * Minimal-schema sqlite harness, same pattern as EcInvoiceListTest.
 */
class TeamTodoListTest extends TestCase
{
    private int $seq = 0;

    private const TABLES = [
        'todos', 'admin_notifications', 'admin_security_events',
        'quote_requests', 'admin_users',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();
        foreach (self::TABLES as $t) {
            Schema::dropIfExists($t);
        }

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->boolean('is_active')->default(true);
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamps();
        });

        // The My Work endpoint reads these alongside the to-dos under test.
        Schema::create('quote_requests', function (Blueprint $table) {
            $table->id();
            $table->string('ref_number')->nullable();
            $table->string('company_name')->nullable();
            $table->string('full_name')->nullable();
            $table->string('status', 40)->nullable();
            $table->string('qualification_status', 40)->nullable();
            $table->string('lead_priority', 20)->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('follow_up_at')->nullable();
            $table->timestamp('follow_up_completed_at')->nullable();
            $table->string('proposal_status', 40)->nullable();
            $table->string('proposal_number')->nullable();
            $table->timestamp('proposal_accepted_at')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->timestamps();
        });

        Schema::create('admin_security_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 100);
            $table->string('severity', 20)->default('info');
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('admin_email')->nullable();
            $table->string('admin_role', 50)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('description', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->constrained('admin_users')->cascadeOnDelete();
            $table->string('type', 100);
            $table->string('severity', 20)->default('info');
            $table->string('title', 255);
            $table->text('body')->nullable();
            $table->text('message')->nullable();
            $table->string('action_url', 255)->nullable();
            $table->string('link', 255)->nullable();
            $table->string('related_type', 100)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // The real migration, run against real SQL.
        $this->runMigration('2026_08_28_000006_create_todos_table');

        Schema::enableForeignKeyConstraints();

        Todo::forgetAvailableCheck();
    }

    protected function tearDown(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (self::TABLES as $t) {
            Schema::dropIfExists($t);
        }
        Schema::enableForeignKeyConstraints();
        parent::tearDown();
    }

    private function runMigration(string $name): void
    {
        (require database_path("migrations/{$name}.php"))->up();
    }

    private function admin(string $role = 'viewer'): AdminUser
    {
        return AdminUser::create([
            'name'                    => 'Staff ' . (++$this->seq),
            'email'                   => 'td' . $this->seq . uniqid() . '@okelcor.test',
            'role'                    => $role,
            'password'                => Hash::make('secret-pass-123'),
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    // ── the migration itself ──────────────────────────────────────────────

    public function test_the_migration_applies_against_real_sql_and_is_idempotent(): void
    {
        $this->runMigration('2026_08_28_000006_create_todos_table');

        $this->assertTrue(Schema::hasTable('todos'));
    }

    // ── anyone can use it ─────────────────────────────────────────────────

    public function test_any_role_can_create_and_everyone_sees_the_shared_list(): void
    {
        // A viewer — the least-privileged role there is.
        $viewer  = $this->admin('viewer');
        $support = $this->admin('support');

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/v1/admin/todos', [
                'title' => 'Chase the Croatia shipping quote', 'priority' => 'high',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.you_may_edit', true);

        // One shared list: someone else sees it too, but may not move it.
        $this->actingAs($support, 'sanctum')
            ->getJson('/api/v1/admin/todos')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Chase the Croatia shipping quote')
            ->assertJsonPath('data.0.you_may_edit', false)
            ->assertJsonPath('meta.open_count', 1);
    }

    // ── the tag ───────────────────────────────────────────────────────────

    public function test_tagging_a_teammate_notifies_them_and_lands_in_their_my_work(): void
    {
        $creator  = $this->admin('editor');
        $assignee = $this->admin('support');

        $todoId = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/v1/admin/todos', [
                'title'             => 'Send the signed CMR to the customer',
                'due_on'            => now()->addDays(2)->toDateString(),
                'assigned_admin_id' => $assignee->id,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseHas('admin_notifications', [
            'admin_user_id' => $assignee->id,
            'type'          => 'todo_assigned',
        ]);

        $work = $this->actingAs($assignee, 'sanctum')
            ->getJson('/api/v1/admin/my-work')
            ->assertOk();

        $this->assertSame(1, $work->json('meta.counts.todo_tasks'));
        $task = $work->json('data.todo_tasks.0');
        $this->assertSame($todoId, $task['id']);
        $this->assertTrue($task['editable']);
        $this->assertSame("/admin/todos?todo={$todoId}", $task['action_url']);
        $this->assertSame('open', $task['status_options'][0]['value']);
    }

    // ── who may move an item ──────────────────────────────────────────────

    public function test_only_the_people_an_item_concerns_can_move_it(): void
    {
        $creator  = $this->admin('editor');
        $assignee = $this->admin('support');
        $stranger = $this->admin('support');

        $todoId = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/v1/admin/todos', [
                'title' => 'Update the Artikelmerkmale sheet', 'assigned_admin_id' => $assignee->id,
            ])->json('data.id');

        // The assignee moves it — no special permission needed.
        $this->actingAs($assignee, 'sanctum')
            ->patchJson("/api/v1/admin/todos/{$todoId}", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        // A bystander does not, even though everyone can read the list.
        $this->actingAs($stranger, 'sanctum')
            ->patchJson("/api/v1/admin/todos/{$todoId}", ['status' => 'done'])
            ->assertStatus(403);
    }

    public function test_completion_notifies_the_creator_and_reopening_clears_the_stamp(): void
    {
        $creator  = $this->admin('editor');
        $assignee = $this->admin('support');

        $todoId = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/v1/admin/todos', [
                'title' => 'Reconcile order 10075', 'assigned_admin_id' => $assignee->id,
            ])->json('data.id');

        $this->actingAs($assignee, 'sanctum')
            ->patchJson("/api/v1/admin/todos/{$todoId}", ['status' => 'done'])
            ->assertOk()
            ->assertJsonPath('data.status', 'done');

        $this->assertNotNull(Todo::find($todoId)->completed_at);
        $this->assertDatabaseHas('admin_notifications', [
            'admin_user_id' => $creator->id,
            'type'          => 'todo_completed',
        ]);

        // A reopened item was NOT done — the stamp goes with the status.
        $this->actingAs($creator, 'sanctum')
            ->patchJson("/api/v1/admin/todos/{$todoId}", ['status' => 'open'])
            ->assertOk();

        $this->assertNull(Todo::find($todoId)->completed_at);
        $this->assertNull(Todo::find($todoId)->completed_by);
    }

    public function test_deleting_is_the_creators_call_not_the_assignees(): void
    {
        $creator  = $this->admin('editor');
        $assignee = $this->admin('support');

        $todoId = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/v1/admin/todos', [
                'title' => 'Draft the returns text', 'assigned_admin_id' => $assignee->id,
            ])->json('data.id');

        // The assignee marks things done; they do not erase that it was asked.
        $this->actingAs($assignee, 'sanctum')
            ->deleteJson("/api/v1/admin/todos/{$todoId}")
            ->assertStatus(403);

        $this->actingAs($creator, 'sanctum')
            ->deleteJson("/api/v1/admin/todos/{$todoId}")
            ->assertOk();

        $this->assertSame(0, Todo::count());
    }

    // ── retagging and the filters ─────────────────────────────────────────

    public function test_retagging_notifies_the_new_assignee_and_scopes_filter_the_list(): void
    {
        $creator = $this->admin('editor');
        $first   = $this->admin('support');
        $second  = $this->admin('support');

        $todoId = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/v1/admin/todos', [
                'title' => 'Call the carrier', 'assigned_admin_id' => $first->id,
            ])->json('data.id');

        $this->actingAs($creator, 'sanctum')
            ->patchJson("/api/v1/admin/todos/{$todoId}", ['assigned_admin_id' => $second->id])
            ->assertOk();

        $this->assertDatabaseHas('admin_notifications', [
            'admin_user_id' => $second->id,
            'type'          => 'todo_assigned',
        ]);

        // scope=mine follows the tag.
        $this->assertCount(0, $this->actingAs($first, 'sanctum')
            ->getJson('/api/v1/admin/todos?scope=mine')->json('data'));
        $this->assertCount(1, $this->actingAs($second, 'sanctum')
            ->getJson('/api/v1/admin/todos?scope=mine')->json('data'));

        // The default view is the working list — done items leave it but stay
        // reachable under status=done.
        $this->actingAs($second, 'sanctum')
            ->patchJson("/api/v1/admin/todos/{$todoId}", ['status' => 'done'])
            ->assertOk();

        $this->assertCount(0, $this->actingAs($creator, 'sanctum')
            ->getJson('/api/v1/admin/todos')->json('data'));
        $this->assertCount(1, $this->actingAs($creator, 'sanctum')
            ->getJson('/api/v1/admin/todos?status=done')->json('data'));
    }

    // ── deploy-order safety ───────────────────────────────────────────────

    public function test_the_list_and_my_work_survive_the_feature_arriving_before_its_migration(): void
    {
        $viewer = $this->admin('viewer');

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('todos');
        Schema::enableForeignKeyConstraints();
        Todo::forgetAvailableCheck();

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/admin/todos')
            ->assertOk()
            ->assertJsonPath('meta.todos_available', false);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/admin/my-work')
            ->assertOk()
            ->assertJsonPath('meta.counts.todo_tasks', 0);
    }
}

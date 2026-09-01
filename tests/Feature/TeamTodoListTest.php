<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Todo;
use App\Support\AdminPermissions;
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

        // The real migrations, run against real SQL.
        $this->runMigration('2026_08_28_000006_create_todos_table');
        $this->runMigration('2026_09_01_000001_add_assignee_note_to_todos_table');
        $this->runMigration('2026_09_01_000002_add_created_by_role_to_todos_table');

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

    private function rollbackMigration(string $name): void
    {
        (require database_path("migrations/{$name}.php"))->down();
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
        $this->assertSame('open', $task['status_options'][0]['value']);

        // The task opens IN My Work. This asserted `/admin/todos?todo=N` —
        // the whole list page — until the assignee reported clicking her
        // task and landing on a list she then had to search. The shared list
        // stays reachable, as a second link rather than the only one.
        $this->assertSame("/admin/my-work?todo={$todoId}", $task['action_url']);
        $this->assertSame("/admin/todos?todo={$todoId}", $task['list_url']);
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

    // ── the assignee's whole view of the task (Session 108) ───────────────

    public function test_my_work_carries_the_whole_todo_and_the_note_travels_back(): void
    {
        $creator  = $this->admin('editor');
        $assignee = $this->admin('support');

        $todoId = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/v1/admin/todos', [
                'title'             => 'Send the signed CMR to the customer',
                'details'           => 'He needs it before the container sails on Thursday.',
                'due_on'            => '2026-09-04',
                'priority'          => 'high',
                'assigned_admin_id' => $assignee->id,
            ])->json('data.id');

        // Everything the task IS, not a subtitle it was baked into. Without
        // these the panel can show the row but not the brief, and the
        // assignee has to leave My Work to find out what was asked.
        $task = $this->actingAs($assignee, 'sanctum')
            ->getJson('/api/v1/admin/my-work')
            ->assertOk()
            ->json('data.todo_tasks.0');

        $this->assertSame('He needs it before the container sails on Thursday.', $task['details']);
        $this->assertSame('2026-09-04', $task['due_on']);
        $this->assertSame($creator->name, $task['creator']);
        $this->assertNull($task['assignee_note']);

        // The reply. It is a separate field from `details` on purpose: the
        // brief belongs to whoever asked, and a note that overwrote it would
        // destroy the question while answering it.
        $this->actingAs($assignee, 'sanctum')
            ->patchJson("/api/v1/admin/todos/{$todoId}", [
                'status'        => 'in_progress',
                'assignee_note' => 'Customer asked to push it to Thursday.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.assignee_note', 'Customer asked to push it to Thursday.')
            ->assertJsonPath('data.details', 'He needs it before the container sails on Thursday.');

        // Whoever asked hears the reason, not just the status.
        $this->assertDatabaseHas('admin_notifications', [
            'admin_user_id' => $creator->id,
            'type'          => 'todo_note_added',
            'body'          => 'Customer asked to push it to Thursday.',
        ]);

        // And it comes back on the row, so the note is a conversation rather
        // than a write-only field.
        $this->assertSame(
            'Customer asked to push it to Thursday.',
            $this->actingAs($assignee, 'sanctum')
                ->getJson('/api/v1/admin/my-work')
                ->json('data.todo_tasks.0.assignee_note')
        );
    }

    public function test_the_note_column_can_arrive_after_the_code_does(): void
    {
        // Deploy-order safety, the same contract the table itself has. The
        // status is the load-bearing half of the update and must still land
        // when the column is not there yet — a to-do nobody can close is
        // worse than one that cannot carry a reason.
        Schema::table('todos', fn (Blueprint $t) => $t->dropColumn('assignee_note'));
        Todo::forgetAvailableCheck();

        $creator  = $this->admin('editor');
        $assignee = $this->admin('support');

        $todoId = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/v1/admin/todos', [
                'title' => 'Book the truck', 'assigned_admin_id' => $assignee->id,
            ])->json('data.id');

        $this->actingAs($assignee, 'sanctum')
            ->patchJson("/api/v1/admin/todos/{$todoId}", [
                'status' => 'done', 'assignee_note' => 'dropped, no column yet',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.assignee_note', null);

        $this->assertNull(
            $this->actingAs($assignee, 'sanctum')
                ->getJson('/api/v1/admin/my-work')
                ->json('data.todo_tasks.0.assignee_note')
        );
    }

    public function test_the_migration_is_idempotent_and_leaves_existing_rows_alone(): void
    {
        $creator = $this->admin('editor');

        $todoId = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/v1/admin/todos', ['title' => 'Chase the Croatia quote'])
            ->json('data.id');

        // Already applied in setUp — a second run must be a no-op, not a
        // duplicate-column error, and must not touch the row.
        $this->runMigration('2026_09_01_000001_add_assignee_note_to_todos_table');

        $this->assertTrue(Schema::hasColumn('todos', 'assignee_note'));
        $this->assertSame('Chase the Croatia quote', Todo::find($todoId)->title);
    }

    // ── where a to-do came from (Session 109) ─────────────────────────────

    public function test_every_role_has_a_department(): void
    {
        // A role added without a department would quietly render as a tidied
        // role name on every to-do it raises. Red test rather than silent
        // drift — the same contract OrderLog::ACTIONS has with its ENUM.
        foreach (AdminPermissions::ROLES as $role) {
            $this->assertArrayHasKey(
                $role,
                AdminPermissions::DEPARTMENTS,
                "Role '{$role}' has no department — add it to AdminPermissions::DEPARTMENTS.",
            );
        }
    }

    public function test_a_todo_carries_the_department_that_raised_it(): void
    {
        $finance  = $this->admin('finance');
        $ops      = $this->admin('order_manager');
        $assignee = $this->admin('support');

        $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/admin/todos', [
                'title' => 'Share Invoice copy', 'assigned_admin_id' => $assignee->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.created_by_role', 'finance')
            ->assertJsonPath('data.department', 'Finance');

        $this->actingAs($ops, 'sanctum')
            ->postJson('/api/v1/admin/todos', ['title' => 'Confirm the delivery window'])
            ->assertCreated()
            ->assertJsonPath('data.department', 'Operations');

        // It reaches the assignee's My Work too — that is the list where
        // placing a request matters most, because she did not choose to
        // look at it.
        $this->assertSame(
            'Finance',
            $this->actingAs($assignee, 'sanctum')
                ->getJson('/api/v1/admin/my-work')
                ->json('data.todo_tasks.0.department')
        );
    }

    public function test_the_department_filter_and_its_counts(): void
    {
        $finance = $this->admin('finance');
        $ops     = $this->admin('order_manager');
        $boss    = $this->admin('super_admin');

        $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/admin/todos', ['title' => 'Share Invoice copy']);
        $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/admin/todos', ['title' => 'Share Bank statement']);
        $this->actingAs($ops, 'sanctum')
            ->postJson('/api/v1/admin/todos', ['title' => 'Confirm the delivery window']);

        $all = $this->actingAs($boss, 'sanctum')->getJson('/api/v1/admin/todos')->assertOk();

        $this->assertSame(2, $all->json('meta.departments.Finance'));
        $this->assertSame(1, $all->json('meta.departments.Operations'));

        $filtered = $this->actingAs($boss, 'sanctum')
            ->getJson('/api/v1/admin/todos?department=Finance')
            ->assertOk();

        $this->assertCount(2, $filtered->json('data'));
        foreach ($filtered->json('data') as $row) {
            $this->assertSame('Finance', $row['department']);
        }

        // An unrecognised department returns nothing rather than everything.
        // A filter that silently stops filtering is the worse failure: the
        // reader believes they are looking at one department's work.
        $this->assertCount(
            0,
            $this->actingAs($boss, 'sanctum')
                ->getJson('/api/v1/admin/todos?department=Accounting')
                ->assertOk()
                ->json('data')
        );
    }

    public function test_several_roles_share_one_department_and_the_filter_finds_both(): void
    {
        // `admin` and `super_admin` are both Management. Filtering on the
        // ROLE would have returned half of it — the reason the filter takes
        // the department name.
        $boss    = $this->admin('super_admin');
        $manager = $this->admin('admin');

        $this->actingAs($boss, 'sanctum')
            ->postJson('/api/v1/admin/todos', ['title' => 'Sign off the Q3 numbers']);
        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/admin/todos', ['title' => 'Book the auditor']);

        $this->assertCount(
            2,
            $this->actingAs($boss, 'sanctum')
                ->getJson('/api/v1/admin/todos?department=Management')
                ->assertOk()
                ->json('data')
        );
    }

    public function test_the_stamp_is_frozen_when_the_creator_changes_role(): void
    {
        $joseph = $this->admin('finance');

        $todoId = $this->actingAs($joseph, 'sanctum')
            ->postJson('/api/v1/admin/todos', ['title' => 'Share Invoice copy'])
            ->json('data.id');

        // He moves to admin. The to-do was raised BY FINANCE and still was.
        $joseph->update(['role' => 'admin']);

        $this->assertSame('Finance', Todo::find($todoId)->department());

        // And it survives the account going away entirely — `created_by` is
        // nullOnDelete, so a derived label would have vanished here.
        Todo::where('id', $todoId)->update(['created_by' => null]);

        $this->assertSame('Finance', Todo::find($todoId)->fresh()->department());
    }

    public function test_the_backfill_stamps_existing_rows_and_is_re_runnable(): void
    {
        $finance = $this->admin('finance');

        $todoId = $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/admin/todos', ['title' => 'Share Invoice copy'])
            ->json('data.id');

        // A row from before the stamp existed — production has 32 of these.
        Todo::where('id', $todoId)->update(['created_by_role' => null]);

        $this->runMigration('2026_09_01_000002_add_created_by_role_to_todos_table');
        $this->assertSame('finance', Todo::find($todoId)->created_by_role);

        // Re-running must not overwrite a stamp that is already correct,
        // even once the creator has moved on.
        $finance->update(['role' => 'admin']);
        $this->runMigration('2026_09_01_000002_add_created_by_role_to_todos_table');

        $this->assertSame('finance', Todo::find($todoId)->fresh()->created_by_role);
    }

    public function test_the_source_column_can_arrive_after_the_code_does(): void
    {
        // Through the migration's own down(), which drops the index before
        // the column — exercising the rollback path rather than a hand-rolled
        // drop that would not survive on sqlite.
        $this->rollbackMigration('2026_09_01_000002_add_created_by_role_to_todos_table');
        Todo::forgetAvailableCheck();

        $this->assertFalse(Schema::hasColumn('todos', 'created_by_role'));

        $finance = $this->admin('finance');

        // Creating still works — a to-do with no badge beats one that cannot
        // be raised at all.
        $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/admin/todos', ['title' => 'Share Invoice copy'])
            ->assertCreated()
            ->assertJsonPath('data.department', null);

        $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/admin/todos?department=Finance')
            ->assertOk()
            ->assertJsonPath('meta.departments', []);
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

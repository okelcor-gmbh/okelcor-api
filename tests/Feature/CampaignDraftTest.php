<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\CampaignDraft;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Campaign autosave.
 *
 * Reported by a marketer: leaving the Mail Campaign tab for the Media Library
 * and coming back lost everything typed. Nothing persisted work in progress —
 * `POST /admin/bulk-emails` sends rather than saves.
 *
 * Minimal-schema sqlite harness, same pattern as BulkEmailCampaignTest.
 */
class CampaignDraftTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();

        foreach (['campaign_drafts', 'personal_access_tokens', 'admin_users'] as $table) {
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

        Schema::create('campaign_drafts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_user_id');
            $table->string('subject', 255)->nullable();
            $table->text('blocks')->nullable();
            $table->text('theme')->nullable();
            $table->longText('body_html')->nullable();
            $table->text('filters')->nullable();
            $table->string('name', 150)->nullable();
            $table->timestamps();
        });
    }

    private function admin(string $role = 'admin'): AdminUser
    {
        $this->seq++;

        return AdminUser::create([
            'name'                    => 'Marketer ' . $this->seq,
            'email'                   => "marketer{$this->seq}@okelcor.com",
            'password'                => Hash::make('secret-password'),
            'role'                    => $role,
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function headers(?AdminUser $admin = null): array
    {
        $admin ??= $this->admin();

        // `auth:sanctum` memoises the resolved user on the guard instance, and
        // that instance survives between requests inside one test method — so
        // without this, a second request made as a DIFFERENT admin is still
        // served as the first one, and a privacy test would pass or fail for
        // reasons that have nothing to do with the code under test.
        // (PartnerAuth is unaffected: it resolves the token itself and calls
        // setUserResolver, so there is no guard to cache.)
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken];
    }

    /** Switch identity mid-test without the cached guard following along. */
    private function asAdmin(AdminUser $admin): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken];
    }

    private function blocks(): array
    {
        return [
            ['type' => 'heading', 'text' => 'Summer tyre offer'],
            ['type' => 'text', 'text' => 'Hello **there**'],
        ];
    }

    // ── the reported problem ──────────────────────────────────────────────

    public function test_work_survives_leaving_the_editor_and_coming_back(): void
    {
        $headers = $this->headers();

        // Compose something.
        $created = $this->postJson('/api/v1/admin/campaign-drafts', [
            'subject' => 'Summer offer',
            'blocks'  => $this->blocks(),
            'theme'   => ['preset' => 'okelcor_dark'],
            'filters' => ['market' => 'germany'],
        ], $headers);

        $created->assertStatus(201);
        $id = $created->json('data.id');

        // Go to the Media Library, come back, editor asks what it was doing.
        $restored = $this->getJson('/api/v1/admin/campaign-drafts/latest', $headers);

        $restored->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.subject', 'Summer offer')
            ->assertJsonPath('data.theme.preset', 'okelcor_dark')
            ->assertJsonPath('data.filters.market', 'germany');

        $this->assertCount(2, $restored->json('data.blocks'));
    }

    public function test_autosave_reuses_one_row_rather_than_piling_up(): void
    {
        $headers = $this->headers();

        $id = $this->postJson('/api/v1/admin/campaign-drafts', ['subject' => 'S'], $headers)
            ->json('data.id');

        // Twenty autosaves as the marketer types.
        for ($i = 0; $i < 20; $i++) {
            $this->putJson("/api/v1/admin/campaign-drafts/{$id}", [
                'subject' => 'Summer offer ' . $i,
                'blocks'  => $this->blocks(),
            ], $headers)->assertOk();
        }

        $this->assertDatabaseCount('campaign_drafts', 1);
        $this->assertSame('Summer offer 19', CampaignDraft::first()->subject);
    }

    // ── autosave must accept incomplete work ──────────────────────────────

    public function test_a_half_finished_campaign_still_saves(): void
    {
        $headers = $this->headers();

        // No subject, no filters, and a Button block with no URL — all of
        // which the send endpoint would rightly reject. Autosave must not:
        // refusing to save incomplete work defeats the entire point.
        $this->postJson('/api/v1/admin/campaign-drafts', [
            'blocks' => [
                ['type' => 'button', 'label' => 'Shop now'],
                ['type' => 'heading'],
            ],
        ], $headers)->assertStatus(201);

        $this->assertDatabaseCount('campaign_drafts', 1);
    }

    public function test_an_entirely_empty_draft_is_not_offered_for_restore(): void
    {
        $headers = $this->headers();

        // The editor opens and autosaves a blank canvas before anything is
        // typed. Offering to "restore your work" here restores nothing and
        // trains the marketer to dismiss the prompt.
        $this->postJson('/api/v1/admin/campaign-drafts', [], $headers)->assertStatus(201);

        $this->getJson('/api/v1/admin/campaign-drafts/latest', $headers)
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_nothing_to_restore_is_not_an_error(): void
    {
        $this->getJson('/api/v1/admin/campaign-drafts/latest', $this->headers())
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    // ── full replace, not merge ───────────────────────────────────────────

    public function test_removing_every_block_is_expressible(): void
    {
        $headers = $this->headers();

        $id = $this->postJson('/api/v1/admin/campaign-drafts', [
            'subject' => 'Summer offer',
            'blocks'  => $this->blocks(),
        ], $headers)->json('data.id');

        // The marketer deletes the blocks. A merge-style update would leave
        // the old ones in place and they would reappear on restore.
        $this->putJson("/api/v1/admin/campaign-drafts/{$id}", [
            'subject' => 'Summer offer',
        ], $headers)->assertOk();

        $this->assertNull(CampaignDraft::find($id)->blocks);
    }

    // ── privacy ───────────────────────────────────────────────────────────

    public function test_a_draft_is_private_to_its_author(): void
    {
        $mine    = $this->admin();
        $someone = $this->admin();

        $created = $this->postJson('/api/v1/admin/campaign-drafts', [
            'subject' => 'Unannounced Q4 pricing',
        ], $this->headers($mine));

        // Asserted rather than assumed: a null id here would make the URLs
        // below `.../campaign-drafts/`, which matches the INDEX route and
        // returns a misleading 200.
        $created->assertStatus(201);
        $id = $created->json('data.id');

        // 404, not 403 — a 403 would confirm the id exists.
        $this->getJson("/api/v1/admin/campaign-drafts/{$id}", $this->asAdmin($someone))->assertStatus(404);
        $this->putJson("/api/v1/admin/campaign-drafts/{$id}", ['subject' => 'x'], $this->asAdmin($someone))->assertStatus(404);

        // Someone else's discard must not destroy my work.
        $this->deleteJson("/api/v1/admin/campaign-drafts/{$id}", [], $this->asAdmin($someone))->assertOk();
        $this->assertNotNull(CampaignDraft::find($id));

        $this->getJson('/api/v1/admin/campaign-drafts', $this->asAdmin($someone))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ── housekeeping ──────────────────────────────────────────────────────

    public function test_drafts_are_capped_per_author(): void
    {
        $me      = $this->admin();
        $headers = $this->headers($me);

        for ($i = 0; $i < CampaignDraft::MAX_PER_AUTHOR + 5; $i++) {
            $this->postJson('/api/v1/admin/campaign-drafts', ['subject' => "Draft {$i}"], $headers)
                ->assertStatus(201);
        }

        // Autosave creates rows casually, so the cap is enforced on write
        // rather than by a scheduled command — nothing guarantees a scheduler
        // runs on this host.
        $this->assertSame(
            CampaignDraft::MAX_PER_AUTHOR,
            CampaignDraft::where('admin_user_id', $me->id)->count(),
        );

        // The newest survived.
        $this->assertNotNull(CampaignDraft::where('subject', 'Draft 24')->first());
    }

    public function test_discarding_an_already_gone_draft_is_not_an_error(): void
    {
        $headers = $this->headers();

        $id = $this->postJson('/api/v1/admin/campaign-drafts', ['subject' => 'S'], $headers)
            ->json('data.id');

        // Discard is fired on send and on "start fresh", both retryable after
        // a dropped connection.
        $this->deleteJson("/api/v1/admin/campaign-drafts/{$id}", [], $headers)->assertOk();
        $this->deleteJson("/api/v1/admin/campaign-drafts/{$id}", [], $headers)->assertOk();
    }

    public function test_an_oversized_body_is_refused_with_a_readable_message(): void
    {
        $response = $this->postJson('/api/v1/admin/campaign-drafts', [
            'body_html' => str_repeat('a', 524_289),
        ], $this->headers());

        $response->assertStatus(422)->assertJsonValidationErrors('body_html');

        $this->assertStringContainsString(
            'too large',
            $response->json('errors.body_html.0'),
        );
    }

    public function test_the_list_labels_a_draft_that_has_no_subject_yet(): void
    {
        $headers = $this->headers();

        $this->postJson('/api/v1/admin/campaign-drafts', ['blocks' => $this->blocks()], $headers);

        $this->getJson('/api/v1/admin/campaign-drafts', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.label', 'Untitled campaign')
            ->assertJsonPath('data.0.block_count', 2);
    }

    public function test_the_migration_applies_against_real_sql_and_is_idempotent(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('campaign_drafts');

        $migration = require database_path('migrations/2026_08_07_000002_create_campaign_drafts_table.php');
        $migration->up();

        $this->assertTrue(Schema::hasTable('campaign_drafts'));

        foreach (['admin_user_id', 'subject', 'blocks', 'theme', 'body_html', 'filters', 'name'] as $column) {
            $this->assertTrue(Schema::hasColumn('campaign_drafts', $column), "missing {$column}");
        }

        $migration->up(); // guarded — a re-run is a no-op

        $this->assertTrue(Schema::hasTable('campaign_drafts'));
    }
}

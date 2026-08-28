<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\EcInvoiceGroup;
use App\Models\EcInvoiceLine;
use App\Models\SiteSetting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * EC Invoice List — the Zusammenfassende Meldung portal (Session 100, from
 * finance's File6.html mockup): ZM groups per period, itemized invoices with
 * their documents, the assignee chase through My Work, the CSV audit file
 * and the § 18a ELSTER payload.
 *
 * Minimal-schema sqlite harness, same pattern as FinanceSnapshotTest.
 */
class EcInvoiceListTest extends TestCase
{
    private int $seq = 0;

    private const TABLES = [
        'ec_invoice_lines', 'ec_invoice_groups', 'ec_invoice_periods',
        'site_settings', 'admin_notifications', 'admin_security_events',
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

        // The My Work endpoint reads these alongside the EC tasks under test.
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

        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type', 30)->nullable();
            $table->string('group', 50)->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        // The real migration, run against real SQL — the group unique index is
        // the thing preventing a doubled ZM line, so a hand-built table would
        // be testing a different schema from the one that ships.
        $this->runMigration('2026_08_28_000004_create_ec_invoice_tables');

        Schema::enableForeignKeyConstraints();

        EcInvoiceGroup::forgetAvailableCheck();
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

    private function admin(string $role = 'finance'): AdminUser
    {
        return AdminUser::create([
            'name'                    => 'Staff ' . (++$this->seq),
            'email'                   => 'ec' . $this->seq . uniqid() . '@okelcor.test',
            'role'                    => $role,
            'password'                => Hash::make('secret-pass-123'),
            'is_active'               => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function group(AdminUser $finance, array $overrides = []): int
    {
        $response = $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/admin/ec-invoices/groups', array_merge([
                'period'           => '2026-Q3',
                'country_code'     => 'FR',
                'customer_vat_id'  => 'FR12345678901',
                'transaction_type' => 'goods',
            ], $overrides));

        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    // ── the migration itself ──────────────────────────────────────────────

    public function test_the_migration_applies_against_real_sql_and_is_idempotent(): void
    {
        $this->runMigration('2026_08_28_000004_create_ec_invoice_tables');

        $this->assertTrue(Schema::hasTable('ec_invoice_periods'));
        $this->assertTrue(Schema::hasTable('ec_invoice_groups'));
        $this->assertTrue(Schema::hasTable('ec_invoice_lines'));
    }

    // ── groups ────────────────────────────────────────────────────────────

    public function test_a_duplicate_customer_group_is_a_friendly_422(): void
    {
        $finance = $this->admin();
        $this->group($finance);

        // Doubling the group would double its ZM line — refused with the
        // existing group in the payload so the UI can jump to it.
        $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/admin/ec-invoices/groups', [
                'period'           => '2026-Q3',
                'country_code'     => 'FR',
                'customer_vat_id'  => 'fr 12345678901', // spacing and case must not evade it
                'transaction_type' => 'goods',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer_vat_id']);

        $this->assertSame(1, EcInvoiceGroup::count());
    }

    public function test_a_period_must_be_a_quarter_or_a_month(): void
    {
        $finance = $this->admin();

        $this->actingAs($finance, 'sanctum')
            ->postJson('/api/v1/admin/ec-invoices/groups', [
                'period' => '2026-Q5', 'country_code' => 'FR',
                'customer_vat_id' => 'FR1', 'transaction_type' => 'goods',
            ])
            ->assertStatus(422);

        $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/admin/ec-invoices?period=banana')
            ->assertStatus(422);
    }

    // ── lines, documents and the arithmetic ───────────────────────────────

    public function test_lines_sum_into_the_group_total_and_documents_round_trip(): void
    {
        Storage::fake('local');

        $finance = $this->admin();
        $groupId = $this->group($finance);

        $this->actingAs($finance, 'sanctum')
            ->post("/api/v1/admin/ec-invoices/groups/{$groupId}/lines", [
                'invoice_number' => 'INV-2026-001',
                'invoice_date'   => '2026-07-15',
                'amount'         => 10000,
                'invoice_file'   => UploadedFile::fake()->create('inv-001.pdf', 60, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.has_invoice_file', true);

        $this->actingAs($finance, 'sanctum')
            ->postJson("/api/v1/admin/ec-invoices/groups/{$groupId}/lines", [
                'invoice_number' => 'INV-2026-042', 'amount' => 4500.50,
            ])
            ->assertCreated();

        $response = $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/admin/ec-invoices?period=2026-Q3')
            ->assertOk();

        // The total is the sum of the lines — never stored, computed here.
        $this->assertSame(14500.5, (float) $response->json('data.groups.0.total'));

        $lines  = collect($response->json('data.groups.0.lines'));
        $lineId = $lines->firstWhere('invoice_number', 'INV-2026-001')['id'];

        $this->actingAs($finance, 'sanctum')
            ->get("/api/v1/admin/ec-invoices/lines/{$lineId}/download?kind=invoice")
            ->assertStatus(200);

        $this->actingAs($finance, 'sanctum')
            ->get("/api/v1/admin/ec-invoices/lines/{$lineId}/download?kind=proof")
            ->assertStatus(404);
    }

    public function test_a_delivery_proof_arriving_completes_a_pending_line(): void
    {
        Storage::fake('local');

        $finance = $this->admin();
        $groupId = $this->group($finance);

        $lineId = $this->actingAs($finance, 'sanctum')
            ->postJson("/api/v1/admin/ec-invoices/groups/{$groupId}/lines", [
                'invoice_number' => 'INV-2026-118', 'amount' => 6100,
            ])
            ->json('data.id');

        $this->assertSame('pending_doc', EcInvoiceLine::find($lineId)->task_status);

        // The proof arriving is what "Pending Proof" was waiting for — the
        // mockup's rule, kept.
        $this->actingAs($finance, 'sanctum')
            ->post("/api/v1/admin/ec-invoices/lines/{$lineId}/file", [
                'kind' => 'proof',
                'file' => UploadedFile::fake()->create('cmr-signed.pdf', 40, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.task_status', 'complete')
            ->assertJsonPath('data.has_proof_file', true);
    }

    // ── permissions ───────────────────────────────────────────────────────

    public function test_reading_is_finance_view_and_writing_is_finance_manage(): void
    {
        $ops = $this->admin('order_manager'); // holds finance.view, not finance.manage

        $this->actingAs($ops, 'sanctum')
            ->getJson('/api/v1/admin/ec-invoices?period=2026-Q3')
            ->assertOk();

        $this->actingAs($ops, 'sanctum')
            ->postJson('/api/v1/admin/ec-invoices/groups', [
                'period' => '2026-Q3', 'country_code' => 'FR',
                'customer_vat_id' => 'FR1', 'transaction_type' => 'goods',
            ])
            ->assertStatus(403);

        $this->actingAs($this->admin('viewer'), 'sanctum')
            ->getJson('/api/v1/admin/ec-invoices')
            ->assertStatus(403);
    }

    // ── the filing status ─────────────────────────────────────────────────

    public function test_the_period_status_moves_and_the_submission_stamp_follows_it(): void
    {
        $finance = $this->admin();

        $this->actingAs($finance, 'sanctum')
            ->patchJson('/api/v1/admin/ec-invoices/periods/2026-Q3', ['status' => 'submitted'])
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $this->assertNotNull($this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/admin/ec-invoices?period=2026-Q3')
            ->json('data.period.submitted_at'));

        // Back to draft: the stamp goes too — a period moved back was NOT
        // submitted, and keeping the date would say it was.
        $this->actingAs($finance, 'sanctum')
            ->patchJson('/api/v1/admin/ec-invoices/periods/2026-Q3', ['status' => 'draft'])
            ->assertOk()
            ->assertJsonPath('data.submitted_at', null);
    }

    /** The members of `site_settings.type` — enum('string','boolean','json'). */
    private const SITE_SETTING_TYPES = ['string', 'boolean', 'json'];

    public function test_the_taxpayer_vat_id_is_saved_and_served(): void
    {
        $finance = $this->admin();

        $this->actingAs($finance, 'sanctum')
            ->putJson('/api/v1/admin/ec-invoices/company-vat', ['vat_id' => ' de123456789 '])
            ->assertOk();

        $this->assertSame('DE123456789', SiteSetting::where('key', 'company_vat_id')->value('value'));

        $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/admin/ec-invoices?period=2026-Q3')
            ->assertJsonPath('meta.company_vat_id', 'DE123456789');

        // The row must also be WRITABLE on MySQL. `site_settings.type` is an
        // ENUM; SQLite renders it as a plain varchar and accepts anything, so
        // the round-trip above passed in CI while production answered
        // "1265 Data truncated for column 'type'" and returned a 500. Assert
        // the value we store is a member of the enum, on every driver.
        $this->assertContains(
            SiteSetting::where('key', 'company_vat_id')->value('type'),
            self::SITE_SETTING_TYPES,
            'The taxpayer VAT ID was stored with a type that MySQL will reject.',
        );
    }

    // ── the two outputs ───────────────────────────────────────────────────

    public function test_the_csv_audit_file_is_excel_safe_and_names_the_gaps(): void
    {
        $finance = $this->admin();
        $groupId = $this->group($finance);

        $this->actingAs($finance, 'sanctum')
            ->putJson('/api/v1/admin/ec-invoices/company-vat', ['vat_id' => 'DE123456789'])
            ->assertOk();

        $this->actingAs($finance, 'sanctum')
            ->postJson("/api/v1/admin/ec-invoices/groups/{$groupId}/lines", [
                'invoice_number' => 'INV-2026-001', 'amount' => 10000,
            ])
            ->assertCreated();

        $response = $this->actingAs($finance, 'sanctum')
            ->get('/api/v1/admin/ec-invoices/export?period=2026-Q3');

        $response->assertStatus(200);
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('DE123456789', $csv);
        $this->assertStringContainsString('INV-2026-001', $csv);
        $this->assertStringContainsString('Pending Proof', $csv);
        // A document that is not there is an audit gap, named as one.
        $this->assertStringContainsString('missing', $csv);

        // Export needs orders.export on top of finance.view.
        $this->actingAs($this->admin('support'), 'sanctum')
            ->get('/api/v1/admin/ec-invoices/export?period=2026-Q3')
            ->assertStatus(403);
    }

    public function test_the_elster_payload_rounds_and_maps_the_art_codes(): void
    {
        $finance = $this->admin();

        $goods = $this->group($finance);
        $this->group($finance, ['country_code' => 'NL', 'customer_vat_id' => 'NL987654321B01', 'transaction_type' => 'services']);
        $this->group($finance, ['country_code' => 'AT', 'customer_vat_id' => 'ATU11223344', 'transaction_type' => 'triangular']);

        $this->actingAs($finance, 'sanctum')
            ->postJson("/api/v1/admin/ec-invoices/groups/{$goods}/lines", [
                'invoice_number' => 'INV-1', 'amount' => 10000.49,
            ])->assertCreated();
        $this->actingAs($finance, 'sanctum')
            ->postJson("/api/v1/admin/ec-invoices/groups/{$goods}/lines", [
                'invoice_number' => 'INV-2', 'amount' => 4500.02,
            ])->assertCreated();

        $this->actingAs($finance, 'sanctum')
            ->putJson('/api/v1/admin/ec-invoices/company-vat', ['vat_id' => 'DE123456789'])
            ->assertOk();

        $xml = $this->actingAs($finance, 'sanctum')
            ->get('/api/v1/admin/ec-invoices/elster?period=2026-Q3')
            ->assertStatus(200)
            ->streamedContent();

        // § 18a takes whole euros: 10000.49 + 4500.02 = 14500.51 → 14501.
        $this->assertStringContainsString('<Betrag>14501</Betrag>', $xml);
        $this->assertStringContainsString('<Landescode>FR</Landescode>', $xml);
        $this->assertStringContainsString('<Art>L</Art>', $xml);
        $this->assertStringContainsString('<Art>S</Art>', $xml);
        $this->assertStringContainsString('<Art>D</Art>', $xml);
        $this->assertStringContainsString('<UStIdNr>DE123456789</UStIdNr>', $xml);
        $this->assertStringContainsString('<Zeitraum>2026-Q3</Zeitraum>', $xml);
    }

    // ── the chase: assignment, My Work, the assignee's half ───────────────

    public function test_tagging_an_assignee_notifies_them_and_lands_in_their_my_work(): void
    {
        $finance  = $this->admin();
        $assignee = $this->admin('support');
        $groupId  = $this->group($finance);

        $lineId = $this->actingAs($finance, 'sanctum')
            ->postJson("/api/v1/admin/ec-invoices/groups/{$groupId}/lines", [
                'invoice_number'    => 'INV-2026-118',
                'amount'            => 6100,
                'assigned_admin_id' => $assignee->id,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseHas('admin_notifications', [
            'admin_user_id' => $assignee->id,
            'type'          => 'ec_invoice_task_assigned',
        ]);

        $work = $this->actingAs($assignee, 'sanctum')
            ->getJson('/api/v1/admin/my-work')
            ->assertOk();

        $this->assertSame(1, $work->json('meta.counts.ec_invoice_tasks'));
        $task = $work->json('data.ec_invoice_tasks.0');
        $this->assertSame($lineId, $task['id']);
        $this->assertTrue($task['editable']);
        // The deep link lands on the exact line, not the whole page.
        $this->assertSame("/admin/ec-invoices?period=2026-Q3&line={$lineId}", $task['action_url']);
        // The EC statuses travel with the item — they are not the finance-task set.
        $this->assertSame('complete', $task['status_options'][0]['value']);
    }

    public function test_the_assignee_updates_their_own_line_and_the_creator_hears_it(): void
    {
        $finance  = $this->admin();
        $assignee = $this->admin('support');
        $stranger = $this->admin('support');
        $groupId  = $this->group($finance);

        $lineId = $this->actingAs($finance, 'sanctum')
            ->postJson("/api/v1/admin/ec-invoices/groups/{$groupId}/lines", [
                'invoice_number'    => 'INV-2026-118',
                'amount'            => 6100,
                'assigned_admin_id' => $assignee->id,
            ])->json('data.id');

        // Being the assignee IS the authorization — support holds no finance
        // permission at all.
        $this->actingAs($assignee, 'sanctum')
            ->patchJson("/api/v1/admin/my-work/ec-invoice-lines/{$lineId}", ['task_status' => 'complete'])
            ->assertOk()
            ->assertJsonPath('data.task_status', 'complete');

        $this->assertDatabaseHas('admin_notifications', [
            'admin_user_id' => $finance->id,
            'type'          => 'ec_invoice_task_updated',
        ]);

        // Someone who is neither assignee nor finance is refused.
        $this->actingAs($stranger, 'sanctum')
            ->patchJson("/api/v1/admin/my-work/ec-invoice-lines/{$lineId}", ['task_status' => 'review'])
            ->assertStatus(403);
    }

    // ── deploy-order safety ───────────────────────────────────────────────

    public function test_the_page_and_my_work_survive_the_feature_arriving_before_its_migration(): void
    {
        $finance = $this->admin();

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('ec_invoice_lines');
        Schema::dropIfExists('ec_invoice_groups');
        Schema::dropIfExists('ec_invoice_periods');
        Schema::enableForeignKeyConstraints();
        EcInvoiceGroup::forgetAvailableCheck();

        $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/admin/ec-invoices')
            ->assertOk()
            ->assertJsonPath('meta.ec_invoices_available', false);

        // My Work must not 500 over a table that is not there yet.
        $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/admin/my-work')
            ->assertOk()
            ->assertJsonPath('meta.counts.ec_invoice_tasks', 0);
    }
}

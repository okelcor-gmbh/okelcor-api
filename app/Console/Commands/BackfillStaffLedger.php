<?php

namespace App\Console\Commands;

use App\Models\AdminSecurityEvent;
use App\Models\BulkEmailCampaign;
use App\Models\EbayListingLog;
use App\Models\CustomerCommunication;
use App\Models\EcInvoiceGroup;
use App\Models\EcInvoiceLine;
use App\Models\EcInvoicePeriod;
use App\Models\FinanceInvoice;
use App\Models\FinanceLiquidityEntry;
use App\Models\FinanceSnapshotItem;
use App\Models\LiquidityWeek;
use App\Models\OrderCostLine;
use App\Models\SalesOrderEntry;
use App\Models\Todo;
use App\Models\OrderLog;
use App\Models\Media;
use App\Models\OrderSignoff;
use App\Models\PartnerSaleAudit;
use App\Models\StaffActivity;
use App\Models\TradeDocument;
use App\Services\StaffActivityRecorder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads the history the API has already been writing into the contribution
 * ledger.
 *
 * This is why the ledger can open with real content rather than an empty page:
 * every source it reads has recorded who-did-what for months, and none of it was
 * ever surfaced. A performance tool that starts at zero says nothing useful for
 * a quarter, and by then people have stopped opening it.
 *
 * Survey first — that is the default, deliberately. Without `--fix` the command
 * reports what it would write, split by person and category, so the numbers can
 * be checked against what the business believes happened before anything lands
 * in a table people will be judged on.
 *
 * Re-runnable. The ledger is keyed on (source_type, source_id, action), so a
 * second run updates the rows it already wrote rather than doubling anyone's
 * month.
 */
class BackfillStaffLedger extends Command
{
    protected $signature = 'staff:backfill-ledger
        {--fix : Write the rows. Without this the command only reports what it would do}
        {--since= : Only read source rows from this date onward (YYYY-MM-DD)}
        {--chunk=500 : Rows read per batch}';

    protected $description = 'Read existing order, document, sign-off, support, campaign and partner history into the staff contribution ledger';

    /**
     * Each source, with the recorder method that already knows its rules.
     *
     * @return array<string, array{model: class-string<Model>, method: string}>
     */
    private function sources(): array
    {
        return [
            'Order log'          => ['model' => OrderLog::class,             'method' => 'fromOrderLog'],
            'Trade documents'    => ['model' => TradeDocument::class,        'method' => 'fromTradeDocument'],
            'Order sign-offs'    => ['model' => OrderSignoff::class,         'method' => 'fromSignoff'],
            'Customer replies'   => ['model' => CustomerCommunication::class,'method' => 'fromCommunication'],
            'Email campaigns'    => ['model' => BulkEmailCampaign::class,    'method' => 'fromCampaign'],
            'Finance invoices'   => ['model' => FinanceInvoice::class,       'method' => 'fromFinanceInvoice'],
            'Partner sale audit' => ['model' => PartnerSaleAudit::class,     'method' => 'fromPartnerSaleAudit'],

            // Finance's own working (Session 111). The list above is built
            // from the ORDER trail, and finance does most of its work beside
            // it — which is why both finance accounts read as an empty month
            // while 293 snapshot items sat in their table.
            'Finance snapshot'   => ['model' => FinanceSnapshotItem::class,  'method' => 'fromFinanceSnapshotItem'],
            'EC invoice groups'  => ['model' => EcInvoiceGroup::class,       'method' => 'fromEcInvoiceGroup'],
            'EC invoice lines'   => ['model' => EcInvoiceLine::class,        'method' => 'fromEcInvoiceLine'],
            'EC periods'         => ['model' => EcInvoicePeriod::class,      'method' => 'fromEcInvoicePeriod'],
            'Liquidity weeks'    => ['model' => LiquidityWeek::class,        'method' => 'fromLiquidityWeek'],
            'Liquidity entries'  => ['model' => FinanceLiquidityEntry::class,'method' => 'fromLiquidityEntry'],
            'Order cost lines'   => ['model' => OrderCostLine::class,        'method' => 'fromOrderCostLine'],
            'Sales order board'  => ['model' => SalesOrderEntry::class,      'method' => 'fromSalesOrderEntry'],

            // Completed to-dos only — see fromTodo(). Raising one asks for
            // work; finishing it is the work.
            'Completed to-dos'   => ['model' => Todo::class,                 'method' => 'fromTodo'],

            // Technical work. Added once it was clear the list above is all
            // business operations — somebody who builds the system rather than
            // operating it appeared to have done nothing.
            'Media uploads'      => ['model' => Media::class,                'method' => 'fromMedia'],
            'eBay listing log'   => ['model' => EbayListingLog::class,       'method' => 'fromEbayListingLog'],
            'Admin events'       => ['model' => AdminSecurityEvent::class,   'method' => 'fromSecurityEvent'],
        ];
    }

    public function handle(): int
    {
        if (! Schema::hasTable('staff_activities')) {
            $this->error('The staff_activities table does not exist. Run `artisan migrate` first.');

            return self::FAILURE;
        }

        StaffActivity::forgetLedgerCheck();

        $write = (bool) $this->option('fix');
        $since = $this->option('since');
        $chunk = max(50, (int) $this->option('chunk'));

        if ($since !== null && $since !== '' && strtotime($since) === false) {
            $this->error("--since must be a date, got '{$since}'.");

            return self::FAILURE;
        }

        $this->info($write
            ? 'Writing the ledger from existing history.'
            : 'Survey only — nothing will be written. Add --fix once the numbers below look right.');

        if ($since) {
            $this->line("Reading source rows from {$since} onward.");
        }

        $this->newLine();

        $recorder = app(StaffActivityRecorder::class);
        $tally    = [];
        $skipped  = 0;

        foreach ($this->sources() as $label => $source) {
            /** @var class-string<Model> $modelClass */
            $modelClass = $source['model'];
            $method     = $source['method'];
            $table      = (new $modelClass)->getTable();

            if (! Schema::hasTable($table)) {
                $this->line(sprintf('  %-20s <fg=gray>table absent, skipped</>', $label));
                continue;
            }

            $query = $modelClass::query()->orderBy('id');

            if ($since && Schema::hasColumn($table, 'created_at')) {
                $query->where('created_at', '>=', $since . ' 00:00:00');
            }

            $read    = 0;
            $written = 0;

            $query->chunkById($chunk, function ($rows) use ($recorder, $method, $write, &$tally, &$read, &$written, &$skipped) {
                foreach ($rows as $row) {
                    $read++;

                    $result = $write
                        ? $this->summarise($recorder->{$method}($row))
                        : $this->preview($recorder, $method, $row);

                    if ($result === null) {
                        $skipped++;
                        continue;
                    }

                    $written++;
                    $tally[$result['name']][$result['category']]
                        = ($tally[$result['name']][$result['category']] ?? 0) + 1;
                }
            });

            $this->line(sprintf('  %-20s read %7d    ledger rows %7d', $label, $read, $written));
        }

        $this->newLine();
        $this->renderTally($tally);

        $this->newLine();
        $this->line(sprintf(
            '%s source rows carried no member of staff and were skipped — customer decisions, webhooks, scheduled jobs, and logins (which are presence, not work).',
            number_format($skipped)
        ));

        $this->newLine();
        $this->line('Development work is not in any of these tables. Import it with `staff:import-commits`.');

        if (! $write) {
            $this->newLine();
            $this->warn('Nothing was written. Re-run with --fix to build the ledger.');
        }

        return self::SUCCESS;
    }

    /**
     * Runs the recorder's decision without keeping it.
     *
     * A transaction that always rolls back is the only way to preview
     * `updateOrCreate` without reimplementing its rules — and reimplementing
     * them is exactly how a survey comes to disagree with the fix it previews.
     *
     * @return array{name: string, category: string}|null
     */
    private function preview(StaffActivityRecorder $recorder, string $method, Model $row): ?array
    {
        $result = null;

        try {
            DB::transaction(function () use ($recorder, $method, $row, &$result) {
                $result = $this->summarise($recorder->{$method}($row));

                throw new RollbackPreview;
            });
        } catch (RollbackPreview) {
            // Expected — the rollback is the point.
        }

        return $result;
    }

    /**
     * @return array{name: string, category: string}|null
     */
    private function summarise(?StaffActivity $activity): ?array
    {
        return $activity === null ? null : [
            'name'     => $activity->admin_name ?: '(unknown)',
            'category' => $activity->category,
        ];
    }

    /**
     * @param  array<string, array<string, int>>  $tally
     */
    private function renderTally(array $tally): void
    {
        if ($tally === []) {
            $this->warn('No attributable work found. If that is a surprise, check the source tables actually hold an admin id.');

            return;
        }

        ksort($tally);

        $categories = StaffActivity::CATEGORIES;
        $header     = array_merge(['Person'], array_map(fn ($c) => ucfirst($c), $categories), ['Total']);
        $rows       = [];

        foreach ($tally as $name => $counts) {
            $row   = [$name];
            $total = 0;

            foreach ($categories as $category) {
                $n = $counts[$category] ?? 0;
                $total += $n;
                $row[] = $n ?: '·';
            }

            $row[]  = $total;
            $rows[] = $row;
        }

        $this->table($header, $rows);
    }
}

/** Unwinds a preview transaction. Never escapes this file. */
class RollbackPreview extends \RuntimeException
{
}

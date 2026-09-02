<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\FinanceLiquidityEntry;
use App\Models\FinanceSnapshotItem;
use App\Services\AdminAuditLogger;
use App\Services\AdminNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The finance snapshot board: the six-category pipeline finance tracks by
 * hand (per staff member), plus the liquidity working lines. This is the
 * shared, database-backed replacement for the localStorage D13.html board.
 *
 * Read and write are both `finance.snapshot` (super_admin, finance). Not
 * finance.view/finance.manage: this board is the finance team's own working
 * pipeline and is deliberately closed to `admin` and the order manager,
 * while reconciliation, profitability, EC Invoices and the Sales & Order
 * board keep their wider audience.
 *
 * The one way in without that permission is being tagged on an item, which
 * is answered from My Work rather than here — see
 * AdminWorkQueueController::updateFinanceItem.
 */
class AdminFinanceSnapshotController extends Controller
{
    // ── GET /api/v1/admin/finance-snapshot — finance.snapshot ────────────────
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'items'     => FinanceSnapshotItem::with('assignee:id,name,display_name')
                    ->orderBy('category')->orderBy('person')->orderBy('date')
                    ->get()->map(fn ($i) => $this->formatItem($i))->values(),
                'liquidity' => FinanceLiquidityEntry::orderBy('line')->orderBy('week_key')->orderBy('period')->orderBy('id')
                    ->get()->map(fn ($e) => $this->formatEntry($e))->values(),
                'meta'      => [
                    'categories'      => FinanceSnapshotItem::CATEGORIES,
                    'statuses'        => FinanceSnapshotItem::STATUSES,
                    'liquidity_lines' => collect(FinanceLiquidityEntry::LINES)
                        ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
                    // The grid's arithmetic, served rather than hardcoded in
                    // the panel: Cash Position = bank_balance + these;
                    // Forecasted = Cash Position + revenue_payment.
                    'liquidity_expense_lines' => FinanceLiquidityEntry::EXPENSE_LINES,
                    // Weeks before this one are CLOSED. Served so the panel
                    // marks columns off the server's clock, which is also
                    // the clock the write refusals use — a browser in
                    // another timezone must not disagree about which week
                    // is still open.
                    'current_week'            => FinanceLiquidityEntry::currentWeekKey(),
                    // For the "assign to staff" picker: tagging someone is how
                    // a record reaches their My Work queue and notifies them.
                    'staff'           => AdminUser::where('is_active', true)
                        ->orderBy('name')
                        ->get(['id', 'name', 'display_name'])
                        ->map(fn ($a) => ['id' => $a->id, 'name' => trim($a->display_name ?: $a->name)])
                        ->values(),
                ],
            ],
        ]);
    }

    // ── GET /api/v1/admin/finance-snapshot/export — finance.snapshot ─────────
    //
    // The six-category pipeline as a spreadsheet. BOM-prefixed because this
    // goes to finance, who opens it in Excel — without one, Excel reads
    // UTF-8 as Latin-1 and mangles every umlaut in a client name.
    public function exportItems(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $items = FinanceSnapshotItem::with('assignee:id,name,display_name')
            ->orderBy('category')->orderBy('person')->orderBy('date')
            ->get();

        $filename = 'okelcor-finance-snapshot-' . now()->toDateString() . '.csv';

        return response()->streamDownload(function () use ($items) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Category', 'Person', 'Assignee', 'Ref', 'Date', 'Client', 'Status', 'Comment', 'Amount']);

            foreach ($items as $i) {
                $assignee = $i->assignee ? trim($i->assignee->display_name ?: $i->assignee->name) : '';
                fputcsv($out, [
                    $i->category, $i->person, $assignee, $i->ref,
                    $i->date?->toDateString() ?? '', $i->client ?? '',
                    $i->status, $i->comment ?? '',
                    number_format((float) $i->amount, 2, '.', ''),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ── GET /api/v1/admin/finance-snapshot/liquidity/export ──────────────────
    //
    // The Details ledger, in EXACTLY the column layout `liquidity:import`
    // reads — so a downloaded file is also a restorable one, and finance can
    // round-trip it through Excel. Legacy period-keyed rows (if any ever
    // reappear) ride along with their period in the Week column rather than
    // being silently dropped; the importer would name them, not guess.
    public function exportLiquidity(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $entries = FinanceLiquidityEntry::orderBy('line')->orderBy('week_key')->orderBy('id')->get();
        $labels  = FinanceLiquidityEntry::LINES;

        $filename = 'okelcor-liquidity-' . now()->toDateString() . '.csv';

        return response()->streamDownload(function () use ($entries, $labels) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Item', 'Supplier', 'Description', 'Week', 'Currency', 'Amount', 'Comment']);

            foreach ($entries as $e) {
                fputcsv($out, [
                    $labels[$e->line] ?? $e->line,
                    $e->supplier ?? '',
                    $e->description,
                    $e->week_key ? 'Week ' . ltrim(substr($e->week_key, 6), '0') : $e->period,
                    $e->currency ?: 'EUR',
                    number_format((float) $e->amount, 2, '.', ''),
                    $e->comment ?? '',
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ── Items — finance.snapshot ─────────────────────────────────────────────

    public function storeItem(Request $request): JsonResponse
    {
        $data = $this->validateItem($request);
        $data['created_by'] = $request->user()->id;

        $item = FinanceSnapshotItem::create($data);

        $this->notifyAssignee($item, $request->user()->id);

        return response()->json(['data' => $this->formatItem($item->load('assignee:id,name,display_name'))], 201);
    }

    /**
     * CSV upload lands here: the panel parses the file and posts the rows in
     * one call, so a 300-line sheet is one request, not 300.
     */
    public function storeItemsBulk(Request $request): JsonResponse
    {
        $request->validate([
            'items'   => ['required', 'array', 'max:2000'],
            'items.*' => ['array'],
        ]);

        $rows  = [];
        $now   = now();
        $byId  = $request->user()->id;

        foreach ($request->input('items') as $i => $raw) {
            if (is_array($raw)) {
                $raw['date'] = $this->coerceDate($raw['date'] ?? null);
            }
            $validated = validator($raw, $this->itemRules())->validate();
            $rows[] = array_merge($this->normalizeItem($validated), [
                'created_by' => $byId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            FinanceSnapshotItem::insert($chunk);
        }

        return response()->json(['message' => count($rows) . ' record(s) added.'], 201);
    }

    public function updateItem(Request $request, int $id): JsonResponse
    {
        $item = FinanceSnapshotItem::findOrFail($id);

        $previousAssignee = $item->assigned_admin_id;
        $item->update($this->validateItem($request));

        // Notify only on a NEW assignment — editing an amount must not ping
        // the person who has held the task all along.
        if ($item->assigned_admin_id && $item->assigned_admin_id !== $previousAssignee) {
            $this->notifyAssignee($item, $request->user()->id);
        }

        return response()->json(['data' => $this->formatItem($item->fresh()->load('assignee:id,name,display_name'))]);
    }

    public function destroyItem(int $id): JsonResponse
    {
        FinanceSnapshotItem::findOrFail($id)->delete();

        return response()->json(['message' => 'Record deleted.']);
    }

    // ── Liquidity entries — finance.snapshot ─────────────────────────────────

    public function storeLiquidity(Request $request): JsonResponse
    {
        $data = $this->validateEntry($request);

        if ($refusal = $this->closedWeekRefusal($data['week_key'] ?? null)) {
            return $refusal;
        }

        // Who wrote the line. The table carried no person until Session 111,
        // which is why finance's weekly file credited nobody for the one
        // thing they touch every week. Guarded: the column can lag the code,
        // and a liquidity line must save either way.
        if (FinanceLiquidityEntry::supportsAttribution()) {
            $data['created_by'] = $request->user()?->id;
            $data['updated_by'] = $request->user()?->id;
        }

        $entry = FinanceLiquidityEntry::create($data);

        return response()->json(['data' => $this->formatEntry($entry)], 201);
    }

    public function updateLiquidity(Request $request, int $id): JsonResponse
    {
        $entry = FinanceLiquidityEntry::findOrFail($id);
        $data  = $this->validateEntry($request);

        // The one rule (Session 106): a write must LAND in an open week.
        // Editing in place inside a closed week lands in that closed week —
        // refused. Moving a record forward out of a closed week lands in an
        // open one — allowed, because rolling an unpaid item into the week
        // ahead is exactly what finance does when a week ends, and making
        // them delete-and-retype it was the complaint.
        if ($refusal = $this->closedWeekRefusal($data['week_key'] ?? $entry->week_key)) {
            return $refusal;
        }

        if (FinanceLiquidityEntry::supportsAttribution()) {
            $data['updated_by'] = $request->user()?->id;
            // A line edited by someone whose predecessor never signed it can
            // at least name its current author, rather than staying anonymous
            // for the rest of its life.
            $data['created_by'] = $entry->created_by ?? $request->user()?->id;
        }

        $entry->update($data);

        return response()->json(['data' => $this->formatEntry($entry->fresh())]);
    }

    public function destroyLiquidity(int $id): JsonResponse
    {
        $entry = FinanceLiquidityEntry::findOrFail($id);

        // A closed week's rows are what happened. If one is genuinely wrong
        // it can be moved into the current week and corrected there — an
        // act that leaves the record in view rather than erasing history.
        if ($entry->week_key && FinanceLiquidityEntry::isClosedWeek($entry->week_key)) {
            return response()->json([
                'message' => 'Week ' . ltrim(substr($entry->week_key, 6), '0') . ' (' . $entry->week_key . ')'
                    . ' has ended — closed weeks keep their records. Move it to an open week first if it needs correcting.',
            ], 422);
        }

        $entry->delete();

        return response()->json(['message' => 'Entry deleted.']);
    }

    /**
     * 422 when a write would land in a week that has already ended, null
     * when the target week is open (or the entry is legacy period-keyed).
     */
    private function closedWeekRefusal(?string $weekKey): ?JsonResponse
    {
        if ($weekKey === null || ! FinanceLiquidityEntry::isClosedWeek($weekKey)) {
            return null;
        }

        return response()->json([
            'message' => 'Week ' . ltrim(substr($weekKey, 6), '0') . ' (' . $weekKey . ') has ended — closed weeks cannot take new or edited entries. Move the record to the current week or a later one.',
            'errors'  => ['week_key' => ['This week is closed.']],
        ], 422);
    }

    // ── POST /api/v1/admin/finance-snapshot/import — finance.snapshot ────────
    //
    // Restores a backup in the EXACT shape the original D13 board exports
    // ({ items: [...], liquidityItems: [{ id, openCurrent: [...], nextMonth:
    // [...] }] }), replacing everything — it is a restore, not a merge. This
    // is how finance's existing data comes across: he uploads the backup he
    // already makes, once.
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'items'                        => ['present', 'array', 'max:5000'],
            'items.*.category'             => ['required', Rule::in(FinanceSnapshotItem::CATEGORIES)],
            'items.*.person'               => ['required', 'string', 'max:100'],
            'items.*.ref'                  => ['required', 'string', 'max:50'],
            // No `date` rule here on purpose: the real backups carry
            // European DD/MM/YYYY dates that PHP's parser either rejects
            // (30/12/2024) or, far worse, silently reads as American
            // month-first (05/02/2026 → 2nd of May). coerceDate() below
            // handles both correctly; an unreadable date costs that one
            // date, never the restore.
            'items.*.client'               => ['nullable', 'string', 'max:255'],
            'items.*.status'               => ['nullable', 'string', 'max:30'],
            'items.*.comment'              => ['nullable', 'string', 'max:500'],
            'items.*.amount'               => ['required', 'numeric'],
            'liquidityItems'               => ['present', 'array', 'max:50'],
            'liquidityItems.*.id'          => ['required', Rule::in(array_keys(FinanceLiquidityEntry::LINES))],
            'liquidityItems.*.openCurrent' => ['nullable', 'array'],
            'liquidityItems.*.nextMonth'   => ['nullable', 'array'],
        ]);

        $byId = $request->user()->id;
        $now  = now();

        $itemRows = [];
        foreach ($request->input('items') as $raw) {
            $raw['date'] = $this->coerceDate(is_scalar($raw['date'] ?? null) ? (string) $raw['date'] : null);
            $itemRows[] = array_merge($this->normalizeItem($raw), [
                'created_by' => $byId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $entryRows = [];
        foreach ($request->input('liquidityItems') as $line) {
            foreach (['openCurrent' => 'open_current', 'nextMonth' => 'next_month'] as $srcKey => $period) {
                foreach ((array) ($line[$srcKey] ?? []) as $sub) {
                    $entryRows[] = [
                        'line'        => $line['id'],
                        'period'      => $period,
                        'description' => mb_substr((string) ($sub['desc'] ?? $sub['description'] ?? ''), 0, 255),
                        'reference'   => mb_substr((string) ($sub['ref'] ?? $sub['reference'] ?? ''), 0, 100) ?: null,
                        'amount'      => (float) ($sub['amount'] ?? 0),
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ];
                }
            }
        }

        DB::transaction(function () use ($itemRows, $entryRows) {
            FinanceSnapshotItem::query()->delete();
            FinanceLiquidityEntry::query()->delete();

            foreach (array_chunk($itemRows, 500) as $chunk) {
                FinanceSnapshotItem::insert($chunk);
            }
            foreach (array_chunk($entryRows, 500) as $chunk) {
                FinanceLiquidityEntry::insert($chunk);
            }
        });

        AdminAuditLogger::warning('finance_snapshot_restored', 'Finance snapshot restored from a JSON backup — previous board contents replaced', $request, $request->user(), [
            'items'             => count($itemRows),
            'liquidity_entries' => count($entryRows),
        ]);

        return response()->json([
            'message' => 'Snapshot restored: ' . count($itemRows) . ' records and ' . count($entryRows) . ' liquidity entries.',
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function validateItem(Request $request): array
    {
        return $this->normalizeItem($request->validate($this->itemRules()));
    }

    /** @return array<string, array<int, mixed>> */
    private function itemRules(): array
    {
        return [
            'category'          => ['required', Rule::in(FinanceSnapshotItem::CATEGORIES)],
            'person'            => ['required', 'string', 'max:100'],
            'assigned_admin_id' => ['nullable', 'integer', Rule::exists('admin_users', 'id')->where('is_active', true)],
            'ref'               => ['required', 'string', 'max:50'],
            'date'              => ['nullable', 'date'],
            'client'            => ['nullable', 'string', 'max:255'],
            'status'            => ['nullable', 'string', 'max:30'],
            'comment'           => ['nullable', 'string', 'max:500'],
            'amount'            => ['required', 'numeric'],
        ];
    }

    /**
     * Common shaping for validated item input. Unknown statuses collapse to
     * Pending rather than erroring: the CSVs this board is fed from are
     * hand-typed, and a row lost over "pending" vs "Pending" helps nobody.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeItem(array $data): array
    {
        $status  = (string) ($data['status'] ?? 'Pending');
        $matched = collect(FinanceSnapshotItem::STATUSES)
            ->first(fn ($s) => strcasecmp($s, $status) === 0);

        return [
            'category'          => $data['category'],
            'person'            => trim((string) $data['person']),
            'assigned_admin_id' => $data['assigned_admin_id'] ?? null,
            'ref'               => trim((string) $data['ref']),
            'date'              => $data['date'] ?? null,
            'client'            => $data['client'] ?? null,
            'status'            => $matched ?? 'Pending',
            'comment'           => $data['comment'] ?? null,
            'amount'            => round((float) $data['amount'], 2),
        ];
    }

    /**
     * Read a date the way the finance team writes them: day-first.
     *
     * Accepts ISO (2026-08-19), European slash/dot/dash forms (30/12/2024,
     * 5.2.2026), and named-month strings (13-Aug-2026). A `/`-date is NEVER
     * handed to PHP's parser directly — it would read 05/02/2026 as the 2nd
     * of May, which is worse than any error. Unreadable input returns null:
     * one lost date, not a failed restore.
     */
    private function coerceDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '0000-00-00') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
        }

        if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $value, $m)) {
            [$a, $b, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if (checkdate($b, $a, $year)) {          // day-first, the house convention
                return sprintf('%04d-%02d-%02d', $year, $b, $a);
            }
            if (checkdate($a, $b, $year)) {          // only readable month-first (e.g. 12/25/2026)
                return sprintf('%04d-%02d-%02d', $year, $a, $b);
            }

            return null;
        }

        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Tell a tagged staff member something landed on their plate — as ONE
     * deduped nudge, not one notification per record. Finance tags in
     * batches; the person gets a single "new finance tasks" notification
     * pointing at My Work (where everything is listed), and the full
     * itemized report follows in the daily digest (panel + email, see
     * finance:remind-assignees). Reading the nudge re-arms it, so a later
     * same-day batch still notifies. Never self-notifies.
     */
    private function notifyAssignee(FinanceSnapshotItem $item, int $actorId): void
    {
        if (! $item->assigned_admin_id || $item->assigned_admin_id === $actorId) {
            return;
        }

        AdminNotificationService::notifyUser(
            adminUserId: $item->assigned_admin_id,
            type: 'finance_task_assigned',
            title: 'New finance tasks were assigned to you',
            body: 'Open My Work to see everything on your plate. You will also get a daily email report while tasks are open.',
            actionUrl: '/admin/my-work',
            severity: 'info',
            dedupeKey: 'finance_tasks_assigned:' . $item->assigned_admin_id . ':' . now()->toDateString(),
        );
    }

    private function validateEntry(Request $request): array
    {
        $data = $request->validate([
            'line'        => ['required', Rule::in(array_keys(FinanceLiquidityEntry::LINES))],
            // Week-keyed is the live model (finance's Liquidity File);
            // period-keyed remains only for the D13 restore path. One of
            // the two must place the entry somewhere.
            'week_key'    => ['required_without:period', 'nullable', 'regex:/^\d{4}-W\d{2}$/'],
            'period'      => ['required_without:week_key', 'nullable', Rule::in(FinanceLiquidityEntry::PERIODS)],
            'supplier'    => ['nullable', 'string', 'max:150'],
            // Optional now — in the file most rows carry a supplier and no
            // description at all. The column is NOT NULL, so null lands ''.
            'description' => ['nullable', 'string', 'max:255'],
            'reference'   => ['nullable', 'string', 'max:100'],
            'amount'      => ['required', 'numeric'],
            'currency'    => ['nullable', 'string', 'size:3', 'alpha:ascii'],
            'comment'     => ['nullable', 'string', 'max:255'],
        ]);

        $data['description'] = $data['description'] ?? '';
        // Old rows predate the column; a week-keyed entry stores no period.
        $data['period']      = $data['period'] ?? '';

        if (array_key_exists('currency', $data) && $data['currency'] !== null) {
            $data['currency'] = strtoupper($data['currency']);
        }

        return $data;
    }

    private function formatItem(FinanceSnapshotItem $i): array
    {
        return [
            'id'                => $i->id,
            'category'          => $i->category,
            'person'            => $i->person,
            'assigned_admin_id' => $i->assigned_admin_id,
            'assignee_name'     => $i->assignee ? trim($i->assignee->display_name ?: $i->assignee->name) : null,
            'ref'               => $i->ref,
            'date'              => $i->date?->format('Y-m-d'),
            'client'            => $i->client,
            'status'            => $i->status,
            'comment'           => $i->comment,
            'amount'            => (float) $i->amount,
        ];
    }

    private function formatEntry(FinanceLiquidityEntry $e): array
    {
        return [
            'id'          => $e->id,
            'line'        => $e->line,
            'period'      => $e->period,
            'week_key'    => $e->week_key,
            'supplier'    => $e->supplier,
            'description' => $e->description,
            'reference'   => $e->reference,
            'amount'      => (float) $e->amount,
            'currency'    => $e->currency ?: 'EUR',
            'comment'     => $e->comment,
        ];
    }
}

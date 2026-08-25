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
 * Read = finance.view (super_admin, admin, finance, order_manager).
 * Write = finance.manage (super_admin, admin, finance).
 */
class AdminFinanceSnapshotController extends Controller
{
    // ── GET /api/v1/admin/finance-snapshot — finance.view ────────────────────
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'items'     => FinanceSnapshotItem::with('assignee:id,name,display_name')
                    ->orderBy('category')->orderBy('person')->orderBy('date')
                    ->get()->map(fn ($i) => $this->formatItem($i))->values(),
                'liquidity' => FinanceLiquidityEntry::orderBy('line')->orderBy('period')->orderBy('id')
                    ->get()->map(fn ($e) => $this->formatEntry($e))->values(),
                'meta'      => [
                    'categories'      => FinanceSnapshotItem::CATEGORIES,
                    'statuses'        => FinanceSnapshotItem::STATUSES,
                    'liquidity_lines' => collect(FinanceLiquidityEntry::LINES)
                        ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
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

    // ── Items — finance.manage ───────────────────────────────────────────────

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

    // ── Liquidity entries — finance.manage ───────────────────────────────────

    public function storeLiquidity(Request $request): JsonResponse
    {
        $entry = FinanceLiquidityEntry::create($this->validateEntry($request));

        return response()->json(['data' => $this->formatEntry($entry)], 201);
    }

    public function updateLiquidity(Request $request, int $id): JsonResponse
    {
        $entry = FinanceLiquidityEntry::findOrFail($id);
        $entry->update($this->validateEntry($request));

        return response()->json(['data' => $this->formatEntry($entry->fresh())]);
    }

    public function destroyLiquidity(int $id): JsonResponse
    {
        FinanceLiquidityEntry::findOrFail($id)->delete();

        return response()->json(['message' => 'Entry deleted.']);
    }

    // ── POST /api/v1/admin/finance-snapshot/import — finance.manage ──────────
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
            'items.*.date'                 => ['nullable', 'date'],
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
     * Tell a tagged staff member what landed on their plate. Best-effort by
     * design — AdminNotificationService catches its own failures — and never
     * self-notifies: assigning your own task needs no announcement.
     */
    private function notifyAssignee(FinanceSnapshotItem $item, int $actorId): void
    {
        if (! $item->assigned_admin_id || $item->assigned_admin_id === $actorId) {
            return;
        }

        $bodyParts = array_filter([
            $item->client,
            'Amount ' . number_format($item->amount, 2),
            $item->date ? 'Due ' . $item->date->format('d M Y') : null,
            $item->comment,
        ]);

        AdminNotificationService::notifyUser(
            adminUserId: $item->assigned_admin_id,
            type: 'finance_task_assigned',
            title: "Finance task for you: {$item->ref} — " . ucwords(strtolower($item->category)),
            body: implode(' · ', $bodyParts) ?: null,
            actionUrl: '/admin/my-work',
            severity: 'info',
            relatedType: 'finance_snapshot_item',
            relatedId: $item->id,
        );
    }

    private function validateEntry(Request $request): array
    {
        return $request->validate([
            'line'        => ['required', Rule::in(array_keys(FinanceLiquidityEntry::LINES))],
            'period'      => ['required', Rule::in(FinanceLiquidityEntry::PERIODS)],
            'description' => ['required', 'string', 'max:255'],
            'reference'   => ['nullable', 'string', 'max:100'],
            'amount'      => ['required', 'numeric'],
        ]);
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
            'description' => $e->description,
            'reference'   => $e->reference,
            'amount'      => (float) $e->amount,
        ];
    }
}

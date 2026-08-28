<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\EcInvoiceGroup;
use App\Models\EcInvoiceLine;
use App\Models\EcInvoicePeriod;
use App\Models\SiteSetting;
use App\Services\AdminNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * EC Invoice List — the Zusammenfassende Meldung (ZM) portal, from finance's
 * mockup. Per reporting period: ZM groups (EU country × customer VAT ID ×
 * transaction type), each itemizing the invoices behind its aggregate with
 * the invoice PDF and delivery proof attached, an assignee chasing what is
 * missing, a CSV audit export, and the § 18a ELSTER XML payload.
 *
 * Reads are finance.view; writes are finance.manage. The assignee updates
 * their own line's status through the My Work endpoint on the work-queue
 * controller, not here — they may not hold finance.manage.
 */
class AdminEcInvoiceController extends Controller
{
    private const COMPANY_VAT_KEY = 'company_vat_id';

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/ec-invoices?period= — finance.view
    // -------------------------------------------------------------------------
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => ['nullable', 'string', 'max:8'],
        ]);

        if (! EcInvoiceGroup::available()) {
            return response()->json([
                'data'    => null,
                'meta'    => ['ec_invoices_available' => false],
                'message' => 'The EC Invoice List is not available yet — the database migration has not run.',
            ]);
        }

        $period = $data['period'] ?? $this->currentQuarter();

        if (! EcInvoicePeriod::isValidPeriod($period)) {
            return response()->json([
                'message' => "'{$period}' is not a reporting period. Use a quarter (2026-Q1) or a month (2026-05).",
                'errors'  => ['period' => ['Expected YYYY-Qn or YYYY-MM.']],
            ], 422);
        }

        $groups = EcInvoiceGroup::with(['lines.assignee:id,name,display_name'])
            ->where('period', $period)
            ->orderBy('country_code')->orderBy('customer_vat_id')
            ->get();

        $periodRow = EcInvoicePeriod::where('period', $period)->first();

        return response()->json([
            'data' => [
                'period' => [
                    'period'       => $period,
                    'status'       => $periodRow?->status ?? 'draft',
                    'submitted_at' => $periodRow?->submitted_at?->toIso8601String(),
                ],
                'groups' => $groups->map(fn ($g) => $this->formatGroup($g))->values(),
            ],
            'meta' => [
                'ec_invoices_available' => true,
                'company_vat_id' => SiteSetting::where('key', self::COMPANY_VAT_KEY)->value('value') ?? '',
                'countries'      => EcInvoiceGroup::COUNTRIES,
                'types'          => collect(EcInvoiceGroup::TYPE_LABELS)
                    ->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'art' => EcInvoiceGroup::TYPE_ART[$key]])
                    ->values(),
                'statuses'       => collect(EcInvoiceLine::STATUS_LABELS)
                    ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
                'period_statuses' => EcInvoicePeriod::STATUSES,
                // The assignee picker — tagging someone notifies them and
                // lands the line in their My Work, same as the snapshot board.
                'staff'          => AdminUser::where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'display_name'])
                    ->map(fn ($a) => ['id' => $a->id, 'name' => trim($a->display_name ?: $a->name)])
                    ->values(),
                // Periods that already hold data, so the selector can mark them.
                'known_periods'  => EcInvoiceGroup::query()->distinct()->orderByDesc('period')->pluck('period'),
            ],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // PUT /api/v1/admin/ec-invoices/company-vat — finance.manage
    //
    // The Melder's own USt-IdNr. — a setting, editable where finance uses it
    // rather than behind settings.manage, which finance does not hold.
    // -------------------------------------------------------------------------
    public function setCompanyVat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vat_id' => ['required', 'string', 'max:20'],
        ]);

        // `site_settings.type` is an ENUM('string','boolean','json') — 'text'
        // is not a member of it, and MySQL in strict mode rejects the insert
        // outright (1265 Data truncated). A VAT ID is a plain string, same as
        // every other setting the seeder writes.
        SiteSetting::updateOrCreate(
            ['key' => self::COMPANY_VAT_KEY],
            ['value' => strtoupper(trim($data['vat_id'])), 'type' => 'string', 'group' => 'finance'],
        );

        return response()->json(['message' => 'Taxpayer VAT ID saved.']);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/v1/admin/ec-invoices/periods/{period} — finance.manage
    // -------------------------------------------------------------------------
    public function setPeriodStatus(Request $request, string $period): JsonResponse
    {
        if (! EcInvoicePeriod::isValidPeriod($period)) {
            return response()->json(['message' => "'{$period}' is not a reporting period."], 422);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(EcInvoicePeriod::STATUSES)],
        ]);

        $row = EcInvoicePeriod::updateOrCreate(
            ['period' => $period],
            [
                'status'       => $data['status'],
                // The submission stamp follows the status in both directions:
                // a period moved back to draft was NOT submitted, and leaving
                // the old stamp would say it was.
                'submitted_at' => $data['status'] === 'submitted' ? now() : null,
                'updated_by'   => $request->user()?->id,
            ],
        );

        return response()->json([
            'data' => [
                'period'       => $row->period,
                'status'       => $row->status,
                'submitted_at' => $row->submitted_at?->toIso8601String(),
            ],
            'message' => 'Period marked ' . $row->status . '.',
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/ec-invoices/groups — finance.manage
    // -------------------------------------------------------------------------
    public function storeGroup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period'           => ['required', 'string', 'max:8'],
            'country_code'     => ['required', Rule::in(EcInvoiceGroup::COUNTRIES)],
            'customer_vat_id'  => ['required', 'string', 'max:20'],
            'transaction_type' => ['required', Rule::in(EcInvoiceGroup::TYPES)],
        ]);

        if (! EcInvoicePeriod::isValidPeriod($data['period'])) {
            return response()->json([
                'message' => "'{$data['period']}' is not a reporting period.",
                'errors'  => ['period' => ['Expected YYYY-Qn or YYYY-MM.']],
            ], 422);
        }

        $data['customer_vat_id'] = strtoupper(preg_replace('/\s+/', '', $data['customer_vat_id']));

        $duplicate = EcInvoiceGroup::where($data)->first();

        if ($duplicate !== null) {
            // A friendly 422 rather than a database error: the same customer
            // twice in one period would double its ZM line.
            return response()->json([
                'message' => "{$data['customer_vat_id']} is already in {$data['period']} for this transaction type — add invoices to the existing group.",
                'errors'  => ['customer_vat_id' => ['This customer is already in the period.']],
                'data'    => $this->formatGroup($duplicate->load('lines.assignee:id,name,display_name')),
            ], 422);
        }

        $group = EcInvoiceGroup::create($data + ['created_by' => $request->user()?->id]);

        return response()->json([
            'data'    => $this->formatGroup($group->load('lines')),
            'message' => 'Country group added.',
        ], 201);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/v1/admin/ec-invoices/groups/{id} — finance.manage
    // -------------------------------------------------------------------------
    public function updateGroup(Request $request, int $id): JsonResponse
    {
        $group = EcInvoiceGroup::findOrFail($id);

        $data = $request->validate([
            'country_code'     => ['sometimes', Rule::in(EcInvoiceGroup::COUNTRIES)],
            'customer_vat_id'  => ['sometimes', 'string', 'max:20'],
            'transaction_type' => ['sometimes', Rule::in(EcInvoiceGroup::TYPES)],
        ]);

        if (isset($data['customer_vat_id'])) {
            $data['customer_vat_id'] = strtoupper(preg_replace('/\s+/', '', $data['customer_vat_id']));
        }

        $candidate = array_merge([
            'period'           => $group->period,
            'country_code'     => $group->country_code,
            'customer_vat_id'  => $group->customer_vat_id,
            'transaction_type' => $group->transaction_type,
        ], $data);

        $duplicate = EcInvoiceGroup::where($candidate)->where('id', '!=', $group->id)->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'That exact customer group already exists in this period.',
                'errors'  => ['customer_vat_id' => ['This customer is already in the period.']],
            ], 422);
        }

        $group->update($data);

        return response()->json([
            'data'    => $this->formatGroup($group->fresh(['lines.assignee:id,name,display_name'])),
            'message' => 'Group updated.',
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/admin/ec-invoices/groups/{id} — finance.manage
    // -------------------------------------------------------------------------
    public function destroyGroup(int $id): JsonResponse
    {
        $group = EcInvoiceGroup::with('lines')->findOrFail($id);

        foreach ($group->lines as $line) {
            $this->deleteLineFiles($line);
        }

        $group->delete();

        return response()->json(['message' => 'Group removed.']);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/ec-invoices/groups/{id}/lines — finance.manage
    //
    // One request including both documents where finance has them — a
    // separate "now attach it" step is a step that gets skipped.
    // -------------------------------------------------------------------------
    public function storeLine(Request $request, int $id): JsonResponse
    {
        $group = EcInvoiceGroup::findOrFail($id);

        $data = $request->validate([
            'invoice_number'    => ['required', 'string', 'max:50'],
            'invoice_date'      => ['nullable', 'date'],
            'amount'            => ['required', 'numeric', 'min:0', 'max:99999999'],
            'assigned_admin_id' => ['nullable', 'integer', 'exists:admin_users,id'],
            'person_name'       => ['nullable', 'string', 'max:100'],
            'task_status'       => ['nullable', Rule::in(EcInvoiceLine::STATUSES)],
            'invoice_file'      => ['nullable', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png'],
            'proof_file'        => ['nullable', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $line = EcInvoiceLine::create([
            'group_id'          => $group->id,
            'invoice_number'    => $data['invoice_number'],
            'invoice_date'      => $data['invoice_date'] ?? null,
            'amount'            => $data['amount'],
            'assigned_admin_id' => $data['assigned_admin_id'] ?? null,
            'person_name'       => $this->personName($data),
            'task_status'       => $data['task_status'] ?? EcInvoiceLine::STATUS_PENDING_DOC,
            'created_by'        => $request->user()?->id,
        ]);

        $warning = null;

        if ($request->hasFile('invoice_file') && ! $this->storeFile($request->file('invoice_file'), $line, 'invoice')) {
            $warning = 'Line saved, but the invoice PDF could not be stored. Attach it again.';
        }

        if ($request->hasFile('proof_file') && ! $this->storeFile($request->file('proof_file'), $line, 'proof')) {
            $warning ??= 'Line saved, but the delivery proof could not be stored. Attach it again.';
        }

        $this->notifyAssignee($line->fresh(), $request->user());

        return response()->json([
            'data'    => $this->formatLine($line->fresh('assignee:id,name,display_name')),
            'message' => $warning ?? 'Invoice line added.',
        ], 201);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/v1/admin/ec-invoices/lines/{id} — finance.manage
    // -------------------------------------------------------------------------
    public function updateLine(Request $request, int $id): JsonResponse
    {
        $line = EcInvoiceLine::findOrFail($id);

        $data = $request->validate([
            'invoice_number'    => ['sometimes', 'string', 'max:50'],
            'invoice_date'      => ['sometimes', 'nullable', 'date'],
            'amount'            => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
            'assigned_admin_id' => ['sometimes', 'nullable', 'integer', 'exists:admin_users,id'],
            'person_name'       => ['sometimes', 'nullable', 'string', 'max:100'],
            'task_status'       => ['sometimes', Rule::in(EcInvoiceLine::STATUSES)],
        ]);

        $previousAssignee = $line->assigned_admin_id;

        $line->fill($data);

        if (array_key_exists('assigned_admin_id', $data) && ($data['person_name'] ?? null) === null) {
            $line->person_name = $this->personName($data) ?? $line->person_name;
        }

        $line->save();

        if ($line->assigned_admin_id !== $previousAssignee) {
            $this->notifyAssignee($line, $request->user());
        }

        return response()->json([
            'data'    => $this->formatLine($line->fresh('assignee:id,name,display_name')),
            'message' => 'Invoice line updated.',
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/admin/ec-invoices/lines/{id} — finance.manage
    // -------------------------------------------------------------------------
    public function destroyLine(int $id): JsonResponse
    {
        $line = EcInvoiceLine::findOrFail($id);

        $this->deleteLineFiles($line);
        $line->delete();

        return response()->json(['message' => 'Invoice line removed.']);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/ec-invoices/lines/{id}/file — finance.manage
    // -------------------------------------------------------------------------
    public function uploadLineFile(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['invoice', 'proof'])],
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $line = EcInvoiceLine::findOrFail($id);

        if (! $this->storeFile($request->file('file'), $line, $data['kind'])) {
            return response()->json(['message' => 'File could not be saved. Please try again.'], 500);
        }

        return response()->json([
            'data'    => $this->formatLine($line->fresh('assignee:id,name,display_name')),
            'message' => $data['kind'] === 'proof' ? 'Delivery proof attached.' : 'Invoice document attached.',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/ec-invoices/lines/{id}/download?kind= — finance.view
    // -------------------------------------------------------------------------
    public function downloadLineFile(Request $request, int $id): BinaryFileResponse|JsonResponse
    {
        $kind = $request->query('kind') === 'proof' ? 'proof' : 'invoice';
        $line = EcInvoiceLine::findOrFail($id);

        $path = $kind === 'proof' ? $line->getRawOriginal('proof_path') : $line->getRawOriginal('file_path');
        $name = $kind === 'proof' ? $line->proof_original_filename : $line->original_filename;

        if (! $path) {
            return response()->json(['message' => 'No document of that kind is attached to this line.'], 404);
        }

        // Asked of the disk rather than assembled from storage_path() — the
        // root is configuration.
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            Log::warning('EC invoice file missing on disk', ['id' => $id, 'kind' => $kind, 'path' => $path]);

            return response()->json(['message' => 'The attached document could not be found.'], 404);
        }

        return response()->download($disk->path($path), $name ?: basename($path));
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/ec-invoices/export?period= — finance.view + orders.export
    //
    // The CSV audit file: one row per invoice with its documents named, so the
    // itemization travels with the aggregate. BOM-prefixed — this goes to
    // people who will open it in Excel.
    // -------------------------------------------------------------------------
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $period = $request->query('period', $this->currentQuarter());

        if (! EcInvoiceGroup::available()) {
            return response()->json(['message' => 'The EC Invoice List is not available yet.'], 503);
        }

        if (! EcInvoicePeriod::isValidPeriod($period)) {
            return response()->json(['message' => "'{$period}' is not a reporting period."], 422);
        }

        $groups = EcInvoiceGroup::with(['lines.assignee:id,name,display_name'])
            ->where('period', $period)
            ->orderBy('country_code')->orderBy('customer_vat_id')
            ->get();

        $vat      = SiteSetting::where('key', self::COMPANY_VAT_KEY)->value('value') ?? '';
        $filename = "okelcor-zm-audit-{$period}.csv";

        return response()->streamDownload(function () use ($groups, $vat, $period) {
            $out = fopen('php://output', 'w');

            // Excel reads a UTF-8 CSV as Latin-1 unless it starts with a BOM.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['Okelcor — EC Invoice List (Zusammenfassende Meldung audit file)']);
            fputcsv($out, ['Taxpayer VAT', $vat, 'Period', $period]);
            fputcsv($out, ['Generated', now()->toDateTimeString()]);
            fputcsv($out, []);
            fputcsv($out, [
                'Country', 'Customer VAT ID', 'Transaction type', 'Group total EUR',
                'Invoice #', 'Invoice date', 'Amount EUR', 'Assigned person',
                'Task status', 'Invoice document', 'Delivery proof',
            ]);

            foreach ($groups as $group) {
                $label = EcInvoiceGroup::TYPE_LABELS[$group->transaction_type] ?? $group->transaction_type;
                $total = number_format((float) $group->lines->sum('amount'), 2, '.', '');

                if ($group->lines->isEmpty()) {
                    fputcsv($out, [$group->country_code, $group->customer_vat_id, $label, '0.00',
                        '—', '—', '0.00', '—', '—', '—', '—']);

                    continue;
                }

                foreach ($group->lines as $line) {
                    fputcsv($out, [
                        $group->country_code,
                        $group->customer_vat_id,
                        $label,
                        $total,
                        $line->invoice_number,
                        $line->invoice_date?->toDateString() ?? '—',
                        number_format((float) $line->amount, 2, '.', ''),
                        $line->assignee?->name ?? $line->person_name ?? '—',
                        EcInvoiceLine::STATUS_LABELS[$line->task_status] ?? $line->task_status,
                        $line->original_filename ?? 'missing',
                        $line->proof_original_filename ?? 'missing',
                    ]);
                }
            }

            fputcsv($out, []);
            fputcsv($out, ['Note', 'Group total is the sum of the itemized invoices; the ZM filing reports '
                . 'that aggregate per customer. "missing" in a document column is an audit gap to chase '
                . 'before the period is marked ready.']);

            fclose($out);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/ec-invoices/elster?period= — finance.view
    //
    // The § 18a UStG transmission payload: one <Zeile> per group, Betrag
    // rounded to whole euros (the schema takes integers), Art from the
    // transaction type. Served as a downloadable .xml.
    // -------------------------------------------------------------------------
    public function elster(Request $request): StreamedResponse|JsonResponse
    {
        $period = $request->query('period', $this->currentQuarter());

        if (! EcInvoiceGroup::available()) {
            return response()->json(['message' => 'The EC Invoice List is not available yet.'], 503);
        }

        if (! EcInvoicePeriod::isValidPeriod($period)) {
            return response()->json(['message' => "'{$period}' is not a reporting period."], 422);
        }

        $groups = EcInvoiceGroup::with('lines')
            ->where('period', $period)
            ->orderBy('country_code')->orderBy('customer_vat_id')
            ->get();

        $vat = SiteSetting::where('key', self::COMPANY_VAT_KEY)->value('value') ?? '';

        $lines = $groups->map(function (EcInvoiceGroup $g) {
            $total = (int) round((float) $g->lines->sum('amount'));
            $art   = EcInvoiceGroup::TYPE_ART[$g->transaction_type] ?? 'L';

            return "          <Zeile>\n"
                . '            <Landescode>' . e($g->country_code) . "</Landescode>\n"
                . '            <UStIdNr>' . e($g->customer_vat_id) . "</UStIdNr>\n"
                . "            <Betrag>{$total}</Betrag>\n"
                . "            <Art>{$art}</Art>\n"
                . '          </Zeile>';
        })->implode("\n");

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<Elster xmlns=\"http://www.elster.de/elster/xml/schema/v1\">\n"
            . "  <TransferHeader>\n"
            . "    <Verfahren>ZM</Verfahren>\n"
            . "    <DatenArt>ZM</DatenArt>\n"
            . "    <Vorgang>send-Auth</Vorgang>\n"
            . "  </TransferHeader>\n"
            . "  <DatenTeil>\n"
            . "    <Nutzdatenblock>\n"
            . "      <Nutzdaten>\n"
            . "        <ZM version=\"" . now()->format('Y') . "\">\n"
            . "          <Melder>\n"
            . '            <UStIdNr>' . e($vat) . "</UStIdNr>\n"
            . '            <Zeitraum>' . e($period) . "</Zeitraum>\n"
            . "          </Melder>\n"
            . ($lines !== '' ? $lines . "\n" : '')
            . "        </ZM>\n"
            . "      </Nutzdaten>\n"
            . "    </Nutzdatenblock>\n"
            . "  </DatenTeil>\n"
            . "</Elster>\n";

        $filename = "ZM_ELSTER_{$period}.xml";

        return response()->streamDownload(
            fn () => print($xml),
            $filename,
            [
                'Content-Type'        => 'text/xml; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ],
        );
    }

    // -------------------------------------------------------------------------

    /**
     * The display name follows the tag unless one was typed — same rule as the
     * snapshot board.
     */
    private function personName(array $data): ?string
    {
        if (($data['person_name'] ?? null) !== null && $data['person_name'] !== '') {
            return $data['person_name'];
        }

        if (($data['assigned_admin_id'] ?? null) !== null) {
            $staff = AdminUser::find($data['assigned_admin_id']);

            return $staff ? trim($staff->display_name ?: $staff->name) : null;
        }

        return null;
    }

    /**
     * One deduped nudge per assignee per day, pointing at My Work — the same
     * contract as finance snapshot tags. Never self-notifies.
     */
    private function notifyAssignee(EcInvoiceLine $line, ?AdminUser $actor): void
    {
        if (! $line->assigned_admin_id || $line->assigned_admin_id === $actor?->id) {
            return;
        }

        try {
            AdminNotificationService::notifyUser(
                adminUserId: $line->assigned_admin_id,
                type: 'ec_invoice_task_assigned',
                title: 'EC invoice paperwork was assigned to you',
                body: "Invoice {$line->invoice_number} needs its documents completed for the ZM filing. Open My Work to see everything on your plate.",
                actionUrl: '/admin/my-work',
                severity: 'info',
                dedupeKey: 'ec_invoice_assigned:' . $line->assigned_admin_id . ':' . now()->toDateString(),
            );
        } catch (\Throwable $e) {
            Log::warning('EC invoice assignee notification failed', ['line' => $line->id, 'error' => $e->getMessage()]);
        }
    }

    private function storeFile(\Illuminate\Http\UploadedFile $file, EcInvoiceLine $line, string $kind): bool
    {
        $safe = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), '_');
        $ext  = strtolower($file->getClientOriginalExtension());
        $path = 'ec-invoices/' . now()->format('Y') . '/'
            . now()->format('YmdHis') . "_{$kind}_{$line->id}_" . $safe . '.' . $ext;

        try {
            Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));
        } catch (\Throwable $e) {
            Log::error('EC invoice file could not be stored', ['line' => $line->id, 'kind' => $kind, 'error' => $e->getMessage()]);

            return false;
        }

        $previous = $kind === 'proof' ? $line->getRawOriginal('proof_path') : $line->getRawOriginal('file_path');

        if ($previous) {
            Storage::disk('local')->delete($previous);
        }

        $update = $kind === 'proof'
            ? [
                'proof_path'              => $path,
                'proof_original_filename' => $file->getClientOriginalName(),
                'proof_mime_type'         => $file->getClientMimeType(),
                'proof_file_size'         => $file->getSize(),
                'proof_uploaded_at'       => now(),
            ]
            : [
                'file_path'         => $path,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type'         => $file->getClientMimeType(),
                'file_size'         => $file->getSize(),
                'uploaded_at'       => now(),
            ];

        // The mockup's rule, kept: the delivery proof arriving is what
        // "Pending Proof" was waiting for.
        if ($kind === 'proof' && $line->task_status === EcInvoiceLine::STATUS_PENDING_DOC) {
            $update['task_status'] = EcInvoiceLine::STATUS_COMPLETE;
        }

        $line->update($update);

        return true;
    }

    private function deleteLineFiles(EcInvoiceLine $line): void
    {
        foreach (['file_path', 'proof_path'] as $column) {
            if ($path = $line->getRawOriginal($column)) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    /** Today's quarter — the default a fresh page opens on. */
    private function currentQuarter(): string
    {
        return now()->format('Y') . '-Q' . now()->quarter;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatGroup(EcInvoiceGroup $group): array
    {
        return [
            'id'               => $group->id,
            'period'           => $group->period,
            'country_code'     => $group->country_code,
            'customer_vat_id'  => $group->customer_vat_id,
            'transaction_type' => $group->transaction_type,
            'type_label'       => EcInvoiceGroup::TYPE_LABELS[$group->transaction_type] ?? $group->transaction_type,
            // Never stored — the sum of the lines, here and nowhere else.
            'total'            => round((float) $group->lines->sum('amount'), 2),
            'lines'            => $group->lines->map(fn ($l) => $this->formatLine($l))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatLine(EcInvoiceLine $line): array
    {
        return [
            'id'                => $line->id,
            'group_id'          => $line->group_id,
            'invoice_number'    => $line->invoice_number,
            'invoice_date'      => $line->invoice_date?->toDateString(),
            'amount'            => (float) $line->amount,
            'assigned_admin_id' => $line->assigned_admin_id,
            'person'            => $line->assignee
                ? trim($line->assignee->display_name ?: $line->assignee->name)
                : $line->person_name,
            'task_status'       => $line->task_status,
            'has_invoice_file'  => $line->hasInvoiceFile(),
            'invoice_file_name' => $line->original_filename,
            'has_proof_file'    => $line->hasProofFile(),
            'proof_file_name'   => $line->proof_original_filename,
        ];
    }
}

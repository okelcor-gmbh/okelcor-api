<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\StaffContribution;
use App\Support\AdminPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Work the API could not see, entered by the person who did it.
 *
 * Everything here is self-reported and stays labelled that way. A verified
 * entry is a self-reported entry a manager agreed with — it never becomes the
 * same kind of fact as a recorded activity, and no endpoint in this codebase
 * adds the two together.
 *
 * Evidence is invited, not required. A supplier phone call leaves no artifact,
 * and refusing to record it would only mean it goes unrecorded — so the entry
 * is accepted and the reviewer is told plainly whether anything backs it up.
 */
class AdminStaffContributionController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /api/v1/admin/staff/contributions — staff.self
    // -------------------------------------------------------------------------
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'admin_user_id' => ['nullable', 'integer'],
            'from'          => ['nullable', 'date'],
            'to'            => ['nullable', 'date'],
            'category'      => ['nullable', Rule::in(StaffContribution::CATEGORIES)],
            'status'        => ['nullable', Rule::in(StaffContribution::STATUSES)],
            'per_page'      => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $me          = $request->user();
        $canViewTeam = AdminPermissions::can($me->role, 'staff.view_team');

        $query = StaffContribution::query()
            ->with(['adminUser:id,name,role', 'reviewer:id,name'])
            ->orderByDesc('performed_on')
            ->orderByDesc('id');

        // Without staff.view_team the filter is not a filter — it is the only
        // thing the caller may see, applied whatever they asked for.
        if (! $canViewTeam) {
            $query->forAdmin($me->id);
        } elseif ($request->filled('admin_user_id')) {
            $query->forAdmin((int) $request->input('admin_user_id'));
        }

        foreach (['category', 'status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('from')) {
            $query->whereDate('performed_on', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('performed_on', '<=', $request->input('to'));
        }

        $paginated = $query->paginate(min((int) $request->input('per_page', 25), 100));

        return response()->json([
            'data' => collect($paginated->items())->map(fn (StaffContribution $c) => $this->format($c, $me))->values(),
            'meta' => [
                'current_page'  => $paginated->currentPage(),
                'per_page'      => $paginated->perPage(),
                'total'         => $paginated->total(),
                'last_page'     => $paginated->lastPage(),
                'categories'    => StaffContribution::CATEGORY_LABELS,
                'statuses'      => StaffContribution::STATUSES,
                'can_view_team' => $canViewTeam,
                'can_verify'    => AdminPermissions::can($me->role, 'staff.verify'),
            ],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/staff/contributions — staff.self
    // -------------------------------------------------------------------------
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category'    => ['required', Rule::in(StaffContribution::CATEGORIES)],
            'title'       => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'performed_on' => ['required', 'date', 'before_or_equal:today'],
            'minutes'     => ['nullable', 'integer', 'min:1', 'max:1440'],
            'link'        => ['nullable', 'url', 'max:500'],
            'file'        => ['nullable', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx'],
        ], [
            'performed_on.before_or_equal' => 'Work cannot be logged for a date in the future.',
        ]);

        unset($data['file']);

        // Always the caller. There is no path for entering work on somebody
        // else's behalf, deliberately — a self-reported record that someone
        // else can write is neither self-reported nor a record.
        $data['admin_user_id'] = $request->user()->id;
        $data['status']        = StaffContribution::STATUS_PENDING;

        $contribution = StaffContribution::create($data);

        // One request rather than two, same as the finance invoice upload:
        // people have the artifact in front of them while they are writing the
        // entry, and a separate "now attach it" step is a step that gets
        // skipped.
        if ($request->hasFile('file') && ! $this->storeFile($request, $contribution)) {
            return response()->json([
                'data'    => $this->format($contribution->fresh(['adminUser', 'reviewer']), $request->user()),
                'message' => 'Entry saved, but the attachment could not be stored. Add it again from the list.',
            ], 201);
        }

        return response()->json([
            'data'    => $this->format($contribution->fresh(['adminUser', 'reviewer']), $request->user()),
            'message' => 'Work logged.',
        ], 201);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/v1/admin/staff/contributions/{id} — staff.self
    // -------------------------------------------------------------------------
    public function update(Request $request, int $id): JsonResponse
    {
        $contribution = StaffContribution::findOrFail($id);
        $me           = $request->user();

        if ($contribution->admin_user_id !== $me->id) {
            return response()->json(['message' => 'You can only edit your own entries.'], 403);
        }

        if (! $contribution->isEditable()) {
            // Rewording an entry after a manager agreed with it would change
            // what they agreed to. Add a correcting entry instead.
            return response()->json([
                'message' => 'This entry has already been reviewed, so it can no longer be edited. Add a new entry with the correction.',
                'code'    => 'already_reviewed',
            ], 409);
        }

        $data = $request->validate([
            'category'     => ['sometimes', Rule::in(StaffContribution::CATEGORIES)],
            'title'        => ['sometimes', 'string', 'max:160'],
            'description'  => ['sometimes', 'nullable', 'string', 'max:2000'],
            'performed_on' => ['sometimes', 'date', 'before_or_equal:today'],
            'minutes'      => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'],
            'link'         => ['sometimes', 'nullable', 'url', 'max:500'],
        ]);

        $contribution->update($data);

        return response()->json([
            'data'    => $this->format($contribution->fresh(['adminUser', 'reviewer']), $me),
            'message' => 'Entry updated.',
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/admin/staff/contributions/{id} — staff.self
    // -------------------------------------------------------------------------
    public function destroy(Request $request, int $id): JsonResponse
    {
        $contribution = StaffContribution::findOrFail($id);

        if ($contribution->admin_user_id !== $request->user()->id) {
            return response()->json(['message' => 'You can only remove your own entries.'], 403);
        }

        if (! $contribution->isEditable()) {
            return response()->json([
                'message' => 'This entry has already been reviewed and is part of the record now.',
                'code'    => 'already_reviewed',
            ], 409);
        }

        if ($path = $contribution->getRawOriginal('file_path')) {
            Storage::disk('local')->delete($path);
        }

        $contribution->delete();

        return response()->json(['message' => 'Entry removed.']);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/staff/contributions/{id}/file — staff.self
    // -------------------------------------------------------------------------
    public function uploadFile(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx'],
        ]);

        $contribution = StaffContribution::findOrFail($id);

        if ($contribution->admin_user_id !== $request->user()->id) {
            return response()->json(['message' => 'You can only attach evidence to your own entries.'], 403);
        }

        if (! $contribution->isEditable()) {
            return response()->json([
                'message' => 'This entry has already been reviewed, so its evidence is fixed.',
                'code'    => 'already_reviewed',
            ], 409);
        }

        if (! $this->storeFile($request, $contribution)) {
            return response()->json(['message' => 'The file could not be saved. Please try again.'], 500);
        }

        return response()->json([
            'data'    => $this->format($contribution->fresh(['adminUser', 'reviewer']), $request->user()),
            'message' => 'Evidence attached.',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/staff/contributions/{id}/file — staff.self
    // -------------------------------------------------------------------------
    public function download(Request $request, int $id): BinaryFileResponse|JsonResponse
    {
        $contribution = StaffContribution::findOrFail($id);
        $me           = $request->user();

        $mine = $contribution->admin_user_id === $me->id;

        if (! $mine && ! AdminPermissions::can($me->role, 'staff.view_team')) {
            return response()->json(['message' => 'You can only open evidence on your own entries.'], 403);
        }

        $path = $contribution->getRawOriginal('file_path');

        if (! $path) {
            return response()->json(['message' => 'No evidence is attached to this entry.'], 404);
        }

        // Asked of the disk rather than assembled from storage_path(): the
        // `local` root is configuration, and hardcoding it silently 404s
        // wherever that root differs.
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            Log::warning('Staff contribution evidence missing on disk', ['id' => $id, 'path' => $path]);

            return response()->json(['message' => 'The attached file could not be found.'], 404);
        }

        return response()->download($disk->path($path), $contribution->original_filename ?: basename($path));
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/staff/contributions/{id}/review — staff.verify
    // -------------------------------------------------------------------------
    public function review(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in([StaffContribution::STATUS_VERIFIED, StaffContribution::STATUS_REJECTED])],
            'note'     => ['nullable', 'string', 'max:500'],
        ]);

        $contribution = StaffContribution::findOrFail($id);
        $me           = $request->user();

        // Nobody countersigns their own claim. The whole value of a
        // verification is that a second person looked at it — the same
        // reasoning that stops one person filling both order sign-off slots.
        if ($contribution->admin_user_id === $me->id) {
            return response()->json([
                'message' => 'You cannot verify your own entry. Ask a colleague with review rights.',
                'code'    => 'self_review',
            ], 422);
        }

        if ($data['decision'] === StaffContribution::STATUS_REJECTED && empty($data['note'])) {
            return response()->json([
                'message' => 'Say why the entry was rejected — a rejection with no reason is not something anyone can act on.',
                'errors'  => ['note' => ['A note is required when rejecting an entry.']],
            ], 422);
        }

        $contribution->update([
            'status'      => $data['decision'],
            'reviewed_by' => $me->id,
            'reviewed_at' => now(),
            'review_note' => $data['note'] ?? null,
        ]);

        return response()->json([
            'data'    => $this->format($contribution->fresh(['adminUser', 'reviewer']), $me),
            'message' => $data['decision'] === StaffContribution::STATUS_VERIFIED
                ? 'Entry verified.'
                : 'Entry rejected.',
        ]);
    }

    // -------------------------------------------------------------------------

    private function storeFile(Request $request, StaffContribution $contribution): bool
    {
        $file = $request->file('file');

        $safe = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), '_');
        $ext  = strtolower($file->getClientOriginalExtension());
        $path = 'staff-contributions/' . $contribution->admin_user_id . '/'
            . now()->format('YmdHis') . '_' . $contribution->id . '_' . $safe . '.' . $ext;

        try {
            Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));
        } catch (\Throwable $e) {
            Log::error('Staff contribution evidence could not be stored', [
                'id'    => $contribution->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        // Replacing evidence removes what it replaced, so the disk does not
        // fill with copies nothing can reach.
        if ($previous = $contribution->getRawOriginal('file_path')) {
            Storage::disk('local')->delete($previous);
        }

        $contribution->update([
            'file_path'         => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type'         => $file->getClientMimeType(),
            'file_size'         => $file->getSize(),
        ]);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function format(StaffContribution $c, AdminUser $viewer): array
    {
        $mine = $c->admin_user_id === $viewer->id;

        return [
            'id'             => $c->id,
            'category'       => $c->category,
            'category_label' => $c->categoryLabel(),
            'title'          => $c->title,
            'description'    => $c->description,
            'performed_on'   => $c->performed_on instanceof Carbon ? $c->performed_on->toDateString() : $c->performed_on,
            'minutes'        => $c->minutes,
            'link'           => $c->link,
            'has_file'       => $c->hasFile(),
            'file_name'      => $c->original_filename,
            'file_size'      => $c->file_size,
            'has_evidence'   => $c->hasEvidence(),
            'status'         => $c->status,
            'review_note'    => $c->review_note,
            'reviewed_by'    => $c->reviewer?->name,
            'reviewed_at'    => $c->reviewed_at?->toIso8601String(),
            'logged_by'      => ['id' => $c->admin_user_id, 'name' => $c->adminUser?->name, 'role' => $c->adminUser?->role],
            'created_at'     => $c->created_at?->toIso8601String(),

            // Never inferred from status in the UI — it is stated here so a
            // verified entry cannot be rendered as though the system observed
            // it.
            'self_reported'  => true,

            // What this particular viewer may do, so the frontend does not have
            // to reimplement the rules and drift from them.
            'can_edit'       => $mine && $c->isEditable(),
            'can_review'     => ! $mine
                && $c->status === StaffContribution::STATUS_PENDING
                && AdminPermissions::can($viewer->role, 'staff.verify'),
        ];
    }
}

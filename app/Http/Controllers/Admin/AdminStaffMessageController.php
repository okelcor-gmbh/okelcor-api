<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\StaffMessage;
use App\Models\StaffMessageRecipient;
use App\Services\AdminNotificationService;
use App\Services\RichEmailHtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Internal staff-to-staff messaging — the same Outlook-style compose the
 * customer inbox has, pointed inward. Delivery is in-app (inbox row +
 * notification bell + companion-app push), never SMTP: an internal message
 * that took a round trip through Resend and the Cloudflare inbound worker
 * would be slower, loopable, and no more delivered.
 *
 * Visibility rule, everywhere: the sender and the recipients of a message may
 * see it; nobody else can — including super_admin, because a super_admin who
 * can silently read everyone's internal mail is a reason for people not to
 * use it. (Anything that must be auditable belongs on the customer record,
 * not here.)
 */
class AdminStaffMessageController extends Controller
{
    // ── GET /admin/staff-messages/inbox ──────────────────────────────────────

    public function inbox(Request $request): JsonResponse
    {
        $request->validate([
            'unread'   => ['sometimes'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $me = $request->user();

        $query = StaffMessageRecipient::query()
            ->where('recipient_admin_user_id', $me->id)
            ->with('message.sender')
            ->orderByDesc('staff_message_id');

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $paginated = $query->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => collect($paginated->items())
                ->filter(fn (StaffMessageRecipient $r) => $r->message !== null)
                ->map(fn (StaffMessageRecipient $r) => $this->formatInboxRow($r))
                ->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
                'unread_count' => $this->unreadCountFor($me),
            ],
            'message' => 'success',
        ]);
    }

    // ── GET /admin/staff-messages/sent ───────────────────────────────────────

    public function sent(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $me = $request->user();

        $paginated = StaffMessage::query()
            ->where('sender_admin_user_id', $me->id)
            ->with('recipientLinks.recipient')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => collect($paginated->items())->map(fn (StaffMessage $m) => [
                'id'          => $m->id,
                'subject'     => $m->subject,
                'preview'     => Str::limit(strip_tags((string) $m->body), 140),
                'recipients'  => $m->recipientLinks->map(fn ($r) => [
                    'id'      => $r->recipient_admin_user_id,
                    'name'    => $r->recipient?->name,
                    'read_at' => $r->read_at?->toIso8601String(),
                ])->values(),
                'read_count'      => $m->recipientLinks->whereNotNull('read_at')->count(),
                'recipient_count' => $m->recipientLinks->count(),
                'has_attachments' => ! empty($m->attachments),
                'thread_root_id'  => $m->threadRootId(),
                'action_url'      => "/admin/messages/{$m->id}",
                'created_at'      => $m->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
            'message' => 'success',
        ]);
    }

    // ── GET /admin/staff-messages/unread-count ───────────────────────────────
    // Standalone so the frontend can poll the mail badge as cheaply as the
    // notification bell's own unread-count.

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data'    => ['unread_count' => $this->unreadCountFor($request->user())],
            'message' => 'success',
        ]);
    }

    // ── GET /admin/staff-messages/recipients ─────────────────────────────────
    // The "To:" picker — every active colleague. Deliberately NOT the staff
    // ledger's members(), which shows only yourself without staff.view_team:
    // a directory you need a manager permission to address would make the
    // whole feature manager-only.
    //
    // meta also answers "is my signature set?" so the composer can nudge
    // without a second request — see FRONTEND_NOTE_staff-messaging.md.

    public function recipients(Request $request): JsonResponse
    {
        $me = $request->user();

        $columns = ['id', 'name', 'email', 'role', 'is_active'];
        if (Schema::hasColumn('admin_users', 'job_title')) {
            $columns[] = 'job_title';
        }

        $users = AdminUser::query()
            ->select($columns)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $users->map(fn (AdminUser $u) => [
                'id'        => $u->id,
                'name'      => $u->name,
                'email'     => $u->email,
                'job_title' => $u->jobTitle(),
                'is_self'   => $u->id === $me->id,
            ])->values(),
            'meta' => [
                'signature_set'  => filled($me->email_signature),
                'signature_html' => $me->email_signature,
            ],
            'message' => 'success',
        ]);
    }

    // ── GET /admin/staff-messages/{id} ───────────────────────────────────────
    // The message plus its whole thread (only the parts the caller may see —
    // a reply addressed to somebody else stays invisible even mid-thread).

    public function show(Request $request, int $id): JsonResponse
    {
        $me      = $request->user();
        $message = StaffMessage::with(['sender', 'recipientLinks.recipient'])->findOrFail($id);

        if (! $message->visibleTo($me)) {
            abort(404);
        }

        $rootId = $message->threadRootId();

        $thread = StaffMessage::with(['sender', 'recipientLinks.recipient'])
            ->where(fn ($q) => $q->where('thread_root_id', $rootId)->orWhere('id', $rootId))
            ->orderBy('id')
            ->get()
            ->filter(fn (StaffMessage $m) => $m->visibleTo($me))
            ->values();

        return response()->json([
            'data' => $this->format($message, $me),
            'meta' => [
                'thread' => $thread->map(fn (StaffMessage $m) => $this->format($m, $me))->values(),
            ],
            'message' => 'success',
        ]);
    }

    // ── POST /admin/staff-messages ───────────────────────────────────────────

    public function store(Request $request, RichEmailHtmlSanitizer $sanitizer): JsonResponse
    {
        $data = $request->validate([
            'to'             => ['required', 'array', 'min:1', 'max:20'],
            'to.*'           => ['integer', 'distinct'],
            'cc'             => ['sometimes', 'array', 'max:20'],
            'cc.*'           => ['integer', 'distinct'],
            'subject'        => ['required', 'string', 'max:300'],
            'body'           => ['required', 'string', 'max:512000'],
            'in_reply_to_id' => ['nullable', 'integer'],
            'attachments'    => ['sometimes', 'array', 'max:5'],
            'attachments.*'  => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,csv'],
        ]);

        $me = $request->user();

        // Resolve recipients up front — a message "sent" to a deactivated or
        // nonexistent account would sit unread forever and read as ignored.
        $toIds = array_values(array_unique($data['to']));
        $ccIds = array_values(array_diff(array_unique($data['cc'] ?? []), $toIds));
        $all   = array_merge($toIds, $ccIds);

        $found = AdminUser::whereIn('id', $all)->get()->keyBy('id');

        $invalid = collect($all)
            ->filter(fn ($id) => ! isset($found[$id]) || ! $found[$id]->is_active)
            ->values();

        if ($invalid->isNotEmpty()) {
            return response()->json([
                'message' => 'Some recipients are not active staff accounts.',
                'code'    => 'invalid_recipients',
                'invalid' => $invalid,
            ], 422);
        }

        // Same rule as the customer composer: a stray id can't be used to
        // forge a fake reply chain — an unseen parent is silently dropped.
        $parent = null;
        if (! empty($data['in_reply_to_id'])) {
            $candidate = StaffMessage::find($data['in_reply_to_id']);
            if ($candidate && $candidate->visibleTo($me)) {
                $parent = $candidate;
            }
        }

        $subject = $data['subject'];
        if ($parent && ! preg_match('/^re:/i', $subject)) {
            $subject = 'Re: ' . $subject;
        }

        try {
            $bodyClean = $sanitizer->sanitize($data['body'], 'staff-messages/' . Str::uuid());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Same doctrine as the customer composer: the signature is appended
        // once, here, so what the recipients read is exactly what was stored.
        $bodyFinal = $this->appendSignature($bodyClean, $me->email_signature);

        $attachmentMeta = [];
        try {
            foreach ($request->file('attachments', []) as $file) {
                $storedPath = $file->store('staff-messages/' . now()->format('Y/m'), 'local');

                $attachmentMeta[] = [
                    'name' => $file->getClientOriginalName(),
                    'path' => $storedPath,
                    'mime' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                ];
            }
        } catch (\Throwable $e) {
            Log::error('[staff_message_attachment_store_failed] Could not store attachment', [
                'event' => 'staff_message_attachment_store_failed',
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'One of the attachments could not be saved. Please try again.',
                'code'    => 'attachment_store_failed',
            ], 502);
        }

        $message = StaffMessage::create([
            'sender_admin_user_id' => $me->id,
            'subject'              => $subject,
            'body'                 => $bodyFinal,
            'attachments'          => $attachmentMeta ?: null,
            'in_reply_to_id'       => $parent?->id,
            'thread_root_id'       => $parent?->threadRootId(),
        ]);

        foreach ($toIds as $id) {
            StaffMessageRecipient::create([
                'staff_message_id'        => $message->id,
                'recipient_admin_user_id' => $id,
                'kind'                    => 'to',
            ]);
        }
        foreach ($ccIds as $id) {
            StaffMessageRecipient::create([
                'staff_message_id'        => $message->id,
                'recipient_admin_user_id' => $id,
                'kind'                    => 'cc',
            ]);
        }

        // The bell (and companion-app push) is the delivery channel. Sending
        // to yourself deliberately doesn't ring it.
        foreach (array_diff($all, [$me->id]) as $id) {
            AdminNotificationService::notifyUser(
                (int) $id,
                'staff_message_received',
                'New message from ' . $me->name,
                $subject,
                "/admin/messages/{$message->id}",
                'info',
                'staff_message',
                $message->id
            );
        }

        Log::info('[staff_message_sent] Internal staff message sent', [
            'event'      => 'staff_message_sent',
            'message_id' => $message->id,
            'by_admin'   => $me->id,
            'recipients' => count($all),
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->format($message->load(['sender', 'recipientLinks.recipient']), $me),
            'message' => 'Message sent.',
        ], 201);
    }

    // ── POST /admin/staff-messages/{id}/read ─────────────────────────────────

    public function markRead(Request $request, int $id): JsonResponse
    {
        $me = $request->user();

        $link = StaffMessageRecipient::where('staff_message_id', $id)
            ->where('recipient_admin_user_id', $me->id)
            ->firstOrFail();

        if (! $link->read_at) {
            $link->update(['read_at' => now()]);
        }

        return response()->json([
            'data'    => ['read_at' => $link->fresh()->read_at?->toIso8601String()],
            'meta'    => ['unread_count' => $this->unreadCountFor($me)],
            'message' => 'success',
        ]);
    }

    // ── POST /admin/staff-messages/read-all ──────────────────────────────────

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = StaffMessageRecipient::where('recipient_admin_user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'data'    => ['marked_read' => $updated],
            'message' => 'success',
        ]);
    }

    // ── GET /admin/staff-messages/{id}/attachments/{index}/download ──────────

    public function downloadAttachment(Request $request, int $id, int $index)
    {
        $message = StaffMessage::findOrFail($id);

        if (! $message->visibleTo($request->user())) {
            abort(404);
        }

        $attachments = $message->attachments ?? [];

        if (! isset($attachments[$index]['path']) || ! Storage::disk('local')->exists($attachments[$index]['path'])) {
            abort(404);
        }

        return Storage::disk('local')->download($attachments[$index]['path'], $attachments[$index]['name']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function unreadCountFor(AdminUser $user): int
    {
        return StaffMessageRecipient::where('recipient_admin_user_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    /** Same markup as AdminCommunicationController::appendSignature. */
    private function appendSignature(string $bodyHtml, ?string $signatureHtml): string
    {
        if (! $signatureHtml) {
            return $bodyHtml;
        }

        return $bodyHtml . '<div style="margin-top:24px;padding-top:16px;border-top:1px solid #eeeeee;">' . $signatureHtml . '</div>';
    }

    private function formatInboxRow(StaffMessageRecipient $r): array
    {
        $m = $r->message;

        return [
            'id'               => $m->id,
            'sender'           => [
                'id'        => $m->sender_admin_user_id,
                'name'      => $m->sender?->name,
                'job_title' => $m->sender?->jobTitle(),
            ],
            'subject'          => $m->subject,
            'preview'          => Str::limit(strip_tags((string) $m->body), 140),
            'unread'           => $r->read_at === null,
            'kind'             => $r->kind,
            'has_attachments'  => ! empty($m->attachments),
            'thread_root_id'   => $m->threadRootId(),
            'action_url'       => "/admin/messages/{$m->id}",
            'created_at'       => $m->created_at?->toIso8601String(),
        ];
    }

    private function format(StaffMessage $m, AdminUser $viewer): array
    {
        $myLink = $m->recipientLinks->firstWhere('recipient_admin_user_id', $viewer->id);

        return [
            'id'             => $m->id,
            'subject'        => $m->subject,
            'body'           => $m->body,
            'sender'         => [
                'id'        => $m->sender_admin_user_id,
                'name'      => $m->sender?->name,
                'job_title' => $m->sender?->jobTitle(),
            ],
            'recipients'     => $m->recipientLinks->map(fn ($r) => [
                'id'        => $r->recipient_admin_user_id,
                'name'      => $r->recipient?->name,
                'job_title' => $r->recipient?->jobTitle(),
                'kind'      => $r->kind,
                'read_at'   => $r->read_at?->toIso8601String(),
            ])->values(),
            'attachments'    => collect($m->attachments ?? [])->map(fn ($a, $i) => [
                'name'         => $a['name'] ?? null,
                'mime'         => $a['mime'] ?? null,
                'size'         => $a['size'] ?? null,
                'download_url' => url("/api/v1/admin/staff-messages/{$m->id}/attachments/{$i}/download"),
            ])->values(),
            'in_reply_to_id' => $m->in_reply_to_id,
            'thread_root_id' => $m->threadRootId(),
            'is_mine'        => $m->sender_admin_user_id === $viewer->id,
            'read_at'        => $myLink?->read_at?->toIso8601String(),
            'created_at'     => $m->created_at?->toIso8601String(),
        ];
    }
}

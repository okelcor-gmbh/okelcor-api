<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\StaffMessageEmail;
use App\Models\AdminUser;
use App\Models\CustomerCommunication;
use App\Models\StaffMessage;
use App\Models\StaffMessageRecipient;
use App\Services\AdminNotificationService;
use App\Services\RichEmailHtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Staff-to-staff messaging (Session 97).
 *
 * The ask: staff addresses are already in the system, so a colleague should
 * be reachable from the admin panel without opening Outlook — and a customer
 * e-mail already in the system should be forwardable to whoever should
 * actually handle it.
 *
 * Delivery is BOTH: the message lands in the colleague's admin inbox (and
 * their notification bell, and their phone via the existing Expo push) AND a
 * real e-mail copy goes to their okelcor.com address, so they see it whether
 * or not they are logged in. That is the same "Email = Inbox" pattern used
 * for customer notifications since Session 47.
 *
 * Ordering note: unlike AdminCommunicationController::composeAndSend, which
 * sends first and logs after, this writes the message row FIRST and sends
 * after. There the e-mail IS the artefact and the log is a record of it;
 * here the panel thread is the artefact and the e-mail is a copy. A mail
 * failure must leave the message sitting in the colleague's inbox, not
 * vanish.
 */
class AdminStaffMessageController extends Controller
{
    private const MAX_RECIPIENTS = 10;

    // ── Reading ──────────────────────────────────────────────────────────────

    // GET /admin/staff-messages?box=inbox|sent&unread=1
    public function index(Request $request): JsonResponse
    {
        $me  = $request->user();
        $box = $request->query('box', 'inbox') === 'sent' ? 'sent' : 'inbox';

        $query = StaffMessage::with(['sender', 'recipients.adminUser'])
            ->orderByDesc('created_at');

        if ($box === 'sent') {
            $query->where('sender_admin_id', $me->id);
        } else {
            $query->whereHas('recipients', function ($q) use ($me, $request) {
                $q->where('admin_user_id', $me->id);

                if ($request->boolean('unread')) {
                    $q->whereNull('read_at');
                }
            });
        }

        $page = $query->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (StaffMessage $m) => $this->formatRow($m, $me))->values(),
            'meta' => [
                'box'          => $box,
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'unread_total' => $this->unreadCountFor($me),
            ],
            'message' => 'success',
        ]);
    }

    // GET /admin/staff-messages/unread-count
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data'    => ['unread' => $this->unreadCountFor($request->user())],
            'message' => 'success',
        ]);
    }

    /**
     * GET /admin/staff-messages/directory
     *
     * Who you can write to. Its own endpoint because listing admin accounts
     * otherwise sits behind `admins.manage`, which is super_admin only — an
     * order manager could not have seen a single colleague's name. This
     * returns strictly what a compose box needs (name, e-mail, job title,
     * role) and nothing sensitive: no password state, no login history, no
     * 2FA status.
     */
    public function directory(Request $request): JsonResponse
    {
        $me = $request->user();

        $colleagues = AdminUser::query()
            ->where('is_active', true)
            ->where('id', '!=', $me->id)          // you are not in your own directory
            ->orderBy('name')
            ->get(['id', 'name', 'display_name', 'first_name', 'last_name', 'email', 'job_title', 'role']);

        return response()->json([
            'data' => $colleagues->map(fn (AdminUser $a) => [
                'id'        => $a->id,
                'name'      => trim($a->display_name ?: $a->name) ?: $a->email,
                'email'     => $a->email,
                'job_title' => $a->job_title,
                'role'      => $a->role,
            ])->values(),
            'message' => 'success',
        ]);
    }

    // GET /admin/staff-messages/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        $me      = $request->user();
        $message = StaffMessage::with(['sender', 'recipients.adminUser'])->find($id);

        if (! $message || ! $this->canSee($message, $me)) {
            // Deliberately the same 404 for "does not exist" and "not yours" —
            // a 403 would confirm that a message with this id exists.
            return response()->json(['message' => 'Message not found.'], 404);
        }

        $thread = StaffMessage::with(['sender', 'recipients.adminUser'])
            ->where('thread_id', $message->thread_id)
            ->orderBy('created_at')
            ->get()
            ->filter(fn (StaffMessage $m) => $this->canSee($m, $me))
            ->values();

        return response()->json([
            'data' => [
                'message' => $this->format($message, $me),
                'thread'  => $thread->map(fn (StaffMessage $m) => $this->format($m, $me))->values(),
            ],
            'message' => 'success',
        ]);
    }

    // POST /admin/staff-messages/{id}/read
    public function markRead(Request $request, int $id): JsonResponse
    {
        $me = $request->user();

        $recipient = StaffMessageRecipient::where('staff_message_id', $id)
            ->where('admin_user_id', $me->id)
            ->first();

        if (! $recipient) {
            // The sender opening their own sent message is a no-op, not an
            // error — there is no unread state to clear on a message you sent.
            $exists = StaffMessage::where('id', $id)->where('sender_admin_id', $me->id)->exists();

            return $exists
                ? response()->json(['data' => ['unread' => $this->unreadCountFor($me)], 'message' => 'success'])
                : response()->json(['message' => 'Message not found.'], 404);
        }

        if (! $recipient->read_at) {
            $recipient->update(['read_at' => now()]);
        }

        return response()->json([
            'data'    => ['unread' => $this->unreadCountFor($me)],
            'message' => 'success',
        ]);
    }

    // GET /admin/staff-messages/{id}/attachments/{index}/download
    public function downloadAttachment(Request $request, int $id, int $index)
    {
        $message = StaffMessage::find($id);

        if (! $message || ! $this->canSee($message, $request->user())) {
            abort(404);
        }

        $attachments = $message->attachments ?? [];

        if (! isset($attachments[$index]['path']) || ! Storage::disk('local')->exists($attachments[$index]['path'])) {
            abort(404);
        }

        return Storage::disk('local')->download($attachments[$index]['path'], $attachments[$index]['name']);
    }

    // ── Writing ──────────────────────────────────────────────────────────────

    // POST /admin/staff-messages
    public function store(Request $request, RichEmailHtmlSanitizer $sanitizer): JsonResponse
    {
        $data = $request->validate([
            'to'            => ['required', 'array', 'min:1', 'max:' . self::MAX_RECIPIENTS],
            'to.*'          => ['integer', 'distinct'],
            'cc'            => ['sometimes', 'array', 'max:' . self::MAX_RECIPIENTS],
            'cc.*'          => ['integer', 'distinct'],
            'subject'       => ['required', 'string', 'max:300'],
            'body'          => ['required', 'string', 'max:512000'],
            'attachments'   => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,csv'],
        ]);

        $resolved = $this->resolveRecipients($data['to'], $data['cc'] ?? [], $request->user());

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        return $this->deliver($request, $sanitizer, $data['subject'], $data['body'], $resolved, null, null);
    }

    // POST /admin/staff-messages/{id}/reply
    public function reply(Request $request, RichEmailHtmlSanitizer $sanitizer, int $id): JsonResponse
    {
        $me     = $request->user();
        $parent = StaffMessage::with('recipients')->find($id);

        if (! $parent || ! $this->canSee($parent, $me)) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        $data = $request->validate([
            'body'          => ['required', 'string', 'max:512000'],
            'reply_all'     => ['sometimes', 'boolean'],
            'attachments'   => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,csv'],
        ]);

        // Reply goes to the original sender; reply-all adds everyone else who
        // was on it. Recipients are taken from the PARENT, never from the
        // request — a reply cannot be used to pull a colleague into a thread
        // they were never part of and show them its history.
        $ids = collect([$parent->sender_admin_id]);

        if ($request->boolean('reply_all')) {
            $ids = $ids->merge($parent->recipients->pluck('admin_user_id'));
        }

        $ids = $ids->filter()->unique()->reject(fn ($aid) => $aid === $me->id)->values()->all();

        if ($ids === []) {
            return response()->json([
                'message' => 'There is nobody left to reply to on this message.',
                'code'    => 'no_recipients',
            ], 422);
        }

        $resolved = $this->resolveRecipients($ids, [], $me);

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $subject = preg_match('/^re:/i', $parent->subject) ? $parent->subject : 'Re: ' . $parent->subject;

        return $this->deliver($request, $sanitizer, $subject, $data['body'], $resolved, $parent, null);
    }

    /**
     * POST /admin/communications/{id}/forward
     *
     * Forward a customer e-mail already in the system to a colleague.
     * Gated on crm.view at the route — you must already be allowed to read
     * the communication you are forwarding.
     *
     * Targets are admin_users only, deliberately: a free-text recipient here
     * would turn the admin panel into a route for customer correspondence to
     * leave the company, which needs its own permission and its own audit
     * trail before it is worth having.
     */
    public function forward(Request $request, RichEmailHtmlSanitizer $sanitizer, int $id): JsonResponse
    {
        $comm = CustomerCommunication::with(['customer', 'quoteRequest'])->find($id);

        if (! $comm) {
            return response()->json(['message' => 'Communication not found.'], 404);
        }

        $data = $request->validate([
            'to'      => ['required', 'array', 'min:1', 'max:' . self::MAX_RECIPIENTS],
            'to.*'    => ['integer', 'distinct'],
            'cc'      => ['sometimes', 'array', 'max:' . self::MAX_RECIPIENTS],
            'cc.*'    => ['integer', 'distinct'],
            'note'    => ['nullable', 'string', 'max:512000'],
            'subject' => ['nullable', 'string', 'max:300'],
        ]);

        $resolved = $this->resolveRecipients($data['to'], $data['cc'] ?? [], $request->user());

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $subject = ($data['subject'] ?? null)
            ?: (preg_match('/^fwd:/i', (string) $comm->subject)
                ? $comm->subject
                : 'Fwd: ' . ($comm->subject ?: 'Customer message'));

        // The forwarded original is quoted into the body rather than linked,
        // so the message is complete in the colleague's mailbox too — a link
        // alone is useless to someone reading it on their phone at a port.
        $body = ($data['note'] ?? '') . $this->buildQuotedOriginal($comm);

        return $this->deliver($request, $sanitizer, $subject, $body, $resolved, null, $comm);
    }

    // ── The shared send path ─────────────────────────────────────────────────

    /**
     * @param  array{to: array<int, AdminUser>, cc: array<int, AdminUser>}  $resolved
     */
    private function deliver(
        Request $request,
        RichEmailHtmlSanitizer $sanitizer,
        string $subject,
        string $rawBody,
        array $resolved,
        ?StaffMessage $parent,
        ?CustomerCommunication $forwardedFrom
    ): JsonResponse {
        $me = $request->user();

        try {
            $bodyClean = $sanitizer->sanitize($rawBody, 'staff-messages/' . Str::uuid());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $bodyWithSignature = $this->appendSignature($bodyClean, $me->email_signature);

        // Stored before the message row is written, so a storage failure is a
        // clean 502 rather than a message that exists with attachments the
        // sender thinks were included and weren't.
        try {
            $attachmentMeta = $this->storeUploads($request);
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

        // Carry the forwarded message's own attachments across as real copies,
        // not references — a forward must still be readable after the original
        // communication (and its files) are deleted.
        if ($forwardedFrom) {
            $attachmentMeta = array_merge($attachmentMeta, $this->copyForwardedAttachments($forwardedFrom));
        }

        $message = DB::transaction(function () use (
            $me, $subject, $bodyWithSignature, $attachmentMeta, $parent, $forwardedFrom, $resolved
        ) {
            $message = StaffMessage::create([
                'thread_id'                       => $parent?->thread_id ?: (string) Str::uuid(),
                'sender_admin_id'                 => $me->id,
                'sender_label'                    => trim($me->display_name ?: $me->name) ?: $me->email,
                'subject'                         => $subject,
                'body'                            => $bodyWithSignature,
                'attachments'                     => $attachmentMeta ?: null,
                'in_reply_to_id'                  => $parent?->id,
                'forwarded_from_communication_id' => $forwardedFrom?->id,
                'forwarded_from_customer_id'      => $forwardedFrom?->customer_id,
                'forwarded_from_quote_request_id' => $forwardedFrom?->quote_request_id,
            ]);

            foreach (['to', 'cc'] as $kind) {
                foreach ($resolved[$kind] as $admin) {
                    StaffMessageRecipient::create([
                        'staff_message_id' => $message->id,
                        'admin_user_id'    => $admin->id,
                        'kind'             => $kind,
                    ]);
                }
            }

            return $message;
        });

        // Everything below is best-effort delivery of a message that already
        // exists and is already visible in the recipients' panel inbox. None
        // of it can fail the request.
        $this->sendEmailCopies($message, $me, $resolved, $forwardedFrom);
        $this->raiseNotifications($message, $me, $resolved);

        $message->load(['sender', 'recipients.adminUser']);

        $failed = $message->recipients->where('email_status', 'failed');

        return response()->json([
            'data' => $this->format($message, $me),
            'meta' => [
                'email_failures' => $failed->pluck('admin_user_id')->values(),
            ],
            'message' => $failed->isEmpty()
                ? 'Message sent.'
                : 'Message delivered in the panel, but the e-mail copy failed for '
                    . $failed->count() . ' recipient(s). They will still see it when they log in.',
        ], 201);
    }

    /**
     * @param  array{to: array<int, AdminUser>, cc: array<int, AdminUser>}  $resolved
     */
    private function sendEmailCopies(
        StaffMessage $message,
        AdminUser $sender,
        array $resolved,
        ?CustomerCommunication $forwardedFrom
    ): void {
        $files = collect($message->attachments ?? [])
            ->filter(fn (array $a) => isset($a['path']) && Storage::disk('local')->exists($a['path']))
            ->map(fn (array $a) => [
                'path' => Storage::disk('local')->path($a['path']),
                'name' => $a['name'] ?? 'attachment',
                'mime' => $a['mime'] ?? 'application/octet-stream',
            ])
            ->values()
            ->all();

        $panelUrl = rtrim(config('app.frontend_url'), '/') . '/admin/messages/' . $message->id;
        $context  = $forwardedFrom ? $this->forwardContextLine($forwardedFrom) : null;

        foreach (array_merge($resolved['to'], $resolved['cc']) as $admin) {
            $status = 'sent';
            $error  = null;

            try {
                Mail::to($admin->email)->send(new StaffMessageEmail(
                    sender: $sender,
                    subjectLine: $message->subject,
                    bodyHtml: $message->body,
                    attachmentFiles: $files,
                    panelUrl: $panelUrl,
                    forwardedContext: $context,
                ));
            } catch (\Throwable $e) {
                $status = 'failed';
                $error  = $e->getMessage();

                Log::error('[staff_message_email_failed] Internal message e-mail copy failed', [
                    'event'      => 'staff_message_email_failed',
                    'message_id' => $message->id,
                    'to_admin'   => $admin->id,
                    'error'      => $error,
                ]);
            }

            StaffMessageRecipient::where('staff_message_id', $message->id)
                ->where('admin_user_id', $admin->id)
                ->update(['email_status' => $status, 'email_error' => $error]);
        }
    }

    /**
     * @param  array{to: array<int, AdminUser>, cc: array<int, AdminUser>}  $resolved
     */
    private function raiseNotifications(StaffMessage $message, AdminUser $sender, array $resolved): void
    {
        $senderName = trim($sender->display_name ?: $sender->name) ?: $sender->email;

        foreach (array_merge($resolved['to'], $resolved['cc']) as $admin) {
            AdminNotificationService::notifyUser(
                adminUserId: $admin->id,
                type: 'staff_message_received',
                title: $senderName . ': ' . Str::limit($message->subject, 80),
                body: Str::limit(strip_tags($message->body), 140),
                actionUrl: '/admin/messages/' . $message->id,
                severity: 'info',
                relatedType: 'staff_message',
                relatedId: $message->id,
                // Every message is its own event — without a unique key the
                // service's dedupe would swallow a second message from the
                // same colleague while the first is still unread.
                dedupeKey: 'staff_message:' . $message->id . ':' . $admin->id,
            );
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Turn recipient ids into active admin accounts, or return the 422.
     *
     * Silently drops the sender from their own recipient list rather than
     * erroring — a CC to yourself is a habit from Outlook, not a mistake,
     * and your own copy is already in Sent.
     *
     * @return array{to: array<int, AdminUser>, cc: array<int, AdminUser>}|JsonResponse
     */
    private function resolveRecipients(array $to, array $cc, AdminUser $me): array|JsonResponse
    {
        $to = array_values(array_diff(array_unique(array_map('intval', $to)), [$me->id]));
        $cc = array_values(array_diff(array_unique(array_map('intval', $cc)), [$me->id], $to));

        if ($to === []) {
            return response()->json([
                'message' => 'Choose at least one colleague to send this to.',
                'code'    => 'no_recipients',
            ], 422);
        }

        $found = AdminUser::whereIn('id', array_merge($to, $cc))
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $missing = array_values(array_diff(array_merge($to, $cc), $found->keys()->all()));

        if ($missing !== []) {
            return response()->json([
                'message' => 'One or more recipients are not active staff accounts.',
                'code'    => 'unknown_recipient',
                'errors'  => ['to' => ['Unknown or deactivated admin id(s): ' . implode(', ', $missing)]],
            ], 422);
        }

        return [
            'to' => array_values(array_map(fn ($i) => $found[$i], $to)),
            'cc' => array_values(array_map(fn ($i) => $found[$i], $cc)),
        ];
    }

    /**
     * @return array<int, array{name:string, path:string, mime:string, size:int}>
     */
    private function storeUploads(Request $request): array
    {
        $meta = [];

        foreach ($request->file('attachments', []) as $file) {
            $meta[] = [
                'name' => $file->getClientOriginalName(),
                'path' => $file->store('staff-messages/' . now()->format('Y/m'), 'local'),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ];
        }

        return $meta;
    }

    /**
     * @return array<int, array{name:string, path:string, mime:string, size:int}>
     */
    private function copyForwardedAttachments(CustomerCommunication $comm): array
    {
        $copied = [];

        foreach ($comm->attachments ?? [] as $a) {
            if (! isset($a['path']) || ! Storage::disk('local')->exists($a['path'])) {
                continue;
            }

            $destination = 'staff-messages/' . now()->format('Y/m') . '/' . Str::uuid()
                . '.' . pathinfo($a['path'], PATHINFO_EXTENSION);

            try {
                Storage::disk('local')->copy($a['path'], $destination);
            } catch (\Throwable $e) {
                // A missing attachment must not sink the forward — the note
                // and the quoted body are still worth delivering.
                Log::warning('[staff_message_forward_attachment_skipped] Could not copy attachment', [
                    'event'            => 'staff_message_forward_attachment_skipped',
                    'communication_id' => $comm->id,
                    'error'            => $e->getMessage(),
                ]);

                continue;
            }

            $copied[] = [
                'name' => $a['name'] ?? 'attachment',
                'path' => $destination,
                'mime' => $a['mime'] ?? 'application/octet-stream',
                'size' => $a['size'] ?? 0,
            ];
        }

        return $copied;
    }

    private function buildQuotedOriginal(CustomerCommunication $comm): string
    {
        $who  = $this->correspondentName($comm);
        $when = $comm->created_at?->format('D, j M Y \a\t H:i') ?? 'an earlier date';
        $dir  = $comm->direction === 'inbound' ? 'From' : 'To';

        return '<div style="margin-top:24px;padding-top:16px;border-top:1px solid #eeeeee;">'
            . '<p style="color:#5c5e62;font-size:13px;margin:0 0 12px 0;">'
            . '---------- Forwarded message ----------<br>'
            . e($dir) . ': ' . e($who) . '<br>'
            . 'Date: ' . e($when) . '<br>'
            . 'Subject: ' . e($comm->subject ?: '(no subject)')
            . '</p>'
            . $comm->body
            . '</div>';
    }

    private function forwardContextLine(CustomerCommunication $comm): string
    {
        return 'Forwarded from correspondence with ' . $this->correspondentName($comm) . '.';
    }

    private function correspondentName(CustomerCommunication $comm): string
    {
        if ($comm->customer) {
            return $comm->customer->company_name
                ?: trim($comm->customer->first_name . ' ' . $comm->customer->last_name)
                ?: ($comm->customer->email ?? 'a customer');
        }

        return $comm->quoteRequest->full_name ?? 'a customer';
    }

    /** Same markup as the customer composer, so both threads render alike. */
    private function appendSignature(string $bodyHtml, ?string $signatureHtml): string
    {
        if (! $signatureHtml) {
            return $bodyHtml;
        }

        return $bodyHtml
            . '<div style="margin-top:24px;padding-top:16px;border-top:1px solid #eeeeee;">'
            . $signatureHtml . '</div>';
    }

    private function canSee(StaffMessage $message, AdminUser $me): bool
    {
        if ($message->sender_admin_id === $me->id) {
            return true;
        }

        return $message->recipients->contains(fn (StaffMessageRecipient $r) => $r->admin_user_id === $me->id);
    }

    private function unreadCountFor(AdminUser $me): int
    {
        return StaffMessageRecipient::where('admin_user_id', $me->id)->whereNull('read_at')->count();
    }

    private function formatRow(StaffMessage $m, AdminUser $me): array
    {
        $mine = $m->recipients->firstWhere('admin_user_id', $me->id);

        return [
            'id'           => $m->id,
            'thread_id'    => $m->thread_id,
            'subject'      => $m->subject,
            'preview'      => Str::limit(strip_tags((string) $m->body), 140),
            'sender'       => $this->personLabel($m->sender, $m->sender_label),
            'recipients'   => $m->recipients->map(fn ($r) => $this->personLabel($r->adminUser, null, $r->kind))->values(),
            'is_forward'   => $m->isForward(),
            'has_attachments' => ! empty($m->attachments),
            'unread'       => $mine ? $mine->read_at === null : false,
            'created_at'   => $m->created_at?->toIso8601String(),
        ];
    }

    private function format(StaffMessage $m, AdminUser $me): array
    {
        $mine = $m->recipients->firstWhere('admin_user_id', $me->id);

        return [
            'id'         => $m->id,
            'thread_id'  => $m->thread_id,
            'subject'    => $m->subject,
            'body'       => $m->body,
            'sender'     => $this->personLabel($m->sender, $m->sender_label),
            'sent_by_me' => $m->sender_admin_id === $me->id,
            'recipients' => $m->recipients->map(fn (StaffMessageRecipient $r) => array_merge(
                $this->personLabel($r->adminUser, null, $r->kind),
                [
                    'read_at'      => $r->read_at?->toIso8601String(),
                    // Only the sender needs to know whether the e-mail copy
                    // landed; to everyone else it is noise about someone
                    // else's mailbox.
                    'email_status' => $m->sender_admin_id === $me->id ? $r->email_status : null,
                ]
            ))->values(),
            'attachments' => collect($m->attachments ?? [])->map(fn ($a, $i) => [
                'name'         => $a['name'] ?? null,
                'mime'         => $a['mime'] ?? null,
                'size'         => $a['size'] ?? null,
                'download_url' => url("/api/v1/admin/staff-messages/{$m->id}/attachments/{$i}/download"),
            ])->values(),
            'is_forward'     => $m->isForward(),
            'forwarded_from' => $m->isForward() ? [
                'communication_id' => $m->forwarded_from_communication_id,
                'customer_id'      => $m->forwarded_from_customer_id,
                'quote_request_id' => $m->forwarded_from_quote_request_id,
                'action_url'       => $m->forwarded_from_customer_id
                    ? "/admin/customers/{$m->forwarded_from_customer_id}?tab=communications"
                    : ($m->forwarded_from_quote_request_id ? "/admin/quotes/{$m->forwarded_from_quote_request_id}" : null),
            ] : null,
            'in_reply_to_id' => $m->in_reply_to_id,
            'unread'         => $mine ? $mine->read_at === null : false,
            'created_at'     => $m->created_at?->toIso8601String(),
        ];
    }

    private function personLabel(?AdminUser $a, ?string $fallback = null, ?string $kind = null): array
    {
        $base = [
            'id'    => $a?->id,
            'name'  => $a ? (trim($a->display_name ?: $a->name) ?: $a->email) : ($fallback ?: 'A former colleague'),
            'email' => $a?->email,
        ];

        return $kind ? $base + ['kind' => $kind] : $base;
    }
}

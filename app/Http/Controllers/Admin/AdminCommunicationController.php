<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\CustomerAdHocEmail;
use App\Models\Customer;
use App\Models\CustomerCommunication;
use App\Models\QuoteRequest;
use App\Services\CustomerNotifier;
use App\Services\RichEmailHtmlSanitizer;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminCommunicationController extends Controller
{
    // ── GET /admin/customers/{id}/communications ─────────────────────────────

    public function indexForCustomer(int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $comms = CustomerCommunication::where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data'    => $comms->map(fn ($c) => $this->format($c))->values(),
            'message' => 'success',
        ]);
    }

    // ── GET /admin/communications/inbox ───────────────────────────────────────
    // Unified feed of every inbound e-mail (Cloudflare webhook or portal
    // reply) across all customers/leads, ordered most-recent-first, so
    // admins can triage new replies without opening each customer's profile
    // individually. staff_read_at (already used per-conversation by
    // markRead()) doubles as the unread flag here.

    public function indexInbox(Request $request): JsonResponse
    {
        $request->validate([
            'unread'   => ['sometimes'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = CustomerCommunication::query()
            ->where('type', 'email')
            ->where('direction', 'inbound')
            ->with([
                'customer:id,first_name,last_name,company_name,customer_type',
                'quoteRequest:id,full_name,email',
            ])
            ->orderByDesc('created_at');

        if ($request->boolean('unread')) {
            $query->whereNull('staff_read_at');
        }

        $paginated = $query->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => collect($paginated->items())->map(fn ($c) => $this->formatInboxRow($c))->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
                'unread_count' => CustomerCommunication::where('type', 'email')
                    ->where('direction', 'inbound')
                    ->whereNull('staff_read_at')
                    ->count(),
            ],
            'message' => 'success',
        ]);
    }

    // ── GET /admin/quote-requests/{id}/communications ────────────────────────

    public function indexForQuote(int $id): JsonResponse
    {
        $quote = QuoteRequest::findOrFail($id);

        $comms = CustomerCommunication::where('quote_request_id', $quote->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data'    => $comms->map(fn ($c) => $this->format($c))->values(),
            'message' => 'success',
        ]);
    }

    // ── POST /admin/customers/{id}/communications ────────────────────────────

    public function storeForCustomer(Request $request, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        $data = $this->validateCommunicationInput($request);

        $comm = CustomerCommunication::create(array_merge($data, [
            'customer_id'  => $customer->id,
            'admin_user_id' => $request->user()?->id,
            'completed_at' => isset($data['scheduled_at']) ? null : now(),
            'status'       => isset($data['scheduled_at']) ? 'planned' : 'completed',
        ]));

        Log::info('[communication_logged] Communication logged for customer', [
            'event'       => 'communication_logged',
            'customer_id' => $customer->id,
            'type'        => $comm->type,
            'by_admin'    => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->format($comm),
            'message' => 'Communication logged.',
        ], 201);
    }

    // ── POST /admin/quote-requests/{id}/communications ───────────────────────

    public function storeForQuote(Request $request, int $id): JsonResponse
    {
        $quote = QuoteRequest::findOrFail($id);

        $data = $this->validateCommunicationInput($request);

        $comm = CustomerCommunication::create(array_merge($data, [
            'quote_request_id' => $quote->id,
            'customer_id'      => $quote->customer_id,
            'admin_user_id'    => $request->user()?->id,
            'completed_at'     => isset($data['scheduled_at']) ? null : now(),
            'status'           => isset($data['scheduled_at']) ? 'planned' : 'completed',
        ]));

        Log::info('[communication_logged] Communication logged for quote', [
            'event'     => 'communication_logged',
            'quote_ref' => $quote->ref_number,
            'type'      => $comm->type,
            'by_admin'  => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->format($comm),
            'message' => 'Communication logged.',
        ], 201);
    }

    // ── Outlook-style compose/reply ───────────────────────────────────────────

    // POST /admin/customers/{id}/communications/send-email
    public function sendEmailForCustomer(Request $request, RichEmailHtmlSanitizer $sanitizer, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        if (empty($customer->email)) {
            return response()->json([
                'message' => 'This customer has no e-mail address on file.',
                'code'    => 'missing_recipient_email',
            ], 422);
        }

        return $this->composeAndSend(
            $request, $sanitizer,
            ['customer_id' => $customer->id, 'quote_request_id' => null],
            $customer->email, $customer->full_name
        );
    }

    // POST /admin/quote-requests/{id}/communications/send-email
    public function sendEmailForQuote(Request $request, RichEmailHtmlSanitizer $sanitizer, int $id): JsonResponse
    {
        $quote = QuoteRequest::findOrFail($id);

        if (empty($quote->email)) {
            return response()->json([
                'message' => 'This quote has no e-mail address on file.',
                'code'    => 'missing_recipient_email',
            ], 422);
        }

        return $this->composeAndSend(
            $request, $sanitizer,
            ['customer_id' => $quote->customer_id, 'quote_request_id' => $quote->id],
            $quote->email, $quote->full_name ?? 'valued customer'
        );
    }

    /**
     * Shared by both contexts above. Sanitizes the pasted body (and any
     * inline images) via RichEmailHtmlSanitizer, stores attachments before
     * attempting to send (so a failed send never loses them), sends the
     * real e-mail, and always logs a CustomerCommunication row — on success
     * or failure — so nothing about the attempt is ever silently lost.
     */
    private function composeAndSend(
        Request $request,
        RichEmailHtmlSanitizer $sanitizer,
        array $context,
        string $recipientEmail,
        string $recipientName
    ): JsonResponse {
        $data = $request->validate([
            'subject'        => ['required', 'string', 'max:300'],
            'body'           => ['required', 'string', 'max:512000'],
            'cc'             => ['sometimes', 'array', 'max:5'],
            'cc.*'           => ['email', 'max:255', 'distinct'],
            'in_reply_to_id' => ['nullable', 'integer'],
            'attachments'    => ['sometimes', 'array', 'max:5'],
            'attachments.*'  => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,csv'],
        ]);

        // The parent message must belong to the same customer thread —
        // otherwise a stray id can't be used to forge a fake reply chain.
        $parent = null;
        if (! empty($data['in_reply_to_id'])) {
            $candidate = CustomerCommunication::find($data['in_reply_to_id']);
            if ($candidate && $context['customer_id'] && $candidate->customer_id === $context['customer_id']) {
                $parent = $candidate;
            }
        }

        $subject = $data['subject'];
        if ($parent && ! preg_match('/^re:/i', $subject)) {
            $subject = 'Re: ' . $subject;
        }

        try {
            $bodyClean = $sanitizer->sanitize($data['body'], 'communications/' . Str::uuid());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Store attachments before sending — a failed send below still keeps
        // the files, and the communication log stays a true record either way.
        // Wrapped: a storage failure here must surface as a clear error, not
        // a raw 500, and must never silently drop an attachment the admin
        // explicitly added.
        $attachmentMeta  = [];
        $attachmentFiles = [];
        try {
            foreach ($request->file('attachments', []) as $file) {
                $storedPath = $file->store('communications/' . now()->format('Y/m'), 'local');

                $attachmentMeta[] = [
                    'name' => $file->getClientOriginalName(),
                    'path' => $storedPath,
                    'mime' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                ];
                $attachmentFiles[] = [
                    'path' => storage_path('app/private/' . $storedPath),
                    'name' => $file->getClientOriginalName(),
                    'mime' => $file->getClientMimeType(),
                ];
            }
        } catch (\Throwable $e) {
            Log::error('[communication_attachment_store_failed] Could not store attachment', [
                'event' => 'communication_attachment_store_failed',
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'One of the attachments could not be saved. Please try again.',
                'code'    => 'attachment_store_failed',
            ], 502);
        }

        $messageId = Str::uuid()->toString() . '@' . config('services.mail_inbound.message_id_domain', 'okelcor.com');
        $sender    = $request->user();
        $cc        = array_values(array_unique($data['cc'] ?? []));

        $status = 'sent';
        $error  = null;

        try {
            Mail::to($recipientEmail)
                ->send(new CustomerAdHocEmail(
                    sender: $sender,
                    subjectLine: $subject,
                    bodyHtml: $bodyClean,
                    ccRecipients: $cc,
                    attachmentFiles: $attachmentFiles,
                    messageId: $messageId,
                    inReplyTo: $parent?->message_id,
                ));

            Log::info('[communication_email_sent] Ad-hoc customer e-mail sent', [
                'event'    => 'communication_email_sent',
                'to'       => $recipientEmail,
                'by_admin' => $sender?->id,
            ]);
        } catch (\Throwable $e) {
            $status = 'failed';
            $error  = $e->getMessage();

            Log::error('[communication_email_failed] Ad-hoc customer e-mail failed', [
                'event'    => 'communication_email_failed',
                'to'       => $recipientEmail,
                'error'    => $error,
                'by_admin' => $sender?->id,
            ]);
        }

        // The e-mail attempt itself (sent or failed) is already final at this
        // point — a failure to WRITE the log entry below must never turn
        // into a 500 that hides whether the e-mail actually went out.
        $comm      = null;
        $logFailed = null;
        try {
            $comm = CustomerCommunication::create(array_merge($context, [
                'admin_user_id' => $sender?->id,
                'type'          => 'email',
                'direction'     => 'outbound',
                'channel'       => 'email',
                'subject'       => $subject,
                'body'          => $bodyClean,
                'cc'            => $cc ?: null,
                'attachments'   => $attachmentMeta ?: null,
                'message_id'    => $messageId,
                'in_reply_to'   => $parent?->message_id,
                'status'        => $status,
                'completed_at'  => $status === 'sent' ? now() : null,
                'metadata'      => $error ? ['error' => $error] : null,
            ]));
        } catch (\Throwable $e) {
            $logFailed = $e->getMessage();
            Log::error('[communication_log_write_failed] Could not save communication record', [
                'event'          => 'communication_log_write_failed',
                'error'          => $logFailed,
                'send_status'    => $status,
                'recipient'      => $recipientEmail,
            ]);
        }

        if ($status === 'sent' && $comm) {
            // "Email = Inbox" — the customer also sees this in their portal
            // bell, same as every other transactional e-mail in this app.
            CustomerNotifier::notifyByEmail($recipientEmail, 'message_received', $subject,
                'You have a new message from Okelcor.', [
                    'severity'     => 'info',
                    'action_url'   => '/account/messages',
                    'related_type' => 'customer_communication',
                    'related_id'   => $comm->id,
                ]
            );
        }

        if ($status === 'failed') {
            return response()->json([
                'success' => false,
                'message' => 'E-mail could not be sent.' . ($comm ? ' The message was logged so nothing is lost.' : ''),
                'code'    => 'email_send_failed',
                'error'   => $error,
                'data'    => $comm ? $this->format($comm) : null,
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data'    => $comm ? $this->format($comm) : null,
            'message' => "E-mail sent to {$recipientEmail}."
                . (! $comm ? ' (Note: the record could not be saved to the communication log — error: ' . $logFailed . ')' : ''),
        ], 201);
    }

    // ── WhatsApp compose/reply ────────────────────────────────────────────────

    // POST /admin/customers/{id}/communications/send-whatsapp
    public function sendWhatsAppForCustomer(Request $request, WhatsAppService $whatsapp, int $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        if (empty($customer->phone)) {
            return response()->json([
                'message' => 'This customer has no phone number on file.',
                'code'    => 'missing_recipient_phone',
            ], 422);
        }

        return $this->composeAndSendWhatsApp(
            $request, $whatsapp,
            ['customer_id' => $customer->id, 'quote_request_id' => null],
            $customer->phone
        );
    }

    // POST /admin/quote-requests/{id}/communications/send-whatsapp
    public function sendWhatsAppForQuote(Request $request, WhatsAppService $whatsapp, int $id): JsonResponse
    {
        $quote = QuoteRequest::findOrFail($id);

        if (empty($quote->phone)) {
            return response()->json([
                'message' => 'This inquiry has no phone number on file.',
                'code'    => 'missing_recipient_phone',
            ], 422);
        }

        return $this->composeAndSendWhatsApp(
            $request, $whatsapp,
            ['customer_id' => $quote->customer_id, 'quote_request_id' => $quote->id],
            $quote->phone
        );
    }

    /**
     * Free-form text only — WhatsApp only allows this within the 24h
     * customer-service window (the customer must have messaged in the last
     * 24h). Outside that window, Meta rejects the send; the error is
     * surfaced as-is so the UI can suggest a template message instead
     * (there is no general-purpose "send any text as a template" — see
     * WHATSAPP_SETUP.md for how templates get created).
     */
    private function composeAndSendWhatsApp(Request $request, WhatsAppService $whatsapp, array $context, string $phone): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:4096'], // WhatsApp's own text message cap
        ]);

        $result = $whatsapp->sendText($phone, $data['body']);
        $sent   = ! isset($result['error']);

        $comm = null;
        try {
            $comm = CustomerCommunication::create(array_merge($context, [
                'admin_user_id'       => $request->user()?->id,
                'phone_number'        => $phone,
                'type'                => 'whatsapp',
                'direction'           => 'outbound',
                'channel'             => 'whatsapp',
                'body'                => $data['body'],
                'whatsapp_message_id' => $result['message_id'] ?? null,
                'whatsapp_status'     => $sent ? 'sent' : 'failed',
                'status'              => $sent ? 'sent' : 'failed',
                'completed_at'        => $sent ? now() : null,
                'metadata'            => $sent ? null : ['error' => $result['error']],
            ]));
        } catch (\Throwable $e) {
            Log::error('[whatsapp_log_write_failed] Could not save WhatsApp communication record', [
                'error' => $e->getMessage(),
                'phone' => $phone,
            ]);
        }

        if (! $sent) {
            return response()->json([
                'success' => false,
                'message' => 'WhatsApp message could not be sent: ' . $result['error'],
                'code'    => 'whatsapp_send_failed',
                'data'    => $comm ? $this->format($comm) : null,
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data'    => $comm ? $this->format($comm) : null,
            'message' => "WhatsApp message sent to {$phone}.",
        ], 201);
    }

    // POST /admin/communications/{id}/read
    public function markRead(int $id): JsonResponse
    {
        $comm = CustomerCommunication::findOrFail($id);

        if (! $comm->staff_read_at) {
            $comm->update(['staff_read_at' => now()]);
        }

        return response()->json(['data' => $this->format($comm->fresh()), 'message' => 'success']);
    }

    // GET /admin/communications/{id}/attachments/{index}/download
    public function downloadAttachment(int $id, int $index)
    {
        $comm        = CustomerCommunication::findOrFail($id);
        $attachments = $comm->attachments ?? [];

        if (! isset($attachments[$index]['path']) || ! Storage::disk('local')->exists($attachments[$index]['path'])) {
            abort(404);
        }

        return Storage::disk('local')->download($attachments[$index]['path'], $attachments[$index]['name']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function validateCommunicationInput(Request $request): array
    {
        return $request->validate([
            'type'         => ['required', 'in:email,call,whatsapp,note,system'],
            'direction'    => ['required', 'in:inbound,outbound,internal'],
            'subject'      => ['nullable', 'string', 'max:300'],
            'body'         => ['nullable', 'string', 'max:10000'],
            'scheduled_at' => ['nullable', 'date'],
        ]);
    }

    private function formatInboxRow(CustomerCommunication $c): array
    {
        $customerName = $c->customer
            ? ($c->customer->company_name ?: trim($c->customer->first_name . ' ' . $c->customer->last_name))
            : ($c->quoteRequest->full_name ?? null);

        return [
            'id'               => $c->id,
            'customer_id'      => $c->customer_id,
            'quote_request_id' => $c->quote_request_id,
            'customer_name'    => $customerName,
            'channel'          => $c->channel,
            'subject'          => $c->subject,
            'preview'          => Str::limit(strip_tags((string) $c->body), 140),
            'unread'           => $c->staff_read_at === null,
            'action_url'       => $c->customer_id
                ? "/admin/customers/{$c->customer_id}?tab=communications"
                : ($c->quote_request_id ? "/admin/quotes/{$c->quote_request_id}" : null),
            'created_at'       => $c->created_at?->toIso8601String(),
        ];
    }

    private function format(CustomerCommunication $c): array
    {
        return [
            'id'               => $c->id,
            'type'             => $c->type,
            'direction'        => $c->direction,
            'channel'          => $c->channel,
            'subject'          => $c->subject,
            'body'             => $c->body,
            'cc'               => $c->cc ?? [],
            'attachments'      => collect($c->attachments ?? [])->map(fn ($a, $i) => [
                'name'          => $a['name'] ?? null,
                'mime'          => $a['mime'] ?? null,
                'size'          => $a['size'] ?? null,
                'download_url'  => url("/api/v1/admin/communications/{$c->id}/attachments/{$i}/download"),
            ])->values(),
            'message_id'       => $c->message_id,
            'in_reply_to'      => $c->in_reply_to,
            'phone_number'     => $c->phone_number,
            'whatsapp_message_id'    => $c->whatsapp_message_id,
            'whatsapp_status'        => $c->whatsapp_status,
            'whatsapp_template_name' => $c->whatsapp_template_name,
            'status'           => $c->status,
            'admin_user_id'    => $c->admin_user_id,
            'customer_id'      => $c->customer_id,
            'quote_request_id' => $c->quote_request_id,
            'scheduled_at'     => $c->scheduled_at?->toIso8601String(),
            'completed_at'     => $c->completed_at?->toIso8601String(),
            'staff_read_at'    => $c->staff_read_at?->toIso8601String(),
            'customer_read_at' => $c->customer_read_at?->toIso8601String(),
            'created_at'       => $c->created_at?->toIso8601String(),
        ];
    }
}

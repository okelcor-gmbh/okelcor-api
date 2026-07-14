<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\CustomerAdHocEmail;
use App\Models\Customer;
use App\Models\CustomerCommunication;
use App\Models\QuoteRequest;
use App\Services\CustomerNotifier;
use App\Services\RichEmailHtmlSanitizer;
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
        $attachmentMeta  = [];
        $attachmentFiles = [];
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

        $messageId = Str::uuid()->toString() . '@okelcor.com';
        $sender    = $request->user();
        $cc        = array_values(array_unique($data['cc'] ?? []));

        $status = 'sent';
        $error  = null;

        try {
            Mail::to($recipientEmail)
                ->cc($cc)
                ->send(new CustomerAdHocEmail(
                    sender: $sender,
                    subjectLine: $subject,
                    bodyHtml: $bodyClean,
                    cc: $cc,
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

        if ($status === 'sent') {
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
                'message' => 'E-mail could not be sent. The message was logged so nothing is lost.',
                'code'    => 'email_send_failed',
                'error'   => $error,
                'data'    => $this->format($comm),
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->format($comm),
            'message' => "E-mail sent to {$recipientEmail}.",
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

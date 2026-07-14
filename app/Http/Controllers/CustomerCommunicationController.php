<?php

namespace App\Http\Controllers;

use App\Models\CustomerCommunication;
use App\Services\AdminNotificationService;
use App\Services\RichEmailHtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Customer-portal side of the Outlook-style compose/reply feature — the
 * "two-way visibility" piece: a reply made here lands back in the system
 * immediately (fanned out to every crm.view admin, not just whoever sent the
 * original message), without needing a full inbound-e-mail-capture pipeline
 * (receiving subdomain + MX record + webhook). Real inbound e-mail capture
 * — an actual reply from the customer's own mail client routing back in —
 * is a separate, bigger phase and is NOT built here; see
 * FRONTEND_NOTE_outlook-style-email.md.
 *
 *   GET  /api/v1/auth/customer/communications
 *   POST /api/v1/auth/customer/communications/{id}/reply
 *   POST /api/v1/auth/customer/communications/{id}/read
 *   GET  /api/v1/auth/customer/communications/{id}/attachments/{index}/download
 *
 * Scoped strictly to the logged-in customer's own customer_id, and to
 * type=email rows only — internal call/note/system log entries never
 * surface in the portal.
 */
class CustomerCommunicationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customer = $request->user();

        $comms = CustomerCommunication::where('customer_id', $customer->id)
            ->where('type', 'email')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $comms->map(fn ($c) => $this->format($c))->values(),
            'meta' => [
                'unread_count' => $comms->where('direction', 'outbound')->whereNull('customer_read_at')->count(),
            ],
            'message' => 'success',
        ]);
    }

    public function reply(Request $request, RichEmailHtmlSanitizer $sanitizer, int $id): JsonResponse
    {
        $customer = $request->user();

        $parent = CustomerCommunication::where('customer_id', $customer->id)
            ->where('type', 'email')
            ->findOrFail($id);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:51200'],
        ]);

        try {
            $bodyClean = $sanitizer->sanitize($data['body'], 'communications/' . Str::uuid());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $subject = $parent->subject ?: 'Message from ' . $customer->full_name;
        if (! preg_match('/^re:/i', $subject)) {
            $subject = 'Re: ' . $subject;
        }

        $comm = CustomerCommunication::create([
            'customer_id'      => $customer->id,
            'quote_request_id' => $parent->quote_request_id,
            'order_id'         => $parent->order_id,
            'type'             => 'email',
            'direction'        => 'inbound',
            'channel'          => 'portal',
            'subject'          => $subject,
            'body'             => $bodyClean,
            'message_id'       => Str::uuid()->toString() . '@okelcor.com',
            'in_reply_to'      => $parent->message_id,
            'status'           => 'completed',
            'completed_at'     => now(),
        ]);

        // Fan out to every admin who can see the CRM inbox — not just the
        // original sender — so a reply is never missed because one person
        // is out sick or has left.
        $customerName = $customer->company_name ?: trim($customer->first_name . ' ' . $customer->last_name);
        AdminNotificationService::notifyPermission(
            permission:  'crm.view',
            type:        'customer_message_reply',
            title:       'Customer replied',
            body:        sprintf('%s replied: %s', $customerName, $subject),
            actionUrl:   "/admin/customers/{$customer->id}?tab=communications",
            severity:    'info',
            relatedType: 'customer_communication',
            relatedId:   $comm->id,
        );

        return response()->json([
            'data'    => $this->format($comm),
            'message' => 'Reply sent.',
        ], 201);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();

        $comm = CustomerCommunication::where('customer_id', $customer->id)
            ->where('type', 'email')
            ->where('direction', 'outbound')
            ->findOrFail($id);

        if (! $comm->customer_read_at) {
            $comm->update(['customer_read_at' => now()]);
        }

        return response()->json(['data' => $this->format($comm->fresh()), 'message' => 'success']);
    }

    public function downloadAttachment(Request $request, int $id, int $index)
    {
        $customer = $request->user();

        $comm = CustomerCommunication::where('customer_id', $customer->id)
            ->where('type', 'email')
            ->findOrFail($id);

        $attachments = $comm->attachments ?? [];

        if (! isset($attachments[$index]['path']) || ! Storage::disk('local')->exists($attachments[$index]['path'])) {
            abort(404);
        }

        return Storage::disk('local')->download($attachments[$index]['path'], $attachments[$index]['name']);
    }

    private function format(CustomerCommunication $c): array
    {
        return [
            'id'          => $c->id,
            'direction'   => $c->direction,
            'channel'     => $c->channel,
            'subject'     => $c->subject,
            'body'        => $c->body,
            'attachments' => collect($c->attachments ?? [])->map(fn ($a, $i) => [
                'name'         => $a['name'] ?? null,
                'mime'         => $a['mime'] ?? null,
                'size'         => $a['size'] ?? null,
                'download_url' => url("/api/v1/auth/customer/communications/{$c->id}/attachments/{$i}/download"),
            ])->values(),
            'in_reply_to' => $c->in_reply_to,
            'read'        => $c->direction === 'outbound' ? (bool) $c->customer_read_at : null,
            'created_at'  => $c->created_at?->toIso8601String(),
        ];
    }
}

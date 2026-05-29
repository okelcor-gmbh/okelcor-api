<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\CrmFollowUpEmail;
use App\Models\CustomerCommunication;
use App\Models\QuoteRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AdminCrmEmailController extends Controller
{
    private const TEMPLATES = [
        'follow_up_quote' => [
            'name'    => 'Follow Up on Quote',
            'subject' => 'Following up on your tyre inquiry — {ref}',
            'body'    => "Dear {name},\n\nWe wanted to follow up on your recent tyre inquiry ({ref}).\n\nOur team is ready to assist you. Please let us know if you have updated requirements, preferred brands, or delivery timelines.\n\nWe look forward to hearing from you.",
        ],
        'request_more_information' => [
            'name'    => 'Request More Information',
            'subject' => 'We need a few more details about your enquiry — {ref}',
            'body'    => "Dear {name},\n\nThank you for your tyre enquiry ({ref}).\n\nTo prepare an accurate quote, we need a few more details:\n- Exact tyre sizes required\n- Estimated quantity\n- Preferred delivery date\n- Destination country\n\nPlease reply with the above information and we will respond promptly.",
        ],
        'invite_to_register' => [
            'name'    => 'Invite to Register',
            'subject' => 'Create your Okelcor business account',
            'body'    => "Dear {name},\n\nThank you for your interest in Okelcor's wholesale tyre exports.\n\nAs a B2B buyer, we'd like to invite you to create a business account with us. This will allow you to track your orders, view invoices, and access our full wholesale catalogue.\n\nPlease contact us or reply to this email to get started.",
        ],
        'quote_ready' => [
            'name'    => 'Quote Ready',
            'subject' => 'Your Okelcor quote is ready — {ref}',
            'body'    => "Dear {name},\n\nYour tyre quote ({ref}) has been prepared and is ready for your review.\n\nPlease log in to your account or contact us directly to review the quote and confirm your order.\n\nThis offer is valid for 7 business days.",
        ],
        'payment_reminder' => [
            'name'    => 'Payment Reminder',
            'subject' => 'Payment reminder for your Okelcor order — {ref}',
            'body'    => "Dear {name},\n\nThis is a friendly reminder that payment is outstanding for your order ({ref}).\n\nPlease arrange payment at your earliest convenience to avoid delays to your shipment. If you have any questions about payment options, please contact us.",
        ],
        'document_available' => [
            'name'    => 'Document Available',
            'subject' => 'Your document is ready — {ref}',
            'body'    => "Dear {name},\n\nA document related to your order ({ref}) is now available for download in your Okelcor account.\n\nPlease log in to access it. If you need assistance, reply to this email.",
        ],
    ];

    // ── GET /admin/crm/email-templates ───────────────────────────────────────

    public function templates(): JsonResponse
    {
        $list = collect(self::TEMPLATES)->map(fn ($t, $key) => [
            'key'     => $key,
            'name'    => $t['name'],
            'subject' => $t['subject'],
            'body'    => $t['body'],
        ])->values();

        return response()->json(['data' => $list, 'message' => 'success']);
    }

    // ── POST /admin/quote-requests/{id}/send-follow-up-email ─────────────────

    public function sendFollowUpEmail(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'template'       => ['required', 'string', 'in:' . implode(',', array_keys(self::TEMPLATES))],
            'message'        => ['nullable', 'string', 'max:5000'],
            'custom_subject' => ['nullable', 'string', 'max:300'],
        ]);

        $quote    = QuoteRequest::findOrFail($id);
        $template = self::TEMPLATES[$data['template']];

        // Resolve recipient
        $recipientEmail = $quote->email;
        $recipientName  = $quote->full_name ?? 'valued customer';
        $ref            = $quote->ref_number;

        // Render subject + body with placeholders
        $subject = str_replace(['{ref}', '{name}'], [$ref, $recipientName],
            $data['custom_subject'] ?? $template['subject']
        );

        $body = str_replace(['{ref}', '{name}'], [$ref, $recipientName], $template['body']);

        if (! empty($data['message'])) {
            $body .= "\n\n---\n" . $data['message'];
        }

        // Send
        $status = 'sent';
        $error  = null;

        try {
            Mail::to($recipientEmail)->send(new CrmFollowUpEmail(
                recipientName: $recipientName,
                subject: $subject,
                body: $body,
                ref: $ref,
            ));

            Log::info('[crm_email_sent] CRM follow-up email sent', [
                'event'     => 'crm_email_sent',
                'quote_ref' => $ref,
                'template'  => $data['template'],
                'to'        => $recipientEmail,
                'by_admin'  => $request->user()?->id,
            ]);
        } catch (\Throwable $e) {
            $status = 'failed';
            $error  = $e->getMessage();

            Log::error('[crm_email_failed] CRM follow-up email failed', [
                'event'     => 'crm_email_failed',
                'quote_ref' => $ref,
                'template'  => $data['template'],
                'to'        => $recipientEmail,
                'error'     => $error,
                'by_admin'  => $request->user()?->id,
            ]);
        }

        // Always log the communication, even on failure
        CustomerCommunication::create([
            'quote_request_id' => $quote->id,
            'customer_id'      => $quote->customer_id,
            'admin_user_id'    => $request->user()?->id,
            'type'             => 'email',
            'direction'        => 'outbound',
            'subject'          => $subject,
            'body'             => $body,
            'status'           => $status,
            'completed_at'     => $status === 'sent' ? now() : null,
            'metadata'         => [
                'template'  => $data['template'],
                'recipient' => $recipientEmail,
                'error'     => $error,
            ],
        ]);

        if ($status === 'failed') {
            return response()->json([
                'success' => false,
                'message' => 'Email failed to send but the attempt has been logged.',
                'error'   => $error,
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => "Follow-up email ({$data['template']}) sent to {$recipientEmail}.",
        ]);
    }
}

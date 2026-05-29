<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerCommunication;
use App\Models\QuoteRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminCrmFollowUpController extends Controller
{
    private const CLOSED_STATUSES = ['converted', 'closed', 'spam', 'rejected'];

    // ── GET /admin/crm/follow-ups ────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'due'                  => ['nullable', 'in:today,overdue,upcoming'],
            'assigned_to'          => ['nullable', 'integer'],
            'qualification_status' => ['nullable', 'string'],
            'customer_id'          => ['nullable', 'integer'],
            'per_page'             => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $now   = now();
        $today = $now->toDateString();

        $query = QuoteRequest::whereNotNull('follow_up_at')
            ->whereNotIn('qualification_status', self::CLOSED_STATUSES)
            ->orderBy('follow_up_at');

        // Due filter
        if ($request->filled('due')) {
            match ($request->due) {
                'today'    => $query->whereDate('follow_up_at', $today),
                'overdue'  => $query->where('follow_up_at', '<', $now)->whereDate('follow_up_at', '!=', $today),
                'upcoming' => $query->where('follow_up_at', '>', $now->endOfDay()),
                default    => null,
            };
        }

        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->integer('assigned_to'));
        }

        if ($request->filled('qualification_status')) {
            $query->where('qualification_status', $request->qualification_status);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->integer('customer_id'));
        }

        $paginated = $query->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => $paginated->map(fn ($q) => $this->formatFollowUp($q))->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
            ],
            'message' => 'success',
        ]);
    }

    // ── POST /admin/crm/follow-ups/{id}/complete ─────────────────────────────

    public function complete(Request $request, int $id): JsonResponse
    {
        $data  = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $quote = QuoteRequest::findOrFail($id);

        $prevFollowUpAt = $quote->follow_up_at;

        $quote->update([
            'follow_up_at'          => null,
            'follow_up_completed_at' => now(),
            'follow_up_completed_by' => $request->user()?->id,
        ]);

        // Log communication entry
        CustomerCommunication::create([
            'quote_request_id' => $quote->id,
            'customer_id'      => $quote->customer_id,
            'admin_user_id'    => $request->user()?->id,
            'type'             => 'note',
            'direction'        => 'internal',
            'subject'          => 'Follow-up completed',
            'body'             => $data['note'] ?? 'Follow-up marked as completed.',
            'status'           => 'completed',
            'completed_at'     => now(),
            'metadata'         => ['previous_follow_up_at' => $prevFollowUpAt?->toIso8601String()],
        ]);

        Log::info('[follow_up_completed] Follow-up marked complete', [
            'event'            => 'follow_up_completed',
            'quote_ref'        => $quote->ref_number,
            'by_admin'         => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->formatFollowUp($quote->fresh()),
            'message' => 'Follow-up marked as completed.',
        ]);
    }

    // ── POST /admin/crm/follow-ups/{id}/reschedule ───────────────────────────

    public function reschedule(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'follow_up_at' => ['required', 'date', 'after:now'],
            'note'         => ['nullable', 'string', 'max:2000'],
        ]);

        $quote = QuoteRequest::findOrFail($id);

        $prevFollowUpAt = $quote->follow_up_at;

        $quote->update(['follow_up_at' => $data['follow_up_at']]);

        CustomerCommunication::create([
            'quote_request_id' => $quote->id,
            'customer_id'      => $quote->customer_id,
            'admin_user_id'    => $request->user()?->id,
            'type'             => 'note',
            'direction'        => 'internal',
            'subject'          => 'Follow-up rescheduled',
            'body'             => $data['note'] ?? "Follow-up rescheduled to {$data['follow_up_at']}.",
            'status'           => 'completed',
            'completed_at'     => now(),
            'metadata'         => [
                'previous_follow_up_at' => $prevFollowUpAt?->toIso8601String(),
                'new_follow_up_at'      => $data['follow_up_at'],
            ],
        ]);

        Log::info('[follow_up_rescheduled] Follow-up rescheduled', [
            'event'      => 'follow_up_rescheduled',
            'quote_ref'  => $quote->ref_number,
            'new_date'   => $data['follow_up_at'],
            'by_admin'   => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->formatFollowUp($quote->fresh()),
            'message' => 'Follow-up rescheduled.',
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    public function computeFollowUpStatus(QuoteRequest $quote): string
    {
        if (in_array($quote->qualification_status ?? 'new', self::CLOSED_STATUSES, true)) {
            return 'none';
        }

        if ($quote->follow_up_at === null) {
            return $quote->follow_up_completed_at ? 'completed' : 'none';
        }

        $now = now();
        if ($quote->follow_up_at->isToday()) {
            return 'due';
        }
        if ($quote->follow_up_at->lt($now)) {
            return 'overdue';
        }
        return 'scheduled';
    }

    private function formatFollowUp(QuoteRequest $q): array
    {
        return [
            'id'                      => $q->id,
            'ref_number'              => $q->ref_number,
            'full_name'               => $q->full_name,
            'company_name'            => $q->company_name,
            'email'                   => $q->email,
            'phone'                   => $q->phone,
            'country'                 => $q->country,
            'tyre_category'           => $q->tyre_category,
            'qualification_status'    => $q->qualification_status ?? 'new',
            'lead_priority'           => $q->lead_priority ?? 'normal',
            'lead_customer_type'      => $q->lead_customer_type ?? 'unknown',
            'assigned_to'             => $q->assigned_to,
            'follow_up_at'            => $q->follow_up_at?->toIso8601String(),
            'follow_up_status'        => $this->computeFollowUpStatus($q),
            'follow_up_completed_at'  => $q->follow_up_completed_at?->toIso8601String(),
            'follow_up_completed_by'  => $q->follow_up_completed_by,
            'created_at'              => $q->created_at?->toIso8601String(),
        ];
    }
}

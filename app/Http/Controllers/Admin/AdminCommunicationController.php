<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerCommunication;
use App\Models\QuoteRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
            'subject'          => $c->subject,
            'body'             => $c->body,
            'status'           => $c->status,
            'admin_user_id'    => $c->admin_user_id,
            'customer_id'      => $c->customer_id,
            'quote_request_id' => $c->quote_request_id,
            'scheduled_at'     => $c->scheduled_at?->toIso8601String(),
            'completed_at'     => $c->completed_at?->toIso8601String(),
            'created_at'       => $c->created_at?->toIso8601String(),
        ];
    }
}

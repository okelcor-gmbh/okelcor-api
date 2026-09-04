<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\Claim;
use App\Models\Order;
use App\Services\AdminNotificationService;
use App\Services\CustomerNotifier;
use App\Support\AdminPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The customer's half of the claims loop (Session 120).
 *
 * Session 119 gave staff the queue; a customer still had to e-mail their
 * problem and hope. Now they file it from the portal — prefilled from their
 * account, optionally pinned to one of their orders — and it lands in the
 * SAME queue the team already works, marked `source: portal`. They watch
 * its status from the portal, and a decision notifies them through the
 * same in-app inbox their order updates use.
 *
 * Ownership is by account: a customer sees exactly the claims carrying
 * their customer_id, which staff-logged e-mail claims do not have. An
 * order ref on a filed claim must belong to the customer (matched by
 * e-mail, the same rule CustomerOrderController applies) — anything else
 * is rejected rather than silently unlinked.
 */
class CustomerClaimController extends Controller
{
    private const UNAVAILABLE = 'Claims are not available yet.';

    /** How each status reads to the person who filed the claim. */
    private const CUSTOMER_STATUS_COPY = [
        'new'               => 'Received. Our team will pick this up shortly.',
        'in_review'         => 'Our team is reviewing your claim.',
        'awaiting_customer' => 'We need something from you. Please check your messages.',
        'approved'          => 'Approved. We are arranging the resolution.',
        'rejected'          => 'Reviewed and declined. See the outcome note.',
        'closed'            => 'Closed.',
    ];

    // -------------------------------------------------------------------------
    // GET /api/v1/auth/claims — the customer's own claims
    // -------------------------------------------------------------------------
    public function index(Request $request): JsonResponse
    {
        if (! Claim::supportsCustomerLink()) {
            return response()->json([
                'data'    => [],
                'meta'    => ['claims_available' => false],
                'message' => self::UNAVAILABLE,
            ]);
        }

        $customer = $request->user();

        $claims = Claim::where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $claims->map(fn (Claim $c) => $this->format($c))->values(),
            'meta' => [
                'claims_available' => true,
                'open_count'       => $claims->whereNotIn('status', Claim::CLOSED_STATUSES)->count(),
                'types'            => collect(Claim::TYPE_LABELS)
                    ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            ],
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/auth/claims — file a claim from the portal
    // -------------------------------------------------------------------------
    public function store(Request $request): JsonResponse
    {
        if (! Claim::supportsCustomerLink()) {
            return response()->json(['message' => self::UNAVAILABLE], 503);
        }

        $customer = $request->user();

        $data = $request->validate([
            'order_ref'   => ['nullable', 'string', 'max:60'],
            'type'        => ['nullable', Rule::in(Claim::TYPES)],
            'description' => ['required', 'string', 'min:20', 'max:5000'],
            'quantity'    => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        // An order ref must be the customer's own order — matched by e-mail,
        // the rule the rest of the portal uses. A ref we cannot verify is
        // refused, not quietly dropped: the claim's whole value to the team
        // is knowing which shipment it is about.
        $order = null;
        if (! empty($data['order_ref'])) {
            $order = Order::where('ref', $data['order_ref'])->first();

            if (! $order || strtolower((string) $order->customer_email) !== strtolower((string) $customer->email)) {
                return response()->json([
                    'message' => 'That order reference does not match an order on your account.',
                    'errors'  => ['order_ref' => ['That order reference does not match an order on your account.']],
                ], 422);
            }
        }

        $claim = Claim::create([
            'customer_id'      => $customer->id,
            'source'           => 'portal',
            'order_id'         => $order?->id,
            'order_number'     => $order?->ref,
            'customer_name'    => trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')) ?: $customer->email,
            'customer_email'   => $customer->email,
            'customer_company' => $customer->company_name,
            'type'             => $data['type'] ?? 'other',
            'description'      => $data['description'],
            'quantity'         => $data['quantity'] ?? null,
            'status'           => 'new',
            // No created_by: a portal claim is the customer's act, and the
            // contribution ledger's "no person, no row" rule keeps it out of
            // anyone's monthly report. Whoever DECIDES it is still credited.
        ]);

        $this->notifyClaimsTeam($claim);

        // The confirmation the e-mail thread never gave: filed, numbered,
        // trackable.
        CustomerNotifier::notify(
            $customer,
            'claim_received',
            "We received your claim {$claim->ref}",
            'Our team will review it and you will be notified here when its status changes.',
            [
                'severity'     => 'info',
                'action_url'   => '/account/claims',
                'related_type' => 'claim',
                'related_id'   => $claim->id,
                'dedupe_key'   => "claim_received:{$claim->id}",
            ],
        );

        return response()->json([
            'data'    => $this->format($claim),
            'message' => "Claim {$claim->ref} filed. We will keep you posted here.",
        ], 201);
    }

    // -------------------------------------------------------------------------

    /**
     * A portal claim arrives unassigned, so the whole claims team hears about
     * it — every active admin whose role holds claims.manage, deduped per
     * claim. Wrapped so a notification failure never loses the claim itself.
     */
    private function notifyClaimsTeam(Claim $claim): void
    {
        try {
            $roles = AdminPermissions::MAP['claims.manage'] ?? [];

            AdminUser::where('is_active', true)
                ->whereIn('role', $roles)
                ->pluck('id')
                ->each(fn (int $adminId) => AdminNotificationService::notifyUser(
                    adminUserId: $adminId,
                    type: 'claim_filed',
                    title: "New claim {$claim->ref} from {$claim->customer_name}",
                    body: (Claim::TYPE_LABELS[$claim->type] ?? $claim->type)
                        . ($claim->order_number ? " · order {$claim->order_number}" : '')
                        . ' · ' . Str::limit($claim->description, 120),
                    actionUrl: '/admin/claims?claim=' . $claim->id,
                    severity: 'info',
                    relatedType: 'claim',
                    relatedId: $claim->id,
                    dedupeKey: "claim_filed:{$claim->id}:{$adminId}",
                ));
        } catch (\Throwable $e) {
            Log::warning('Portal claim team notification failed', ['claim' => $claim->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The customer's view of their claim. Deliberately narrower than the
     * admin format: no assignee, no internal ids — the customer sees what
     * they reported, where it stands in plain words, and the outcome.
     */
    private function format(Claim $claim): array
    {
        return [
            'id'           => $claim->id,
            'ref'          => $claim->ref,
            'order_number' => $claim->order_number,
            'type'         => $claim->type,
            'type_label'   => Claim::TYPE_LABELS[$claim->type] ?? $claim->type,
            'description'  => $claim->description,
            'quantity'     => $claim->quantity,
            'status'       => $claim->status,
            'status_label' => Claim::STATUS_LABELS[$claim->status] ?? $claim->status,
            'status_note'  => self::CUSTOMER_STATUS_COPY[$claim->status] ?? null,
            'outcome_note' => $claim->outcome_note,
            'open'         => ! in_array($claim->status, Claim::CLOSED_STATUSES, true),
            'filed_at'     => $claim->created_at?->toIso8601String(),
            'resolved_at'  => $claim->resolved_at?->toIso8601String(),
        ];
    }
}

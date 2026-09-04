<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Claim;
use App\Services\AdminNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * The after-sales claims queue (Session 119).
 *
 * Claims used to live in e-mail threads: nobody could see how many were
 * open, who was on each one, or how long a customer had been waiting. This
 * controller is the structured version, on the same machinery as the finance
 * snapshot board and the team to-dos — status + assignee + My Work +
 * notify-on-change:
 *
 *   - tagging someone notifies them and lands the claim in their My Work,
 *     and being the assignee is authorization to work it from there
 *     (AdminWorkQueueController::updateClaim), no claims permission needed;
 *   - a status change travels back to whoever logged the claim;
 *   - the queue's stats (open count, decision time) are served in meta so
 *     the dashboard can read them as a quality signal.
 *
 * Route gating: claims.view to read, claims.manage to write, claims.delete
 * (super_admin) to remove — a claim someone logged wrongly is closed with a
 * note, not erased, because the queue is also the record of what customers
 * told us.
 */
class AdminClaimController extends Controller
{
    private const UNAVAILABLE = 'The claims queue is not available yet — the database migration has not run.';

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/claims — claims.view
    //
    // ?status=open|new|in_review|awaiting_customer|approved|rejected|closed|all
    // "open" (the default) is everything not closed — the queue people work.
    // ?assignee=<id> · ?type=<type> · ?q= matches ref, customer, order number.
    // -------------------------------------------------------------------------
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status'   => ['nullable', Rule::in([...Claim::STATUSES, 'open', 'all'])],
            'type'     => ['nullable', Rule::in(Claim::TYPES)],
            'assignee' => ['nullable', 'integer'],
            'q'        => ['nullable', 'string', 'max:100'],
        ]);

        if (! Claim::available()) {
            return response()->json([
                'data'    => [],
                'meta'    => ['claims_available' => false],
                'message' => self::UNAVAILABLE,
            ]);
        }

        $query = Claim::with([
            'assignee:id,name,display_name',
            'creator:id,name,display_name',
        ]);

        match ($filters['status'] ?? 'open') {
            'all'   => null,
            'open'  => $query->whereNotIn('status', Claim::CLOSED_STATUSES),
            default => $query->where('status', $filters['status']),
        };

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['assignee'])) {
            $query->where('assigned_admin_id', $filters['assignee']);
        }

        if (! empty($filters['q'])) {
            $q = $filters['q'];
            $query->where(fn ($sub) => $sub
                ->where('ref', 'like', "%{$q}%")
                ->orWhere('customer_name', 'like', "%{$q}%")
                ->orWhere('customer_company', 'like', "%{$q}%")
                ->orWhere('order_number', 'like', "%{$q}%"));
        }

        // The oldest open claim is the one a customer has waited longest on,
        // so the working queue reads oldest-first; closed claims, when asked
        // for, read newest-first like any archive.
        $claims = $query
            ->orderByRaw("CASE WHEN status = 'closed' THEN 1 ELSE 0 END")
            ->orderBy('created_at')
            ->limit(500)
            ->get();

        return response()->json([
            'data' => $claims->map(fn (Claim $c) => $this->format($c))->values(),
            'meta' => array_merge(['claims_available' => true], $this->stats(), [
                'types' => collect(Claim::TYPE_LABELS)
                    ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
                'statuses' => collect(Claim::STATUS_LABELS)
                    ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
                // The tag picker — tagging is how a claim reaches someone's
                // My Work and notifies them.
                'staff' => AdminUser::where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'display_name'])
                    ->map(fn ($a) => ['id' => $a->id, 'name' => trim($a->display_name ?: $a->name)])
                    ->values(),
            ]),
            'message' => 'success',
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/admin/claims — claims.manage
    // -------------------------------------------------------------------------
    public function store(Request $request): JsonResponse
    {
        if (! Claim::available()) {
            return response()->json(['message' => self::UNAVAILABLE], 503);
        }

        $data = $request->validate([
            'customer_name'     => ['required', 'string', 'max:160'],
            'customer_email'    => ['nullable', 'email', 'max:160'],
            'customer_company'  => ['nullable', 'string', 'max:160'],
            'order_id'          => ['nullable', 'integer', 'exists:orders,id'],
            'order_number'      => ['nullable', 'string', 'max:60'],
            'type'              => ['nullable', Rule::in(Claim::TYPES)],
            'description'       => ['required', 'string', 'max:5000'],
            'quantity'          => ['nullable', 'integer', 'min:1', 'max:100000'],
            'assigned_admin_id' => ['nullable', 'integer', 'exists:admin_users,id'],
        ]);

        $claim = Claim::create([
            ...$data,
            'type'       => $data['type'] ?? 'other',
            'status'     => 'new',
            'created_by' => $request->user()?->id,
        ]);

        $this->notifyAssignee($claim, $request->user());

        return response()->json([
            'data'    => $this->format($claim->fresh(['assignee:id,name,display_name', 'creator:id,name,display_name'])),
            'message' => "Claim {$claim->ref} logged.",
        ], 201);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/admin/claims/{id} — claims.view
    // -------------------------------------------------------------------------
    public function show(int $id): JsonResponse
    {
        $claim = Claim::with([
            'assignee:id,name,display_name',
            'creator:id,name,display_name',
            'resolver:id,name,display_name',
        ])->findOrFail($id);

        return response()->json(['data' => $this->format($claim), 'message' => 'success']);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/v1/admin/claims/{id} — claims.manage
    // -------------------------------------------------------------------------
    public function update(Request $request, int $id): JsonResponse
    {
        $claim = Claim::findOrFail($id);
        $user  = $request->user();

        $data = $request->validate([
            'customer_name'     => ['sometimes', 'string', 'max:160'],
            'customer_email'    => ['sometimes', 'nullable', 'email', 'max:160'],
            'customer_company'  => ['sometimes', 'nullable', 'string', 'max:160'],
            'order_id'          => ['sometimes', 'nullable', 'integer', 'exists:orders,id'],
            'order_number'      => ['sometimes', 'nullable', 'string', 'max:60'],
            'type'              => ['sometimes', Rule::in(Claim::TYPES)],
            'description'       => ['sometimes', 'string', 'max:5000'],
            'quantity'          => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'status'            => ['sometimes', Rule::in(Claim::STATUSES)],
            'outcome_note'      => ['sometimes', 'nullable', 'string', 'max:5000'],
            'assigned_admin_id' => ['sometimes', 'nullable', 'integer', 'exists:admin_users,id'],
        ]);

        $previousAssignee = $claim->assigned_admin_id;
        $previousStatus   = $claim->status;

        $claim->fill($data);
        $this->applyStatusStamps($claim, $previousStatus, $user);
        $claim->save();

        if ($claim->assigned_admin_id && $claim->assigned_admin_id !== $previousAssignee) {
            $this->notifyAssignee($claim, $user);
        }

        $this->notifyCreatorOfStatusChange($claim, $previousStatus, $user);
        self::notifyCustomerOfStatusChange($claim, $previousStatus);

        return response()->json([
            'data' => $this->format($claim->fresh([
                'assignee:id,name,display_name', 'creator:id,name,display_name', 'resolver:id,name,display_name',
            ])),
            'message' => "Claim {$claim->ref} updated.",
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/admin/claims/{id} — claims.delete (super_admin)
    // -------------------------------------------------------------------------
    public function destroy(int $id): JsonResponse
    {
        $claim = Claim::findOrFail($id);
        $claim->delete();

        return response()->json(['message' => "Claim {$claim->ref} removed."]);
    }

    // -------------------------------------------------------------------------
    // Shared with AdminWorkQueueController — the stamp rules are the same
    // whether the status moves from the queue page or from My Work.
    // -------------------------------------------------------------------------

    /**
     * The decision and closing stamps follow the status in both directions:
     * a claim moved back out of approved/rejected was NOT decided, and
     * keeping the stamp would say it was. `resolved_at` survives the move
     * from approved/rejected to closed — closing the loop is not a second
     * decision.
     */
    public static function applyStatusStamps(Claim $claim, string $previousStatus, ?AdminUser $actor): void
    {
        $wasResolved = in_array($previousStatus, [...Claim::RESOLVED_STATUSES, 'closed'], true);
        $isResolved  = in_array($claim->status, [...Claim::RESOLVED_STATUSES, 'closed'], true);

        if ($isResolved && ! $wasResolved) {
            $claim->resolved_at = now();
            $claim->resolved_by = $actor?->id;
        } elseif (! $isResolved && $wasResolved) {
            $claim->resolved_at = null;
            $claim->resolved_by = null;
        }

        if ($claim->status === 'closed' && $previousStatus !== 'closed') {
            $claim->closed_at = now();
        } elseif ($claim->status !== 'closed') {
            $claim->closed_at = null;
        }
    }

    /**
     * One deduped nudge per assignee per day — the same tagging contract as
     * the snapshot board and the to-dos. Never self-notifies.
     */
    public static function notifyAssignee(Claim $claim, ?AdminUser $actor): void
    {
        if (! $claim->assigned_admin_id || $claim->assigned_admin_id === $actor?->id) {
            return;
        }

        try {
            AdminNotificationService::notifyUser(
                adminUserId: $claim->assigned_admin_id,
                type: 'claim_assigned',
                title: 'A claim was assigned to you',
                body: "{$claim->ref} — {$claim->customer_name}: " . \Illuminate\Support\Str::limit($claim->description, 120),
                actionUrl: '/admin/my-work?claim=' . $claim->id,
                severity: 'info',
                relatedType: 'claim',
                relatedId: $claim->id,
                dedupeKey: 'claim_assigned:' . $claim->assigned_admin_id . ':' . now()->toDateString(),
            );
        } catch (\Throwable $e) {
            Log::warning('Claim assignee notification failed', ['claim' => $claim->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * A status change travels back to whoever logged the claim — they are
     * the person the customer's e-mail thread still lands on, so they need
     * to know what to tell them.
     */
    public static function notifyCreatorOfStatusChange(Claim $claim, string $previousStatus, ?AdminUser $actor): void
    {
        if ($claim->status === $previousStatus
            || ! $claim->created_by
            || $claim->created_by === $actor?->id) {
            return;
        }

        try {
            AdminNotificationService::notifyUser(
                adminUserId: $claim->created_by,
                type: 'claim_status_changed',
                title: ($actor?->name ?? 'Someone') . " set {$claim->ref} to "
                    . (Claim::STATUS_LABELS[$claim->status] ?? $claim->status),
                body: $claim->outcome_note,
                actionUrl: '/admin/claims?claim=' . $claim->id,
                severity: match ($claim->status) {
                    'approved'          => 'success',
                    'rejected', 'closed' => 'info',
                    default             => 'info',
                },
                relatedType: 'claim',
                relatedId: $claim->id,
                dedupeKey: "claim_status_changed:{$claim->id}:{$claim->status}",
            );
        } catch (\Throwable $e) {
            Log::warning('Claim status notification failed', ['claim' => $claim->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The other side of the loop (Session 120): a claim filed from the
     * portal carries the account behind it, and a status change reaches
     * that account through the same in-app inbox its order updates use —
     * in plain words, with the outcome note where there is one. Staff-
     * logged e-mail claims have no customer_id and are skipped: their
     * customer is answered on the e-mail thread, by a person.
     */
    public static function notifyCustomerOfStatusChange(Claim $claim, string $previousStatus): void
    {
        if ($claim->status === $previousStatus
            || ! Claim::supportsCustomerLink()
            || ! $claim->customer_id) {
            return;
        }

        try {
            $customer = $claim->customer;
            if (! $customer) {
                return;
            }

            $copy = match ($claim->status) {
                'in_review'         => 'Our team is reviewing it now.',
                'awaiting_customer' => 'We need something from you to continue. Please check your messages.',
                'approved'          => 'It was approved and we are arranging the resolution.',
                'rejected'          => 'After review we cannot accept this claim.',
                'closed'            => 'It is now closed.',
                default             => 'Its status was updated.',
            };

            \App\Services\CustomerNotifier::notify(
                $customer,
                'claim_update',
                "Your claim {$claim->ref}: " . (Claim::STATUS_LABELS[$claim->status] ?? $claim->status),
                trim($copy . ($claim->outcome_note ? "\n\n" . $claim->outcome_note : '')),
                [
                    'severity'     => match ($claim->status) {
                        'approved'          => 'success',
                        'awaiting_customer' => 'warning',
                        default             => 'info',
                    },
                    'action_url'   => '/account/claims',
                    'related_type' => 'claim',
                    'related_id'   => $claim->id,
                    'dedupe_key'   => "claim_update:{$claim->id}:{$claim->status}",
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('Claim customer notification failed', ['claim' => $claim->id, 'error' => $e->getMessage()]);
        }
    }

    // -------------------------------------------------------------------------

    /**
     * The queue's numbers, served with every listing so the page never
     * computes its own version of them. `avg_days_to_decision` is the
     * quality signal: how long a customer waits between the claim being
     * logged and a decision, over the last 90 days of decided claims.
     */
    private function stats(): array
    {
        $byStatus = Claim::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $decided = Claim::whereNotNull('resolved_at')
            ->where('resolved_at', '>=', now()->subDays(90))
            ->get(['created_at', 'resolved_at']);

        $avgDays = $decided->isEmpty()
            ? null
            : round($decided->avg(fn (Claim $c) => $c->created_at->diffInHours($c->resolved_at)) / 24, 1);

        return [
            'counts'               => $byStatus,
            'open_count'           => Claim::whereNotIn('status', Claim::CLOSED_STATUSES)->count(),
            'avg_days_to_decision' => $avgDays,
        ];
    }

    private function format(Claim $claim): array
    {
        $name = fn (?AdminUser $a) => $a ? trim($a->display_name ?: $a->name) : null;

        return [
            'id'                => $claim->id,
            'ref'               => $claim->ref,
            'order_id'          => $claim->order_id,
            'order_number'      => $claim->order_number,
            'customer_name'     => $claim->customer_name,
            'customer_email'    => $claim->customer_email,
            'customer_company'  => $claim->customer_company,
            'type'              => $claim->type,
            'type_label'        => Claim::TYPE_LABELS[$claim->type] ?? $claim->type,
            'description'       => $claim->description,
            'quantity'          => $claim->quantity,
            'status'            => $claim->status,
            'status_label'      => Claim::STATUS_LABELS[$claim->status] ?? $claim->status,
            // 'portal' means the customer filed it themselves (Session 120)
            // and is watching its status from their account — the panel
            // badges these so the team knows the clock is visible.
            'source'            => Claim::supportsCustomerLink() ? ($claim->source ?? 'admin') : 'admin',
            'outcome_note'      => $claim->outcome_note,
            'assigned_admin_id' => $claim->assigned_admin_id,
            'assignee'          => $name($claim->assignee),
            'created_by'        => $claim->created_by,
            'creator'           => $name($claim->creator),
            'resolved_at'       => $claim->resolved_at?->toIso8601String(),
            'resolved_by_name'  => $claim->relationLoaded('resolver') ? $name($claim->resolver) : null,
            'closed_at'         => $claim->closed_at?->toIso8601String(),
            'created_at'        => $claim->created_at?->toIso8601String(),
            // How long the customer has been waiting — the number the queue
            // sorts by and the number that should make someone uncomfortable.
            'age_days'          => $claim->created_at
                ? (int) round($claim->created_at->diffInHours($claim->resolved_at ?? now()) / 24)
                : null,
        ];
    }
}

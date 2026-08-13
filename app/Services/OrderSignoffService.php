<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\OrderSignoff;
use App\Support\AdminPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Two signatures on an order confirmation — operations and finance.
 *
 * The control is only worth having if three things hold, and each of them is
 * enforced here rather than left to the caller:
 *
 *   1. Two DIFFERENT people. A single super_admin holds both permissions as
 *      break-glass, so without this the control is satisfiable alone, which is
 *      not a control.
 *   2. The signature covers a specific version of the order. Approving €10,000
 *      and then sending a confirmation for €30,000 is worse than no approval at
 *      all, because it carries evidence that two people agreed to it. Editing
 *      the money withdraws both signatures.
 *   3. Withdrawal is itself recorded. A sign-off that can be quietly removed is
 *      a sign-off nobody can rely on afterwards.
 *
 * Nothing here throws for an ordinary refusal — callers get a structured
 * refusal to turn into a 409 with a message the order manager can act on.
 */
class OrderSignoffService
{
    /**
     * Whether the gate applies to this order at all.
     *
     * Orders raised before the rule existed were confirmed under the old
     * single-approval process. Applying it to them retrospectively would freeze
     * every open order on production the moment this deploys — a new control
     * that halts the business on its first day gets switched off, and then
     * there is no control.
     */
    /**
     * Whether the sign-off tables are actually there.
     *
     * Deploy-order safety, and it is not theoretical: the order-item edit path
     * calls into this service, so between the code shipping and the migration
     * running EVERY item correction would 500 on a missing table. An existing
     * test caught exactly that. A new control must not be able to break an old
     * feature by arriving first.
     *
     * Memoised per process — this is on the order-detail path and Schema
     * introspection is a real query.
     */
    private static ?bool $tableExists = null;

    public function recordingAvailable(): bool
    {
        return self::$tableExists ??= Schema::hasTable('order_signoffs');
    }

    /** Test seam — the harness creates the table after the container is booted. */
    public static function forgetTableCheck(): void
    {
        self::$tableExists = null;
    }

    public function applies(Order $order): bool
    {
        // No table, no signatures, so nothing can be gated. Refusing to send
        // until two people sign, when signing is impossible, would be an
        // outage rather than a control.
        if (! $this->recordingAvailable()) {
            return false;
        }

        if (! config('orders.signoff.required', true)) {
            return false;
        }

        $from = config('orders.signoff.applies_from');

        if (! $from) {
            return true;
        }

        try {
            $boundary = CarbonImmutable::parse($from);
        } catch (\Throwable) {
            // A malformed date must not silently disable a compliance control.
            Log::warning('orders.signoff.applies_from is not a readable date; applying sign-off to all orders', [
                'value' => $from,
            ]);

            return true;
        }

        return $order->created_at !== null && $order->created_at->gte($boundary);
    }

    /** Both slots signed and neither withdrawn. */
    public function isComplete(Order $order): bool
    {
        $live = $this->live($order);

        return isset($live[OrderSignoff::SLOT_OPS], $live[OrderSignoff::SLOT_FINANCE]);
    }

    /**
     * The full picture, in the shape the admin panel renders.
     *
     * Returns something meaningful even when the gate does not apply, because
     * "this order predates the rule" is a thing the order manager needs to be
     * told rather than left to infer from an empty panel.
     *
     * @return array<string, mixed>
     */
    public function state(Order $order, ?AdminUser $viewer = null): array
    {
        $live    = $this->live($order);
        $applies = $this->applies($order);

        $slots = [];

        foreach (OrderSignoff::SLOTS as $slot) {
            $signature = $live[$slot] ?? null;

            $slots[] = [
                'slot'        => $slot,
                'label'       => OrderSignoff::SLOT_LABELS[$slot],
                'signed'      => $signature !== null,
                'signed_at'   => $signature?->signed_at?->toIso8601String(),
                'signed_by'   => $signature?->admin_name,
                'signed_role' => $signature?->admin_role,
                'note'        => $signature?->note,
                'permission'  => OrderSignoff::SLOT_PERMISSIONS[$slot],
                'roles'       => AdminPermissions::MAP[OrderSignoff::SLOT_PERMISSIONS[$slot]] ?? [],
            ];
        }

        $complete = $this->isComplete($order);

        return [
            'required'  => $applies,
            'complete'  => $complete,
            // What the UI should actually say. "2 of 2" with a green tick reads
            // very differently from "not required for this order", and both are
            // states this returns.
            // match(true) compares each arm with ===, so a non-empty array is
            // NOT a match for true — written as `$live` this arm was
            // unreachable and a half-signed order reported itself as
            // untouched.
            'status'    => match (true) {
                ! $applies    => 'not_required',
                $complete     => 'complete',
                $live !== []  => 'partial',
                default       => 'awaiting',
            },
            'signed_count' => count($live),
            'slots'        => $slots,

            // Which slots THIS viewer may act on. Answered here rather than
            // left to the client because neither question can be derived from
            // the payload: signing carries the two-different-people rule, which
            // needs the signatory's user id and not their display name, and
            // withdrawing is satisfied by the bypass permission as well as by
            // the slot's own. Frontend reported having to make a second request
            // for the first, and having reimplemented the second by hand.
            'you_may_sign'   => $viewer === null ? [] : array_values(array_filter(
                OrderSignoff::SLOTS,
                fn ($slot) => $this->canSign($order, $viewer, $slot)['ok']
            )),
            'you_may_revoke' => $viewer === null ? [] : array_values(array_filter(
                OrderSignoff::SLOTS,
                fn ($slot) => $this->canRevoke($viewer, $slot) && isset($live[$slot])
            )),
            'history'      => ! $this->recordingAvailable() ? [] : $order->signoffs()
                ->with(['adminUser:id,name', 'revokedBy:id,name'])
                ->get()
                ->map(fn (OrderSignoff $s) => [
                    'slot'          => $s->slot,
                    'label'         => OrderSignoff::SLOT_LABELS[$s->slot] ?? $s->slot,
                    'signed_by'     => $s->admin_name,
                    'signed_role'   => $s->admin_role,
                    'signed_at'     => $s->signed_at?->toIso8601String(),
                    'note'          => $s->note,
                    'revoked'       => $s->active === null,
                    'revoked_at'    => $s->revoked_at?->toIso8601String(),
                    'revoked_by'    => $s->revokedBy?->name,
                    'revoke_reason' => $s->revoke_reason,
                ])->values()->all(),
        ];
    }

    /**
     * Whether this admin could sign this slot right now — the same rules as
     * sign(), with nothing written.
     *
     * Separate from sign() because the panel needs to ask before offering the
     * button, and "call the writer and see if it succeeds" would leave a
     * signature behind every time someone opened an order.
     *
     * @return array{ok: bool, code?: string, message?: string}
     */
    public function canSign(Order $order, AdminUser $admin, string $slot): array
    {
        if (! $this->recordingAvailable()) {
            return [
                'ok'      => false,
                'code'    => 'not_available',
                'message' => 'Order sign-off is not switched on yet — the database migration has not run.',
            ];
        }

        if (! in_array($slot, OrderSignoff::SLOTS, true)) {
            return ['ok' => false, 'code' => 'unknown_slot', 'message' => 'That is not a sign-off slot.'];
        }

        if (! AdminPermissions::can($admin->role, OrderSignoff::SLOT_PERMISSIONS[$slot])) {
            $roles = implode(' or ', AdminPermissions::MAP[OrderSignoff::SLOT_PERMISSIONS[$slot]] ?? []);

            return [
                'ok'      => false,
                'code'    => 'not_entitled',
                'message' => "The {$slot} signature can only be given by: {$roles}.",
            ];
        }

        $live = $this->live($order);

        if (isset($live[$slot])) {
            return [
                'ok'      => false,
                'code'    => 'already_signed',
                'message' => OrderSignoff::SLOT_LABELS[$slot] . ' has already signed this order ('
                    . $live[$slot]->admin_name . '). Withdraw that signature first if it needs to change.',
            ];
        }

        // Separation of duties. The reason the whole thing exists.
        foreach ($live as $other) {
            if ((int) $other->admin_user_id === (int) $admin->id) {
                return [
                    'ok'      => false,
                    'code'    => 'same_person',
                    'message' => 'You have already signed this order as ' . OrderSignoff::SLOT_LABELS[$other->slot]
                        . '. Two different people must sign — that is the point of the second signature.',
                ];
            }
        }

        return ['ok' => true];
    }

    /**
     * Records one signature.
     *
     * @return array{ok: bool, code?: string, message?: string, signoff?: OrderSignoff}
     */
    public function sign(Order $order, AdminUser $admin, string $slot, ?string $note = null, ?string $ip = null): array
    {
        $allowed = $this->canSign($order, $admin, $slot);

        if (! $allowed['ok']) {
            return $allowed;
        }

        $signoff = DB::transaction(function () use ($order, $admin, $slot, $note, $ip) {
            $signoff = OrderSignoff::create([
                'order_id'      => $order->id,
                'order_ref'     => $order->ref,
                'slot'          => $slot,
                'admin_user_id' => $admin->id,
                'admin_role'    => $admin->role,
                'admin_name'    => $admin->name,
                'signed_at'     => now(),
                'note'          => $note,
                'active'        => 1,
            ]);

            $this->log($order, $admin, 'signoff_given', OrderSignoff::SLOT_LABELS[$slot] . ' signed off'
                . ($note ? ': ' . $note : ''), $ip);

            return $signoff;
        });

        return ['ok' => true, 'signoff' => $signoff];
    }

    /**
     * Withdraws a standing signature.
     *
     * @return array{ok: bool, code?: string, message?: string}
     */
    public function revoke(Order $order, AdminUser $admin, string $slot, string $reason, ?string $ip = null): array
    {
        if (! in_array($slot, OrderSignoff::SLOTS, true)) {
            return ['ok' => false, 'code' => 'unknown_slot', 'message' => 'That is not a sign-off slot.'];
        }

        if (! $this->canRevoke($admin, $slot)) {
            return [
                'ok'      => false,
                'code'    => 'not_entitled',
                'message' => 'You cannot withdraw the ' . OrderSignoff::SLOT_LABELS[$slot] . ' signature.',
            ];
        }

        $signature = $this->live($order)[$slot] ?? null;

        if ($signature === null) {
            return ['ok' => false, 'code' => 'not_signed', 'message' => 'There is no standing signature to withdraw.'];
        }

        DB::transaction(function () use ($order, $admin, $slot, $reason, $signature, $ip) {
            $signature->update([
                'active'        => null,
                'revoked_at'    => now(),
                'revoked_by'    => $admin->id,
                'revoke_reason' => $reason,
            ]);

            $this->log($order, $admin, 'signoff_revoked',
                OrderSignoff::SLOT_LABELS[$slot] . ' signature withdrawn (' . $signature->admin_name . '): ' . $reason, $ip);
        });

        return ['ok' => true];
    }

    /**
     * Withdraws every standing signature because the order's money changed.
     *
     * Called from the paths that can move a figure the signatories agreed to.
     * Silent when there is nothing signed, which is the overwhelmingly common
     * case — this must never turn an ordinary edit into a noisy event.
     *
     * @return int  how many signatures were withdrawn
     */
    public function invalidateForFinancialChange(Order $order, ?AdminUser $admin, string $what, ?string $ip = null): int
    {
        $live = $this->live($order);

        if ($live === []) {
            return 0;
        }

        $reason = 'Automatically withdrawn: ' . $what . '. The order changed after it was signed, so the '
            . 'signatures no longer describe what would be sent.';

        DB::transaction(function () use ($order, $admin, $live, $reason, $ip) {
            foreach ($live as $signature) {
                $signature->update([
                    'active'        => null,
                    'revoked_at'    => now(),
                    'revoked_by'    => $admin?->id,
                    'revoke_reason' => $reason,
                ]);
            }

            $this->log($order, $admin, 'signoff_revoked',
                count($live) . ' sign-off(s) withdrawn automatically — ' . $reason, $ip);
        });

        return count($live);
    }

    /**
     * The check the send endpoints make.
     *
     * @return array{ok: bool, code?: string, message?: string, signoff?: array<string, mixed>}
     */
    public function guardSend(Order $order, AdminUser $admin, bool $bypassRequested = false, ?string $bypassReason = null, ?string $ip = null): array
    {
        if (! $this->applies($order) || $this->isComplete($order)) {
            return ['ok' => true];
        }

        if ($bypassRequested && AdminPermissions::can($admin->role, 'orders.signoff_bypass')) {
            if (($bypassReason === null || trim($bypassReason) === '')) {
                return [
                    'ok'      => false,
                    'code'    => 'bypass_reason_required',
                    'message' => 'Sending without both signatures needs a written reason.',
                ];
            }

            $this->log($order, $admin, 'signoff_bypassed',
                'Order confirmation sent without full sign-off: ' . $bypassReason, $ip);

            return ['ok' => true];
        }

        $missing = array_values(array_diff(OrderSignoff::SLOTS, array_keys($this->live($order))));
        $labels  = array_map(fn ($s) => OrderSignoff::SLOT_LABELS[$s], $missing);

        return [
            'ok'      => false,
            'code'    => 'signoff_incomplete',
            'message' => 'This order confirmation still needs a signature from: ' . implode(' and ', $labels) . '.',
            'signoff' => $this->state($order),
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * Whoever may give a signature may take one back, and a bypass holder may
     * always. Anyone else withdrawing someone's approval would be able to
     * unpick a control they are not part of.
     *
     * Unlike signing there is no same-person rule — withdrawing your own
     * signature is exactly what you should be able to do when you notice a
     * mistake.
     */
    public function canRevoke(AdminUser $admin, string $slot): bool
    {
        if (! in_array($slot, OrderSignoff::SLOTS, true)) {
            return false;
        }

        return AdminPermissions::can($admin->role, OrderSignoff::SLOT_PERMISSIONS[$slot])
            || AdminPermissions::can($admin->role, 'orders.signoff_bypass');
    }

    /**
     * Standing signatures, keyed by slot.
     *
     * @return array<string, OrderSignoff>
     */
    private function live(Order $order): array
    {
        if (! $this->recordingAvailable()) {
            return [];
        }

        return $order->signoffs()->live()->get()->keyBy('slot')->all();
    }

    /**
     * The audit row.
     *
     * Wrapped like every other OrderLog write in this codebase — a failed log
     * must not fail the user's action. Unlike the earlier ones it is no longer
     * silently lossy: OrderLog::ACTIONS is the source the column's ENUM is
     * built from, and a test asserts every action written in app/ appears in
     * it, so a value the column would reject cannot reach here unnoticed.
     */
    private function log(Order $order, ?AdminUser $admin, string $action, string $notes, ?string $ip): void
    {
        try {
            if (! in_array($action, OrderLog::ACTIONS, true)) {
                throw new RuntimeException("Unknown order log action '{$action}'");
            }

            OrderLog::create([
                'order_id'         => $order->id,
                'order_ref'        => $order->ref,
                'admin_user_id'    => $admin?->id,
                'admin_user_email' => $admin?->email,
                'action'           => $action,
                'notes'            => $notes,
                'ip_address'       => $ip,
                'created_at'       => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Order sign-off audit row could not be written', [
                'order_ref' => $order->ref,
                'action'    => $action,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}

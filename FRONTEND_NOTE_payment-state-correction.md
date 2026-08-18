# Frontend note — correcting a payment state that says paid when it isn't

**Session 90 · backend and frontend both built and tested · migration #40 (`order_logs.action` ENUM) · 1 new route**

The order manager reported the same shape of problem for the second time:

> *"It keeps giving automatic and there is no option for us to set it manually."*
>
> *"I am still waiting for the deposit but the website shows the client has paid…
> I thought you fixed that earlier."*

She is right that it was fixed, and right that it is still happening — because
those were two different faults with the same symptom.

---

## What Session 76 fixed, and what it left

Session 76 closed every path that was moving an order forward on its own.
Generating a proforma stopped starting the deposit ladder and stopped e-mailing
the buyer; `mark-paid` started working on manually recorded orders, so ticking
"paid" on the creation form stopped being the only route to a paid order.

What it did not close is the direction of travel. **Every route through the
payment ladder moves forward.** `request-deposit` refuses unless the order is at
`pending_proforma`; `deposit-paid` refuses unless it is at `pending_proforma` or
`deposit_requested`; `balance-paid` refuses unless the deposit is in. Each guard
is right on its own. Together they mean that an order which arrives at a paid
state — however it got there, including from before Session 76 shipped — can
never be put back by anyone using the product.

That is the whole of the second complaint. Not that something set it
automatically today, but that nothing can unset what was set. Every order in
that state needed a developer, and until one was free the buyer's portal kept
telling him he had paid.

---

## New endpoint — the way back

```http
POST /api/v1/admin/orders/{id}/payment-milestones/correct
```

Permission: **`payments.correct_state`** — `super_admin`, `admin`,
`order_manager`. Same holders as `payments.mark_paid`, on its own key so it can
be narrowed later without also taking away the ability to record a payment.

| Field | Type | Notes |
|---|---|---|
| `payment_stage` | string, **required** | The stage the order should be at. |
| `reset_payment_status` | boolean, optional | Puts `payment_status` back to `pending`. |
| `reason` | string, **required** | 5–500 chars. Goes to the order log. |

Returns `200` with the milestone object plus `payment_status`, `corrected_from`
and `cleared` (the timestamp columns it emptied).

### What it deliberately will not do

**It never moves an order forward.** `409 use_the_milestone_actions` if the
target stage is later than the current one. Recording that money arrived stays
with the milestone actions, which notify the customer and stamp who confirmed
it — if this could do it too, it would become the quick way round both and the
ladder's guards would stop meaning anything.

**It never touches a Stripe order.** `409 gateway_managed_payment`. The gateway
owns that payment state; a figure typed here would only disagree with Stripe
until somebody noticed.

**It never e-mails the customer.** Correcting our own record is not an event in
his order, and "your payment status changed" for a payment that never happened
is exactly the confusion this exists to end.

**It always writes one audit row**, `payment_state_corrected`, naming who, what
moved, what was cleared and why. This is the only order log write in the
codebase that is *not* wrapped in a try/catch: a correction that failed to
record itself would be worse than the state it corrects, so it rolls back
instead.

Other errors: `409 nothing_to_correct` when the order is already in that state,
`422` validation on a missing or too-short `reason`.

---

## What changed in the admin panel

`components/admin/payment-milestones-card.tsx`:

1. **A "Marked paid" chip in the header**, shown whenever `payment_status` is
   `paid`. An order reads as paid through *two* columns and they can disagree —
   an order sitting at "Pending Proforma" with `payment_status: paid` was
   showing the customer a paid order while the card showed stage one. Both are
   now visible.

2. **A correction panel** below the ladder, offered at any stage past the start
   or whenever the order is flagged paid, and hidden entirely for Stripe orders
   and for roles without the permission. The stage dropdown lists only stages
   *behind* the current one, so the UI cannot offer an action the API refuses.
   The "also mark the payment as not yet received" checkbox appears only when
   `payment_status` is `paid`, and defaults to ticked, because that is the field
   the customer's portal actually reads.

3. **New props**: `paymentStatus` and `paymentMethod`. Both come straight off
   the admin order detail payload; nothing else needs to change.

Proxy route added at
`app/api/admin/orders/[id]/payment-milestones/correct/route.ts`, identical in
shape to the other five.

---

## The order she asked about — AB - 1182

Correct it on the server, not through the panel, because it needs looking at
first. `orders:payment-state` is new:

```bash
# 1. Read it. This prints both payment columns, whether the portal is showing
#    the customer a paid order, and the full log history that produced it.
php artisan orders:payment-state "AB - 1182"

# 2. Then correct it.
php artisan orders:payment-state "AB - 1182" \
    --stage=pending_proforma --reset-status \
    --reason="deposit not received; state was never confirmed by anyone"
```

Same service, same guards and same audit row as the panel button. The reference
is matched loosely — `AB - 1182` carries spaces around its dash, and a
transcription difference should not read as a missing order.

### And every other order in the same state

```bash
php artisan orders:payment-state --audit
```

Sweeps the whole table for orders presenting as paid with **nothing** recording
who confirmed it: no Stripe session, no eBay, no `deposit_paid_at` or
`balance_paid_at` stamp, no `payment_status_changed` audit row. Those are the
ones that got there by derivation rather than by observation.

**It reports and never repairs.** Nothing in the code can tell whether the money
actually arrived, only whether we wrote down that it did — an order recorded
from a paper backlog is *supposed* to look like this, a live order is not, and
only somebody who can check the bank can tell them apart.

---

## Prevention

`POST /admin/orders` with `payment_status: paid` now writes a
`payment_status_changed` log row naming the admin who recorded it.

That path is the one legitimate way an order is born paid — a historical order
being backfilled — and the workflow is unchanged, still supported, still
defaulting `payment_stage` to `balance_paid`. What changed is that it is no
longer **anonymous**. Every other route to a paid state already left a record of
who confirmed it; this one wrote nothing, so afterwards a person's assertion
could not be told apart from a derivation. That is why the audit sweep above
exists for the orders already on production, and why it will not need to for
orders recorded from here on.

Nothing to change on the frontend for this.

---

## Nothing removed

No field, endpoint or permission was removed or renamed. `payments.correct_state`
and the `correct` endpoint are new; `paymentStatus` / `paymentMethod` on the
milestone card are optional props. A client that ignores all of it keeps working
exactly as it does today.

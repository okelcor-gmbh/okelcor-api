# Frontend note — operations board, dual sign-off, eBay split

**Session 83. Backend complete and tested (431 passing). Four migrations, nine
new endpoints, one new admin role. No existing endpoint changes shape.**

Everything below is additive. Nothing you already call returns a different
structure, and nothing you already call starts returning fewer rows — that was a
deliberate constraint, and section 4 explains where it bit.

---

## What was asked for

The finance director's sketch: a board with one row per sales channel and seven
columns. Plus four things around it — order managers need to see what is in
transit, they need to upload and send documents themselves (including after
delivery), an order confirmation needs two signatures, and eBay orders need to
stop being mixed in with the rest.

---

## 1. The board — `GET /api/v1/admin/operations/summary`

`orders.view` — which is `super_admin`, `admin`, `order_manager`, `sales_manager`, **`finance`** and `support`. Optional `?from=YYYY-MM-DD&to=YYYY-MM-DD`, defaulting to the
current month (which is the period finance actually reconciles in).

```jsonc
{
  "data": {
    "period": { "from": "2026-08-01", "to": "2026-08-13", "label": "2026-08-01 → 2026-08-13" },
    "channels": [
      {
        "channel": "normal", "label": "Normal",
        "orders_sent": 5,
        "amount": 10000.00, "currency": "EUR",
        "amount_other_currencies": [{ "currency": "USD", "amount": 800.00, "orders": 1 }],
        "clients": 4,
        "orders_confirmed": 10,
        "website_invoices": 5,
        "finance_invoices": 4,
        "invoice_variance": 1,
        "in_transit": 3
      },
      { "channel": "ebay", "label": "eBay", "...": "same shape" }
    ],
    "total": { "channel": "all", "label": "All channels", "...": "same shape" },
    "definitions": { "orders_sent": "Orders raised in the period, excluding …", "…": "…" }
  },
  "meta": { "finance_recording_available": true, "channels": ["normal", "ebay"] }
}
```

**Render `definitions` in the UI** — a tooltip or an info row under the table.
Seven figures that two departments will argue over are worthless if "orders
sent" means something different to the reader than to the query. The strings are
written for that purpose; don't paraphrase them.

Three things that will look like bugs and are not:

- **`total.clients` is not the sum of the channel rows.** One buyer who ordered
  on eBay and on the website is one client. Adding the rows would report two.
- **`amount` is EUR only.** Anything booked in another currency is listed in
  `amount_other_currencies`, not converted. Converting at today's rate would
  make a historic month's revenue change every time the board is opened. Show
  the other-currency amounts as a footnote if the array is non-empty.
- **`invoice_variance` is the point of the two invoice columns.** Show it, and
  make anything non-zero visually distinct — two counts side by side without the
  difference is a mismatch sitting on screen looking like two facts. Non-zero
  should link to the reconciliation below.

When `meta.finance_recording_available` is `false` the finance column is a
structural zero, not a real one. Say "not switched on yet", not "0".

---

## 2. Finance-system invoices (sevDesk)

Finance types in what sevDesk raised; the board compares the count against ours.
Deliberately **not** an integration — an integration that silently stopped
syncing would make the two columns agree by accident, which is the one failure
this board exists to catch.

| Endpoint | Permission | |
|---|---|---|
| `GET /api/v1/admin/finance-invoices` | `finance.view` | filters: `from`, `to`, `channel`, `system`, `matched=yes\|no`, `q`, `per_page` |
| `POST /api/v1/admin/finance-invoices` | `finance.manage` | |
| `PATCH /api/v1/admin/finance-invoices/{id}` | `finance.manage` | |
| `DELETE /api/v1/admin/finance-invoices/{id}` | `finance.manage` | |
| `GET /api/v1/admin/operations/invoice-reconciliation` | `finance.view` | `from`, `to`, `channel=normal\|ebay\|all` |

**Create payload** — only `external_number` and `issued_on` are required:

```jsonc
{
  "external_number": "SD-114",     // the number as it reads in sevDesk
  "issued_on": "2026-08-11",
  "order_ref": "OKL-C06OT",        // optional; drives channel + matching
  "invoice_number": "INV-2026-0042", // optional; our number, second matching key
  "amount": 10000.00,
  "currency": "EUR",
  "channel": "normal",             // inferred from order_ref if omitted
  "notes": "…"
}
```

`order_ref` is **not validated against our orders**, on purpose: an invoice
finance cannot match to an order here is exactly the row worth recording.
The response carries `order_known_here: false` for those — surface it.

Entering the same `external_number` twice returns **422** with
`errors.external_number` rather than a database error, because a duplicate would
make the two sides of the board agree when they do not.

**The reconciliation response** gives you both sides by name:

```jsonc
{
  "data": {
    "available": true,
    "counts": { "website_invoices": 5, "finance_invoices": 4, "matched": 4,
                "only_here": 1, "only_in_finance": 0, "amount_mismatch": 1 },
    "matched":         [{ "order_ref": "…", "our_invoice": "…", "finance_invoice": "…",
                          "our_amount": 5000, "finance_amount": 5500, "amount_matches": false }],
    "only_here":       [{ "invoice_number": "…", "order_ref": "…", "amount": 0, "issued_at": "…" }],
    "only_in_finance": [{ "external_number": "…", "order_ref": "…", "order_known_here": false }]
  }
}
```

`amount_mismatch` is worth its own line in the UI: two systems holding the same
invoice at **different money** is a worse finding than one holding it alone, and
it is invisible from the counts on the board.

---

## 3. Dual sign-off on an order confirmation

Two signatures before a confirmation reaches the customer: one **Operations**,
one **Finance**, and they must be **two different people**.

### The new `finance` role

`admin_users.role` could not store it — it was a MySQL ENUM allowing only four
values, the top Known Gap since Session 52. That is fixed (the column is now a
plain string, validated against `AdminPermissions::ROLES`). **The role picker in
admin user management should now offer all nine roles**, including `finance`,
`sales_manager`, `support`, `content_manager` and `viewer` — five of which the
database has been silently refusing all along.

| Slot | Permission | Roles |
|---|---|---|
| `ops` | `orders.signoff_ops` | `super_admin`, `order_manager` |
| `finance` | `orders.signoff_finance` | `super_admin`, `finance` |
| bypass | `orders.signoff_bypass` | `super_admin` |

`admin` deliberately holds **neither**. A control any single administrator can
satisfy alone is not a separation of duties.

### Endpoints

| Endpoint | |
|---|---|
| `GET /api/v1/admin/orders/{id}/signoffs` | state + `you_may_sign` |
| `POST /api/v1/admin/orders/{id}/signoffs` | `{ "slot": "ops"\|"finance", "note": "…" }` |
| `DELETE /api/v1/admin/orders/{id}/signoffs/{slot}` | `{ "reason": "…" }` — required |

All three sit under `orders.view`; **the entitlement to sign is checked per slot
inside the service, not by route middleware**, because the two halves are held
by different roles. So a 403 from `POST` is a role problem, and a 409 is a state
problem — show them differently.

**`you_may_sign`** is an array of the slots *this* admin can sign right now.
Drive the button off it: one button, or none, never two disabled ones and a
permissions puzzle for the user to solve.

### The same block is on the order detail

`GET /api/v1/admin/orders/{id}` now includes `signoff` with the identical shape,
so the order page needs no second request:

```jsonc
"signoff": {
  "required": true,
  "complete": false,
  "status": "awaiting",        // not_required | awaiting | partial | complete
  "signed_count": 1,
  "slots": [
    { "slot": "ops", "label": "Operations", "signed": true,
      "signed_by": "Edinah Agalla", "signed_role": "order_manager",
      "signed_at": "2026-08-13T09:20:00+00:00", "note": "Stock confirmed",
      "permission": "orders.signoff_ops", "roles": ["super_admin", "order_manager"] },
    { "slot": "finance", "label": "Finance", "signed": false, "…": null }
  ],
  "history": [ { "slot": "ops", "signed_by": "…", "revoked": true,
                 "revoked_by": "…", "revoke_reason": "…" } ]
}
```

**Render all four `status` values distinctly.** `not_required` is not the same
as `awaiting` — it means this order predates the rule (see below) and there is
nothing for the user to do. An empty panel would read as "nobody has signed
yet", which is a different and alarming statement.

### Two behaviours that will generate support questions

**Editing the money withdraws both signatures.** `PATCH /orders/{id}/financials`
and any order-item change that moves the total automatically revoke every
standing signature and return `data.signoffs_withdrawn`. Approving €10,000 and
then sending a confirmation for €10,500 is worse than no approval at all,
because it carries evidence that two people agreed to it. **Show a toast when
`signoffs_withdrawn > 0`** — the message field already says so. An edit that
does not move the total leaves the signatures alone.

**Orders raised before 2026-08-13 are exempt.** `ORDER_SIGNOFF_APPLIES_FROM`
grandfathers the backlog; without it, shipping this would freeze every open
order on production until someone signed it twice, and a control that halts the
business on its first day gets switched off. Those orders report
`status: "not_required"`.

### Where the gate actually fires

`409` with `code: "signoff_incomplete"` and a `signoff` block, on **both**:

- `POST /admin/orders/{id}/send-acceptance-request` (and `/acceptance/send`)
- `POST /admin/trade-documents/{id}/send-email` — **only** when the document is
  an `order_confirmation`

The second one matters: without it the control was one route deep, and the
confirmation could be e-mailed straight from the documents list. Every other
document type is unaffected.

Generating the confirmation is **not** gated — an unsigned confirmation is the
draft the two signatories need to read.

A `super_admin` may pass `override_signoff: true` with
`override_signoff_reason: "…"`. The reason is required and is recorded as
`signoff_bypassed` on the order. If you expose it, make it feel exceptional.

---

## 4. eBay orders, separated — and what we deliberately did *not* do

`GET /api/v1/admin/orders` gains:

- `?channel=normal|ebay|all` — **default is still `all`**
- `?in_transit=1`
- `meta.channel` and `meta.channel_counts: { normal, ebay }` — always present,
  always counted across all orders regardless of the current filter
- `channel` and `in_transit` on every row, and on the detail payload

**The default stays `all` on purpose.** Flipping it to `normal` would have made
the separation happen with no frontend work — and would have silently dropped
eBay orders from every other consumer of this endpoint, including the ops mobile
app from Session 65. A data change dressed up as a feature. The split belongs in
the UI, so:

- **Orders page** → pass `channel=normal`
- **new eBay Orders page** → pass `channel=ebay`
- use `meta.channel_counts.ebay` on the Orders page to show "42 eBay orders —
  view separately", so the split is discoverable rather than something the user
  has to already know about

There is an existing `GET /admin/ebay/orders`, but it is behind `ebay.manage`
(super_admin + admin only) — **an order manager cannot see it**. Use
`/admin/orders?channel=ebay` for the eBay page instead; it is under `orders.view`
like everything else.

`in_transit` is *paid and dispatched, not yet delivered* — the order manager's
cue that trade documents need sending. A dedicated queue view filtered on it is
the single most useful thing you can build from this note.

---

## 5. Documents: order managers decide now, not the system

**Uploading is no longer gated at all.** It previously required
`payment_stage >= deposit_paid`. An uploaded file records something that already
happened outside this system — an accountant's invoice, a bill of lading, a
document handed over at a port. Refusing to store it does not make it not exist;
it only means the one place everyone looks is missing it. This also covers
sending documents *after delivery*, which the old gate had no upper bound for
but sat behind a lower one a historical order could easily fail.

**Generating** a commercial invoice / packing list / delivery note still checks
payment stage, but is now overridable:

```jsonc
POST /admin/orders/{id}/generate-commercial-invoice
{ "override_gate": true, "override_reason": "Customer's bank needs it to release the transfer" }
```

- without the flag: `409`, `code: "document_generation_blocked_payment_stage"`,
  now carrying `overridable: true` and a message that names the escape hatch
- with the flag but no reason: `422`, `code: "override_reason_required"`
- with both: proceeds, recorded as `document_gate_overridden` on the order

Show the 409 as a confirm dialog with a reason field rather than a dead end. The
gates exist for a real reason (Session 76 — a buyer was e-mailed about a deposit
nobody had asked him for), but a refusal the accountable person cannot override
just moves the work outside the system, where nothing is recorded at all.

**Unchanged:** the customer-facing rule that a commercial invoice stays hidden
from the buyer until the order is fully paid. That is a different control, and
the ask was about the admin side.

---

## 6. Suggested screens, in the order I'd build them

1. **Operations board** on the admin dashboard — the grid from the sketch, with
   `definitions` as tooltips and a non-zero `invoice_variance` linking through.
2. **In-transit queue** — `orders?in_transit=1`, with a "documents sent?" column
   from the existing `trade_documents` payload. This is the one that saves the
   order manager real time.
3. **Sign-off panel** on order detail — four states, `you_may_sign` driving the
   button, `history` behind a disclosure.
4. **eBay Orders page** — `orders?channel=ebay`, plus the count banner on the
   normal Orders page.
5. **Finance invoices** — a simple table plus an entry form, and the
   reconciliation view behind the variance.
6. **Role picker** — add the five roles the database has been refusing.

---

## 7. Nothing here breaks

No existing endpoint changed shape or started returning fewer rows. The whole
feature is inert until its migrations run: `signoff` reports `not_required`,
the finance column is a structural zero flagged in `meta`, and the order list
and item-edit paths do not touch the new tables. That is tested, not assumed —
an existing test caught the one path where it was not true.

---

## Addendum — Session 84, answering the frontend report

All three findings were right, and all three are fixed. Nothing you have built
needs changing; two things you worked around can now be deleted.

### `you_may_sign` is on the order detail — and `you_may_revoke` with it

You were right that it could not be derived: the same-person rule compares
`admin_user_id` and the payload carries a display name. `state()` now takes the
viewer, so `GET /admin/orders/{id}` → `data.signoff` carries both arrays and the
second request to `/signoffs` can go.

```jsonc
"signoff": {
  "…": "…",
  "you_may_sign":   ["finance"],   // slots this viewer can sign right now
  "you_may_revoke": ["ops"]        // standing signatures this viewer can withdraw
}
```

`GET /admin/orders/{id}/signoffs` now returns the **identical** shape — a test
asserts the two are the same object, because one being a superset of the other
is how they drift.

**`you_may_revoke` replaces the rule you reimplemented.** Your reading was
right — withdrawal is a pure role check with no same-person rule, satisfied by
the slot's own permission *or* by `orders.signoff_bypass`. The bypass half is
the part that is hard to see from the payload, which is why it now comes from
the server. The array is already filtered to slots that actually have a standing
signature, so `you_may_revoke.includes(slot)` is the whole condition.

Good catch on Withdraw being offered regardless of role. That was a real hole in
the instruction, not just in the implementation.

### The order list now carries document state

Three fields on every row of `GET /admin/orders`, as SQL aggregates — no
relation, no request per row:

```jsonc
{ "documents_count": 2, "documents_sent_count": 1, "last_document_sent_at": "2026-08-10T09:00:00+00:00" }
```

`null` rather than `0` when the aggregate was not selected, so a caller can tell
"none sent" from "not asked". The in-transit queue's "documents sent?" column is
real now — and it was the right call to say plainly that the list did not carry
it rather than assert something you had not been told.

### `orders.view` includes `support`, and the note's omission is fixed

`finance` was in the permission map from the start; the note's table omitted it,
which is a documentation bug and the one that mattered most — a finance admin
who cannot open the order page cannot give the signature the feature exists to
collect. The note is corrected.

**On `support`: I have granted it, so keep your UI as it is.** The panel has
been offering the Orders page to support all along and the API has been refusing
it, so the page 403'd — the divergence was already broken, and granting is the
right half to move. A support role that cannot see an order cannot answer the
commonest support question there is. Read only: `orders.update` and both
sign-off permissions stay off it.

### On settling divergences generally — including `analytics.view`

Don't map roles to pages in the frontend at all. The auth payload already
returns `permissions` for the signed-in user (`AuthController:154`, and the same
on both 2FA paths) — an array of permission keys from the same
`AdminPermissions::MAP` the API enforces. Key page visibility off that and this
class of divergence stops existing: a grant made server-side reaches the UI on
the next login, and a page can never be offered for a call that will 403.

That is the durable answer to the `analytics.view` question rather than my
listing its roles here for you to hardcode again. If a page still needs a role
list after that, tell me which permission it should be gated on and I will add
it to the map.

### Please do commit and push

Yes — push it. The backend for all of the above is on `main`.

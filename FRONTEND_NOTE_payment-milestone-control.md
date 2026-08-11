# Frontend note — payment milestones become admin-driven, custom document types, EU certificate fix

**Session 76 · backend built and tested, pending deploy · migration #31 (`order_logs.action` ENUM)**

Four things the order manager reported, plus one latent bug found on the way.
Two of these change contracts you are already consuming — read those first.

---

## 1. The order that marked itself paid

**What she saw:** she recorded an order by hand, set it to `confirmed`, and it
came out paid.

**What was happening:** `POST /admin/orders/{id}/mark-paid` required
`payment_method === 'bank_transfer'`. Admin-created orders have
`payment_method: null` — the create endpoint never sets it — so that endpoint
**422'd on every manually recorded order**. Ticking "paid" on the creation form
was the only way to reach a paid order at all, which meant declaring the money
received before it was.

**Now:** `mark-paid` accepts any order whose payment is settled off-platform —
bank transfer, admin-recorded, imported. Only Stripe orders are refused, with:

```json
{ "message": "This order is paid through Stripe. Its payment status is set by the gateway, not by hand.",
  "code": "gateway_managed_payment" }
```

**What to change:** show the "Mark as paid" action on manual orders — it now
works. And on the create-order form, `payment_status` should default to
`pending`, not `paid`. `paid` at creation is still correct and still supported
for **historical** orders being backfilled (it keeps defaulting `payment_stage`
to `balance_paid`, exactly as `FRONTEND_NOTE_historical-orders-onboarding.md`
documents — that has not changed). It is the wrong default for a live order.

---

## 2. The deposit request nobody sent — ⚠️ contract change

**What she saw:** a customer opened his portal, found `Deposit Requested — 50%`,
`Deposit Paid`, `Balance Due` and queried a payment he had never been asked for
and had not made.

**What was happening:** generating a proforma invoice called
`setDepositMilestones()`, which advanced `payment_stage` from
`pending_proforma` to `deposit_requested` **and emailed the customer that a
deposit was due**. No admin decided any of that. Issuing a document did it.

**Now:** issuing a proforma still calculates and stores `deposit_amount` and
`balance_amount` — that is arithmetic and costs nothing — but does **not**
advance the stage and does **not** email anyone. Reversible with
`PAYMENT_MILESTONES_AUTO_START=true` in `.env` if the business ever wants the
old behaviour back; no code change needed.

### New endpoint — start the ladder deliberately

```http
POST /api/v1/admin/orders/{id}/payment-milestones/request-deposit
```

| Field | Type | Notes |
|---|---|---|
| `deposit_percent` | number, optional | 0.01–100. Falls back to the order's own, then to 50. |
| `deposit_amount` | number, optional | An agreed round figure. **Wins over `deposit_percent`**; the percentage is derived from it. |
| `notify_customer` | boolean, optional | **Defaults to `false`.** |
| `notes` | string, optional | Max 500, goes to the audit trail. |

`notify_customer` defaults to false on purpose: the common case is bringing the
record in line with a conversation that already happened by phone or e-mail, and
a duplicate "your deposit is due" is worse than silence. Make it a visible,
unticked checkbox — "Also e-mail the customer" — not a hidden default.

Returns `200` with the milestone object, plus `email_sent` and `email_warning`.

Errors: `409 invalid_payment_stage` if the ladder has already started,
`422 deposit_exceeds_total`, `422 order_total_missing`.

### `deposit-paid` is more forgiving

`POST .../payment-milestones/deposit-paid` now accepts `pending_proforma` as
well as `deposit_requested`. Money sometimes just arrives — against a quote,
after a call — and refusing to record a payment already in the bank because the
buttons were pressed in the wrong order helps nobody. The deposit/balance split
is backfilled automatically when it was never set.

### ⚠️ New field — `payment_milestones_active`

On the customer order payload (`GET /api/v1/orders/{ref}`), on the admin order
detail, and on every milestone endpoint response:

```json
{ "payment_stage": "pending_proforma", "payment_milestones_active": false }
```

**Gate the whole milestone panel on this, in the customer portal especially.**
`pending_proforma` is the resting state of every order — it is not a milestone,
it means nobody has started. Rendering the ladder on it is what produced the
original complaint.

Also note: the screenshot she sent shows all five stages rendered with
`Email not sent / Resend` under each, on an order at stage one. Whatever the
current stage is, the panel should make clear which steps have actually
happened and which are just the remaining shape of the process — a stage that
has not been reached has no e-mail to resend, so the Resend control does not
belong there.

---

## 3. Document upload — "File as" and "Document type" now take custom values

New endpoint feeding both dropdowns, so the lists stop being hardcoded in the
admin panel and drifting from the backend:

```http
GET /api/v1/admin/trade-documents/upload-options
```

```json
{
  "data": {
    "document_types": [
      { "value": "commercial_invoice", "label": "Commercial Invoice (CI)",
        "supersedes": true,  "custom_label_required": false },
      { "value": "shipment_document", "label": "Shipment Document (BOL, CMR, …)",
        "supersedes": false, "custom_label_required": false },
      { "value": "other", "label": "Other — type your own",
        "supersedes": false, "custom_label_required": true }
    ],
    "file_as_suggestions": ["Bill of Lading", "Certificate of Origin"],
    "file_as_free_text": true
  },
  "meta": { "file_as_max_length": 100 }
}
```

**"File as" (`type_label`)** has always been free text on the API — max 100
chars, no allowlist. The closed dropdown was purely frontend. Make it a combo
box: `file_as_suggestions` is what this Okelcor has actually filed things as
before, and she can type anything else.

**"Document type" (`type`)** stays a controlled vocabulary because it drives
real behaviour — supersede, payment gating, what the customer sees. But it now
ends in `other`, a plain filing bucket, so nothing is ever unfileable. When
`custom_label_required` is true, require a typed `type_label`.

`supersedes: true` means uploading that type retires the previous one of the
same type on that order — worth showing as a warning in the dialog. `other` and
`shipment_document` do not supersede: several at once is normal and expected.

Render `document_types` from this response rather than a local constant; adding
a type on the backend then needs no frontend deploy.

---

## 4. EU entry certificate — was broken for exactly the customers who need it

`POST /api/v1/auth/orders/{ref}/declaration` gated on
`payment_status === 'paid'`. A milestone order settles through `payment_stage`
(`deposit_paid` → `balance_paid`) and **nothing on that path ever writes
`payment_status`** — it stays `pending` for the life of the order.

So every reverse-charge EU B2B order taken on deposit-and-balance terms — the
normal way these are paid, and precisely the set of customers who need a
Gelangensbestätigung — was permanently refused. Paid in full, delivered, and
told payment must be confirmed first. Without the certificate Okelcor cannot
evidence the intra-community supply, so the zero-rating on that invoice is
unsupported in a tax audit.

Now gated on `Order::isFullyPaid()`, which was written for this and covers both
conventions.

### New field — `declaration_can_sign`

On the customer order payload:

```json
{ "declaration_required": true, "declaration_can_sign": true, "declaration_status": "pending" }
```

**Drive the Sign button off this, not off `payment_status`.** It is derived from
the same three conditions the endpoint enforces — reverse charge, fully paid,
delivered, not already signed — so the button can never offer an action that
422s, and more importantly can never hide one that would succeed. Any client
that recomputes this from `payment_status` will reproduce the bug.

---

## 5. Found on the way: the milestone audit trail never existed

`order_logs.action` is a MySQL ENUM. The milestone actions — `deposit_paid`,
`balance_due`, `balance_paid`, `shipment_released` — **were never in it**, and
every one of those writes sits behind a try/catch that logs a warning and
carries on. So MySQL has been rejecting them since the feature shipped and the
payment milestone history does not exist on production for any order.

Eleven values in total were being written and rejected. Migration #31 adds them.
Nothing to do on the frontend, but if you have ever wondered why an order's
history is thinner than its activity suggests, that is why — and note the rows
already lost are not recoverable.

---

## Nothing removed

No field or endpoint was removed or renamed. `payment_milestones_active` and
`declaration_can_sign` are additive; `request-deposit` and `upload-options` are
new; the rest are relaxed guards. A client that ignores all of it keeps working
exactly as it does today — except that manual orders can now be marked paid, and
proformas no longer e-mail customers a deposit request on their own.

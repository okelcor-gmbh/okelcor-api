# Frontend Note — Recording historical orders for onboarded customers

**From:** Backend · **Re:** admin flow for existing Okelcor customers being
added to the system with prior shipment/order history
**Status:** New endpoint built + tested. Everything else needed for this
flow (documents, shipment updates, customer portal visibility) already
existed — listed below so you can wire the whole flow in one pass.

---

## The scenario

Okelcor is adding customers they've already been doing business with —
people who have real past or in-progress orders/shipments that either
predate this system or were never entered into it. After the customer
record is created (existing `POST /admin/customers` flow), staff need to:

1. Record the order(s) that customer already has with Okelcor.
2. Attach the real documents for it (invoice, packing list, bill of lading, etc.).
3. If a shipment is still in transit, add tracking so the customer can watch it move.
4. When that customer accepts their invite and logs in, all of the above is
   just... there, in their portal — no separate "make it visible" step.

Point 4 works **automatically** and needed no new backend code: an order is
linked to a customer purely by matching e-mail address (not an internal ID),
so the instant an order's `customer_email` matches the customer's account
e-mail, it appears in their portal. The only new piece is point 1 — there
was previously no way to create a single order by hand from the admin side
(only a bulk CSV import or the public storefront checkout existed).

---

## 1. NEW — `POST /admin/orders` (permission: `orders.update`)

Creates one order directly, without a payment session or checkout flow —
built specifically for entering something that already happened.

```jsonc
// Request — either customer_id OR customer_name + customer_email is required
{
  "customer_id": 41,                    // preferred — pulls name/email from the customer record
  // "customer_name": "Acme Tyres GmbH",   // use instead of customer_id if no account exists yet
  // "customer_email": "buyer@acme-tyres.com",
  "customer_phone": "+49 30 1234567",   // optional
  "address": "1 Industrial Ave", "city": "Berlin", "postal_code": "10115", "country": "DE",

  "ref": "OKL-LEGACY-0042",             // optional — reuse the customer's own paper reference if they have one; auto-generated (OKL-XXXXX) if omitted
  "order_date": "2026-03-14",           // optional — backdates the record so it sorts correctly in order history

  "status": "shipped",                  // required: pending | confirmed | awaiting_proforma | processing | shipped | delivered | cancelled
  "payment_status": "paid",             // required: pending | paid | failed | refunded
  "payment_stage": "balance_paid",      // optional — see note below; only needed to override the default

  "carrier": "GLS",                     // optional, all four below can be added/edited later too
  "carrier_type": "road",               // sea | air | dhl | road | truck
  "tracking_number": "50044195855",
  "container_number": null,

  "admin_notes": "Backfilled from prior WhatsApp/email order history, March 2026.",

  // Line items are optional — if omitted, send a flat "total" instead
  "items": [
    { "sku": "CONTI-205-55-R16", "name": "205/55 R16", "brand": "Continental", "unit_price": 80, "quantity": 100 }
  ]
  // "total": 8000   // use this instead of "items" if exact line items aren't known
}
```

Response is the same shape as `GET /admin/orders/{id}` (201 on success), so
you can reuse your existing order-detail component to show it right after creation.

**Important — `payment_stage` default:** several other admin actions
(document upload, invoice visibility) are gated behind how far along payment
is, not just `status`. If you send `payment_status: "paid"` and don't
specify `payment_stage`, the backend defaults it to `balance_paid` (fully
settled) — correct for most historical orders. **If the order is still
mid-flight** (e.g. deposit received, balance still owed, currently in
transit), explicitly send `payment_stage: "deposit_paid"` or `"balance_due"`
so the UI doesn't misrepresent it as fully settled. Valid values, in order:
`pending_proforma → deposit_requested → deposit_paid → balance_due → balance_paid → shipment_released`.

Validation errors come back as standard 422 `errors.{field}` — e.g. missing
both `customer_id` and `customer_name`/`customer_email` returns errors on both name and email fields.

---

## 2. Attach documents — already built, no changes needed

`POST /admin/orders/{id}/trade-documents/upload` (multipart form, permission
already gated on the order's payment stage — see note above):

```
file:        (the actual scanned/original PDF, JPG, PNG, XLS, or CSV — max 20MB)
type_label:  "Commercial Invoice" | "Bill of Lading" | "Packing List" | anything descriptive
notes:       optional free text
```

This is a generic upload — `type_label` is a free-text label you choose, so
it works for any historical document type without needing a fixed dropdown
list on the backend. It only works once the order's `payment_stage` is at
`deposit_paid` or later (hence the note above) — a 409 with
`code: "document_generation_blocked_payment_stage"` means the stage needs
raising first via a `PUT /admin/orders/{id}` call.

List existing documents for an order: `GET /admin/orders/{id}/trade-documents`.

---

## 3. Add shipment tracking updates — already built, no changes needed

For an order still in transit, two levels are available, and both are
already live in the API:

**a. Manual timeline entries** (what most staff will use — no carrier
credentials needed):
```
POST /admin/orders/{id}/shipment-events   { "event_date": "2026-07-10", "status_label": "Left origin port", "location": "Hamburg", "description": "..." }
PUT  /admin/orders/{id}/shipment-events/{event}   (same body, edits an existing entry)
DELETE /admin/orders/{id}/shipment-events/{event}
```
Each of these also updates the order's `tracking_status` to the latest event automatically.

**b. Live carrier sync** (GLS / DHL / ocean freight incl. Maersk — pulls
real tracking data automatically, no manual typing):
```
GET /admin/orders/{id}/shipment-tracking
```
Works once `carrier` + `tracking_number` (or `container_number` for sea
freight) are set on the order — which you can do at creation time (§1) or
later via `PUT /admin/orders/{id}`.

**Recommended UI:** on the order detail page, a "Shipment" tab with a simple
add-event form (for carriers without live integration, or manual entries)
plus a "Sync now" button that calls (b) when the carrier is GLS/DHL/ocean.

---

## 4. Customer portal — nothing to build, just confirm it's wired

Once the customer accepts their invite and logs in, these existing
endpoints already return everything from the order(s) you just created —
confirm your portal pages are already calling them (most likely already are,
since they're the same endpoints every other order uses):

- `GET /auth/orders` — order list (do confirm this is the customer-facing route your proxy calls; it requires the customer's own bearer token)
- `GET /auth/orders/{ref}` — order detail, includes items, trade documents, shipment events
- `GET /orders/{ref}/trade-documents` — the documents list for that order
- `GET /orders/{ref}/tracking` — tracking state (`mode: "carrier"`, includes `tracking_url` for a public carrier tracking page even before any live sync has run)

Nothing about this scenario is different from any other order in the
system, so if your existing order/tracking/documents pages already work for
a normal order, they need zero changes here.

---

## Suggested admin UI flow

1. **Customers → Add Customer** (existing) → creates the account + sends invite.
2. On the new customer's detail page, add an **"Add historical order"**
   action opening a form for §1 above — keep it simple: customer is already
   selected, so just status/payment/carrier/items.
3. On the resulting order's detail page, surface the **Documents** tab
   (upload via §2) and **Shipment** tab (events + sync via §3) — these are
   the same tabs a normal order's detail page should already have; historical
   orders don't need special-cased UI, just entry via §1 to get them into the system.

---

## Please scan / confirm on your side

- Build the "Add historical order" form (§1) — this is the only genuinely new endpoint.
- Confirm the Documents and Shipment tabs already exist on the order detail
  page for normal orders; if so, historical orders need no extra UI work
  beyond creation.
- Double-check the customer portal order/tracking/document pages are already
  pointed at the endpoints in §4 — if they already work for a live website
  order, they'll work here with no changes.

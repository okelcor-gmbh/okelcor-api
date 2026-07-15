# Frontend Note — Order item editing (correcting wrong figures)

**From:** Backend · **Re:** admin order detail page
**Status:** Backend built + tested. There was previously no way to correct a
wrong price/quantity/product name on an order's line items at all — this
adds that.

---

## The problem this fixes

Order manager: *"there is no option for editing on manual orders we create
on the website... the figures are not true and if the client sees this it's
insulting."* Admins could already correct the delivery fee
(`PATCH /admin/orders/{id}/financials`) and change status/tracking, but
there was **no way to fix a line item itself** — wrong unit price, wrong
quantity, wrong product name/size. That's the actual gap this closes.

**Scope, exactly as asked:** this applies to orders created directly in the
system (manual/website orders, and the admin-backfilled historical orders).
It deliberately does **not** apply to eBay-sourced orders — those are
authoritative from eBay and get overwritten on the next sync, so editing
them locally would just be silently reverted, giving a false sense of
having fixed something. Check `source` on the order payload
(`"ebay"` vs `"website"`/`"admin_manual"`) to decide whether to even show
edit controls — but the backend also enforces this itself (403), so it's
safe either way.

---

## Two situations, two different endpoints

An order's financials get **locked** the moment a commercial document
(Order Confirmation, Proforma, or Commercial Invoice) has been issued for it
— `financials_locked_at` on the order payload tells you which state you're
in (`null` = unlocked). Use the right endpoint set for each:

### A. Unlocked order — edit directly, takes effect immediately

```
POST   /admin/orders/{orderId}/items                  — add a new item
PATCH  /admin/orders/{orderId}/items/{itemId}          — correct an existing item
DELETE /admin/orders/{orderId}/items/{itemId}          — remove an item
```

```jsonc
// PATCH body — send only the fields that changed, plus reason (always required)
{
  "unit_price": 75.00,
  "quantity": 8,
  "name": "205/55 R16",      // optional — sku/brand/size also editable
  "reason": "Wrong price was quoted at entry — corrected to the agreed €75/unit."
}
```

```jsonc
// Response (PATCH/POST) — same item shape either way
{
  "data": {
    "id": 412, "sku": "TYRE-1", "brand": "Continental", "name": "205/55 R16",
    "size": "205/55 R16", "unit_price": 75.0, "quantity": 8, "line_total": 600.0
  },
  "message": "Item updated successfully."
}
```

- `reason` is **required** on every add/edit/delete — it's written to the
  order's audit log (already-existing order history/timeline UI), so make
  it a visible, required field in the edit form, not an afterthought.
- The order's `subtotal`/`total` update automatically — re-fetch the order
  (or just add the returned delta) after any item change to refresh the totals shown.
- You cannot delete the only remaining item on an order (422,
  `code: "cannot_delete_last_item"`) — edit it instead, or cancel the order.

### B. Locked order — request a revision, a second admin approves it

This is the **existing** revision-request/approval workflow
(`POST /admin/orders/{id}/financials/revision-request` →
`POST /admin/orders/{id}/financials/approve-revision`), which previously
only supported correcting the delivery fee. It now also accepts item
changes in the same `changes` object:

```jsonc
// POST /admin/orders/{id}/financials/revision-request
{
  "reason": "Client disputes the quoted unit price — confirmed correct figure with them by phone.",
  "changes": {
    "items": [
      { "id": 412, "unit_price": 70.00 }              // correct an existing item — id required
    ],
    "new_items": [
      { "name": "Extra tyre", "unit_price": 50, "quantity": 2 }   // add a missed item
    ],
    "remove_item_ids": [413]                            // remove a duplicate/wrong line
    // "delivery_fee": 45.00   — still supported, unchanged
  }
}
```

This **stores** the proposed change and does not apply it — a second admin
(`orders.approve_financial_revision` permission — super_admin/admin only)
must call `approve-revision` to actually apply it, at which point:
- Any issued Order Confirmation / Proforma / Commercial Invoice is
  automatically superseded (marked invalid — regenerate them after).
- The order's items/totals update to the corrected figures.
- The order re-locks under the new figures.

**UI implication:** if `financials_locked_at` is set, don't show the
direct-edit form — show "Request a revision" instead, which is the same
form fields but submits to `revision-request` and needs a second admin to
approve before it takes effect. This is intentional friction for orders
that already have paperwork out the door, not a bug.

---

## Error responses to handle

| Status | `code` | Meaning | Suggested UI |
|---|---|---|---|
| 403 | `ebay_order_not_editable` | Order is eBay-sourced | Don't show edit controls at all for these; if you do surface the attempt, this message is already customer/staff-appropriate to display as-is |
| 423 | `financials_locked` | Order has an issued document | Switch to the revision-request flow (§B) |
| 422 | `cannot_delete_last_item` | Attempted to delete the only item | Block the delete button when only one item remains, rather than letting it fail |
| 422 | `revision_would_empty_order` | An approved revision would remove every item | Only relevant to the approver's review screen — validate this client-side too if you build a preview |
| 403 | (permission) | Role lacks `orders.update` | Standard permission-denied handling, same as everywhere else |

---

## Suggested UI

On the order detail page's existing line-items table:
- Each row gets an inline "Edit" affordance (opens a small form:
  price/quantity/name + required reason) when the order is unlocked and
  not eBay-sourced.
- A disabled/greyed state with a tooltip ("Synced from eBay — edit in eBay")
  when `source === "ebay"`.
- A "Request revision" button instead of inline edit when
  `financials_locked_at` is set — opens the same field set but submits to
  the revision-request endpoint, and shows a pending-approval badge on the
  order until `approve-revision`/`reject-revision` is actioned.
- An "Add item" row at the bottom of the table (unlocked, non-eBay only).

---

## Please scan / confirm on your side

- Gate the edit UI on `source !== "ebay"` and `financials_locked_at` as
  described — the backend enforces both regardless, but showing controls
  that will just 403/423 is bad UX.
- Make `reason` a required, visible field on every item mutation — it's the
  whole point of the audit trail this writes to.
- Confirm the order detail page already shows `financials_locked_at` /
  order history somewhere staff can see it, so a revision-pending order is obviously not "done."

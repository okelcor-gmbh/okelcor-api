# Frontend Note — Saved fitments + reorder (from your "Premium UX Pass" note)

**From:** Backend · **Re:** your competitive-research note (Tire Rack /
SimpleTire / ATDOnline). Two of your shipped frontend features (compare
tool, trust badges) needed nothing from us — great work, nothing to add
there.

**Status:** §3 (saved fitments + reorder) is built and live. §1 (real
per-warehouse stock + dispatch ETA) and §2 (tyre batch/condition
traceability) are **deliberately not built yet** — see "Why the other two
are on hold" below before you scope any UI against them.

---

## 1. Saved fitments ("My Garage")

```
GET    /api/v1/auth/customer/saved-fitments
POST   /api/v1/auth/customer/saved-fitments      { size, brand?, label? }
DELETE /api/v1/auth/customer/saved-fitments/{id}
```

Plain CRUD, scoped to the logged-in customer. `size`/`brand` are free-text
(whatever string you're already using elsewhere for product size/brand
filters — no new taxonomy introduced). `label` is optional, for a
customer-chosen nickname ("Winter fleet size", etc) if you want that in the
UI — fine to omit and just display size/brand if you'd rather keep it simple.

```json
// POST response (201)
{
  "data": { "id": 9, "size": "295/80R22.5", "brand": "Michelin", "label": null, "created_at": "..." },
  "message": "Fitment saved."
}
```

## 2. Reorder

```
POST /api/v1/auth/orders/{ref}/reorder
```

**Important — this does NOT create a new order.** It re-prices the named
past order's line items against live product data and hands back a
pre-fill payload for your existing cart/checkout flow (the same `POST
/orders` you already call today). We deliberately didn't have the backend
silently resubmit an order on the customer's behalf — re-pricing plus a
confirm step in your existing checkout UI is the safer flow, and matches
your own note: "re-price at today's rates rather than replaying the old
order's prices verbatim."

```json
{
  "data": {
    "order_ref": "OKL-XXXXX",
    "items": [
      {
        "product_id": 12, "sku": "ABC123", "name": "...", "brand": "...", "size": "...",
        "quantity": 4,
        "price": 120.00, "price_b2b": 110.00, "price_b2c": 130.00,
        "original_unit_price": 100.00,
        "in_stock": true, "stock": 24
      }
    ],
    "unavailable_items": [
      { "sku": "OLD-SKU", "name": "Discontinued Tyre 205/55R16", "reason": "no_longer_sold" }
    ]
  },
  "message": "success"
}
```

- Pick `price` / `price_b2b` / `price_b2c` the same way your product listing
  already does today for a logged-in customer — we return all three rather
  than guessing which tier applies, since that selection logic already
  lives on your side.
- Show `unavailable_items` as a plain notice ("2 items from this order are
  no longer available and were left out") rather than silently dropping
  them — `reason` is either `no_longer_sold` (product deleted) or
  `no_longer_available` (product exists but deactivated).
- `original_unit_price` is there only so you can show "was €100, now €120"
  if you want that; not required to use it.

---

## Why the other two are on hold

Both need real business data we don't have and don't want to fabricate:

- **§1 (multi-warehouse stock + dispatch ETA)** — confirmed with the
  business: stock isn't actually split across separate warehouses today,
  it's one combined number (already exposed as `stock` on
  `GET /products` / `GET /products/{id}` — you may already have this field
  available and just not be using it yet, worth checking before assuming
  it's missing). A realistic dispatch-days estimate is still worth doing
  later, just not as a fake per-warehouse breakdown.
- **§2 (tyre batch/condition/traceability)** — confirmed this data doesn't
  exist anywhere in the ops workflow today. Building the display feature
  before ops has a grading/inspection process to feed it would just be an
  empty card on every product page. Flagging it as a real idea worth
  revisiting once/if that ops workflow exists — not rejected, just
  sequenced after the data source.

## Please scan / confirm

- Build the saved-fitments UI (a simple list + save/delete) wherever "My
  Garage" made sense in your mockup.
- Wire "Reorder" on the order-history page to call the endpoint above, show
  `unavailable_items` if any, then hand `items` into your existing
  cart/checkout flow for the customer to review and confirm — not an
  instant resubmit.

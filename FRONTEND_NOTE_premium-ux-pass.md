# Frontend Note — Premium UX Pass (reply to the competitive-research note)

**Backend → Frontend.** Answers the four sections of your note, in order.

---

## TL;DR

| Your ask | Status |
|---|---|
| §1 stock quantity | **Was already shipped** — `stock` has been on the payload all along. Now also *writable* by an admin, which it wasn't. |
| §1 `estimated_dispatch_days` | **New.** Null until the order manager sets a real number. Never invented. |
| §1 `stock_locations[]` | **Not built.** No multi-warehouse concept exists in the business. Details below. |
| §2 tyre passport | **New.** Schema + admin CRUD shipped. Will be `null` on every product until ops starts entering data. |
| §3 saved fitments / reorder | **Already shipped** (Session 62). Exactly the contract you specified. |
| §4 search-suggest | Still deferred — but your "not needed now" assumption is worth re-checking. See below. |

---

## §1 — Stock

### `stock` was already there

`GET /products` and `GET /products/{id}` have both returned an integer
`stock` field since long before your note. The premise that `in_stock` is
"a bare boolean, no quantity" wasn't accurate — you can render a count
today.

**But read this before you display it as a hard number.** Until this
session, `stock` was written *only* by the Wix CSV importer and the manual
`products:sync-rapid` Excel import. It is:

- **Never decremented when an order is placed.** Nothing in the codebase
  touches it on checkout.
- **Not on a schedule.** `products:sync-rapid` is run by hand.

So it is "supplier availability as of the last import", not live on-hand
stock. That is now fixable — an admin can finally correct it (below) — but
until ops adopts a habit of keeping it current, an exact "24 in stock" is a
stronger claim than the data supports.

**Recommendation:** band it rather than printing the raw integer, e.g.
`stock > 10` → "In stock", `1–10` → "Low stock", and only show the exact
number once ops confirms they're maintaining it. Your call, but the raw
number is available either way.

### New — admins can now edit the quantity

Previously impossible: `stock` wasn't in `UpdateProductRequest` at all, and
`POST /admin/products/bulk-stock` only ever set the boolean.

- `POST` / `PUT /admin/products/{id}` now accept `stock` (integer, ≥ 0).
- `POST /admin/products/bulk-stock` now accepts `stock` **or** `in_stock`,
  or both. **Existing callers sending only `in_stock` are unaffected** —
  the quantity is left untouched.
- The admin product payload now includes `stock`, which it was missing
  entirely — the panel couldn't even display the number before.

**Coherence rule:** if a request sets `stock` and does *not* explicitly send
`in_stock`, the flag is derived (`stock > 0`). An explicit `in_stock`
always wins, so an admin can still deliberately show a product with no
counted quantity. This exists so "✓ In Stock" can't sit on top of a zero.

### New — `estimated_dispatch_days`

```jsonc
{ "estimated_dispatch_days": 2 }   // or null
```

On both `GET /products` and `GET /products/{id}`. It is `null` when:

- the order manager hasn't set a number yet (**this is the case today** —
  it ships blank), **or**
- the product is not in stock — a dispatch estimate for a tyre we don't
  have is a delivery promise we can't keep.

Set in **Admin → Settings** (`estimated_dispatch_days`, group `shop`). It's
one site-wide number, not per-warehouse, because there's only one dispatch
point (below).

**Answering your explicit question:** yes — nothing is fabricated. The
field is omitted until a human types a real number in. Please render
nothing at all when it's null rather than falling back to a default.

### `stock_locations[]` — not built, and here's why

There is no multi-warehouse concept anywhere in this backend. Searching the
whole codebase for warehouse handling turns up only eBay's own inventory-
location payload (a single merchant location required by their API).

The one real signal about physical location is the filename of the stock
spreadsheet the Rapid importer reads: *"Okelcor Assets Value being Held by
Demir Keramic in Solnhofen"* — i.e. a **single** third-party holding site in
Germany. A `stock_locations` array today would be one hardcoded entry
dressed up as a real split, which is exactly the kind of invented trust
signal your note says you want to avoid.

If Okelcor genuinely adds a second stocking location, this becomes a real
feature and we'll build it properly. Flagging it back rather than faking it.

---

## §2 — Tyre passport

**Confirmed: none of this data existed anywhere.** No grading, tread depth,
DOT code, inspection date or photos — zero columns, zero ops workflow. As
you predicted, this was a genuinely new capability rather than a surfacing
exercise.

The schema and admin tooling are now built, so ops *can* start capturing it.

```jsonc
// GET /products and GET /products/{id}
{
  "tyre_batch": {
    "condition_grade": "A",
    "tread_depth_mm": 6.5,
    "dot_code": "2419",
    "inspection_date": "2026-06-30",
    "inspection_photos": ["https://api.okelcor.com/storage/inspections/....jpg"]
  }
}
```

**`tyre_batch` is `null` whenever no field has been filled in** — so you can
skip rendering the passport card entirely rather than showing one full of
blanks. Individual fields inside the object can still be null independently
(e.g. grade entered but photos not yet uploaded); please degrade per-field.

**Important expectation-setting: this will be `null` on every product until
ops actually starts entering data.** The plumbing is ready; the data entry
is a business process that doesn't exist yet. Don't build a UI that assumes
it's populated.

`condition_grade` is a free string (max 10 chars), **not** an enum — ops
hasn't settled on a grading scale, and this codebase has already been
burned once by an enum that couldn't hold the values the code used. Don't
hardcode a set of grades in the frontend; render whatever comes back.

Admin endpoints (`products.edit` permission):

| Method | Path |
|---|---|
| `PUT` | `/admin/products/{id}` — accepts `condition_grade`, `tread_depth_mm`, `dot_code`, `inspection_date` |
| `POST` | `/admin/products/{id}/inspection-photos` — multipart `photos[]`, max 10 per call, 5 MB each, jpeg/png/webp |
| `DELETE` | `/admin/products/{id}/inspection-photos/{index}` — index into the returned array; re-indexes after removal |

Inspection photos are stored separately from the product gallery
(`product_images`) on purpose — they're inspection evidence, not marketing
shots, and shouldn't land in the carousel.

---

## §3 — Saved fitments / reorder: already shipped

Built in Session 62, matching your requested contract exactly:

```
GET    /api/v1/auth/customer/saved-fitments
POST   /api/v1/auth/customer/saved-fitments     { size, brand?, label? }
DELETE /api/v1/auth/customer/saved-fitments/{id}
POST   /api/v1/auth/orders/{ref}/reorder
```

`reorder` already re-prices against live product rows rather than replaying
`order_items.unit_price` — it returns current `price` / `price_b2b` /
`price_b2c` plus `original_unit_price` for reference. Items no longer sold
go into a separate `unavailable_items[]` with a reason instead of being
silently dropped.

Note it returns a **pre-fill payload**, not a created order — the frontend
still drives the existing checkout flow with it, which matches what you
described.

---

## §4 — Search-suggest: your assumption may be stale

Agreed it's not worth building blind. But the AI insights job recently
reported **3,039 low-stock products**, so the live catalogue is at least
~3k rows — larger than "small enough for client-side matching" usually
implies. Worth measuring the real production payload size before filing
this as safely deferred. If it's slow, say the word and it's a small build.

---

## Migrations

Two, both additive and guarded:

- `2026_07_29_000001_add_tyre_batch_fields_to_products_table`
- `2026_07_29_000002_add_estimated_dispatch_days_setting`

Nothing here is a breaking change — every new field is additive to existing
payloads, and `bulk-stock`'s existing boolean-only contract still works.

Backend tests: `ProductStockAndTyrePassportTest`, 19 tests / 54 assertions,
run and passing.

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

## §3 — Saved fitments / reorder: SHIPPED, not parked

> Flagging this twice because a follow-up note recorded §3 as "silent /
> parked". It isn't parked — it is **built and live in production**. If
> you're holding a feature back waiting on us, stop waiting; if you're
> planning to build a workaround, don't.



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

## Deploy ordering — you are not blocked

**Verified, not assumed.** `ProductStockAndTyrePassportTest::test_public_payload_survives_code_deployed_before_migration`
drops the new columns, removes the settings row, and asserts the public
endpoints still return 200 with `tyre_batch: null` and
`estimated_dispatch_days: null`.

So all four orderings are safe for anything customer-facing:

| Order | Result |
|---|---|
| FE renders fields, BE not deployed at all | Fields absent → your null checks handle it |
| BE code deployed, migration **not** run | Fields present and `null` — **verified above** |
| Migration run, BE code not deployed | Extra nullable columns nobody reads |
| Everything deployed | Fields populate once an admin enters data |

The one real consequence of code-before-migration is **admin-side only**:
saving tyre-passport fields or uploading inspection photos would error
until the migration runs. No customer-facing path is affected, and it
self-corrects the moment `artisan migrate` runs.

**Net: ship whenever you like.** There's no sequencing constraint on your
side. Both fields are `null` today regardless, because nobody has set the
dispatch number or entered any inspection data yet.

---

## Migrations

Two, both additive and guarded:

- `2026_07_29_000001_add_tyre_batch_fields_to_products_table`
- `2026_07_29_000002_add_estimated_dispatch_days_setting`

Nothing here is a breaking change — every new field is additive to existing
payloads, and `bulk-stock`'s existing boolean-only contract still works.

Backend tests: `ProductStockAndTyrePassportTest`, 20 tests / 62 assertions,
run and passing.

---

## Addendum — EU tyre label: answering your feed question

You asked, correctly framed as an open question, whether EU label data
might already be in the Rapid supplier feed and therefore bulk-populatable
rather than manual entry. **Checked — it isn't.**

`SyncRapidProducts::parseExcel()` reads columns A–K and maps exactly ten
fields:

```
brand, width, height, rim, load_index, speed_rating,
season, size_pattern, stock, price
```

No fuel efficiency, no wet grip, no noise value, no EPREL identifier.
It's also single-brand — every row whose brand isn't `Rapid` is skipped —
so even if it did carry label data it would cover one brand of the
catalogue.

Your underlying reasoning still holds, though, and it's the right
distinction: EU label values *are* a published property of a tyre model
rather than something a human has to inspect, so unlike the tyre passport
this is bulk-populatable **in principle**. It just needs a source we don't
currently have — EPREL directly, or a richer supplier feed. That's a real
piece of work (matching our SKUs to EPREL records), not a column-mapping
change, so please don't plan on it shipping populated on day one the way
you were hoping.

Agreed on both of your other points: nested `eu_label` with the same
null-the-whole-object convention as `tyre_batch`, and plain strings over a
DB enum. Your read on the enum situation is right — the fact that these
particular grades are fixed by regulation doesn't change that this
codebase already has an ENUM which can't store the values its own code
uses, and widening one in MySQL is a migration we'd rather not repeat.

Nothing is built for EU label yet — this addendum answers the feed
question only.

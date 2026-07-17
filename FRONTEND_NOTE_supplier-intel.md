# Backend Note — Supplier Intel, fixed to actually fit what Okelcor sells

**From:** Backend · **Re:** "the current supplier intel isn't exactly what
Okelcor would work with"
**Status:** Live. No breaking changes to the existing `search` /
`alibaba-link` endpoints — same URLs, same auth, richer responses. Two new
endpoints added.

---

## What was actually wrong

Okelcor sells four tyre categories (`pcr`, `tbr`, `used`, `otr` — the same
four slugs everywhere else in this API). The old supplier-search query
builder only recognized the passenger-car size format (`225/45R18`). Two of
your four categories were silently broken:

- **TBR** (truck/bus) sizes often have a **decimal rim** — `295/80R22.5` —
  which the old regex couldn't match at all, so it fell back to sending eBay
  the raw, over-specific product name instead of a clean "brand + size"
  query, and got poor/empty results.
- **OTR** (off-the-road — loader/earthmover tyres) sizes look nothing like a
  passenger tyre — `23.5R25` or `20.5-25`, no three-digit width segment —
  so this category basically never returned anything useful. On top of
  that, OTR genuinely isn't an eBay category in practice (it's a
  freight-quote, B2B-wholesale category) — even a perfect query wouldn't
  have fixed it.

Fixed the query builder to recognize both notations, and **for OTR
specifically, the eBay call is now skipped entirely** (see below) rather
than returning junk results.

---

## What changed in the API

### 1. `GET /admin/supplier/search` — same endpoint, new optional `type` param + richer response

```
GET /admin/supplier/search?q=MICHELIN+295/80R22.5&type=tbr
```

`type` is optional (`pcr` | `tbr` | `used` | `otr`) — pass your product's
own `type` field when you have it (e.g. searching from a product page) so
the query parser picks the right size pattern. Omit it and it still works
like before, just less precisely tuned.

Response now includes a `summary` (aggregate stats over the eBay results —
useful on its own, not just a raw list) and `marketplace_links` (both
wholesale search links, so you don't need a second call for those in the
common case):

```json
{
  "data": [ /* same shape as before: title, price, currency, condition, seller, url, image, quantity_available */ ],
  "summary": {
    "count": 8, "currency": "EUR",
    "min_price": 89.00, "max_price": 145.50, "avg_price": 112.30
  },
  "marketplace_links": {
    "alibaba": "https://www.alibaba.com/trade/search?SearchText=...",
    "made_in_china": "https://www.made-in-china.com/products-search/..."
  },
  "meta": { "total": 8 },
  "message": "success"
}
```

For `type=otr`, `data` is intentionally empty and a `note` field explains
why (see above) — `marketplace_links` and `summary` (zeroed) are still
returned so the UI doesn't need a special case, just render the note.

### 2. New: `GET /admin/supplier/for-product/{id}` — search straight from an Okelcor product

The old flow required copy-pasting a product's brand/size into the search
box by hand. This builds the query directly from one of your own catalogue
products and includes Okelcor's own price alongside the market summary —
one call instead of "look up the product, copy its spec, paste into
search, cross-reference manually."

```json
{
  "data": [ /* ... */ ],
  "summary": { "count": 8, "currency": "EUR", "min_price": 89.00, "max_price": 145.50, "avg_price": 112.30 },
  "marketplace_links": { "alibaba": "...", "made_in_china": "..." },
  "your_product": {
    "id": 42, "sku": "MICH-29580225", "brand": "Michelin",
    "name": "Michelin XZE2+ 295/80R22.5", "size": "295/80R22.5", "type": "TBR",
    "price": 145.00, "price_b2b": 132.00, "price_b2c": 155.00,
    "price_vs_market_pct": 29.1
  },
  "message": "success"
}
```

`price_vs_market_pct` is `(your price − eBay avg) / eBay avg × 100` — a
quick "are we priced above or below the market resale price" signal. It's
a **resale-price benchmark, not a wholesale-cost analysis** (eBay listings
are retail, not purchase cost) — worth labeling that clearly in the UI so
it isn't read as more than it is. Only present when `summary.avg_price` is
non-null.

### 3. New: `GET /admin/supplier/made-in-china-link?q=...`

Same shape as the existing `alibaba-link` endpoint. Made-in-China is the
other major B2B wholesale sourcing marketplace for exactly this category
(bulk TBR/OTR from Chinese manufacturers) — a genuinely stronger channel
than Alibaba alone for those two types specifically.

---

## Suggested UI shape (not required, just what the response was designed for)

- A type filter/tabs on the supplier intel page (PCR / TBR / Used / OTR),
  passed through as `type` — makes the size-parsing accurate and lets OTR
  render its "not on eBay" note cleanly instead of an empty state that
  looks broken.
- On each product's admin detail page, a "Check market" button hitting
  `for-product/{id}` instead of a separate manual search — removes the
  copy-paste step entirely.
- Show `marketplace_links` as quick-open buttons next to the eBay results,
  not just returned from a separate call.

## Please scan / confirm

- Existing `search` / `alibaba-link` callers keep working unchanged — the
  new fields are additive, nothing was removed or renamed.
- If you already built a type selector anywhere for this page, wire it to
  the new `type` param — that's the actual fix.

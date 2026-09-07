# Frontend Note — eBay ↔ website price comparison on the pricing audit

**From:** Backend · **Re:** the eBay price audit gains a comparison view and
one-click adoption of eBay's price as the website price
**Why:** the website prices are stale; the live eBay prices are the ones the
business maintains, so the audit now lets you pull them into the site.

## The comparison data (already in the audit response)

`GET /api/v1/admin/ebay/audit` already returns everything the comparison
needs per row — no new read endpoint:

| Field | Meaning |
|---|---|
| `db_price` | What the website is showing (products.price) |
| `live.price` + `live.currency` | What eBay is live-showing buyers (from the snapshot) |
| `price_drift` | `live.price − db_price`, null when they match (±0.01) or no snapshot row |
| `meta.counts.price_drift` | How many rows are drifted |
| `meta.live.fetched_at` | Snapshot age — surface this; adoption uses the snapshot, so a stale one means stale adoptions |

Refresh the snapshot first with the existing
`POST /api/v1/admin/ebay/audit/sync-live` (202, takes a minute or two,
`meta.live.fetched_at` moves when done).

## New endpoints (both `ebay.manage` — super_admin, admin)

### Adopt one row

```
POST /api/v1/admin/ebay/audit/{productId}/adopt-ebay-price
```

No body. Sets the product's website `price` to the live eBay price.
**Does not call eBay** — eBay already shows that price; only our DB was
behind. Responses:

- `200` → `{ data: { id, price, old_price }, message }` (or a
  "already matches" message with no change)
- `422` → not listed / no SKU / no snapshot row for the SKU / live price is
  not EUR. The `message` says which; show it as-is.

### Adopt in bulk

```
POST /api/v1/admin/ebay/audit/adopt-ebay-prices
Body: { "ids": [1, 2, 3] }        // the rows the admin ticked (max 500)
  or: { "all_drifted": true }     // every drifted listed product
```

Returns:

```json
{
  "data": {
    "updated": [ { "id", "sku", "old_price", "new_price" }, ... ],
    "skipped": [ { "id", "sku", "reason" }, ... ]
  },
  "meta": { "updated_count": 3, "skipped_count": 1 },
  "message": "3 website price(s) updated from eBay."
}
```

In `all_drifted` mode, rows that simply aren't drifted (or have no snapshot
row) are silently not touched; in `ids` mode every requested row that could
not be changed comes back in `skipped` with a human-readable reason.

## Suggested UI on the audit page

- A "Price comparison" filter/tab showing only `price_drift !== null` rows,
  columns: SKU · product · website price · eBay live price · drift
  (red when eBay is lower, green when higher).
- Per-row "Use eBay price" button → the single endpoint.
- Header "Adopt all eBay prices (N)" button (N = `meta.counts.price_drift`)
  → `all_drifted: true`, with a confirm dialog quoting N, then re-fetch the
  audit.
- Keep the existing `apply-price` button as-is — that one is the opposite
  direction (push a new price to BOTH site and eBay).

## Direction cheat-sheet (do not mix the two up in labels)

| Action | Website | eBay |
|---|---|---|
| `apply-price` (existing) | ✏️ set to typed price | ✏️ pushed to typed price |
| `adopt-ebay-price` (new) | ✏️ set to eBay's live price | untouched (already correct) |

Every adoption is logged (`ebay_listing_logs` action `ebay_price_adopted`,
with old/new price and the snapshot timestamp) and audit-trailed.

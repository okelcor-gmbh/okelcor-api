# Frontend Note — Tier pricing (Tyre100 cost → website & eBay prices)

**From:** Backend · **Re:** the pricing model agreed with the order manager
(2026-09-07). Replaces the short-lived "adopt eBay price" flow — eBay is no
longer the price source; Tyre100 (the supplier) is.

## The model

```
cost     = products.cost_price          (the Tyre100 supplier price)
base     = cost × (1 + tier margin)     Premium 15% · Mid-range 20% · Budget 30%
website  = base × 1.03                  (Stripe fee baked in — payment method unknown up front)
eBay     = base × 1.095                 (no Stripe on eBay; eBay charges instead)
```

Every percentage is env-configurable (`PRICING_*_MARGIN_PERCENT`,
`PRICING_STRIPE_FEE_PERCENT`, `PRICING_EBAY_UPLIFT_PERCENT`) — no deploy to
correct them.

## Endpoints (all `pricing.manage` — super_admin, admin)

| Endpoint | What it does |
|---|---|
| `GET /admin/pricing/preview` | Every product with tier, Tyre100 cost, current price, computed website + eBay price, and the change applying would make. `meta`: counts (ready / missing_tier / missing_cost / would_change), the brand list, and the pricing model itself. |
| `POST /admin/pricing/set-tier` | `{tier, brand}` (whole-brand sweep) or `{tier, ids: []}`. Tier ∈ premium\|midrange\|budget. |
| `POST /admin/pricing/apply` | `{ids: []}` or `{all: true}` — writes the formula's WEBSITE price to `products.price`. Only rows with cost + tier are touched; `ids` mode reports skips with reasons. |

Both mutating endpoints 503 with a clear message until migration #68
(`products.price_tier`) has run.

## The eBay side needs no endpoint

`EbaySellingService` prices every offer it pushes (list, update, sync)
through the formula automatically when the product has cost + tier, falling
back to `products.price` otherwise. So after repricing, re-push the
listings (existing eBay page flows) and they carry the eBay-formula price.
The eBay Price Audit's `price_drift` now compares eBay's live price against
this expected formula price (`expected_ebay_price` field) — NOT against the
website price, which by design sits ~6% below the eBay price.

## Admin panel

New page: **Sales Channels → Tyre Pricing** (`/admin/pricing`) — brand→tier
sweep, per-row tier select, both channel prices side by side, apply
selected / apply all. Shipped with this note.

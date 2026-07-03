# Frontend Note — eBay order line-item pricing fix

**From:** Backend · **Re:** wrong per-item price on eBay orders with quantity > 1
**Status:** Fixed for new imports. Historical orders need a one-time backend
command run (see below) — no frontend action needed either way.

## What was wrong

For an eBay order with a line item quantity > 1, the admin panel showed the
**line total** as the **per-item price**, and multiplied it again for the
line total — e.g. 2 tyres at a true €75.14 each (€150.28 for the line)
displayed as "€150.28 each" with the same €150.28 as the total.

Root cause: eBay's API field `lineItemCost` is documented (developer.ebay.com)
as `unit price × quantity` — i.e. it's already the total for that line, not a
per-unit price. The import code was treating it as per-unit and multiplying
by quantity a second time. Confirmed against a real order.

Quantity-1 lines were never affected (the wrong formula happens to produce
the same result when quantity is 1) — this only hit multi-quantity eBay
orders.

## What changed

`EbayOrderSyncService::importOrder()` now divides `lineItemCost` by quantity
to get the correct unit price, and uses `lineItemCost` directly (undivided)
as the line total. **No API response shape changed** — `unit_price`,
`quantity`, and `line_total` on `order_items` are the same fields as always,
just correctly computed now. Nothing to change on the frontend; existing
order-detail rendering will just show the right numbers going forward.

## Historical orders

A new command finds (and can fix) eBay orders imported before this fix:

```bash
php artisan ebay:audit-line-item-pricing            # report only, no writes
php artisan ebay:audit-line-item-pricing --apply    # applies the correction
```

It only touches line items with quantity > 1 on eBay-sourced orders, and only
where the order's stored `subtotal` (which came straight from eBay's own
pricing summary, unaffected by this bug) doesn't match the sum of its line
items — i.e. it won't touch anything that's already correct. This is a
backend/ops task; flagging here so you know why some historical eBay order
totals in the admin panel might visibly change after it's run (the order's
own `total`/`subtotal` were always correct — only the per-item breakdown
updates).

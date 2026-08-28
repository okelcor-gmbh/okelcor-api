# Frontend Note — Order Profitability & Weekly Liquidity

Session 99. Backend-owned contract note for the finance feature set from the
finance discussion note.

**Status: built and tested, NOT yet deployed. Needs migrations #46–48 and a
`route:cache` rebuild (17 new routes).** Everything is deploy-order safe: the
order page shows `finance: null` and the finance pages answer with
`profitability_available: false` / `liquidity_available: false` until the
migrations run — render an "not available yet" state off those flags rather
than treating an empty list as "no data".

---

## 1. What this is

Two screens for finance, plus one block that rides on the existing order page:

1. **Per-order profitability** — the finalized revenue invoice the customer
   agreed to (with its PDF), the supplier invoices and fees against it, the
   computed profit, and finance's verification sign-off.
2. **The finance list, export and dashboard** — one row per order reference,
   a CSV finance can sign, and a month-by-month summary from January.
3. **The liquidity ladder** — the current ISO week plus the three ahead,
   bank balance and expected movements per week, rolling automatically.

Permissions follow the Session 83 split: **reading is `finance.view`**
(super_admin, admin, finance, order_manager), **writing is `finance.manage`**
(super_admin, admin, finance). The export additionally needs `orders.export`.
Key page visibility off the `permissions` array in the auth payload, as ever.

---

## 2. The order page block

`GET /api/v1/admin/orders/{id}` now carries:

```json
"finance": {
  "has_revenue_invoice": true,
  "revenue_invoice_number": "RE-2026-0815",
  "revenue_amount": 9800,
  "customer_agreed": true,
  "costs_total": 6440,
  "cost_lines": 3,
  "profit": 3360,
  "margin_percent": 34.3,
  "currency": "EUR",
  "verified": false
}
```

`finance` is `null` before the migration runs — treat that as "hide the
panel", not an error. This block is how "order tracking knows there is a
finalized invoice" — no second request needed for the summary.

## 3. Per-order endpoints

```
GET    /admin/orders/{id}/profitability                          finance.view
POST   /admin/orders/{id}/profitability/revenue                  finance.manage  (multipart)
GET    /admin/orders/{id}/profitability/revenue/download         finance.view
POST   /admin/orders/{id}/profitability/costs                    finance.manage  (multipart)
PATCH  /admin/orders/{id}/profitability/costs/{costId}           finance.manage
DELETE /admin/orders/{id}/profitability/costs/{costId}           finance.manage
POST   /admin/orders/{id}/profitability/costs/{costId}/file      finance.manage
GET    /admin/orders/{id}/profitability/costs/{costId}/download  finance.view
POST   /admin/orders/{id}/profitability/verify                   finance.manage
DELETE /admin/orders/{id}/profitability/verify                   finance.manage
```

### The GET payload

`data` carries `revenue` (null until recorded), `costs` (totals, split, and
`by_category`), `profit`, `verification`, `lines` (every cost line,
formatted), and `context` — what the system already believes about this
order's money: order total/currency/status, `counts_as_confirmed`,
`customer_acceptance_status`, and the tax invoice this API raised where one
exists (`system_invoice_number` / `system_invoice_amount`). Show `context`
beside the revenue form so finance types the figure with the evidence in
front of them.

### Recording the revenue invoice

`POST …/revenue` — fields: `invoice_number` (required), `amount` (required),
`currency` (optional, defaults to the order's), `issued_on` (optional date),
`customer_agreed` (optional bool, **defaults true** — a revenue invoice is by
definition the one the customer agreed to; send `false` to record the figure
while the agreement is still pending), `file` (optional PDF/JPG/PNG ≤ 20 MB,
same request — don't build a separate attach step, it exists as a fallback
only). Re-posting replaces the figure and the file.

`data.revenue.variance_from_order_total` is the invoiced-vs-ordered gap —
worth showing prominently; it is the number finance reconciles.

### Cost lines

`kind` is `supplier_invoice` or `fee`.

- A **fee requires `category`**: one of `stripe | ebay | bank | shipping |
  other` (422 otherwise). Render these as fixed choices.
- A **supplier invoice requires `supplier`** (name, free text). `reference`
  is the supplier's own invoice number, optional and not unique.
- `amount` required; `currency` optional (EUR default); `incurred_on`,
  `notes`, `file` optional.

**Currencies are matched, never converted.** A cost in a currency other than
the revenue invoice's appears in `costs.other_currencies` (e.g.
`{"USD": 500}`) and is **excluded** from `costs.total` and the profit;
`profit.mixed_currency` is `true`. Surface that plainly — the profit shown is
the same-currency profit, not the whole story for that order.

### Verification

- `POST …/verify` (optional `note`) — 422 with `code: no_revenue_invoice`
  until a revenue figure exists. Disable the button until then, with that
  reason.
- `DELETE …/verify` requires a written `reason` (min 5 chars).
- **Any change that moves the money withdraws the verification
  automatically** (revenue amount/currency, or a cost line's
  amount/currency/kind — note edits do not). The next GET simply shows
  `verified: false`; the withdrawal is in the order log. Warn before edits on
  a verified order: "this will withdraw finance's sign-off".

Everything lands in the order's existing log (`data.logs` on the order page):
`revenue_invoice_set`, `cost_line_added/updated/removed`,
`profitability_verified`, `profitability_verification_withdrawn`.

---

## 4. The list, export and dashboard

```
GET /admin/finance/profitability            finance.view
GET /admin/finance/profitability/export     finance.view + orders.export → CSV
GET /admin/finance/profitability/dashboard  finance.view
```

**List** — one row per order: ref, date, channel, customer, status, order
total, revenue invoice number/amount, `revenue_has_file`, supplier costs,
fees, costs total, profit, margin, `mixed_currency`, verified/by/at.
Filters: `from`, `to`, `channel=normal|ebay`, `verified=yes|no`,
`has_revenue=yes|no`, `q` (ref/customer), `per_page`. Paginated the usual
way. Scope matches the operations board: confirmed business only (cancelled
and Stripe test checkouts excluded). `meta.definitions` ships the meaning of
every figure — render it as the column tooltips rather than writing copy.

`verified=no` is the worklist ("what still needs signing");
`has_revenue=no` is the backlog ("orders with no revenue invoice yet").

**Export** — streams a CSV (UTF-8 BOM, Excel-safe). Defaults to January →
today when no dates are passed. One line per order ref including the
verification columns; a caveat row travels inside the file.

**Dashboard** — `?year=2026` optional, defaults to the current year. Months
are gap-free from January to the current month; each carries `orders`,
`orders_with_revenue`, `order_total_eur`, `revenue_eur`,
`supplier_costs_eur`, `fees_eur`, `costs_eur`, `profit_eur`,
`margin_percent` (null when no revenue), `verified`, `non_eur_orders`; plus
`totals` and `definitions`. Sums are **EUR-only** — non-EUR orders are
counted in `non_eur_orders`, never converted; say so in the UI footnote
(the definition string is provided).

---

## 5. The liquidity ladder

```
GET /admin/finance/liquidity                finance.view
GET /admin/finance/liquidity/history?weeks= finance.view
PUT /admin/finance/liquidity/{weekKey}      finance.manage
```

`GET /liquidity` always returns **exactly 4 weeks: the current ISO week plus
three** (`meta.current_week`, `meta.window`). Each week:

```json
{
  "week_key": "2026-W35", "label": "Week 35, 2026",
  "starts_on": "2026-08-24", "ends_on": "2026-08-30",
  "is_current": true, "recorded": true,
  "bank_balance": 42000, "expected_in": 10000, "expected_out": 6000,
  "projected_closing": 46000,
  "notes": null, "updated_by": "…", "updated_at": "…"
}
```

- **The window rolls itself.** When a week ends it disappears from this
  payload and the next week enters — nothing to trigger, no state to manage
  client-side. Old weeks are under `/history` (newest first, only weeks
  someone actually recorded, `?weeks=` up to 104, default 12).
- `projected_closing` chains: a week opens on its own `bank_balance` where
  entered, otherwise on the previous week's projected close, then
  `+ expected_in − expected_out`. Render it as the computed row — it is why
  four weeks are a ladder and not four unrelated numbers.
- `recorded: false` means no row exists yet — an empty editable week, not an
  error.

`PUT /liquidity/{weekKey}` upserts one week — body: `bank_balance` (may be
negative), `expected_in`, `expected_out` (≥ 0), `notes`. All optional;
omitted fields keep their value, explicit `null` clears. 422s: a key that is
not a real ISO week (`2026-W54`, `week-35`), or more than a year from today.
A week that has already ended is **accepted** (finance corrects history) but
the response says `is_closed: true` and the message notes it lives under
history — warn, don't block.

Week keys are zero-padded (`2026-W09`); always use the `week_key` strings the
API hands out rather than building them client-side.

---

## 6. Not in this build

- **No automatic Stripe/eBay fee capture.** The webhook does not retrieve
  Stripe's balance transaction and the eBay sync stores no fees, so fees are
  typed by finance as `fee` cost lines — the same deliberate
  not-an-integration stance as the sevDesk register (Session 83). If
  automatic capture is built later, it will arrive as pre-filled cost lines,
  same shape, no frontend change.
- No currency conversion anywhere, by design.
- Liquidity has no automatic bank feed — `bank_balance` is what finance
  types.

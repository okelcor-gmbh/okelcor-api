# Frontend Note — Order Profitability, Revenue/Supplier Invoices & Weekly Liquidity

**From:** Backend
**Re:** Finance's request — per-order profitability from the customer-agreed revenue invoice, supplier invoices and fee lines; an exportable signed-off list; a January-onwards dashboard; liquidity as a rolling 4-week window
**Status:** Live on the API. All endpoints below are under `/api/v1/admin/…`, Sanctum + 2FA as usual.

## The model in one paragraph

Every order keeps one **reference** (`ref`, e.g. `AB-1042`). Against it finance records invoices in the existing register (`finance-invoices`), where each row now carries a **role**: `revenue` (the invoice the customer agreed to), `supplier` (a cost), or `register` (plain reconciliation entry, the default — every pre-existing row). A revenue invoice only counts once it is **finalized** ("the customer has agreed"); finalizing locks its money fields. Fees with no invoice behind them (eBay, Stripe, bank charges) are **cost lines** on the order. Profit is always:

```
profit = finalized revenue invoice − supplier invoices − cost lines
```

Until a finalized revenue invoice exists, `profit` and `revenue` are `null` and `profitability_status` is `"awaiting_revenue_invoice"` — render that as a to-do, not as zero.

## Permissions

| Action | Permission | Roles |
|---|---|---|
| All GETs below (incl. export) | `finance.view` | super_admin, admin, finance, order_manager |
| All writes below | `finance.manage` | super_admin, admin, finance |

## 1. Recording invoices (extended existing endpoints)

`POST finance-invoices` (multipart allowed — same single-request file upload as before) accepts two new fields:

```jsonc
{
  "system": "upload",
  "external_number": "REV-2026-114",
  "order_ref": "AB-1042",        // REQUIRED when role is revenue or supplier
  "amount": 9500,
  "issued_on": "2026-08-28",
  "role": "revenue",             // register (default) | revenue | supplier
  "supplier_name": "Linglong BV",// REQUIRED when role=supplier
  "file": <pdf>                  // the uploaded PDF, as before
}
```

`GET finance-invoices` now supports `?role=revenue|supplier|register` and every row includes:

```jsonc
{
  "role": "revenue",
  "supplier_name": null,
  "finalized": true,
  "finalized_at": "2026-08-28T10:12:00+02:00",
  "finalized_by": "Edinah A"
}
```

### Finalizing — the customer-agreed moment

- `POST finance-invoices/{id}/finalize` → row becomes the order's revenue invoice; 409s you must surface:

| `code` | Meaning | Suggested UI |
|---|---|---|
| `amount_missing` | No amount on the row | Ask for the amount first |
| `order_unknown` | `order_ref` empty or names no order here | Ask them to set the order ref |
| `not_revenue` | Row is a supplier invoice | Explain, offer nothing |
| `revenue_invoice_exists` | Order already has a finalized revenue invoice (`data` = that row) | Offer "unfinalize the other one first" |
| `already_finalized` | This row is already finalized | Refresh state |

- `POST finance-invoices/{id}/unfinalize` → withdraws agreement; the order goes back to `awaiting_revenue_invoice`.
- While finalized, `PATCH` and `DELETE` on the row return **409 `finalized_locked`** — show the message; the flow is unfinalize → edit → finalize.

## 2. Cost lines (fees) on an order

- `GET orders/{id}/costs` → `{ order_ref, costs: [...], total, by_type: [...] }`, `meta.types` is the vocabulary:
  `ebay_fee, stripe_fee, bank_cost, shipping, customs, other` (labels included).
- `POST orders/{id}/costs` → `{ type, amount, label?, currency? }` (amount is a positive magnitude; negative allowed for a refunded fee). 201.
- `PATCH order-costs/{id}` / `DELETE order-costs/{id}`.

## 3. Profitability

### Per order — `GET finance/profitability/{ref}`

```jsonc
{
  "data": {
    "order": { "ref": "AB-1042", "date": "2026-08-02", "customer_name": "…", "channel": "ebay",
               "status": "delivered", "payment_status": "paid", "currency": "EUR",
               "subtotal": 9000, "delivery_cost": 400, "total": 9400 },
    "revenue_invoice": { /* the finalized one, or null */
      "id": 41, "external_number": "REV-2026-114", "amount": 9500, "finalized": true,
      "finalized_by": "Edinah A", "has_file": true, "file_name": "invoice.pdf",
      "download_path": "/api/v1/admin/finance-invoices/41/download" },
    "draft_revenue_invoices": [ /* uploaded but not yet agreed — the thing awaiting finalization */ ],
    "supplier_invoices":      [ /* role=supplier rows, each with supplier_name + download_path */ ],
    "register_entries":       [ /* plain reconciliation rows naming this order */ ],
    "costs": { "lines": [ { "type": "stripe_fee", "type_label": "Stripe fees", "amount": 150, … } ], "total": 200 },
    "profitability": {
      "revenue": 9500, "revenue_invoice_number": "REV-2026-114",
      "supplier_costs": 6000, "supplier_invoice_count": 1,
      "fees": 200, "fee_count": 2, "total_costs": 6200,
      "profit": 3300, "margin_percent": 34.7,
      "profitability_status": "complete"    // or "awaiting_revenue_invoice"
    },
    "signoff": { /* the full existing sign-off block, same shape as on the order detail */ },
    "suggested_ebay_fee": 1069.35   // only on eBay orders with no ebay_fee line yet — offer as prefill
  }
}
```

### The list — `GET finance/profitability`

Filters: `from`, `to` (order date), `channel=normal|ebay`, `status`, `q` (ref/customer), `verified=yes|no`, `profitability=complete|awaiting`, `include_cancelled=yes`, `per_page` (≤100). Cancelled orders and Stripe test sessions are excluded by default, like the dashboard. Each row = order fields + the `profitability` block flattened in, plus:

```jsonc
{
  "signoff": { "ops_signed_by": "Solomon M", "ops_signed_at": "…",
               "finance_signed_by": "Edinah A", "finance_signed_at": "…" },
  "verified": true   // both signatures stand — finance's "signed off / verified"
}
```

Sign-off is the existing two-slot control (`orders/{id}/signoff` endpoints) — nothing new to build for "verifying", just show it here too.

### The export — `GET finance/profitability/export`

Same filters, returns a UTF-8-BOM CSV (opens clean in Excel): one row per order reference with order total, revenue invoice number, agreed revenue, supplier costs, fees, total costs, profit, margin, both signatures and a Yes/No **Verified** column. Capped at 5,000 rows. Trigger as a normal file download with the auth header.

### The dashboard — `GET finance/profitability/summary?year=2026`

`data.months` = January through the current month (full 12 for past years), each with `orders, order_total, revenue, supplier_costs, fees, profit, margin_percent, orders_missing_revenue_invoice`; `data.totals` = same keys summed. `orders_missing_revenue_invoice` is the work list behind a low month — worth a badge.

## 4. Liquidity — rolling 4-week window

### `GET finance-liquidity/weeks` (optional `?weeks=1..8`, `?from=date` to look back at history)

Returns exactly the window finance described: the **current ISO week plus the next three**, computed from today on every request — a week that has ended simply stops appearing (its rows stay in the DB as history; `?from=` reaches them).

```jsonc
{
  "data": [
    {
      "week": 35, "year": 2026, "label": "Week 35",
      "starts_on": "2026-08-24", "ends_on": "2026-08-30", "is_current": true,
      "lines": [ { "line": "bank_balance", "label": "Bank Balance", "total": 4520,
                   "entries": [ { "id": 7, "description": "Bank balance", "reference": "Wise EUR",
                                  "amount": 4520, "recorded_by": "Edinah A", … } ] },
                 /* …cost_of_sales, rent, salaries, tax_obligations, internet_phone,
                      loan_payment, consultancy, revenue_payment — same keys as the monthly board */ ],
      "bank_balance": 4520,
      "expected_in": 2000,          // revenue_payment total
      "expected_out": 1500,         // sum of the cost lines (entered as positive magnitudes)
      "projected_closing": 5020     // bank_balance + expected_in − expected_out
    },
    { "week": 36, … }, { "week": 37, … }, { "week": 38, … }
  ],
  "meta": { "current_week": 35, "lines": { "bank_balance": "Bank Balance", … }, "weeks_shown": 4 }
}
```

### Writes

- `PUT finance-liquidity/weeks/bank-balance` `{ week_start, amount, reference? }` — "update the bank balance for each week" as one idempotent call: creates or updates the week's balance row. Any date inside the week is accepted; it normalizes to the Monday.
- `POST finance-liquidity/weeks/entries` `{ week_start, line, amount, description?, reference? }` → 201.
- `PATCH` / `DELETE finance-liquidity/weeks/entries/{id}`.
- Any write into a week that has already ended returns **409 `week_closed`** — closed weeks are read-only history. Disable editing on non-window weeks.

## Behaviour worth knowing

1. **Revenue ≠ order total.** The order's `total` is what we asked for; `profitability.revenue` is what the customer agreed to on the finalized invoice. Show both; the delta is interesting to finance.
2. Amount sums are arithmetic across currencies (currency is a label, no FX in this system — everything is EUR in practice). Show the order currency next to the figures.
3. Invoice PDFs download through the existing authenticated route (`download_path`); there is no public URL, on purpose.
4. Every list/summary excludes Stripe test-session orders and (by default) cancelled orders.
5. If the panel is deployed before the migrations run, `meta.available: false` appears on profitability responses and role fields fall back to `register` — degrade to hiding the profitability tab rather than erroring.

## Please scan / tighten on your side

- [ ] Order detail: add a Finance/Profitability tab fed by `finance/profitability/{ref}`.
- [ ] Upload flow: one form → `POST finance-invoices` with `role` + file, then a separate explicit **Finalize** button with a confirm ("this becomes the order's revenue figure and locks").
- [ ] Surface every 409 `code` above with its message — they are all expected user-facing states, not errors.
- [ ] Liquidity board: render the 4 returned weeks as columns; don't compute the window client-side — the API owns "which week is current".
- [ ] eBay orders: prefill the fee line from `suggested_ebay_fee` when present.

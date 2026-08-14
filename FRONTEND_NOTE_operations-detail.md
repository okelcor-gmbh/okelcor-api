# Frontend note — clients drill-down, the report, and the invoice register

**Session 86. Backend complete and tested (466 passing). One additive migration,
five new endpoints. No existing endpoint changes shape.**

Four things, all extending what you built in Session 83.

---

## 1. The Clients figure opens

`GET /api/v1/admin/operations/clients` — `orders.view`

Query: `from`, `to`, `channel=normal|ebay|all`, `q`, `sort=amount|orders|recent|name`,
`per_page`, `page`. Defaults to the current month, sorted by spend.

```jsonc
{
  "data": [
    {
      "email": "big@acme.de",
      "name": "Acme GmbH",
      "country": "DE",
      "orders_count": 2,
      "amount": 10000, "currency": "EUR",
      "other_currency_orders": 0,
      "first_order_at": "2026-08-02T09:00:00+00:00",
      "last_order_at":  "2026-08-11T14:20:00+00:00",
      "channels": ["normal", "ebay"],
      "customer_id": 42, "company": "Acme GmbH",
      "buyer_tier": "wholesale", "onboarding_status": "active",
      "has_account": true
    }
  ],
  "meta": { "total": 2, "per_page": 25, "current_page": 1, "last_page": 1,
            "sort": "amount", "period": {...}, "definition": "A distinct e-mail …" }
}
```

**Make the Clients number on the board a link to this.** That was the ask, and
it is also the only way anyone can check the figure without asking a developer
to run a query.

**`has_account: false` is normal, not an error.** Plenty of confirmed orders
belong to buyers who never registered. `customer_id` is null for those — don't
render a link to the customer page, because it will 404. Show the e-mail as the
identity instead.

**`meta.total` is guaranteed equal to the board's `clients` figure.** A test
reads both and requires them equal, so if they ever differ that's a bug on my
side, not a filter on yours.

### One client's orders

`GET /api/v1/admin/operations/clients/detail?email=...` — same period params.

Returns the client plus `totals` (`orders_count`, `amount`, `in_transit`) and an
`orders` array with `order_ref`, `channel`, `status`, `payment_status`, `total`,
`in_transit`. **`totals.in_transit` is the useful one** — it's the count of that
client's orders that need trade documents sent.

`404` with `code: "no_orders_in_period"` when the address has none in the window.
That's a real state (the client exists, just not in this period) — say so rather
than showing an empty page.

**Why `?email=` and not `/clients/{email}`:** an e-mail in a path segment means
encoding dots, plus signs and slashes through every proxy between you and here.
Not worth it for one identifier.

---

## 2. The transaction report — and your charts

`GET /api/v1/admin/operations/report` — `orders.view`

Query: `from`, `to`, `granularity=day|week|month` (default `month`),
`channel`. Defaults to the last six months.

```jsonc
{
  "data": {
    "period": { "from": "2026-03-01", "to": "2026-08-14" },
    "granularity": "month",
    "periods": [
      { "key": "2026-06", "label": "Jun 2026", "orders_sent": 4,
        "orders_confirmed": 3, "amount": 12000, "currency": "EUR", "clients": 3 }
    ],
    "change": {
      "from": "Jul 2026", "to": "Aug 2026",
      "metrics": {
        "orders_sent": { "previous": 4, "current": 6, "delta": 2,
                         "percent": 50, "direction": "up" }
      }
    },
    "totals": { "orders_sent": 22, "amount": 68000, "clients": 14, "periods": 6 },
    "series": {
      "labels": ["Mar 2026", "Apr 2026", …],
      "datasets": [
        { "metric": "orders_sent", "label": "Orders sent", "data": [3, 5, …] },
        { "metric": "amount", "label": "Amount (EUR)", "data": [9000, 14000, …] }
      ]
    },
    "note": "Clients are counted distinctly WITHIN each period…"
  }
}
```

**`series` is the charts ask, already shaped.** Parallel arrays on a shared label
axis — feed `labels` and each `dataset.data` straight in. Don't re-aggregate
`periods` client-side to build them; two places that aggregate are two places
that can disagree about a number the business is reading.

Three things that will look wrong and are not:

- **Empty periods are present as zero.** That is deliberate — "we sold nothing
  in July" and "July is missing from this chart" look identical when the empty
  bucket is dropped. Plot them.
- **`change.metrics.*.percent` is `null` when the previous period was zero.**
  Render "—" or "new", never "+100%". A change from nothing is undefined, not
  large, and a percentage there reads as a fact.
- **`totals.clients` is not the sum of the client column.** One buyer ordering
  in two months is one client. It's counted over the whole range by its own
  query. Show the `note` near the chart if you plot clients.

Suggested layout: the `change` block as a row of stat tiles above the chart
(delta + direction arrow), the chart from `series`, `periods` as a table below.

---

## 3. Finance can now attach the sevDesk PDF

| Endpoint | Permission | |
|---|---|---|
| `POST /api/v1/admin/finance-invoices` | `finance.manage` | now accepts a `file` (multipart) alongside the fields |
| `POST /api/v1/admin/finance-invoices/{id}/file` | `finance.manage` | attach or replace |
| `GET /api/v1/admin/finance-invoices/{id}/download` | `finance.view` | |

`pdf, jpg, jpeg, png`, max 20 MB. **Put the file input on the create form**, not
only on the row — finance has the PDF in front of them when they type the
number, and a separate "now attach it" step is a step that gets skipped. Sending
it in the create request is one round trip.

If the record saves but the file fails, you still get `201` with a message saying
so — surface that message rather than treating it as a plain success.

The list response gains `has_file`, `file_name`, `file_size`, `uploaded_at`, and
`?has_file=yes|no` filters. A "missing document" filter is worth having; that's
finance's work queue.

`GET .../download` returns `404` with a readable message when nothing is
attached — don't render a download button when `has_file` is false.

---

## 4. Invoices we send are now in the same register

This is the "so there are no mismatch" half, and it changes what the invoices
list contains.

Every invoice **this system** produces is now written into the same table, in
the same shape, as finance's sevDesk entries:

- a tax invoice this API raises → registered
- a **commercial invoice or proforma issued to a customer** → registered

That second one was the real gap: it is an invoice as far as the customer is
concerned, but it has no row in our `invoices` table, so it appeared on neither
side of the reconciliation.

### What you need to handle

Rows now carry `system` of `sevdesk` | `okelcor` | `upload` | `other`, plus:

```jsonc
{ "auto_registered": true, "source_type": "trade_document", "source_id": 812 }
```

- **`auto_registered: true` rows must render read-only.** `PATCH` and `DELETE`
  return **409** with `code: "auto_registered"` and a message explaining that the
  row follows the document. Show that message rather than a generic error —
  deleting one would only mean it reappears the next time the invoice behind it
  is saved.
- **The create form must offer only `sevdesk`, `upload`, `other`.** `meta.manual_systems`
  in the list response is that exact array — drive the dropdown off it. Sending
  `system: "okelcor"` returns a 422 validation error, deliberately: that would
  put a number on our side of the comparison that nothing on our side issued.
- **`?system=` filters**, so the list can be split into "finance's entries" and
  "ours". That split is worth having as tabs — they are two different things
  being compared, and mixing them in one table is how you stop being able to see
  the comparison.

### The board and reconciliation are unchanged

`finance_invoices` on the board still counts **only** finance's manual entries,
and the reconciliation's finance side does too. That was a bug I introduced and
an existing test caught: counting our auto-registered rows as finance's made the
variance read zero however far apart the two systems actually were. Your existing
screens need no change.

---

## 5. Nothing here breaks

No existing endpoint changed shape. The whole feature is inert until the
migration runs — the register checks for its own columns, and an invoice raised
before it runs still succeeds, because a reporting table must never be able to
fail the thing it reports on. That is tested.

# Frontend Note — Market Intelligence

Session 98. Backend-owned contract note for the market scorecard.

**Status: built and tested, NOT yet deployed. Needs migration #45** — but only
for the *imported* external data. The scorecard itself works without it, from
data already in the database.

---

## 1. What this is and who it is for

The behaviour report (Session 79) answers **"what should we fix"** — it is a
product tool. This answers **"where should we sell"** — it is a business tool,
and the marketing team is the reader.

One row per country, joining five sources that have never been joined:

| Source | What it contributes |
|---|---|
| `search_events` | demand — what people looked for, and what they looked for and did **not** find |
| `quote_requests` | intent — who asked for a price |
| `orders` | revenue — who bought, and in what currency |
| `customers` | accounts |
| `marketing_contacts` | reach — whether Okelcor can even talk to that market |

Search recording has been live on production since **2026-08-13**, so the
demand columns have real data rather than starting empty.

---

## 2. Endpoints

```
GET /api/v1/admin/analytics/markets?from=YYYY-MM-DD&to=YYYY-MM-DD
GET /api/v1/admin/analytics/markets/export?from=&to=      → CSV download
```

Both gated on `analytics.view`, which already includes the **`marketing`**
role — deliberately, since marketing is the audience.

Default window is **90 days**, not 30: a quote needs time to become an order,
and a 30-day window cuts that in half and makes every market look worse at
converting than it is. Range is capped at 400 days.

---

## 3. The row

```json
{
  "country_code": "PL",
  "country": "Poland",
  "signal": "interest_no_reach",
  "signal_label": "Interest, no list",
  "recommended_action": "Real interest and effectively no contacts to talk to. …",
  "priority": 4,

  "demand": {
    "searches": 412, "visitors": 168,
    "unmet_searches": 61, "unmet_rate": 0.148,
    "top_unmet_terms": [{ "term": "315/80r22.5", "searches": 14 }]
  },
  "pipeline":   { "quotes": 9, "quotes_converted": 0 },
  "commercial": { "orders": 0, "revenue_by_currency": {}, "customers": 1 },
  "reach":      { "contacts": 2, "market_slugs": [] },
  "rates":      { "quote_to_order": 0, "quote_win_rate": 0 },
  "reference":  [{ "metric": "tyre_import_volume_usd", "value": 1.1e9,
                   "unit": "USD", "period": "2024", "source": "UN Comtrade" }]
}
```

`markets` is **pre-sorted** — signal priority first, then size within it. Render
in the order given; re-sorting by a single column throws away the ranking.

**`demand` is `null`, not zeroed, when search recording is off.** Check
`meta.search_recording` before drawing demand columns. Zero searches and no
recording are different claims and the UI must not merge them.

**A rate is `null` when its denominator is zero.** Do not render `null` as
`0%` — "0% converted" on a market nobody enquired about reads as failure when
it means "nobody asked".

---

## 4. Signals — the part that makes it useful

Deliberately **not** a 0-100 score. A score invites an argument about weightings
and hides why a market ranks where it does. Every market is in a named state,
and each state implies different work:

| `signal` | Means | Action implied |
|---|---|---|
| `proven` | demand + inquiries + orders | defend and grow |
| `buying_quietly` | orders, little visible demand | find out how they found us — that channel may repeat |
| `demand_not_served` | searched a lot, found nothing | **stock/catalogue gap** — campaigns will not fix it |
| `interest_no_reach` | interest, almost no contacts | **the clearest penetrate signal** — build a list first |
| `demand_not_converting` | interest + list, no orders | blocker between interest and checkout: price, delivery, language, payment |
| `reach_no_interest` | list, no demand | wrong list or wrong message |
| `reach_unmeasured` | list, demand not recorded | revisit once recording is live |
| `emerging` | some signal, below threshold | watch, do not act |

`data.signals` carries the label and action text for every state, so **do not
hardcode them** — read them from the payload and the two cannot drift.

Colour suggestion: `proven` green, `demand_not_served` and `interest_no_reach`
amber (these are the opportunities), `emerging` grey.

---

## 5. Three blocks that must not be hidden

These are the difference between a tool people trust and a dashboard that
quietly misleads. All three are in the payload; please render all three.

**`unrecognised`** — country values that could not be resolved to a code.
Every one is business **missing from the table above**. `orders.country` is
free text, so one `"Deutchland"` silently removes an order from Germany's row.
Render this as a data-quality prompt, not an error.

```json
"unrecognised": [{ "source": "orders", "value": "Deutchland", "rows": 3 }]
```

**`unmeasured`** — countries with imported external data and no traffic yet.
They are **deliberately excluded from the ranked table**: a zero there would
read as "no demand" when it means "never measured". Show them as a separate
"not measured yet" list.

**`meta.not_covered`** — plain sentences naming what the report cannot see.
Worth a permanent footer.

---

## 6. Revenue

`revenue_by_currency` is a map: `{ "EUR": 41200.00, "USD": 8300.00 }`.

**No exchange rate is applied, on purpose.** Converting a three-month-old order
at today's rate produces a number that is not the money Okelcor received, and a
market ranked on it would move when the euro moves. Show the currencies side by
side. If the business wants one figure, it should choose the rate.

---

## 7. Handing off into a campaign

`reach.market_slugs` gives the `marketing_contact_markets` slugs that map to
that country. The campaign builder already filters on exactly these
(`filters.market` / `filters.markets`, `POST /admin/bulk-emails`), so a
"Build a campaign for this market" button can deep-link straight into it —
no new endpoint needed on either side.

Where `market_slugs` is empty but `reach.contacts` is greater than zero, the
contacts exist without market membership; filter by `country` instead.

---

## 8. The export

`GET .../markets/export` streams a CSV, BOM-prefixed so Excel does not mangle
accented country names. It carries the signal, the recommended action, the
unrecognised values, the unmeasured markets and the caveats — **not just the
numbers**. A spreadsheet gets forwarded, and its caveats have to travel with it.

That file is the "market database" the business asked for. A plain link works;
it is a normal authenticated GET.

---

## 9. Not built

- **No single opportunity score.** See §4.
- **No market-size data out of the box.** `markets:import-reference <file.csv>`
  loads it (columns: `country,metric,value[,unit,period,source,notes]`, survey
  first, `--fix` to write) — but someone has to obtain the data. Until then
  `reference` is empty and `unmeasured` is empty.
- **No city/region breakdown.** Country is the finest granularity any of the
  five sources carries.
- **No forecasting.** Everything here is observed history.

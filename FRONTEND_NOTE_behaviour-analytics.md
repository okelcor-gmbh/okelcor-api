# Frontend note — customer behaviour analytics

**Session 79. One new endpoint, one migration, no change to any existing
response.** The catalogue keeps working exactly as it does today.

---

## What this is

The insights tool answers "how is the business doing". This answers the other
question the order manager asked for: **what are customers looking for, what do
they search most, and what can we not give them** — so the range and the UX get
improved on evidence rather than instinct.

**Collection needs nothing from you.** The frontend already calls
`GET /api/v1/products` with the filters people choose. That call is now
recorded. Nothing to add, nothing to instrument, no events to fire.

```
GET /api/v1/admin/analytics/behaviour?days=30      (analytics.view)
GET /api/v1/admin/analytics/behaviour?from=2026-07-01&to=2026-07-31
```

`days` defaults to 30, capped at 365 (422 above it).

---

## The payload

Every series is pre-aggregated. Plot it; don't recompute it.

```jsonc
{
  "data": {
    "range": { "from": "...", "to": "...", "days": 30 },
    "available": true,

    "summary": {
      "searches": 1284, "visitors": 412,
      "empty_searches": 187,
      "empty_rate": 14.6,        // ← the one number worth a big tile
      "avg_results": 8.4
    },

    "daily": [                    // gap-free: every date in range, zeros included
      { "date": "2026-08-01", "searches": 42, "visitors": 18, "empty_searches": 6 }
    ],

    "top_searches": [
      { "term": "225/45r17", "searches": 96, "visitors": 61,
        "empty_searches": 0, "best_results": 14 }
    ],

    "unmet_demand": [             // ← the most actionable list in here
      { "term": "315/70r22.5", "searches": 44, "visitors": 29,
        "last_searched": "2026-08-11T09:14:00+00:00" }
    ],

    "demand_vs_stock": [
      { "brand": "Pirelli", "searches": 61, "products": 12,
        "in_stock_products": 0, "status": "all_out_of_stock" }
      // status: "not_stocked" | "all_out_of_stock" | "available"
    ],

    "brand_demand":    [ { "value": "Michelin", "searches": 88, "empty_searches": 2 } ],
    "category_demand": [ { "value": "tbr", "searches": 140, "empty_searches": 9 } ],
    "season_demand":   [ { "value": "Winter", "searches": 51, "empty_searches": 1 } ],
    "countries":       [ { "value": "DE", "searches": 300, "empty_searches": 20 } ],

    "size_demand": {
      "rim":    [ { "value": "22.5", "searches": 210, "empty_searches": 14 } ],
      "width":  [ … ],
      "height": [ … ]
    },

    "saved_fitments": [ { "size": "295/80R22.5", "saves": 12, "customers": 9 } ],

    "funnel": {
      "searches": 1284, "visitors": 412, "inquiries": 38, "orders": 11,
      "inquiry_rate_per_visitor": 9.22,
      "order_rate_per_visitor": 2.67,
      "note": "Counts of three separate populations over the same period…"
    },

    "signed_in_share": { "searches": 1284, "signed_in": 210, "signed_in_percent": 16.4 }
  },
  "meta": {
    "generated_at": "…",
    "covers": "Catalogue searches and filters made against this API, plus inquiries, orders and saved fitments.",
    "not_covered": "Page views, scroll depth, click paths and time on page never reach this API and are not represented here."
  }
}
```

---

## Build the screen around this, in this order

**1. `unmet_demand` deserves the most prominent position on the page.** Not the
search volume chart — this list. Every row is either a product to stock or a
word the catalogue doesn't recognise for something already sold. It is the one
thing here that changes a purchasing decision, and it is the thing no existing
report could show. Give each row the term, the count, and a "last searched"
recency.

**2. `demand_vs_stock`, filtered to `status !== "available"`.** Same argument:
a product nobody could buy sold nothing, which in a sales report looks exactly
like a product nobody wanted. Colour `all_out_of_stock` and `not_stocked`
differently — they're different actions (restock vs range decision).

**3. `summary.empty_rate` as a headline stat**, with `daily.empty_searches`
plotted against `daily.searches`. A rising no-result rate is the single best
early warning that the catalogue is drifting away from what people ask for.

**4. Then the demand breakdowns** — `size_demand.rim` especially. Rim size is
how a tyre buyer actually thinks, and it's the natural bar chart on this page.

**5. `daily` is gap-free on purpose.** Days with no traffic come back as zero
rather than being omitted, so plot it directly — no gap-filling, and don't
"tidy" the zeros away. A gap in a line chart reads as missing data, which is a
different claim from "nobody searched that day".

---

## Three things to get right, or the page will mislead

**Show `meta.not_covered` on the page, not just in this document.** A dashboard
called "customer behaviour" that silently omits page views and click paths
invites someone to conclude those things aren't happening. One line of small
print under the title is enough.

**Never present the funnel as one person's journey.** Searches are anonymous
and orders are not, so nothing is joined — these are three counts of three
populations over one period, and `funnel.note` says so. Render it as three
figures side by side, **not** as a narrowing funnel graphic with arrows: the
graphic makes a claim about individuals that the data cannot support. If you
want a conversion-style number, use the per-visitor rates already provided and
label them as proportions.

**`available: false` is not "no demand".** Before the migration runs, or before
anyone has searched, the report returns `available: false` with a `reason`.
Show the reason, not an empty chart — an empty chart says "customers aren't
searching", which is a different and wrong statement.

---

## Optional: accurate visitor counts

Every request reaches Laravel through your Next proxy, so the address it sees
is the proxy's. Unique-visitor counts are therefore coarse unless you send an
id of your own:

```
X-Okelcor-Visitor: <opaque random string, stable per browser>
```

Anything opaque works — a UUID in `localStorage`. The backend never stores it:
it goes into a salted one-way digest **that includes the date**, so a day's
visitors are countable and the same person cannot be followed from one day to
the next.

**This is optional deliberately, and it is a consent decision, not a technical
one.** Storing an identifier in an EU visitor's browser needs consent, and
unique-visitor precision is not worth making that call on the customer's
behalf. Send it only where you already have analytics consent; everything else
on this page works without it.

## What is never stored

No IP address, no user agent, no referrer, no page URL. The only free text kept
is what someone typed into the search box. There is a test asserting a stored
row contains neither the request's IP nor its user agent, so this is a property
of the code rather than an intention.

---

## Expect it to be empty at first

The table starts empty and fills as people use the catalogue. A week is roughly
the first point at which `top_searches` means anything and `unmet_demand` is
worth acting on. Worth saying in the UI for the first few days so nobody
concludes the feature is broken — an empty state that says "collecting since
<date>" is better than an empty chart.

---

## Contract summary

| | |
|---|---|
| New endpoint | `GET /admin/analytics/behaviour` |
| Permission | `analytics.view` (super_admin / admin / order_manager / editor) |
| Migration | **#32** `search_events` — must run before anything is recorded |
| Collection | automatic; **no frontend change required** |
| Optional header | `X-Okelcor-Visitor` for accurate unique counts (consent-gated) |
| Breaking changes | none — the catalogue response is untouched |
| Deploy-order | safe both ways. Searches simply aren't recorded until the migration runs |
| AI insights | new `behaviour` category appears in the existing `GET /admin/insights` feed once data exists — no change needed on your side |

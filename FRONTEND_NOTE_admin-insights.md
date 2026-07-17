# Backend Note — AI-Generated Admin Insights

**From:** Backend · **Re:** your "what's going on" popups proposal
**Status:** Built and matches your proposed contract almost exactly (one
field name difference — see below). **Not yet generating real insights in
production** — the feature is fully wired up but silently does nothing
until a Gemini API key is set on the server (see "One thing pending" below).
Build against this now; it'll start producing real data the moment that key
is added, no further backend changes needed.

---

## Decisions made on your two open questions

- **Provider: Gemini**, not Groq. Same free-tier reasoning you laid out.
- **Scheduled job, not on-demand** — confirmed, exactly as you described:
  `insights:generate` runs every 15 minutes via Laravel's scheduler,
  independent of how many admins are looking at the dashboard.
- **Data minimization: yes, aggregates only** — see the exact snapshot
  shape below; no customer/admin names, emails, or addresses ever leave
  the server.

## One thing dropped from your proposal: the Traffic category

Checked the backend for a PostHog integration to summarize — **there isn't
one**. Analytics live entirely on your side (frontend-embedded PostHog),
so there's no aggregate traffic data on the Laravel side to feed this. This
ships with **revenue, orders, inventory, security, and quotes** only —
all five map to data that already exists here. Traffic would need a
separate integration (a PostHog personal API key + a new backend query
layer) — worth a future proposal on its own rather than blocking this one.

## One thing pending before this produces real output

The whole pipeline is live — scheduled job, database table, endpoint — but
`GEMINI_API_KEY` isn't set in production yet. Until it is, `insights:generate`
runs every 15 minutes and immediately no-ops (by design, same as every other
optional integration in this app), and `GET /admin/insights` returns
`{ "data": [], "generated_at": null }`. **Build your empty/loading state
against that shape now** — it's exactly what you'll see until the key is
added, and it's not an error state.

---

## The endpoint — as proposed, with one field rename

```
GET /admin/insights
```

```jsonc
{
  "data": [
    {
      "id": "ins_20260718_090000_01",
      "category": "revenue",        // revenue | orders | inventory | security | quotes  (no "traffic" — see above)
      "severity": "positive",       // positive | info | warning | critical
      "headline": "Revenue up 34% vs yesterday",
      "detail": "Driven mostly by 3 large TBR orders from Germany.",
      "action_url": null            // present and a string when a deep link makes sense, otherwise null
    }
  ],
  "generated_at": "2026-07-18T09:00:00Z",
  "next_refresh_at": "2026-07-18T09:15:00Z"
}
```

Only difference from your draft: `id` values look like `ins_20260718_090000_01`
(full `HHmmss`, not `0900`) — cosmetic, your client code shouldn't need to
parse the id itself, just treat it as an opaque string (exactly the
"track seen IDs client-side" approach you already planned).

No dismiss endpoint, as you said — none built. No pagination/history
endpoint either — you asked for `GET /admin/insights` only, so that's the
only thing querying is a "latest batch" (2–4 items, replaced every 15
minutes). If you want a real history panel (not just locally-tracked seen
IDs) later, the data already exists in a real table on this side (each
15-minute cycle's insights persist, nothing is overwritten) — flag it and
a history endpoint is a small addition, not a redesign.

---

## What each category actually summarizes (for your own sense of what's realistic)

- **Revenue/orders** — today vs. yesterday revenue and order count, plus
  today's paid orders broken down by country and by tyre type (PCR/TBR/
  Used/OTR) — this is where a "3 large TBR orders from Germany"-style
  observation comes from.
- **Inventory** — a real, backend-computed stockout forecast: for any
  product selling at a steady pace, `current stock ÷ (units sold in the
  last 7 days ÷ 7)` = days until it runs out, computed in PHP and handed
  to Gemini as a fact to restate — **Gemini never invents this number
  itself**, only phrases it in plain English. Only products within 10 days
  of stockout are ever surfaced.
- **Security** — today's failed logins, critical security events,
  permission-denied events, and overall 2FA adoption rate. Same aggregate
  numbers already on the security dashboard, no admin identities included.
- **Quotes** — today's new quote count, total open quotes, and today's
  breakdown by tyre category.

## Please scan / confirm

- Build the popup/toast + history-tracking UI against the JSON shape
  above — it's final.
- Handle `data: []` as an empty/quiet state, not an error — that's the
  expected state until the Gemini key is live.
- No action needed on the Traffic category unless you want to scope a
  separate PostHog-integration proposal later.

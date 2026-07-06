# Frontend Note — Shipment Tracking

**From:** Backend · **Re:** carrier-based shipment tracking (GLS / DHL / ocean
freight incl. Maersk) · **Status:** Live, verified against real orders.

## Update 2026-07-06 — DPD added to `tracking_url`

`tracking_url` now also recognizes DPD (`carrier` containing "dpd") and
returns a deep link to DPD's own public tracking page
(`https://tracking.dpd.de/status/en_US/parcel/{trackingNumber}`). No response
shape change and no frontend code change needed — this was already a
"render `tracking_url` if present" button per the section below. DPD orders
previously got `tracking_url: null` (unrecognized carrier) with no events,
which is what prompted this fix. DPD does **not** have live event auto-sync
(no API credentials yet) — only the public link, same as GLS/DHL/Maersk get
in addition to their live event sync.

## ⚠️ Removed — Traccar / GPS fleet tracking

The Traccar-based own-fleet GPS tracking feature (admin fleet dashboard,
live map, device assignment, ETA/progress bar) has been **removed entirely**
— it's no longer needed now that real carrier tracking (GLS/DHL/ocean
freight) is live and verified. If any of this was built on the frontend,
please remove it:

- **Admin fleet dashboard page** (map of devices, route/trip playback,
  geofences) — the endpoints it called (`GET /admin/tracking/status`,
  `/devices`, `/devices/{id}`, `/devices/{id}/route`, `/devices/{id}/trips`,
  `/geofences`) no longer exist (404 now).
- **"Assign tracking device" control** on the admin order page —
  `PUT /admin/tracking/orders/{id}/device` no longer exists. The admin order
  detail payload no longer returns `tracking_device_id`, `dest_lat`, `dest_lon`.
- **"Set destination" pin control** — `PUT /admin/tracking/orders/{id}/destination`
  no longer exists.
- **Customer live map / ETA countdown** — the customer tracking endpoint
  (`GET /auth/orders/{ref}/tracking`) no longer has a `gps_live` mode. It
  never returns `position`, `route`, or `eta` fields anymore. See below for
  what it returns instead — same endpoint, one response shape now.

Nothing else needs to change in how you *call* the customer tracking
endpoint — same URL, same polling pattern — just the response shape is
simpler (one mode instead of two) and there's no more map/ETA UI to build
around it.

---

## Customer — track my order

```
GET /api/v1/auth/orders/{ref}/tracking      (customer bearer)
```

Always `200`. Tracking is tied to the order's shipment status so it's never
misleading — only shows anything once the order is actually shipped.

```jsonc
// Not available — render a status note, nothing else
{
  "data": {
    "available": false,
    "reason": "no_device" | "not_shipped" | "order_cancelled" | "unavailable",
    "order_ref": "AB-1042",
    "order_status": "processing"
  },
  "message": "Your order is being prepared. Live tracking starts once it ships."
}

// Available (order_status = shipped or delivered)
{
  "data": {
    "available": true,
    "mode": "carrier",
    "order_ref": "AB-1042",
    "order_status": "shipped",     // shipped | delivered
    "delivered": false,
    "carrier": "GLS Germany",
    "tracking_number": "50044195855",
    "stage": "in_transit",         // preparing | in_transit | delivered
    "tracking_url": "https://gls-group.eu/DE/en/parcel-tracking?match=50044195855",
    "events": [                    // newest first — [] if nothing synced/entered yet
      {
        "event_date": "2026-07-03",
        "time": "08:55",
        "location": null,
        "status_label": "The parcel is expected to be delivered during the day.",
        "description": "The parcel is expected to be delivered during the day."
      },
      {
        "event_date": "2026-07-01",
        "time": "08:55",
        "location": null,
        "status_label": "The parcel was handed over to GLS.",
        "description": "The parcel was handed over to GLS."
      }
    ]
  }
}
```

`mode` is always `"carrier"` now (kept in the shape in case you're already
branching on it — there's just one value now instead of two). Reason
meanings: `no_device` (no carrier/tracking number set) · `not_shipped` ·
`order_cancelled` · `unavailable` (carrier API temporarily down). Scoped to
the signed-in customer's own order (others → 404); lean, customer-safe
payload.

### `tracking_url` — always render this if present, regardless of `events`
A deep link to the carrier's own public tracking page (GLS/DHL/Maersk),
built from `carrier` + `tracking_number`/container number. `null` when the
carrier isn't recognized. Render a "Track on GLS.com ↗" / "Track on
DHL.com ↗" button — this works even when `events` is `[]`.

### UI, modeled on the eBay "Track shipment" style
- A simple 3-node stepper driven by `stage` (preparing → in_transit → delivered).
- A "Shipping overview" line: `carrier` + `tracking_number` + the
  `tracking_url` button.
- The `events` list, newest first (date, time, location, description).
  `status_label` is a short heading, `description` the full text (identical
  today, kept as two fields in case you want a collapsed vs expanded view).
  Render an empty state ("No updates yet — track directly on GLS.com") when
  `events` is `[]`, rather than hiding the card — `tracking_url` still works.
  `location` is frequently `null` even when events exist (the carrier doesn't
  always populate city/postal code per event) — don't rely on it being present.

### Data freshness
Reads the **persisted** timeline (kept fresh by an hourly backend job), not
a live carrier call on every page view — stays fast even if a carrier API is
briefly slow.

---

## Admin — order detail page

1. **Carrier + tracking number fields** — already exist on the order update
   form (`carrier`, `carrier_type`, `tracking_number`) via
   `PUT /admin/orders/{id}` / `PATCH /admin/orders/{id}/status`. This is the
   only field admin needs to fill in for tracking to start working.
2. **"Refresh tracking" button** — `GET /admin/orders/{id}/shipment-tracking`
   (permission `tracking.view`) returns `{carrier, tracking_number, stage,
   tracking_url, events}` (no `available`/`mode`/`order_ref` wrapper) and
   does a **live** carrier-API call + persists any new events. Confirmed
   working for GLS, DHL, and ocean freight. Degrades to a usable response
   (including `tracking_url`) even if the live call fails; only 503s when
   the order has no carrier/tracking number at all.
3. **Shipment-events timeline editor (optional)** — for hand-adding events,
   e.g. annotating something the carrier feed missed. Existed since an
   earlier session but was never given a frontend note, so it's likely not
   built yet:

   | Endpoint | Body | Notes |
   |---|---|---|
   | `POST /admin/orders/{id}/shipment-events` | `{event_date, status_label, location?, description?}` | `event_date`: date; `status_label`: short heading (max 100 chars); `location`/`description` optional |
   | `PUT /admin/orders/{id}/shipment-events/{eventId}` | same body | edit an existing event |
   | `DELETE /admin/orders/{id}/shipment-events/{eventId}` | — | remove an event |

   Simple UI: a form (date, short status text, optional location/description)
   + a list of existing events below it (edit/delete). Permission:
   `orders.update`. Lower priority than the two items above.

## eBay orders — no separate UI needed

Carrier/tracking number auto-backfill from eBay's own shipping fulfillment
record on the existing hourly `ebay:sync-orders` job (whatever carrier/
tracking eBay has on file), whenever they're not already set — never
overrides a manual entry. eBay orders flow through the exact same
`carrier`/`tracking_number` fields as any other order, so the same tracking
UI above just works for them too.

> **Can we show eBay's exact tracking timeline?** Not via a direct pull —
> eBay's Sell API only exposes carrier code + tracking number + ship date,
> never the detailed event history (that's eBay's internal integration, not
> exposed to sellers). Doesn't matter in practice: since our own GLS
> integration is live, the events we show for a GLS-carried eBay order are
> the same events eBay is showing — we're both reading from GLS directly.

## "Track it live" notification (when an order ships)

When an admin marks an order **shipped**, the customer's `order_shipped`
notification (existing Email = Inbox feed) says *"Your order is on its way
— track it live in your account."* whenever a carrier + tracking number are
set on the order (was previously tied to a GPS device — same notification
contract, just a different trigger condition server-side, no FE change
needed). Carries `metadata.live_tracking: true` when applicable.

```jsonc
{
  "type": "order_shipped",
  "title": "Order AB-1042 has shipped",
  "body": "Your order is on its way — track it live in your account. Tracking number: …",
  "action_url": "/account/orders/AB-1042",
  "metadata": { "stage": "shipped", "order_ref": "AB-1042", "live_tracking": true }
}
```

**Frontend:** nothing required — the notification bell/inbox already render
this.

## ⚠️ Carrier types changed (admin order form) — unrelated to the Traccar removal

The `carrier_type` enum dropped **`bus`** and added **`truck`**. Valid values
are now: `sea`, `air`, `dhl`, `road`, `truck`. If not already done, update
the admin order carrier-type `<select>` to replace "Bus / Courier" with
**"Truck Freight"** (value `truck`).

---

## NEW — Proposal → Proforma: one less acceptance step

**Why:** the order manager pointed out that requiring the customer to accept
the Proposal *and then separately* accept an Order Confirmation before the
Proforma Invoice can be issued was pure friction — both documents cover
almost the same ground.

**What changed:** for orders that came from an accepted CRM-7 proposal
(`quote_requests.proposal_status === 'accepted'`), admin can now generate/send
the Proforma Invoice **immediately after proposal acceptance** — no separate
"customer accepts the Order Confirmation" step required. The Order
Confirmation document itself still exists and still auto-generates (some
customers still want it), it's just no longer a gate.

**Frontend impact:** if any admin UI conditionally shows/hides the "Generate
Proforma" action based on `customer_acceptance_status === 'accepted'`, that
condition should now also pass when the order's originating quote has
`proposal_accepted_at` set (the order detail payload doesn't currently expose
this quote field directly — ask backend if the UI needs it surfaced). Direct/
manual orders with no proposal history are **unaffected** — they still need
explicit Order Confirmation acceptance before a Proforma can be issued.

## NEW — Commercial Invoice hidden until fully paid

**What changed:** on the customer side, an issued Commercial Invoice no
longer appears in `trade_documents` (order detail / `/auth/orders/{ref}`)
or downloads until the order is fully paid (`balance_paid` /
`shipment_released`, or a simple non-milestone order marked `paid`). Admin
visibility is unchanged. No FE action needed — this is enforced entirely
server-side; if a customer UI was already just rendering whatever
`trade_documents` returns, it will now correctly stop showing an unpaid
order's CI without any code change.

---

## Reminder (shared infra)
Proxy `API_URL` must include `/api/v1`, no trailing slash.

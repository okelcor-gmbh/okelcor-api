# Frontend Note — GPS / Fleet Tracking (Traccar)

**From:** Backend · **Re:** live device tracking (admin fleet map + customer
delivery tracking) · **Status:** Backend built + tested (10 tests). Activates
once a Traccar server is configured (`TRACCAR_URL` etc.) — see `TRACCAR_SETUP.md`.

The backend is a client of a Traccar GPS server and reshapes its data for you.
**Speed is already km/h, distance already km** (Traccar's native knots/metres are
converted server-side). All endpoints go through your usual proxy with the bearer.

---

## Admin — fleet dashboard

All under the admin Sanctum area, permission **`tracking.view`**
(super_admin / admin / order_manager / sales_manager). Device assignment is
`orders.update`.

| Endpoint | Returns |
|---|---|
| `GET /api/v1/admin/tracking/status` | `{ configured, connected, server, devices, message }` — show a "not configured / disconnected" banner if `connected:false` |
| `GET /api/v1/admin/tracking/devices` | `{ data: Device[], meta:{total} }` — each device + its latest position. Powers the live map + list. |
| `GET /api/v1/admin/tracking/devices/{id}` | `{ data: Device }` |
| `GET /api/v1/admin/tracking/devices/{id}/route?from=&to=` | `{ data: Position[], meta:{from,to,total} }` — ordered points for route playback. Defaults to last 24h. |
| `GET /api/v1/admin/tracking/devices/{id}/trips?from=&to=` | `{ data: Trip[], meta:{from,to,total} }` |
| `GET /api/v1/admin/tracking/geofences` | `{ data: Geofence[] }` — `area` is WKT (`CIRCLE(...)`, `POLYGON(...)`) |
| `PUT /api/v1/admin/tracking/orders/{orderId}/device` | body `{ tracking_device_id: "7" \| null }` → assign/clear the device a customer can track |

`from`/`to` are ISO-8601 datetimes (optional; default last 24h).

### Shapes

```jsonc
// Device
{
  "id": 7, "name": "Truck 1", "unique_id": "T-001",
  "status": "online",            // online | offline | unknown
  "disabled": false, "category": "truck",
  "last_update": "2026-06-28T10:00:00Z",
  "position": {                  // null if no fix yet
    "latitude": 52.52, "longitude": 13.40, "altitude": 34,
    "speed_kmh": 18.5, "course": 90,
    "address": "Berlin", "fix_time": "2026-06-28T10:00:00Z", "valid": true
  }
}

// Trip
{ "start_time","end_time","start_address","end_address",
  "start_lat","start_lon","end_lat","end_lon",
  "distance_km": 25.0, "avg_speed_kmh": 37.0, "max_speed_kmh": 64.8,
  "duration_ms": 3600000 }

// Geofence
{ "id":1, "name":"Depot", "description":"Main yard", "area":"CIRCLE (52.5 13.4, 200)" }
```

---

## Customer — track my delivery

```
GET /api/v1/auth/orders/{ref}/tracking      (customer bearer)
```

Always `200`. **Tracking is tied to the order's shipment status** so it's never
misleading — a live truck only appears once the order is actually shipped.

```jsonc
// Not live — render a status note (or nothing), no map
{
  "data": {
    "available": false,
    "reason": "no_device" | "not_shipped" | "order_cancelled" | "unavailable",
    "order_ref": "AB-1042",
    "order_status": "processing"
  },
  "message": "Your order is being prepared. Live tracking starts once it ships."
}

// Live (order_status = shipped or delivered)
{
  "data": {
    "available": true,
    "order_ref": "AB-1042",
    "order_status": "shipped",          // shipped | delivered
    "delivered": false,                 // true → show "Delivered", stop polling
    "name": "Truck 1",
    "status": "online",                 // device online/offline
    "last_update": "2026-06-28T10:00:00Z",
    "position": { "latitude":52.52, "longitude":13.40, "speed_kmh":18.5, "course":90, "address":"Berlin", "fix_time":"…" },
    "route": [ { "latitude":…, "longitude":…, "fix_time":"…" } ],  // CURRENT TRIP trail; [] when delivered
    "eta": {                                  // null when not computable / delivered
      "eta": "2026-06-29T14:30:00+00:00",     // estimated arrival timestamp
      "minutes_remaining": 145,
      "distance_remaining_km": 168.4,
      "speed_kmh_used": 58.0,                  // moving avg, or fallback cruising speed
      "progress_percent": 37                   // 0–100 for the progress bar
    }
  }
}
```

### Delivery countdown + progress bar (the "2 days left" UI)
The backend gives you an **`eta` timestamp**, **`distance_remaining_km`**, and
**`progress_percent`**. Render the live countdown **client-side** from `eta.eta`
(don't poll per second):
- Compute `eta.eta - now` and format as `Xd Yh`, then `Yh Zm`, then `Zm` as it
  shrinks. Re-derive every second from the timestamp; refresh the payload on your
  normal 30s poll so distance/progress stay current.
- Progress bar width = `eta.progress_percent`.
- `eta` can be `null` (no GPS fix yet, or destination not geocodable) — just hide
  the countdown/bar; the map still works.

> Honesty note: this is a **straight-line estimate** (great-circle × road factor ÷
> recent average speed), not traffic-aware routing. It's a good "roughly N hours
> out" indicator — label it "estimated", not a guaranteed time.

Reason meanings: `no_device` (none assigned) · `not_shipped` (still being
prepared — show "tracking starts when it ships") · `order_cancelled` · `unavailable`
(Traccar down — just hide). When `delivered: true`, show the final position and
stop the 30s poll. Scoped to the signed-in customer's own order (others → 404);
lean payload, no internal device attributes.

---

## What the frontend needs to do

1. **Admin fleet page (new):** map of `GET /admin/tracking/devices` (markers from
   `position`, colour by `status`), a device list, and on click a route/trip
   panel (`/route`, `/trips`). Render geofences from WKT `area`. Show the
   `status` banner from `/admin/tracking/status`.
2. **Admin order page:** a small "assign tracking device" control →
   `PUT /admin/tracking/orders/{id}/device` (dropdown sourced from
   `/admin/tracking/devices`, or a free-text device id; send `null` to clear).
   The admin order detail payload now returns **`tracking_device_id`** so you can
   pre-select the current device.
   - **Set destination (for ETA):** `PUT /admin/tracking/orders/{id}/destination`
     — body `{ "lat": 48.13, "lon": 11.58 }` (a map pin) **or** `{ "address": "…" }`
     (geocoded server-side; `422` `geocode_failed` if not found), or `{}` to clear.
     The order detail payload returns the current **`dest_lat` / `dest_lon`** so you
     can show/prefill the pin. Use this for orders whose address is too sparse to
     auto-geocode (ETA comes back `null` for those until a destination is set).
3. **Customer order page:** call `/auth/orders/{ref}/tracking`; if
   `available:true` show a live map (position marker + `route` polyline) + "last
   updated" stamp, and poll ~30s **only while `order_status === "shipped"`** (stop
   when `delivered:true`). If `available:false`, use `reason`: show "tracking
   starts once your order ships" for `not_shipped`, otherwise render nothing.
   The endpoint already enforces this — the FE just mirrors it.
4. **Map library is your call** (Leaflet/Mapbox/Google). Backend only supplies
   lat/lng + WKT; no tiles.

## "Track it live" notification (when an order ships)

When an admin marks an order **shipped**, the customer already gets an
`order_shipped` notification (the existing Email = Inbox feed). Now, **if a
tracking device is assigned**, that notification:
- says *"Your order is on its way — track it live in your account."* (vs the plain
  "on its way" copy when there's no device), and
- carries `metadata.live_tracking: true`.

Shape (from the existing notifications feed — `GET /auth/customer/notifications`):
```jsonc
{
  "type": "order_shipped",
  "title": "Order AB-1042 has shipped",
  "body": "Your order is on its way — track it live in your account. Tracking number: …",
  "action_url": "/account/orders/AB-1042",
  "metadata": { "stage": "shipped", "order_ref": "AB-1042", "live_tracking": true }
}
```

**Frontend:** nothing required — the notification bell/inbox already render this.
Optional polish: when `metadata.live_tracking === true`, you can deep-link the
notification straight to the order's tracking map (the `action_url` already points
at the order page where the DeliveryTracking card lives), or show a small "Live"
badge on the notification.

**Admin workflow note:** assign the device **before** marking the order shipped,
so the shipped notification includes the "track it live" copy. (Re-marking an
already-shipped order won't re-send — one notification per shipment.)

## ⚠️ Carrier types changed (admin order form)

The `carrier_type` enum dropped **`bus`** and added **`truck`**. Valid values are
now: `sea`, `air`, `dhl`, `road`, `truck`. **Update the admin order carrier-type
`<select>`** to replace the "Bus / Courier" option with **"Truck Freight"**
(value `truck`). Any existing `bus` orders were migrated to `truck` server-side.

---

## NEW — Shipment tracking: fully live for GLS, DHL, and ocean freight

**Status: all carrier auto-sync is now working — GLS, DHL, and ocean
freight/Maersk.** Earlier notes here said GLS was parked after hitting
persistent "invalid credentials" errors; root cause turned out to be a
stray extra character copy-pasted into the API key in production `.env`,
not a real integration problem. Verified live against a real order — real
tracking events came back, matching what eBay itself shows for the same
parcel. **Build the full experience now** — no need to treat GLS
differently from DHL/ocean freight anymore. Tracking still has three
layers that stack (in case any future carrier isn't configured), but all
three now apply to every supported carrier including GLS:

1. **Zero effort — `tracking_url` (see below).** The moment carrier +
   tracking number are set on an order (whether admin typed them in, or eBay
   auto-supplied them — see the eBay section below), a working deep link to
   the carrier's own tracking page appears. No events, no API, nothing else
   required. This directly covers "what if we don't know the process yet" —
   there's no process to know; the link just works.
2. **Automatic — GLS / DHL / ocean freight (Maersk etc.).** All fully
   working now (real credentials in place for all three) — events
   auto-populate hourly, no admin action beyond setting carrier + tracking
   number.
3. **Manual, optional — the shipment-events timeline.** For hand-adding
   events on a carrier that isn't one of the three above, or annotating
   with internal notes. A nice-to-have on top of layer 1/2, not a
   prerequisite for tracking to show anything.

### Admin: two things to add to the order detail page

1. **Carrier + tracking number fields** — already exist on the order update
   form (`carrier`, `carrier_type`, `tracking_number`) via the existing
   `PUT /admin/orders/{id}` / `PATCH /admin/orders/{id}/status`. If these
   aren't on the form yet, add plain text/select inputs — no new endpoint.
   **This is the one field admin actually needs to fill in — everything else
   is automatic or optional on top.**
2. **A "Shipment events" timeline editor (optional)** — this
   endpoint has existed since an earlier session but was never given a
   frontend note, so it's likely not built yet:

   | Endpoint | Body | Notes |
   |---|---|---|
   | `POST /admin/orders/{id}/shipment-events` | `{event_date, status_label, location?, description?}` | `event_date`: date; `status_label`: short heading (max 100 chars, e.g. "Arrived at parcel center"); `location`/`description` optional |
   | `PUT /admin/orders/{id}/shipment-events/{eventId}` | same body | edit an existing event |
   | `DELETE /admin/orders/{id}/shipment-events/{eventId}` | — | remove an event |

   Simple UI: a form (date, short status text, optional location/description)
   + a list of existing events below it (edit/delete), on the order detail
   page. Permission: `orders.update` (same as the rest of the order edit
   form). Build this after the carrier/tracking-number field + `tracking_url`
   button — it's the richest option, not the required one.

Once carrier + tracking number are set, the customer automatically sees
something via the tracking endpoint below (`mode: "carrier"`) — at minimum
the `tracking_url` link, plus whatever events exist (manual or auto-synced).

---

## Real carrier tracking (GLS / DHL / ocean freight incl. Maersk) — all live

**Why:** Order manager wanted the same "Track shipment" view eBay shows for
GLS parcels — a 3-stage stepper, a "Shipping overview" carrier/tracking-number
line, and a chronological event log — available in Okelcor's own admin panel
and customer portal, for any order (not just eBay-sourced ones), instead of
having to log into eBay/GLS separately.

This **reuses and extends the existing tracking endpoint** rather than adding
a parallel one — `GET /api/v1/auth/orders/{ref}/tracking` now returns one of
two shapes, discriminated by a new `mode` field. **Nothing changes for orders
using Okelcor's own fleet** (`mode: "gps_live"` — identical to the shape
documented above). What's new is `mode: "carrier"` for orders shipped with a
third-party carrier (GLS, DHL, or ocean freight incl. Maersk — ShipsGo
aggregates multiple shipping lines).

```jsonc
// mode: "carrier" — GLS / DHL / ocean freight, no fleet device assigned
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
    "tracking_url": "https://gls-group.eu/DE/en/parcel-tracking?match=50044195855",  // see below
    "events": [                    // newest first — [] if none entered/synced yet
      {
        "event_date": "2026-07-01",
        "time": "10:40",
        "location": "BORNHEIM, 53332",
        "status_label": "The package has arrived at the parcel center.",
        "description": "The package has arrived at the parcel center."
      },
      {
        "event_date": "2026-06-29",
        "time": "19:18",
        "location": "BORNHEIM, 53332",
        "status_label": "The sender has made the package available for collection by GLS.",
        "description": "The sender has made the package available for collection by GLS."
      }
    ]
  }
}
```

`available: false` responses are unchanged (`reason: no_device | not_shipped |
order_cancelled | unavailable`) — `no_device` now also covers "no carrier
assigned either."

### `tracking_url` — always render this if present, regardless of `events`
A deep link to the carrier's own public tracking page (GLS/DHL/Maersk today),
built from `carrier` + `tracking_number`/container number — **no API
credentials needed, always works** as long as carrier + tracking number are
set on the order. This is the fallback for exactly the "we don't know the
process yet" case: even before any event has been entered manually or
auto-synced, `tracking_url` is already there. **Build this first** — a
"Track on GLS.com ↗" / "Track on DHL.com ↗" button that opens `tracking_url`
in a new tab is the lowest-effort, always-working piece of this feature.
`null` when the carrier isn't recognized (only GLS/DHL/Maersk have a known
public URL pattern today) — hide the button in that case.

### What the frontend needs to do
1. **Branch on `mode`** wherever `/auth/orders/{ref}/tracking` is consumed:
   `gps_live` → existing map UI (no change); `carrier` → new UI, modeled on
   the eBay "Track shipment" modal:
   - A simple 3-node stepper driven by `stage` (preparing → in_transit →
     delivered).
   - A "Shipping overview" line: `carrier` + `tracking_number` + the
     `tracking_url` button described above.
   - The `events` list rendered newest-first (date, time, location,
     description) — `status_label` is a short heading, `description` the
     full text (identical today, kept as two fields in case the FE wants a
     collapsed vs expanded view like eBay's "See more"). Render an empty
     state ("No updates yet — track directly on GLS.com") when `events` is
     `[]`, rather than hiding the whole card — `tracking_url` still works.
2. **Admin order page:** `GET /admin/orders/{id}/shipment-tracking`
   (permission `tracking.view`) returns the same `{carrier, tracking_number,
   stage, tracking_url, events}` shape (no `available`/`mode`/`order_ref`
   wrapper) and does a **live** carrier-API call + persists any new events —
   now confirmed working for GLS, DHL, and ocean freight. Even if a call
   ever fails (network blip, carrier outage), it still returns a usable
   response (including `tracking_url`); it only errors (503) when the order
   has no carrier/tracking number at all. Safe to wire up as the "refresh
   tracking" button on the order page.
3. **No FE change needed for eBay orders specifically** — carrier/tracking
   number now auto-backfill from eBay's own shipping fulfillment record
   during the existing hourly `ebay:sync-orders` job (whatever carrier/
   tracking eBay has on file — e.g. an order fulfilled manually in eBay's
   Seller Hub), whenever they're not already set. So eBay orders flow through
   the exact same `carrier`/`tracking_number` fields as manual orders — no
   separate "eBay tracking" UI needed, and no manual copy-paste from eBay
   into the admin panel required either.

> **Can we show eBay's exact tracking timeline (the rich "arrived at parcel
> center" style history)?** Checked against eBay's own Fulfillment API docs —
> no direct pull, but it doesn't matter in practice anymore: eBay's Sell API
> only exposes `shippingCarrierCode` + `trackingNumber` + ship date (which we
> already pull, point 3 above), never the detailed event history itself
> (that's eBay's internal carrier integration, not exposed to sellers via
> API). But now that our own GLS integration is live and verified against a
> real eBay-sourced order, the events we show **are** the same events eBay
> is showing — we're both reading from GLS, we just do it ourselves instead
> of relying on eBay's copy.

### Data freshness
The customer endpoint reads the **persisted** timeline (kept fresh by an
hourly backend job), not a live carrier call — so it stays fast even if a
carrier API is slow/down. The admin endpoint **does** call live (for the "I
need this right now" case) and persists what it finds — but degrades to
persisted-only + `tracking_url` if the live call fails, same as the customer
endpoint, never a hard error while there's still something to show.

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

## Resolved / status
- ✅ **Customer trail = current trip** (done): `route` is now bounded to the most
  recent trip's start (capped at `TRACCAR_ROUTE_HOURS`, default 12), not a flat
  24h window. No FE change needed — same shape, just a tighter set of points.
- ❌ **ETA** — not exposed (per your call).
- Reminder (shared infra): proxy `API_URL` must include `/api/v1`, no trailing
  slash.

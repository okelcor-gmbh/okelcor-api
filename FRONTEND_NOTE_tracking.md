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

## NEW — Shipment tracking: build the MANUAL entry UI first (this is the active path)

**Status: this is what to build now.** GLS's live API integration (below) hit
persistent "invalid credentials" issues that cost more time than it saved, so
for the time being **admin manually enters carrier/tracking info and shipment
events** — no live carrier API calls involved. The backend was already built
carrier-agnostic (manual and auto-synced events share the same table), so
nothing changes about the response shapes documented below — an order tracked
manually looks identical, on the wire, to one that would eventually be
auto-synced. **Automatic GLS/DHL/ocean-freight sync (further down this
section) is built and harmless but not the priority — don't block on it.**

### Admin: two things to add to the order detail page

1. **Carrier + tracking number fields** — already exist on the order update
   form (`carrier`, `carrier_type`, `tracking_number`) via the existing
   `PUT /admin/orders/{id}` / `PATCH /admin/orders/{id}/status`. If these
   aren't on the form yet, add plain text/select inputs — no new endpoint.
2. **A "Shipment events" timeline editor** — this endpoint has existed since
   an earlier session but was never given a frontend note, so it's likely not
   built yet:

   | Endpoint | Body | Notes |
   |---|---|---|
   | `POST /admin/orders/{id}/shipment-events` | `{event_date, status_label, location?, description?}` | `event_date`: date; `status_label`: short heading (max 100 chars, e.g. "Arrived at parcel center"); `location`/`description` optional |
   | `PUT /admin/orders/{id}/shipment-events/{eventId}` | same body | edit an existing event |
   | `DELETE /admin/orders/{id}/shipment-events/{eventId}` | — | remove an event |

   Simple UI: a form (date, short status text, optional location/description)
   + a list of existing events below it (edit/delete), on the order detail
   page — matches the eBay "Track shipment" timeline the order manager wants
   to replicate, just filled in by hand instead of pulled live. Permission:
   `orders.update` (same as the rest of the order edit form).

Once both are set — carrier/tracking number on the order, plus at least one
shipment event — the customer automatically sees it via the tracking endpoint
below (`mode: "carrier"`), no extra step needed.

---

## Real carrier tracking (GLS / DHL / ocean freight incl. Maersk) — built, GLS on hold

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
    "events": [                    // newest first
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

### What the frontend needs to do
1. **Branch on `mode`** wherever `/auth/orders/{ref}/tracking` is consumed:
   `gps_live` → existing map UI (no change); `carrier` → new UI, modeled on
   the eBay "Track shipment" modal:
   - A simple 3-node stepper driven by `stage` (preparing → in_transit →
     delivered).
   - A "Shipping overview" line: `carrier` + `tracking_number`.
   - The `events` list rendered newest-first (date, time, location,
     description) — `status_label` is a short heading, `description` the
     full text (identical today, kept as two fields in case the FE wants a
     collapsed vs expanded view like eBay's "See more").
2. **Admin order page:** new endpoint
   `GET /admin/orders/{id}/shipment-tracking` (permission `tracking.view`,
   same as the fleet endpoints) returns the same `{carrier, tracking_number,
   stage, events}` shape (no `available`/`mode`/`order_ref` wrapper — just the
   tracking data) and does a **live** carrier-API call + persists any new
   events, unlike the customer endpoint which reads the persisted timeline.
   **Hold off wiring a "live sync" button to this for now** — GLS isn't
   working yet (see below), so today it would only do anything for DHL/ocean
   orders. The manual entry UI above is what to build first; this endpoint
   is a bonus once GLS is sorted, not a blocker.
3. **No FE change needed for eBay orders specifically** — they flow through
   the exact same admin order carrier/tracking-number fields as manual
   orders, so no separate "eBay tracking" UI is needed.

### Data freshness
The customer endpoint reads the **persisted** timeline (kept fresh by an
hourly backend job), not a live carrier call — so it stays fast even if a
carrier API is slow/down. The admin endpoint **does** call live (for the "I
need this right now" case) and persists what it finds.

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

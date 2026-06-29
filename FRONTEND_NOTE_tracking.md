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
    "route": [ { "latitude":…, "longitude":…, "fix_time":"…" } ]   // CURRENT TRIP trail; [] when delivered
  }
}
```

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
3. **Customer order page:** call `/auth/orders/{ref}/tracking`; if
   `available:true` show a live map (position marker + `route` polyline) + "last
   updated" stamp, and poll ~30s **only while `order_status === "shipped"`** (stop
   when `delivered:true`). If `available:false`, use `reason`: show "tracking
   starts once your order ships" for `not_shipped`, otherwise render nothing.
   The endpoint already enforces this — the FE just mirrors it.
4. **Map library is your call** (Leaflet/Mapbox/Google). Backend only supplies
   lat/lng + WKT; no tiles.

## ⚠️ Carrier types changed (admin order form)

The `carrier_type` enum dropped **`bus`** and added **`truck`**. Valid values are
now: `sea`, `air`, `dhl`, `road`, `truck`. **Update the admin order carrier-type
`<select>`** to replace the "Bus / Courier" option with **"Truck Freight"**
(value `truck`). Any existing `bus` orders were migrated to `truck` server-side.

## Resolved / status
- ✅ **Customer trail = current trip** (done): `route` is now bounded to the most
  recent trip's start (capped at `TRACCAR_ROUTE_HOURS`, default 12), not a flat
  24h window. No FE change needed — same shape, just a tighter set of points.
- ❌ **ETA** — not exposed (per your call).
- Reminder (shared infra): proxy `API_URL` must include `/api/v1`, no trailing
  slash.

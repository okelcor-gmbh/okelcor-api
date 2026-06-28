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

Always `200`. Two shapes:

```jsonc
// No device assigned, or tracking temporarily down → just hide the map
{ "data": { "available": false, "reason": "no_device" | "unavailable" }, "message": "…" }

// Live
{
  "data": {
    "available": true,
    "order_ref": "AB-1042",
    "name": "Truck 1",
    "status": "online",
    "last_update": "2026-06-28T10:00:00Z",
    "position": { "latitude":52.52, "longitude":13.40, "speed_kmh":18.5, "course":90, "address":"Berlin", "fix_time":"…" },
    "route": [ { "latitude":…, "longitude":…, "fix_time":"…" } ]   // recent ~24h trail
  }
}
```

Scoped to the signed-in customer's own order (others → 404). Lean payload — no
internal device attributes are exposed.

---

## What the frontend needs to do

1. **Admin fleet page (new):** map of `GET /admin/tracking/devices` (markers from
   `position`, colour by `status`), a device list, and on click a route/trip
   panel (`/route`, `/trips`). Render geofences from WKT `area`. Show the
   `status` banner from `/admin/tracking/status`.
2. **Admin order page:** a small "assign tracking device" control →
   `PUT /admin/tracking/orders/{id}/device` (dropdown sourced from
   `/admin/tracking/devices`, or a free-text device id; send `null` to clear).
3. **Customer order page:** call `/auth/orders/{ref}/tracking`; if
   `available:true` show a live map (position marker + `route` polyline) and a
   "last updated" stamp; if `false`, render nothing (or a subtle "live tracking
   not available" note). Safe to poll every ~30s while the order is in transit.
4. **Map library is your call** (Leaflet/Mapbox/Google). Backend only supplies
   lat/lng + WKT; no tiles.

## Open questions for you
- Do you want the customer trail (`route`) limited to the **current trip** rather
  than a flat last-24h window? Backend can switch to "since last stop" if useful.
- Should we expose an **ETA**? Traccar doesn't compute delivery ETA; we'd derive
  it (distance to destination) only if you want it.
- Reminder (shared infra): proxy `API_URL` must include `/api/v1`, no trailing
  slash.

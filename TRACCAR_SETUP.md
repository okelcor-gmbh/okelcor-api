# Traccar GPS Tracking — Setup

Okelcor API is a **client** of a Traccar server (Traccar runs elsewhere — it's a
Java app + its own DB and cannot run on the cPanel shared host). This doc gets
the integration talking to a Traccar instance.

## 1. Pick a server

| Option | When | Notes |
|--------|------|-------|
| **Public demo** (`https://demo.traccar.org`) | Trial / this first build | Free account, real devices you add yourself. Data is on Traccar's demo box — not for production. There are also demo2/demo3/demo4. |
| **Self-host** (VPS) | Production | One small VPS (1–2 GB RAM). Install per https://www.traccar.org/install/. Put it behind HTTPS. |
| **Traccar Cloud** | Production, no ops | Paid hosted plan at traccar.org. |

## 2. Get credentials

Two supported auth modes (token preferred):

- **API token (Bearer)** — in the Traccar web UI: top-right user → **Settings →
  user → Token → generate**. Copy it.
- **Email + password (Basic)** — any Traccar user account (e.g. your demo login).

## 3. Set env vars (server `.env`)

```env
TRACCAR_URL=https://demo.traccar.org      # no trailing slash, no /api
TRACCAR_TOKEN=xxxxxxxxxxxxxxxxxxxx        # preferred
# — or — Basic auth fallback:
TRACCAR_EMAIL=you@example.com
TRACCAR_PASSWORD=your-password
# TRACCAR_TIMEOUT=15                       # optional, seconds
```

Then: `php artisan config:clear && php artisan config:cache`.

> Until these are set the integration **degrades gracefully** — admin tracking
> endpoints return `503` with a clear message and the customer tracking endpoint
> returns `available: false`. Nothing breaks.

## 4. Verify

```bash
# Connection probe (admin token required)
curl -H "Authorization: Bearer <ADMIN_TOKEN>" https://api.okelcor.com/api/v1/admin/tracking/status
# → { "data": { "configured": true, "connected": true, "user": "…" } }
```

Add a device in the Traccar UI (Devices → +, set a unique id), optionally run the
Traccar **Client** app on a phone reporting to that unique id, and you'll see it
in `GET /admin/tracking/devices`.

## 5. Migration

This feature adds one guarded column — run on deploy:

```
2026_06_28_000002_add_tracking_device_to_orders_table   # orders.tracking_device_id
```

## How a customer sees their delivery

1. Admin assigns a Traccar device to the order:
   `PUT /api/v1/admin/tracking/orders/{orderId}/device` with
   `{ "tracking_device_id": "7" }` (the Traccar device **id**).
2. The customer's order then exposes live tracking at
   `GET /api/v1/auth/orders/{ref}/tracking`.

## Notes

- Speed is normalised to **km/h** and distance to **km** in our responses
  (Traccar returns knots / metres).
- Customer responses are deliberately lean (no internal device attributes).
- Reuses the existing `throttle:tracking` rate limiter.
- Frontend integration: see `FRONTEND_NOTE_tracking.md`.

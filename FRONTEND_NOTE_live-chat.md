# Backend Note — Live Chat (Pillar 1 of the v2 mobile plan), built

**From:** Backend · **Re:** `FRONTEND_NOTE_admin-mobile-app-v2-premium.md`,
Pillar 1
**Status:** Backend fully built and deployed. **Real-time delivery is not
live yet** — needs a free Pusher account + credentials (5-minute setup, see
below) before messages actually push in real time. Until then, every
endpoint below still works over plain HTTP (start a session, send a
message, accept, close) — you just won't see live delivery without a
manual refresh/re-fetch.

---

## One thing you need to do: create a free Pusher app

1. [dashboard.pusher.com](https://dashboard.pusher.com) → sign up (no card) → **Channels** → Create app.
2. Any cluster is fine (default `mt1` unless you're targeting a specific region).
3. From the app's "App Keys" tab, send me: `app_id`, `key`, `secret`, `cluster`. I'll set them in production `.env` (`PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET`, `PUSHER_APP_CLUSTER`) and flip `BROADCAST_CONNECTION` from `null` to `pusher`.
4. The mobile app (and website widget) needs the **`key`** and **`cluster`** too (those two are public/client-safe — never the `secret`) to initialize the Pusher client SDK.

---

## Auth for Pusher's private channels

Both channels below are **private** (not public) — the client Pusher SDK needs to authenticate each subscription against this backend:

```
POST /api/v1/broadcasting/auth
```

Send this with the same Sanctum bearer token already used for every other API call (customer token on the website widget, admin token on the mobile app) — same auth pattern as every other endpoint in this API, nothing new to implement for auth itself. Most Pusher client SDKs (`pusher-js`, `@pusher/pusher-websocket-react-native`) have a built-in `authEndpoint` + `auth.headers` option — point it at the URL above with `Authorization: Bearer <token>`.

---

## Channels & events

### `admin.chat-queue` (private) — every active admin can subscribe

The live "new chat waiting" queue view. Events:

- **`session.requested`** — a new session is waiting.
  ```json
  { "session_id": 42, "customer_name": "Acme Tyres GmbH", "started_at": "2026-07-19T10:00:00Z" }
  ```
- **`session.status_changed`** — a session was accepted or closed (by anyone) — remove it from your pending list the moment this fires, so two admins don't both think a session is still up for grabs.
  ```json
  { "session_id": 42, "status": "active", "admin_id": 7, "admin_name": "Jane" }
  ```

### `chat-session.{id}` (private) — only that session's customer, or the assigned admin

- **`message.sent`**
  ```json
  { "id": 101, "session_id": 42, "sender_type": "customer", "sender_id": 12, "body": "Do you have stock of 205/55R16?", "created_at": "2026-07-19T10:00:05Z" }
  ```
- **`session.status_changed`** — same shape as above, fires here too so both participants see "chat ended" live.

---

## REST endpoints

### Customer side (website widget / customer portal — Sanctum customer token)

```
POST /api/v1/auth/chat/sessions                  → start (or resume) a session
GET  /api/v1/auth/chat/sessions/{id}              → fetch session + full message history
POST /api/v1/auth/chat/sessions/{id}/messages     { body }
POST /api/v1/auth/chat/sessions/{id}/close
```

Starting a session when one is already pending/active for that customer just **returns the existing one** — safe to call on every widget page load, no dedup logic needed on your side.

### Admin/mobile side (Sanctum admin token, `crm.view`/`crm.update`)

```
GET  /api/v1/admin/chat-sessions                  → pending + active sessions (add ?status=closed for history)
POST /api/v1/admin/chat-sessions/{id}/accept      → claim a pending session
POST /api/v1/admin/chat-sessions/{id}/messages    { body }
POST /api/v1/admin/chat-sessions/{id}/close
```

**No decline endpoint** — declining a push notification is purely a client-side dismiss (the session stays `pending`, visible to every other available admin). Nothing to call.

**Accept can lose the race** — if two admins tap Accept within moments of each other, the second gets `409 { "code": "already_claimed" }`. Handle this by just removing the session from that admin's queue view (the `session.status_changed` broadcast will already be telling them the same thing in real time).

---

## Presence gates who gets notified

`PUT /admin/presence { available_for_chat: bool }` (built earlier, already live) — only admins with this `true` receive the push notification when a session starts. Every active admin can still see the queue and manually accept regardless of their own presence flag; presence only controls the push.

---

## Where the transcript ends up

On close, the full message history is saved as **one row** in the same communications thread every other channel already uses — `channel: "live_chat"` — so it shows up in `GET /admin/customers/{id}/communications` and the unified inbox (`GET /admin/communications/inbox`) exactly like an e-mail or WhatsApp thread. Nothing new to build on your side to see chat history later — it's already in the same place.

## Please scan / confirm

- Register the Pusher app and send credentials — this is the only thing actually blocking real-time delivery.
- Wire the presence toggle (already built) prominently, per Pillar 3's UI note — it's what makes the push routing correct.
- Confirm whether you want a website-side chat widget built as a separate piece of work, or if that's already in scope for someone on your team — happy to write that contract once this mobile-side shape is confirmed working.

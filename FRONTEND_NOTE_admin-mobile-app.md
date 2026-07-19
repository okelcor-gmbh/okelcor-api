# Plan — Okelcor Admin/Ops Companion App (React Native)

**From:** Backend · **For:** whoever picks this up on the mobile/frontend
side (this doc is written to be handed to a fresh Claude Code session with
no other context on this conversation).
**Status:** Proposal / plan — nothing built yet on either side. This is the
full v1 scope, architecture recommendation, and a clear split of what's
mobile-side work vs. backend-side work, so both can move in parallel.

---

## The idea, in one sentence

Not a full mobile version of the admin panel — a **push-notification-first
companion app** for admins/ops/sales staff, so the things that already
happen inside the desktop admin panel (a customer reply arrives, a security
alert fires, an AI insight is generated, a quote comes in) reach someone's
phone the moment they happen, with just enough quick-action screens to react
without waiting to get back to a desktop.

## Why this, and why now

This backend already has a working notification pipeline, an AI-insights
feed, and a unified inbound-communications inbox — all of it currently only
visible if someone is logged into the desktop admin panel. The gap isn't
missing features, it's **missing reach**. A sales rep at a trade show, or an
order manager away from their desk, currently has no way to know a customer
just replied or a security event just fired until they next open a laptop.
Closing that gap is the entire value proposition here — faster response
time and fewer missed leads/replies, not a new feature surface.

## Framework recommendation: Expo (managed React Native), not bare RN

- **Push notifications**: Expo's push service (`exp.host/--/api/v2/push/send`)
  sends to both iOS (APNs) and Android (FCM) through one unified API and one
  token format — no separate Apple/Google push certificate management. This
  alone is worth choosing Expo for a v1 built by a small team.
- **Fast iteration**: OTA updates for JS-only changes, no app-store review
  cycle for most fixes once the native shell is approved once.
- **Code sharing with the existing Next.js frontend**: don't force a
  monorepo restructure on day one. Start by sharing just the **TypeScript
  types for API responses** (copy or a small shared npm package) — the
  actual API client/fetch logic is thin enough in both stacks that
  duplicating it once is cheaper than coordinating a shared package before
  there's a second consumer proving out the shape. Revisit a real monorepo
  (Turborepo / npm workspaces) only if duplication actually causes a bug
  from the two clients drifting out of sync.

---

## Auth — fully reused, nothing new needed here

The mobile app authenticates exactly like the existing admin panel:

```
POST /api/v1/admin/login          { email, password }
POST /api/v1/admin/login/2fa      { session_token, code }
GET  /api/v1/admin/me             → user + role + permissions
```

Same Sanctum personal-access-token model, same 2FA requirement, same
`AdminPermissions::MAP` role/permission shape already returned by `/me`.
Store the token in Expo SecureStore (not AsyncStorage — SecureStore uses the
Keychain/Keystore, appropriate for an auth token). Use the returned
`permissions` array to hide/show quick actions per role, same as the web
admin panel already does.

---

## Backend work required (I'll build this in parallel — not your scope)

Two things don't exist yet and need building before push notifications can
actually reach a phone:

1. **Device token registration** — `POST /admin/push-tokens { token,
   platform }`, scoped to the authenticated admin, storing Expo push tokens
   against the admin's account (new small table). Call this once after
   login and whenever Expo's `Notifications.getExpoPushTokenAsync()`
   returns a token (it can rotate).
2. **Actually sending the push** — `AdminNotificationService` (already the
   single place every in-app notification is created —
   `notifyUser`/`notifyPermission`) gets a hook that also posts to Expo's
   push API for any registered device belonging to the target admin(s).
   Every notification type that exists today (`email_reply_received`,
   `customer_message_reply`, security alerts, follow-up reminders, the new
   `admin_insights` generation, etc.) starts reaching phones automatically,
   with zero changes needed on the mobile side beyond having registered a
   token.

I'll ship both alongside your work — flagging them here so the mobile app
isn't designed around a gap. Everything else below (all the GET endpoints)
already exists today and works right now.

---

## V1 screens (deliberately thin — monitoring + quick reaction, not data entry)

| Screen | Backend endpoint(s) | Notes |
|---|---|---|
| Login / 2FA | `POST /admin/login`, `POST /admin/login/2fa` | Existing, unchanged |
| Notification feed | `GET /admin/notifications`, `.../unread-count`, `POST .../{id}/read` | Push tray + in-app list; tap uses each notification's existing `action_url` to deep-link |
| Insights feed | `GET /admin/insights` | Simple card list — `category`/`severity`/`headline`/`detail`; empty state until `GEMINI_API_KEY` is live in production (see `FRONTEND_NOTE_admin-insights.md`) |
| Inbox | `GET /admin/communications/inbox`, `GET /admin/customers/{id}/communications`, `POST /admin/customers/{id}/communications/send-email` | The single most valuable interactive screen — read a reply and respond from a phone. Attachments optional for v1. |
| Order lookup | `GET /admin/orders?q=`, `GET /admin/orders/{id}` | Read-only in v1 — status, payment stage, tracking |
| Quotes list | `GET /admin/quote-requests` (existing web endpoint) | Read-only in v1 |
| Security glance | `GET /admin/security/summary` | Read-only — today's failed logins, critical events, 2FA adoption |
| Settings | — | Logout, notification categories (if/when granularity is wanted — see below) |

## Explicitly out of scope for v1

Anything that's genuinely a desktop workflow stays desktop-only: creating or
editing orders, generating/uploading trade documents, WhatsApp send,
supplier intel search, product/catalogue management, historical order entry.
The phone's job is "notice something happened, glance at it, maybe reply" —
not primary data entry. Re-scope any of these into v2 only if real usage
shows admins reaching for their phone to do them.

## Not built yet, worth flagging (your call whether v1 or later)

- **Per-category push preferences** (e.g. mute security alerts but keep
  inbox replies) — no admin-side equivalent of the customer portal's
  `notification-preferences` endpoint exists today. Fine to default to "all
  categories on" for v1 and add granularity later if requested.

---

## Suggested build order

1. Expo project scaffold + login/2FA screen (proves the auth flow end to
   end against the real API).
2. Push token registration screen/flow (wait on my backend piece landing,
   but the client-side `getExpoPushTokenAsync()` + POST call can be built
   and tested against a stub immediately).
3. Notification feed + insights feed (both trivial reads, good early wins).
4. Inbox thread view + reply (the highest-value, most involved screen).
5. Order lookup, quotes list, security glance (straightforward reads,
   lowest priority, do last).

## Open decisions for whoever builds this

- Confirm Expo managed workflow is acceptable (vs. bare RN) — recommended
  above for the push-notification simplicity, but flag if there's a reason
  to avoid it (e.g. a native module needed later that Expo doesn't support).
- Confirm starting with duplicated types/client rather than a monorepo — 
  revisit only if drift becomes a real problem.
- Decide navigation library / UI kit — no opinion from the backend side,
  pick whatever the team already knows.

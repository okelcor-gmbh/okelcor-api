# Plan — Admin/Ops App v2: Live Chat, In-App Actions, Premium UI

**From:** Backend · **For:** the mobile/frontend team, follow-up to
`FRONTEND_NOTE_admin-mobile-app.md` (v1 — live in production: notifications,
AI insights, inbox read/reply, order lookup, quotes list, security glance,
push notifications).
**Status:** Proposal for v2. Nothing in this note is built yet. Three
pillars: **live chat**, **in-app quick actions** ("execute, not just view"),
and **premium UI/UX polish**. Backend work needed for each is called out
inline — I'll build those in parallel, same split as v1.

---

## Pillar 1 — Live chat

**What it is:** a real-time "Talk to us" widget on the Okelcor website/
customer portal, answered from the mobile app — not a replacement for the
existing e-mail/WhatsApp inbox, a third, real-time channel for the moment a
B2B buyer has a last-minute question right as they're about to commit to an
order. That's the highest-stakes "quick response" moment there is — a
question answered in 30 seconds can be the difference between a placed
order and an abandoned one; answered next business day, it's a lost sale.

**How it should work (MVP, not a full enterprise chat platform):**
- A customer opens the chat widget on the website → creates a chat session,
  routed to whichever admin has marked themselves **"Available for chat"**
  in the mobile app (see Presence below) — not everyone, so a phone doesn't
  buzz for someone off duty.
- That admin gets a push with **Accept / Decline** actions right on the
  notification (see Pillar 3) — accepting opens the chat thread; declining
  routes to the next available admin.
- Messages stream in real time while the session is open. On close (admin
  ends it, or an inactivity timeout), the full transcript is saved into the
  **same `customer_communications` table** every other channel already
  uses — `channel: 'live_chat'` — so a chat shows up in that customer's
  unified history exactly like an e-mail or WhatsApp thread does today. No
  parallel data model.
- **Presence toggle**: a simple on/off switch in the app — "Available for
  chat" — visible and easy to reach (top of the home screen, not buried in
  settings). This is the one thing that makes live chat sustainable rather
  than an always-on interruption.

**Backend work required (I'll build this — flagging so mobile isn't
designed around a gap):**
- Real-time transport: **Pusher (free tier)**, not self-hosted Laravel
  Reverb — corrected after checking this app's actual hosting.
  `QUEUE_CONNECTION=sync` in production today means there isn't even a
  persistent queue worker running, which signals the current shared
  hosting can't run a long-lived WebSocket server process — exactly what
  Reverb needs. Pusher hosts the actual socket server in their cloud;
  our backend only ever makes plain HTTPS calls to publish a message, no
  persistent process needed on our side. 200k messages/day free tier is
  comfortably enough for this app's chat volume. Nothing changes from the
  mobile app's point of view — still just subscribing to a channel.
- New `live_chat_sessions` + reuse `customer_communications` for messages.
- Admin presence endpoint (`PUT /admin/presence { available_for_chat }`).
- Routing logic (least-recently-assigned available admin) + push-with-
  actions on a new chat request.
- A lightweight WebSocket channel the mobile app subscribes to per active
  session for real-time message delivery.

**What ships on the website side too:** a small chat widget component for
the customer portal/site — flagging that this note's "mobile" framing has
a website counterpart; happy to write a separate contract for that once
this shape is confirmed.

---

## Pillar 2 — In-app quick actions ("execute, not just view")

v1 was deliberately read-only beyond replying in the inbox. Now that it's
running, the next lever is turning "notice something" into "resolve it
without opening a laptop" — for the specific set of actions that are a
single, low-risk state transition (not real data entry, which stays
desktop-only, unchanged from v1's scope):

| Action | Where it already exists on the backend |
|---|---|
| Reply to a customer message | Already built (v1) — extend to inline-reply **from the push notification itself**, no app-open required |
| Approve / reject a financial revision request | `POST /admin/orders/{id}/financials/approve-revision` / `reject-revision` — just needs a one-tap UI |
| Mark a bank-transfer order as paid | `POST /admin/orders/{id}/mark-paid` — one-tap with a confirmation step (this one's real money, keep the confirm) |
| Move a quote's status (new → reviewing → quoted) | Existing quote endpoints — quick-action buttons on the quotes list, no full form |
| Bump an order's status along the common path (confirmed → processing → shipped) | Existing `PATCH /admin/orders/{id}/status` — a simplified one-tap version for the common transitions only; anything unusual still routes to desktop |
| Accept / decline a live chat request | New, see Pillar 1 |

**Design principle for what qualifies as a quick action:** reversible or
low-risk, single state change, no multi-field form. Anything that's real
data entry — creating an order, editing line items, generating or
uploading documents, currency conversion — stays a desktop-only workflow,
exactly as scoped in v1. Don't let "premium" quietly turn into "rebuild
the whole desktop admin panel on a phone."

---

## Pillar 3 — Premium UI/UX

Concrete, not just "make it nicer":

- **Actionable push notifications** — use notification categories with
  action buttons (Approve/Decline, Reply, View) so a real chunk of Pillar
  2's actions never require opening the app at all. This is the single
  highest-leverage UI change here — it's the actual product idea ("things
  that require quick response") made literal.
- **A "Today" home screen**, not a generic dashboard — one glanceable
  screen: today's revenue delta, orders needing action, unread inbox
  count, pending chat requests. The AI insights feed (already built) is
  the natural hero content here — it exists specifically to say "here's
  what actually matters right now."
- **Presence toggle** front and center — given it gates whether someone
  gets interrupted at all, it shouldn't be buried in settings.
- **Swipe gestures** on list rows (swipe-to-approve, swipe-to-dismiss) —
  matches the "quick response" ethos better than tap-into-detail-then-act.
- **Haptic feedback** on quick actions — lets someone confirm an action
  succeeded without needing to look, useful for someone glancing at a
  phone mid-task.
- **Dark mode as first-class**, not a toggle added later — a lot of the
  "check your phone quickly" moments this app is built for happen in
  variable lighting (warehouse floor, outdoors, evening).
- **Biometric app lock** (Face ID / fingerprint) — this app now executes
  real financial actions (mark paid, approvals). A lock screen is both a
  real security improvement and a "this feels like a serious tool" signal.
- **Unified badge count** on the app icon — notifications + unread inbox +
  pending chat requests combined, so the badge always means "something
  needs you," not three different half-truths.
- **Tactile success states** — a real confirmation animation on actions
  like "Mark Paid," not just a toast. Small, but it's the difference
  between "utility app" and "premium app."
- **Home-screen widget** (iOS/Android), stretch goal — today's revenue +
  pending-action count visible without even opening the app. The ultimate
  version of "quick glance."

---

## Suggested build order

1. Actionable push notifications (Pillar 3's top item) — highest leverage
   for lowest new surface area, and Pillar 2's quick actions become far
   more useful once they're reachable from the notification itself.
2. Quick actions on existing endpoints (approve/reject, mark-paid, quote
   status, order status bump) — all backend endpoints already exist.
3. Presence toggle + "Today" home screen redesign.
4. Live chat — the biggest lift (new real-time infrastructure), sequence
   last so 1–3 are already raising the app's day-to-day usefulness while
   chat is being built.
5. Polish pass: haptics, dark mode, biometric lock, badge count, widget.

## Open decisions

- Live chat needs a website-side widget too — separate contract, confirm
  timing/ownership once this mobile-side shape is agreed.
- Decide how aggressive the "quick action" UI should be about skipping
  confirmation dialogs — recommended above: confirm on anything touching
  real money (mark-paid), skip confirmation on everything else (quote
  status, chat accept/decline) to keep it genuinely fast.

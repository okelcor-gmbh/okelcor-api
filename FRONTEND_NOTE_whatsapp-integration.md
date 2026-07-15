# Frontend Note — WhatsApp Business integration

**From:** Backend · **Re:** admin communications, customer portal, lead inbox
**Status:** Backend built + tested (service layer fully automated-tested;
webhook/composer flows written and MySQL-gated per repo convention).
**Depends on:** account-side Meta setup (`WHATSAPP_SETUP.md`) before any of
this sends/receives a real message — code-complete either way.

---

## What this is

WhatsApp is now a **channel alongside e-mail** on the exact same
communication log built for the Outlook-style e-mail feature
(`customer_communications` — same table, same `channel`/`attachments`/
`staff_read_at`/`customer_read_at` fields, `type: "whatsapp"` instead of
`"email"`). If you already built UI for the e-mail thread/composer, most of
this is the same components with a different endpoint and a phone number
instead of an e-mail address.

Two genuinely new things beyond "another channel":
1. **Two-way, live** — unlike e-mail (where a customer replying in their own
   inbox doesn't come back to us), a customer's WhatsApp reply lands back in
   this system automatically via webhook. No portal-only workaround needed here.
2. **WhatsApp messages can create leads** — a first-time WhatsApp contact
   with no matching customer/quote automatically becomes a new inquiry in
   the existing CRM pipeline (same quality-scoring, same notifications,
   same lead funnel analytics you already have for website/landing leads).

---

## 1. Admin — compose/send

```
POST /admin/customers/{id}/communications/send-whatsapp
POST /admin/quote-requests/{id}/communications/send-whatsapp
```

```jsonc
// Request
{ "body": "Hi! Just checking in on your tyre order — any questions?" }
```

```jsonc
// Response (201)
{
  "success": true,
  "message": "WhatsApp message sent to 233241234567.",
  "data": {
    "id": 55, "type": "whatsapp", "direction": "outbound", "channel": "whatsapp",
    "phone_number": "233241234567", "body": "Hi! Just checking in...",
    "whatsapp_message_id": "wamid.XXXX", "whatsapp_status": "sent",
    "status": "sent", "created_at": "2026-07-15T10:00:00+00:00"
  }
}
```

**Important — the 24-hour window.** WhatsApp only allows free-form text
replies within 24 hours of the customer's last message to us. Outside that
window, the send fails with 502 `code: "whatsapp_send_failed"` and Meta's
own error message (usually mentions "re-engagement"). There is currently
**no** "send any text as a template" fallback — only a small, fixed set of
pre-approved automated templates exist (order shipped, payment reminder,
etc.), not a general composer-to-template path. Practically: show the
compose box as normal, but if the send fails with this error, tell the
admin the customer needs to message first (or hasn't in the last 24h) —
don't present it as a generic error.

**No attachments in the admin WhatsApp composer yet** (deliberate v1 scope
— unlike the e-mail composer, which supports them). Sending a document (a
proposal/invoice PDF) via WhatsApp exists at the service layer
(`WhatsAppService::sendDocument`) but isn't wired to an admin endpoint yet —
flag if this is wanted and it's a small addition.

Error responses:

| Status | `code` | Meaning |
|---|---|---|
| 422 | `missing_recipient_phone` | Customer/quote has no phone number on file — disable the WhatsApp compose button in this case |
| 502 | `whatsapp_send_failed` | Send failed — most commonly the 24h window (see above); the message + error are both in the response |

---

## 2. Reading the thread

`GET /admin/customers/{id}/communications` (existing endpoint, unchanged
route) already returns WhatsApp messages mixed in with e-mail/manual-log
entries — filter/group by `channel` (`"whatsapp"` vs `"email"`) or `type` in
your thread UI, same as you'd already be doing to distinguish e-mail from
manual notes. New fields on every row: `phone_number`,
`whatsapp_message_id`, `whatsapp_status` (`sent`/`delivered`/`read`/`failed`
for outbound; `received` for inbound), `whatsapp_template_name` (set only
for automated template sends).

`whatsapp_status` is a genuine delivery/read receipt from Meta (updates
asynchronously after send) — worth showing as a small status icon on
outbound WhatsApp messages (✓ sent, ✓✓ delivered, ✓✓ blue read), the same
visual language WhatsApp itself uses, which staff will already recognize.

`POST /admin/communications/{id}/read` (existing, unchanged) works for
WhatsApp rows too — marks staff-side read.

---

## 3. Leads that arrive via WhatsApp

A first-time WhatsApp message with no matching customer/quote becomes a new
row in the **existing** quote/inquiry list — nothing new to build for this
specifically, it'll just show up in whatever inbox/queue you already have
for `GET /admin/quote-requests`, with `lead_source: "whatsapp"`. Two things
worth surfacing if not already generic:

- A "WhatsApp" badge/icon wherever `lead_source` is shown (same place
  you'd show "Website" or "Tyre Wholesaler Landing" today).
- The lead's `email` field will be a synthetic placeholder
  (`whatsapp+{phone}@no-email.okelcor.internal`) until staff replace it with
  a real one during triage — don't display this as if it's a real address;
  maybe render "No e-mail (WhatsApp lead)" when the email matches that
  pattern, or just rely on staff recognizing it.
- Lead Funnel Analytics (`GET /admin/quote-requests/funnel`) already
  breaks down by `lead_source` generically — WhatsApp shows up there with
  zero extra backend work; if your funnel dashboard UI hardcodes a list of
  known sources anywhere, add "whatsapp" to it.

---

## 4. Customer portal — opt-in toggle

WhatsApp notifications are **off by default** for every customer (unlike
e-mail) — Meta requires explicit opt-in before sending template messages.
Add a toggle to the existing notification preferences page:

```
GET/PUT /auth/customer/notification-preferences
```

New field: `whatsapp_enabled` (boolean, same shape as the existing
`email_marketing`/`email_orders` toggles you already render). Suggested copy:
*"Receive order and account updates on WhatsApp"* — with a note that the
customer needs a phone number on file (link to profile/phone field if empty).

No separate customer-facing WhatsApp inbox is built (unlike the e-mail
feature's portal messages page) — WhatsApp conversations happen in the
customer's actual WhatsApp app, not inside the Okelcor portal. Nothing to
build here beyond the opt-in toggle.

---

## Please scan / confirm on your side

- Add the WhatsApp compose action + 24h-window error handling to the
  customer/quote communications tab (§1).
- Render `whatsapp_status` as delivery/read ticks on outbound WhatsApp rows (§2).
- Add a "WhatsApp" lead-source badge if your lead list/funnel UI has a
  fixed list of source icons/labels (§3).
- Add the `whatsapp_enabled` toggle to customer notification preferences (§4).
- Confirm with the order manager whether a document-send button
  (proposal/invoice via WhatsApp) is wanted — service-layer support exists,
  just not wired to an endpoint yet.

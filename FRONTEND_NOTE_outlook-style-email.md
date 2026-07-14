# Frontend Note — Outlook-style compose/reply, signatures, customer messaging

**From:** Backend · **Re:** admin e-mail composer + signature + customer portal messages
**Status:** Backend built + tested (sanitizer fully automated-tested; the rest
written and gated to run in CI against MySQL, same pattern as every other
session in this repo). Nothing here has a UI yet — everything below is new.

---

## What this is, in one paragraph

Staff can now compose and send a real, rich-formatted e-mail to a customer
(or reply to a customer's own portal message) directly from the admin panel
— with a saved signature that's appended automatically, CC, file
attachments, and correct e-mail threading. This extends the existing CRM-6
"communication log" (`customer_communications` table) rather than
introducing a new system — the manual "I called them" / "I emailed them"
logging you already have keeps working unchanged; this adds a **real send**
path alongside it.

**Not built (deliberate scope decision, not an oversight):** true inbound
e-mail capture — i.e. a customer hitting "Reply" inside their own Outlook/
Gmail and that reply automatically landing back in Okelcor. That needs a
receiving subdomain + MX record + webhook, which is materially more
infrastructure than everything else here. Instead, "two-way visibility" is
solved via the customer's own **portal** — they read and reply to messages
logged in their account, and any reply is fanned out to staff immediately.
If a customer replies to the actual e-mail in their inbox, that reply goes
to the sending admin's personal inbox as a normal e-mail, same as before this
feature existed — the portal thread doesn't see it. Flag it if this
distinction needs to be surfaced to the order manager before launch.

---

## 1. Signature — one-time setup, then automatic on every send

- `GET /admin/profile` already returns `email_signature` (raw sanitized HTML, or `null`).
- `PUT /admin/profile/signature` — body: `{ "signature_html": "<the pasted HTML>" }`.
  Sanitized server-side (namespace-tag stripping, inline logo image
  extracted to storage, strict allow-list) before saving. Response echoes
  back the **stored, sanitized** version — render that in the editor
  immediately after save so the admin sees exactly what will go out, not
  their raw paste.
- Build the editor as a plain `contenteditable` div, **not** a controlled
  React input bound to state on every keystroke (that clobbers the cursor
  mid-paste). Load the current signature into it once on mount, read
  `el.innerHTML` only when the admin clicks Save. Let the browser handle
  paste natively — do not intercept and rebuild pasted content yourself, the
  fidelity comes from the browser, the safety comes from the backend sanitizer.
- The signature is appended to every outgoing e-mail **automatically, at
  send time, server-side** — nothing to do in the composer for this. If an
  admin updates their signature, their very next send uses the new one; no
  draft anywhere holds a stale copy.
- One raw-paste size cap: 200KB before sanitization. A signature bigger than
  that is almost certainly a mistake (e.g. a full-resolution photo pasted in
  instead of a logo) — show the 422 message as-is, it's already user-facing.

---

## 2. Compose / send an e-mail

Two endpoints, identical body — pick whichever context you're composing
from (a customer's own page, or a specific quote/inquiry):

```
POST /admin/customers/{id}/communications/send-email
POST /admin/quote-requests/{id}/communications/send-email
```

```jsonc
// multipart/form-data (attachments require this; use it even with none)
{
  "subject": "Following up on your inquiry",
  "body": "<p>Rich HTML from the composer — sanitized server-side, send as-is.</p>",
  "cc": ["someone@company.com"],           // optional, max 5, each a valid e-mail
  "in_reply_to_id": 41,                    // optional — see threading below
  "attachments": [/* File objects */]       // optional, max 5 files, 10MB each, pdf/jpg/jpeg/png/doc/docx/xls/xlsx/csv
}
```

Response (201 on success, matches the existing communication-log shape plus new fields):

```jsonc
{
  "success": true,
  "message": "E-mail sent to buyer@acme-tyres.com.",
  "data": {
    "id": 88,
    "type": "email", "direction": "outbound", "channel": "email",
    "subject": "Following up on your inquiry",
    "body": "<p>...</p>",              // sanitized version, signature NOT included (appended only in the actual e-mail)
    "cc": ["someone@company.com"],
    "attachments": [
      { "name": "invoice.pdf", "mime": "application/pdf", "size": 20481, "download_url": "https://api.okelcor.com/api/v1/admin/communications/88/attachments/0/download" }
    ],
    "message_id": "b3f...@okelcor.com",
    "in_reply_to": null,
    "status": "sent",                  // or "failed" — see below
    "staff_read_at": null,
    "customer_read_at": null,
    "created_at": "2026-07-14T10:00:00+00:00"
  }
}
```

**Composer UI, same paste-through pattern as the signature:** a plain
`contenteditable` div, uncontrolled, native paste. This is the whole point —
someone pasting a table copied from Outlook or a formatted paragraph from
Word should see it rendered faithfully in the composer, exactly like the
signature editor.

**Threading:** if the admin is replying to a specific prior message (viewing
a thread and hitting "Reply" on one row), send that row's `id` as
`in_reply_to_id`. The backend:
- Prefixes the subject with `Re: ` if not already present.
- Sets the real `In-Reply-To`/`References` e-mail headers so the message
  threads correctly in the recipient's own mail client.
- Returns the resolved `subject` and `in_reply_to` in the response — use
  those (not what you sent) since the backend may have rewritten the subject.

**Failure handling:** a send failure still returns the logged communication
row (so nothing is silently lost), but as **502** with
`code: "email_send_failed"` and `success: false`. Show the error but don't
treat it as data loss — the attempt (including any attachments) is already
saved and visible in the thread with `status: "failed"`.

**Recipient has no e-mail on file:** 422 with `code: "missing_recipient_email"`
— disable the compose button (or show this inline) if the customer/quote
record has no e-mail address rather than letting the send attempt fail.

---

## 3. Reading the thread / marking read

`GET /admin/customers/{id}/communications` (existing endpoint, unchanged
route) now also returns the new fields (`channel`, `cc`, `attachments`,
`message_id`, `in_reply_to`, `staff_read_at`, `customer_read_at`) on every
row — including the manually-logged ones (those fields are simply `null`
there). No separate endpoint for the composer's own thread view; reuse this
one and just add a "Compose" button alongside the existing "Log an
interaction" one.

`POST /admin/communications/{id}/read` — marks a message as read by staff
(sets `staff_read_at`). No customer/quote id needed in the path — this is a
flat, shared-inbox style action; any admin with `crm.view` can mark any
message read. Use this to drive an unread badge: a row where
`direction: "inbound"` and `staff_read_at: null` is new and unseen.

---

## 4. Customer portal — messages

```
GET  /auth/customer/communications
POST /auth/customer/communications/{id}/reply
POST /auth/customer/communications/{id}/read
GET  /auth/customer/communications/{id}/attachments/{index}/download
```

- The list only ever contains `type: "email"` rows for that customer — the
  internal call/note/system log entries never surface here.
- `meta.unread_count` on the list response = number of staff-sent messages
  the customer hasn't opened yet (`direction: outbound`, `customer_read_at: null`)
  — wire this to a bell/badge on the portal, same pattern as the existing
  customer notification bell.
- Reply (`POST .../reply`) is **plain body only in this first version** — no
  attachments from the customer side yet (kept out deliberately to limit
  scope; add if the order manager wants it later, same validation pattern as
  the admin side would apply).
- Marking a message read is on the customer to call when they open a thread
  (`POST .../read`) — there's no auto-mark-on-view happening in the API, so
  call it explicitly when the message is opened/scrolled into view.
- A reply create call also **immediately notifies every admin with CRM
  access** (not just whoever sent the original message) — that's what
  prevents a reply from being missed while the original sender is out. You
  don't need to build anything extra for this; it's automatic on the backend.

---

## 5. Suggested UI locations

1. **Admin → Settings/Profile → "My e-mail signature"** — one-time setup,
   the signature editor from §1. Every admin sets their own; there's no
   admin-of-admins signature management.
2. **Customer detail page → Communications tab** — add a **"Compose
   e-mail"** button next to the existing "Log an interaction" one. Opens
   the composer (§2): To (pre-filled, read-only — it's always this
   customer/quote's e-mail), CC (optional, chip input, max 5), Subject,
   body (rich paste-through editor), attachments (drag-and-drop, up to 5).
   A "Reply" affordance on each thread row passes that row's `id` as
   `in_reply_to_id`.
3. **Customer portal → new "Messages" page** — thread list (§4), open a
   thread to read + reply, unread badge in the account nav from
   `unread_count`.

---

## Please scan / confirm on your side

- Build the signature editor + composer as **uncontrolled contenteditable**,
  not a controlled rich-text state binding — this is the one implementation
  detail most likely to get silently "fixed" into something that breaks
  mid-paste cursor position.
- Never call the sanitizer's job yourself client-side "for safety" — paste
  through natively, let the backend be the only place that decides what's safe.
- Confirm with the order manager that portal-only two-way messaging (not
  full inbound-e-mail capture) is acceptable for launch — flagged clearly above.
- Wire the unread badges (staff side via `staff_read_at`, customer side via
  `meta.unread_count`) — cheap to add, and the whole point of building read
  receipts in the first place.

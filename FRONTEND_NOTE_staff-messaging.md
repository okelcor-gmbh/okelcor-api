# Frontend Note — Staff-to-staff messaging & forwarding

Session 97. Backend-owned contract note for the internal messaging feature.

**Status: built and tested, NOT yet deployed. Needs migration #44.**
The endpoints 500 until `2026_08_24_000001_create_staff_messages_tables`
has run — there is no previous behaviour to degrade to, so keep whatever
you build behind a flag until the deploy is confirmed.

---

## 1. What this is

Staff addresses were already in `admin_users`, but every messaging path in
the API ran admin → *customer*. There was no way for one staff member to
reach another from the panel. This adds:

- **Compose** — write to one or more colleagues, picked from a directory.
- **Reply / reply-all** — threaded.
- **Forward** — push a customer e-mail already in the system to whoever
  should actually handle it, with a note on top.

**Delivery is both.** Every message lands in the recipient's panel inbox
**and** as a real e-mail to their `@okelcor.com` address, **and** in their
notification bell, **and** on their phone via the existing Expo push. They
see it whether or not they are logged in.

---

## 2. Envelope

Same as every other endpoint in this API: `{ data, meta, message }`.
Read `data` first. (Yes — this is the thing the partner-sales note got
wrong. It is right here.)

---

## 3. Endpoints

All under `/api/v1/admin/`, all Sanctum bearer token.

| Method | Path | Notes |
|---|---|---|
| `GET` | `staff-messages?box=inbox\|sent&unread=1&per_page=25` | Paginated list |
| `GET` | `staff-messages/unread-count` | For the badge |
| `GET` | `staff-messages/directory` | Who you can write to |
| `GET` | `staff-messages/{id}` | One message + its whole thread |
| `POST` | `staff-messages` | Compose |
| `POST` | `staff-messages/{id}/reply` | Reply / reply-all |
| `POST` | `staff-messages/{id}/read` | Mark read |
| `GET` | `staff-messages/{id}/attachments/{index}/download` | Binary |
| `POST` | `communications/{id}/forward` | Forward a customer e-mail |

### Permissions

**Staff messaging has no permission gate.** Every authenticated admin can
write to a colleague — a permission here would mean an account that can log
in but cannot be told anything. Visibility is enforced per message: you can
see a message only if you sent it or you are on it.

**Forwarding requires `crm.view`** — you must already be allowed to read the
customer communication you are forwarding. An `editor` or `viewer` gets 403.

---

## 4. The directory

```
GET /api/v1/admin/staff-messages/directory

{ "data": [
    { "id": 4, "name": "Ben Adeyemi", "email": "ben@okelcor.com",
      "job_title": "Order Manager", "role": "order_manager" }
  ],
  "message": "success" }
```

Its own endpoint on purpose: listing admin accounts otherwise sits behind
`admins.manage`, which is **super_admin only** — an order manager could not
have seen a single colleague's name to write to. This returns strictly what
a compose box needs. No password state, no login history, no 2FA status.

Excludes you and anyone deactivated.

---

## 5. Compose

```
POST /api/v1/admin/staff-messages
Content-Type: multipart/form-data   (or JSON when there are no files)

to[]          required, admin_user ids, max 10
cc[]          optional, admin_user ids, max 10
subject       required, max 300
body          required, rich HTML, max 512000
attachments[] optional, max 5 files, 10MB each
              pdf,jpg,jpeg,png,doc,docx,xls,xlsx,csv
```

**Recipients are ids, not e-mail addresses** — deliberately. See §9.

Your signature (`admin_users.email_signature`, set via the existing
`PUT /admin/profile/signature`) is appended automatically at send time. Do
not paste it into the body — you will get it twice.

**Three behaviours to build against:**

1. **You are silently stripped from your own recipient list.** CC'ing
   yourself is an Outlook habit, not a mistake — your copy is in Sent.
   No error, the message just doesn't appear in your own inbox.
2. **A message to nobody but yourself is 422 `no_recipients`.**
3. **A deactivated colleague is 422 `unknown_recipient`**, with the offending
   ids in `errors.to`. Nothing is written.

**201 response:**

```json
{
  "data": {
    "id": 12, "thread_id": "9f1c…", "subject": "Container 4412 paperwork",
    "body": "<p>…</p>",
    "sender": { "id": 2, "name": "Ada Okafor", "email": "ada@okelcor.com" },
    "sent_by_me": true,
    "recipients": [
      { "id": 4, "name": "Ben Adeyemi", "email": "ben@okelcor.com",
        "kind": "to", "read_at": null, "email_status": "sent" }
    ],
    "attachments": [
      { "name": "bol.pdf", "mime": "application/pdf", "size": 20480,
        "download_url": "https://api.okelcor.com/api/v1/admin/staff-messages/12/attachments/0/download" }
    ],
    "is_forward": false, "forwarded_from": null,
    "in_reply_to_id": null, "unread": false,
    "created_at": "2026-08-24T09:14:22+00:00"
  },
  "meta": { "email_failures": [] },
  "message": "Message sent."
}
```

### `meta.email_failures` — please surface this

The message row is written **before** any e-mail is attempted, so a mail
failure never loses the message. If SMTP refuses for someone, you still get
**201** — the colleague has it in their panel inbox — and their id appears
in `meta.email_failures`, with `message` saying so.

Show it. "Sent, but the e-mail copy didn't reach Ben" is meaningfully
different from "sent", especially if Ben is the one person who needed to see
it today.

`email_status` per recipient is `sent` / `failed` / `null`, and is **only
populated for the sender** — to everyone else it is noise about someone
else's mailbox.

---

## 6. Reply

```
POST /api/v1/admin/staff-messages/{id}/reply

body          required
reply_all     optional boolean, default false
attachments[] optional, same limits as compose
```

`Re:` is prefixed automatically (not doubled). `thread_id` is carried over.

**Recipients come from the parent message and are never read from the
request.** Sending `to[]` here does nothing — a reply cannot be used to pull
a colleague into a thread they were never on and hand them its history.
If you need to add someone, that is a forward.

- default: replies to the original sender only
- `reply_all: true`: original sender + everyone who was on it, minus you

If you are the only person left on a thread, 422 `no_recipients`.

---

## 7. Forward

```
POST /api/v1/admin/communications/{id}/forward

to[]     required, admin_user ids, max 10
cc[]     optional
note     optional HTML — your covering message, goes above the original
subject  optional — defaults to "Fwd: <original subject>"
```

`{id}` is a `customer_communications` id — the rows you already render in
the unified inbox (`GET /admin/communications/inbox`) and in each customer's
thread.

**The original is quoted into the body, not linked** — a link is useless to
someone reading it on their phone at a port. **Attachments are copied**, not
referenced, so the forward survives the original communication being deleted.

Response carries provenance so you can link back:

```json
"is_forward": true,
"forwarded_from": {
  "communication_id": 812,
  "customer_id": 44,
  "quote_request_id": null,
  "action_url": "/admin/customers/44?tab=communications"
}
```

**Staff recipients only, deliberately.** No free-text address. A free-text
recipient would make the admin panel a route for customer correspondence to
leave the company, which needs its own permission and its own audit trail
before it is worth having. Ask if you want it and we will build it properly.

---

## 8. Reading

`GET staff-messages?box=inbox` returns rows with `unread`, `preview`
(140 chars, tags stripped), `is_forward`, `has_attachments`, `sender`,
`recipients`. `meta.unread_total` is on every list response, so you can
refresh a badge without a second call.

`GET staff-messages/{id}` returns `{ data: { message, thread } }` — `thread`
is every message sharing that `thread_id`, oldest first, filtered to what
you are allowed to see.

**A message you are not on is 404, not 403** — a 403 would confirm that a
message with that id exists. Same for its attachments.

`POST staff-messages/{id}/read` returns the new unread count. The sender
marking their own sent message read is a 200 no-op, not an error.

---

## 9. Two things worth knowing

**Replying from Outlook works, but doesn't come back into the system.**
The e-mail copy sets `Reply-To` to the **sender's own address**, not the
plus-addressed capture address used for customer mail. `InboundEmailProcessor`
deliberately drops anything sent from an `okelcor.com` address — that guard
stops the app's own order and quote notifications spawning fake leads — so a
staff reply arriving that way would be silently swallowed. Pointing Reply-To
at the sender means hitting reply in Outlook is a normal e-mail between two
colleagues: delivered, but outside the system. Replying **in the panel** keeps
it on the thread where everyone can see it.

Worth a line of UI copy on the e-mail and in the thread, so people know which
one keeps the record.

**Recipients are ids, not addresses**, everywhere. A free-text recipient
field would let a typo send internal correspondence to a stranger, and would
make "staff only" unenforceable. The directory endpoint exists so the compose
box never needs a typed address.

---

## 10. Notifications

Each recipient gets an `AdminNotification` of type `staff_message_received`
with `action_url: /admin/messages/{id}` and `related_type: staff_message`.
It flows through the existing bell and the existing Expo push to the mobile
app — nothing new to wire on either.

Note the e-mail's "Open in the admin panel" button also points at
`{FRONTEND_URL}/admin/messages/{id}`. **That route needs to exist** or the
button 404s.

---

## 11. Not built

- **Drafts.** Compose is send-or-discard.
- **Delete / archive.** Nothing removes a message yet. Internal
  correspondence about orders and claims is worth keeping by default; say
  the word if you want archive (hide from inbox, keep the row).
- **Read receipts shown to recipients.** `read_at` is captured per person
  and returned, but only the sender sees `email_status`. Whether colleagues
  should see who has read a message is a culture question, not a technical
  one — tell us what you want.
- **Group aliases** ("all order managers"). Recipients are individuals.
- **External forwarding.** See §7.

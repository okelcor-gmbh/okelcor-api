# Frontend Note — Internal staff messaging + making the signature easy to find

**From:** Backend · **Re:** team-to-team inbox inside the admin panel, plus
signature discoverability (finance's request, Session of 2026-08-24)
**Status:** Backend built + tested (9 feature tests green against MySQL, same
pattern as every other session). Nothing here has a UI yet — everything below
is new.

---

## What this is, in one paragraph

Staff can now send and receive messages **to each other** inside the admin
panel, with the same Outlook-style compose the customer inbox already has:
rich HTML body, attachments, CC, threading, per-recipient read receipts, and
the sender's saved signature appended automatically. Delivery is **in-app**
(inbox + the existing notification bell + companion-app push) — no real SMTP
e-mail is sent. That's deliberate: an internal message routed through Resend
and back through the Cloudflare inbound worker would be slower, could loop,
and would be no more delivered than a database row + bell that ring the
instant the send returns.

**Privacy rule the backend enforces everywhere:** only the sender and the
recipients of a message can read it — including attachments, including
mid-thread replies, including super_admin. Don't build any "all messages"
admin view; the API will 404 it.

---

## 1. Where it lives in the UI (recommendation)

The existing **Inbox** page (`/admin/inbox` or wherever
`GET /admin/communications/inbox` renders today) grows two tabs:

- **Customers** — the current view, unchanged.
- **Team** — the new internal inbox (`GET /admin/staff-messages/inbox`).

Add a second unread badge for Team (`GET /admin/staff-messages/unread-count`)
— poll it wherever you poll `GET /admin/notifications/unread-count`; they're
the same cost. A "New message" button on the Team tab opens the composer.

`action_url` on every payload row is `/admin/messages/{id}` — register that
route in Next.js as the message/thread view (notification bell clicks and
push taps deep-link to it).

## 2. Endpoints

All under `/api/v1/admin`, Sanctum bearer + 2FA as usual. Permission:
`staff_messages.use` — **every role holds it** (an inbox someone can be
locked out of isn't a company inbox), so no permission branching in the UI.

| Method & path | What |
|---|---|
| `GET  /staff-messages/inbox` | My received messages. `?unread=1`, `?per_page` (≤100). `meta.unread_count`. |
| `GET  /staff-messages/sent` | Messages I sent, with per-recipient `read_at` + `read_count`/`recipient_count`. |
| `GET  /staff-messages/unread-count` | `{ data: { unread_count } }` — for the badge. |
| `GET  /staff-messages/recipients` | The "To:" picker — every active colleague. Also `meta.signature_set` / `meta.signature_html` (see §4). |
| `POST /staff-messages` | Send (multipart when attaching). See below. |
| `GET  /staff-messages/{id}` | One message + `meta.thread` (whole thread, oldest first, only parts I may see). |
| `POST /staff-messages/{id}/read` | Mark read. Returns fresh `meta.unread_count`. |
| `POST /staff-messages/read-all` | Mark everything read. |
| `GET  /staff-messages/{id}/attachments/{index}/download` | Attachment (also given as absolute `download_url` on each attachment). |

### Sending

```
POST /api/v1/admin/staff-messages          (multipart/form-data if attaching)
to[]            required  admin_user ids, 1–20
cc[]            optional  admin_user ids, ≤20
subject         required  ≤300 chars
body            required  HTML, ≤500KB — sanitized server-side, same
                          sanitizer as the customer composer (pasted
                          Outlook/Word HTML is fine, inline images extracted)
in_reply_to_id  optional  id of the message being replied to
attachments[]   optional  ≤5 files, 10MB each, pdf/jpg/jpeg/png/doc/docx/xls/xlsx/csv
```

- Replying: pass `in_reply_to_id`; the backend prefixes `Re:` (if missing)
  and threads it (`thread_root_id`). Default the To: field to reply-all
  (thread's sender + recipients minus self) — the backend takes whatever
  `to`/`cc` you send.
- `422` with `code: "invalid_recipients"` + `invalid: [ids]` when a recipient
  is missing/deactivated. `422` plain when the sanitizer rejects the body.
  `502` with `code: "attachment_store_failed"` if storage fails.
- The sender's saved signature is appended server-side — show a live preview
  in the composer footer (`meta.signature_html` from `/recipients`), never
  append it client-side or it will double.

### Row shapes

Inbox row: `{ id, sender: {id, name, job_title}, subject, preview, unread,
kind ("to"|"cc"), has_attachments, thread_root_id, action_url, created_at }`.
Full message (show/thread): adds `body` (trusted HTML — sanitized at write,
render as-is in the same wrapper the customer thread uses), `recipients[]`
with per-person `read_at` (that's the "Seen ✓" line), `attachments[]` with
`download_url`, `is_mine`, `read_at` (mine).

## 3. Notifications (already wired — nothing to build)

Every recipient (except the sender messaging themself) gets a normal admin
notification: `type: "staff_message_received"`, `related_type:
"staff_message"`, `action_url: "/admin/messages/{id}"`, title "New message
from {name}", body = subject. The existing bell and Expo push pick these up
with zero frontend changes beyond routing `/admin/messages/{id}`.

## 4. Signature — make it findable (the second half of the request)

The backend for signatures has existed since the Outlook-style note
(`PUT /admin/profile/signature`, echoed in `GET /admin/profile`) — the
problem reported is that **nobody can find where to set it**. Build it into
three places, all opening the *same* editor:

1. **Settings → My Profile → "E-mail signature"** — a labelled card, not a
   buried field: current signature rendered as it will appear, an **Edit
   signature** button, and when empty a friendly empty-state ("You haven't
   set a signature yet — it's added to every e-mail and team message you
   send").
2. **Inside every composer** (customer e-mail *and* team message): show the
   signature preview under the body field with a small **Edit signature**
   link opening the same editor in a modal. `GET /staff-messages/recipients`
   returns `meta.signature_set` + `meta.signature_html` precisely so the
   composer knows this without an extra request (for the customer composer,
   `GET /admin/profile` has it).
3. **First-use nudge:** when `signature_set === false` and someone opens a
   composer, show a dismissible banner: "Add your signature once and it's
   appended automatically" → opens the editor.

The editor itself: a `contenteditable` div (NOT a controlled input — pasted
Outlook HTML must land raw), Save = `PUT /admin/profile/signature` with
`{ signature_html }`. The server sanitizes (strips Word/Outlook junk,
extracts inline logos to storage, allow-lists tags) and echoes the cleaned
result — always re-render from the response.

### "Get it from Outlook" help — render these steps in the editor

Put a **"Copy your existing signature from Outlook"** expandable right inside
the editor modal, with these steps verbatim:

**Outlook on the web (outlook.office.com):**
1. Click the ⚙️ gear (top right) → **Mail** → **Compose and reply**.
2. Your signature appears in the editor box under **Email signature**.
3. Click inside it, select everything (Ctrl/Cmd + A) and copy (Ctrl/Cmd + C).
4. Come back here, click into the editor, and paste (Ctrl/Cmd + V). Save.

**Outlook desktop (Windows):**
1. **File → Options → Mail → Signatures…**
2. Pick your signature in the list — it shows in the edit box below.
3. Select all of it (Ctrl + A), copy (Ctrl + C), paste it here, Save.

**Outlook desktop (Mac):**
1. **Outlook → Settings → Signatures.**
2. Double-click your signature to open it, select all (Cmd + A), copy
   (Cmd + C), paste it here, Save.

**No Outlook access right now?** Open any e-mail you've sent, select your
signature block at the bottom, copy, and paste it here — formatting and
logo come along.

Reassure in small print: *"Formatting and images are kept. Anything unsafe
is cleaned automatically when you save."*

## 5. Deploy notes

- One new migration: `2026_08_24_000001_create_staff_messages_tables.php`
  (two tables, `staff_messages` + `staff_message_recipients`, no ALTERs to
  existing tables). `php artisan migrate` on the server as usual.
- New permission key `staff_messages.use` ships in `AdminPermissions::MAP`
  (code, not DB — nothing to seed).
- Attachments store on the `local` (private) disk under `staff-messages/Y/m`,
  same as customer communications — no new storage config.
- Tests: `tests/Feature/StaffMessagingTest.php` (9 tests), MySQL-gated like
  the rest.

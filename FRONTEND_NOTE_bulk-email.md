# Frontend Note — Marketing Contacts & Bulk Email

**From:** Backend · **Re:** order manager's bulk-email tool · **Status:**
Backend built + tested (8 tests). New admin screens needed — nothing in the
customer-facing app changes.

The order manager asked for a way to import a contact list (dropped in the
repo root as `contacts.csv`, ~1,720 valid-email rows) and send bulk marketing
emails to it. This is a brand-new list — separate from `customers` (no portal
login) and separate from the existing "Contact Messages" inbox (contact-form
submissions). Everything below is behind Sanctum admin auth, permission
**`marketing.manage`** (roles: super_admin, admin, order_manager).

---

## 1. Contacts screen (list + import)

| Endpoint | Purpose |
|---|---|
| `POST /api/v1/admin/marketing-contacts/import` | multipart `file` (csv/txt, max 10MB) → imports/updates the list. Same Wix export format already used for customer import — no format conversion needed on your side, just a file picker + upload. |
| `GET /api/v1/admin/marketing-contacts?status=&company=&country=&search=&per_page=` | Paginated list |
| `GET /api/v1/admin/marketing-contacts/stats` | `{ total, subscribed, unsubscribed, unknown }` — good for a header summary |
| `DELETE /api/v1/admin/marketing-contacts/{id}` | Remove one contact |

Import response:

```jsonc
{
  "data": {
    "imported": 1723, "updated": 0, "skipped_no_email": 231,
    "unsubscribed": 128, "subscribed": 79, "errors": []
  },
  "message": "1723 contacts imported, 0 updated."
}
```

Contact row shape:

```jsonc
{
  "id": 1, "email": "buyer@acme.com", "first_name": "Jane", "last_name": "Doe",
  "phone": "+49...", "company": "Acme GmbH", "country": "DE", "vat_id": "DE123",
  "labels": "Kontaktformular;...", "source": "Form Submission",
  "status": "subscribed",   // subscribed | unsubscribed | unknown
  "created_at": "...", "updated_at": "..."
}
```

`status: "unknown"` means the person is an existing contact but never
explicitly opted in or out — that's most of the imported list (no double
opt-in on record). `unsubscribed` contacts can never receive a campaign; the
backend hard-excludes them regardless of any filter you send, so you don't
need client-side logic to protect against it, but it's worth greying them out
in the UI so the order manager understands why they can't be selected.

---

## 2. Bulk email composer + campaign history

| Endpoint | Purpose |
|---|---|
| `GET /api/v1/admin/bulk-emails/recipient-count?company=&country=&status=&search=` | Live "this will reach N contacts" preview as filters change — call this before showing the send button |
| `POST /api/v1/admin/bulk-emails` | `{ subject, body_html, filters: { company?, country?, status?, search? } }` → creates + queues the send |
| `GET /api/v1/admin/bulk-emails?per_page=` | Paginated campaign history |
| `GET /api/v1/admin/bulk-emails/{id}` | One campaign, detailed (includes `body_html`) |

`filters.status` only accepts `subscribed` or `unknown` (never `unsubscribed`
— that's not a valid audience to target, so don't offer it as a filter
option). Omit `filters` entirely to target everyone who isn't unsubscribed.

Send response (201):

```jsonc
{
  "data": {
    "id": 7, "subject": "New tyre stock arriving", "filters": { "country": "DE" },
    "total_recipients": 240, "sent_count": 0, "failed_count": 0,
    "status": "queued",     // queued -> sending -> completed | failed
    "created_by": "Jane Admin", "created_at": "...", "completed_at": null
  },
  "message": "Campaign queued for 240 contacts."
}
```

`422` if the filters match zero contacts — show that as a validation error,
not a generic failure.

**Progress UI:** poll `GET /admin/bulk-emails/{id}` while `status` is
`queued`/`sending` to show a progress bar (`sent_count + failed_count` out of
`total_recipients`). `body_html` is sanitized server-side (script/style/event
handlers stripped) before it's stored, so what you send in the composer isn't
necessarily byte-identical to what's stored — fine to just re-render what
`GET /{id}` returns if you need a preview.

**Composer:** plain HTML string in `body_html` — reuse whatever rich-text
editor you already have wired up for article bodies (same sanitizer,
`ArticleHtmlSanitizer`, runs on both). Every sent email automatically gets an
unsubscribe footer link appended server-side — don't add your own.

---

## 3. Nothing else changes

No customer-facing surface is touched. No changes to existing
`newsletter_subscribers` or `contact_messages` endpoints. This is purely a new
admin-only tool — add it to the admin nav under whatever section makes sense
for order_manager tasks (e.g. next to Newsletter).

**Known ops gap (not your side, flagging for visibility):** production
`.env` still has `QUEUE_CONNECTION=sync`. Until that's switched to
`database` + a queue worker is running, a real send of ~1,700 emails will
block the HTTP request instead of running in the background — backend note
tracks this, just don't be surprised if a big campaign's `POST` takes a long
time to respond in the interim.

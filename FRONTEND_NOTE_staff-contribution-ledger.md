# Staff Contribution Ledger — backend contract

**Session 89.** Migration **#38** (two new tables), **10 new routes**, so
`route:cache` must be rebuilt. Deploy-order safe in both directions — see the
last section.

This is phases 1 and 2 of the five-phase plan: **the Ledger** (work the system
watched happen) and **the Log** (work only the person knows about). Scorecards,
AI narrative reports and skills intelligence are phases 3–5 and are *not* in
this deploy.

---

## The one thing to get right in the UI

**Recorded work and self-reported work must never appear as one number.**

The API keeps them in two tables, returns them as two objects, and does not
provide a combined total anywhere. That is not an oversight to work around —
it is the promise that makes the feature acceptable to the people being
measured. A dashboard tile reading "47 items this month" that silently adds
nine self-entered rows to thirty-eight observed ones undoes it.

Every activity row carries `verified: true`. Every contribution row carries
`self_reported: true`. Render the distinction visibly — a chip, a column, a
separate panel. Not a tooltip.

---

## Endpoints

All under `/api/v1/admin/`, all behind the usual admin token + 2FA.

### Reading the ledger

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `staff/activity` | `staff.self` | Paginated feed of observed work |
| `GET` | `staff/summary` | `staff.self` | Counts by category, both halves kept apart |
| `GET` | `staff/members` | `staff.self` | Who this caller may look at |

**`staff.self` is held by every role, deliberately** — including `viewer`.
Nothing may be measured about a person that the person cannot open. Do not gate
the nav item on a permission check; everyone gets it.

`?admin_user_id=` defaults to the caller. Passing somebody else's id needs
`staff.view_team` (super_admin, admin, order_manager) and returns
**403 `code: staff_view_team_required`** otherwise — show the message, it says
what is missing rather than just "forbidden".

Other params: `from`, `to` (default: last 30 days), `category`, `per_page`.

### `GET staff/activity` — response shape

```json
{
  "data": [
    {
      "id": 812,
      "category": "documents",
      "category_label": "Trade documents",
      "action": "document_sent",
      "action_label": "Document sent",
      "subject_type": "order",
      "subject_id": 1042,
      "subject_label": "OKL-1042",
      "occurred_at": "2026-08-15T09:14:22+00:00",
      "metadata": { "new_value": "sent" },
      "verified": true
    }
  ],
  "meta": {
    "current_page": 1, "per_page": 25, "total": 118, "last_page": 5,
    "admin_user": { "id": 4, "name": "Petra Vogel", "role": "finance" },
    "from": "2026-07-19", "to": "2026-08-17",
    "categories": { "orders": "Orders", "documents": "Trade documents", "...": "..." },
    "is_self": true
  }
}
```

`subject_type` + `subject_id` are there so every row can be **opened**, not just
counted — link `order` to `/admin/orders/{id}`, `trade_document` to the document,
`customer` to the customer, `campaign` to the campaign. A number nobody can click
into is a number nobody trusts.

`meta.categories` is served rather than hardcoded, so the filter cannot drift
from what the endpoint accepts.

### `GET staff/summary` — response shape

```json
{
  "data": {
    "admin_user": { "id": 4, "name": "Petra Vogel", "role": "finance" },
    "from": "2026-07-19", "to": "2026-08-17",
    "recorded": {
      "total": 118,
      "by_category": [ { "category": "orders", "label": "Orders", "total": 44 }, "..." ],
      "top_actions": [ { "action": "document_sent", "label": "Document sent", "total": 31 }, "..." ],
      "active_days": 17
    },
    "self_reported": {
      "available": true,
      "total": 9, "verified": 6, "pending": 2, "rejected": 1,
      "by_category": [ { "category": "social_media", "label": "Social media & content", "total": 4 }, "..." ]
    },
    "note": "Recorded work is what the system watched happen. Self-reported work is entered by the person and shown separately, verified or not. The two are never added together."
  },
  "meta": { "is_self": true }
}
```

`by_category` always carries **every** category, empty ones as zero. "Nothing in
marketing" and "marketing is missing from this list" look identical when the
empty row is simply absent — so render the zeros rather than filtering them out.

`active_days` is distinct days on which anything was recorded. It is **not** a
productivity measure and must not be labelled as one — it answers "was this a
normal month or a fortnight of leave", which is the context a count of anything
is meaningless without. Suggested label: *"Days with recorded activity"*.

`self_reported.available: false` means migration #38 has not run. Hide that panel
rather than rendering zeros that look like "this person logged nothing".

### The manual log

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `staff/contributions` | `staff.self` | Own entries; team's with `staff.view_team` |
| `POST` | `staff/contributions` | `staff.self` | Accepts an optional `file` in the same request |
| `PATCH` | `staff/contributions/{id}` | `staff.self` | Own + still pending |
| `DELETE` | `staff/contributions/{id}` | `staff.self` | Own + still pending |
| `POST` | `staff/contributions/{id}/file` | `staff.self` | Attach or replace evidence |
| `GET` | `staff/contributions/{id}/file` | `staff.self` | Download (own, or with `staff.view_team`) |
| `POST` | `staff/contributions/{id}/review` | `staff.verify` | `{ decision: verified\|rejected, note? }` |

**Create fields:** `category` (required, one of the seven below), `title`
(required, ≤160), `description` (≤2000), `performed_on` (required, **not in the
future**), `minutes` (optional, 1–1440), `link` (optional URL), `file`
(optional, pdf/jpg/png/doc/docx/xls/xlsx, ≤20 MB).

Categories: `social_media`, `supplier`, `customer_visit`, `trade_fair`,
`training`, `internal`, `other`. Labels come back in `meta.categories`.

**`minutes` is optional and must stay optional in the UI.** Making someone
account for their hours turns a contribution log into a timesheet, which is a
different product with a very different reception.

**Evidence is invited, not required.** A supplier phone call has no artifact, and
refusing to record it would only mean it goes unrecorded. Each row reports
`has_evidence` so a reviewer can see what they are agreeing to — surface it, but
do not block submission on it.

Each row carries `can_edit` and `can_review` computed for *that viewer*, so the
frontend does not have to reimplement the rules and drift from them. Drive the
buttons off those two booleans, not off `status` plus a permission guess.

### Refusals worth rendering properly

| Status | `code` | When | What to show |
|---|---|---|---|
| 403 | `staff_view_team_required` | Asked for a colleague without the permission | The message — it names what is missing |
| 403 | — | Editing/deleting/attaching to someone else's entry | "You can only edit your own entries." |
| 409 | `already_reviewed` | Editing after a manager ruled on it | Offer "add a correcting entry" instead |
| 422 | `self_review` | Reviewing your own entry | "Ask a colleague with review rights" |
| 422 | validation on `note` | Rejecting with no reason | Make the note field required in the reject dialog |
| 422 | validation on `performed_on` | Future date | "Work cannot be logged for a date in the future." |

---

## What the ledger actually contains

Seven sources, all pre-existing, all already stamped with who did the work:

| Source | Category | Recorded as |
|---|---|---|
| `order_logs` | `orders` / `documents` / `finance` | the log's own action, mapped by area |
| `trade_documents` | `documents` | `document_issued` |
| `order_signoffs` | `finance` | `order_signed_off` |
| `customer_communications` (outbound only) | `support` | `customer_replied` |
| `bulk_email_campaigns` | `marketing` | `campaign_built` |
| `finance_invoices` (hand-entered only) | `finance` | `finance_invoice_recorded` |
| `partner_sale_audits` (`admin_user` only) | `partners` | `partner_sale_{action}` |

Four things are deliberately **not** in it, and it is worth knowing why if
someone asks where a number went:

- **No presence data.** Logins, session length, page views and clicks exist in
  the database and are excluded by design. Measuring presence rewards whoever
  leaves the tab open. If anyone asks for a "hours logged in" column, that is the
  conversation to have before it is built, not after.
- **Customer decisions.** An order confirmation the customer accepted is their
  act, not the order manager's.
- **A partner's own sale entry.** That is the partner's work.
- **Auto-registered invoices.** The registrar writes those; crediting finance
  with them would count the same work twice, once here and once through the
  order log that raised the invoice.

---

## Deploy-order safety

**Safe in both directions, and proved by test rather than assumed.**

- Before the migration: `StaffActivity::ledgerAvailable()` returns false and every
  recording hook no-ops. Confirming an order, raising a document and signing off
  all work exactly as before — a reporting table must never be able to fail the
  thing it reports on. There is a test that drops the table and asserts an order
  log still writes.
- The frontend can ship first: all ten routes 404 until the API deploys, so the
  page should show the usual "not available on this server yet" panel rather than
  an empty state that reads as "you have done nothing".
- `self_reported.available: false` in the summary is the explicit signal for that
  panel on the contributions half.

## After the deploy — one command worth running

```bash
php artisan staff:backfill-ledger              # survey, writes nothing
php artisan staff:backfill-ledger --fix        # then this
```

The ledger opens **empty** until this runs. Every source it reads has been
recording who-did-what for months, so the backfill is what lets the page open
with real history instead of taking a quarter to say anything. Survey first — it
prints the split per person and per category, which is worth eyeballing against
what the business believes happened before anything lands in a table people will
be judged on. Re-runnable; it cannot double anybody's month.

---

## Not built, and why

**Scorecards, AI-written monthly reports and skills intelligence** are phases
3–5. Phase 3 needs one business answer first: *does any of this ever touch pay,
bonus or promotion, or is it purely visibility?* A visibility tool can be
generous and approximate; one that decides money has to be defensible line by
line and needs an appeals route. Building the scoring before that is answered
means building it twice.

Phase 4 (the monthly narrative via Gemini) additionally needs
`QUEUE_CONNECTION=database` and a worker — still outstanding on production.

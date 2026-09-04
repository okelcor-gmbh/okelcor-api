# Frontend note — the after-sales claims queue (Session 119)

Claims used to live in e-mail threads. They are now a structured queue on the
same machinery as the finance snapshot and the team to-dos: **status +
assignee + My Work + notify-on-change**. This note is the API contract for
the admin panel.

## Endpoints

| Method & path | Permission | Notes |
|---|---|---|
| `GET /api/v1/admin/claims` | `claims.view` | `?status=open` (default) \| any status \| `all`; `?type=`, `?assignee=`, `?q=` (ref / customer / company / order number) |
| `POST /api/v1/admin/claims` | `claims.manage` | body below |
| `GET /api/v1/admin/claims/{id}` | `claims.view` | |
| `PATCH /api/v1/admin/claims/{id}` | `claims.manage` | any subset of the store fields + `status`, `outcome_note` |
| `DELETE /api/v1/admin/claims/{id}` | `claims.delete` (super_admin only) | a wrong claim is *closed with a note*, not erased — hide the delete button below super_admin |
| `PATCH /api/v1/admin/my-work/claims/{id}` | **none — being the assignee is the authorization** | `{ status, outcome_note? }` only |

**Who holds what:** `claims.view` = super_admin, admin, order_manager,
support, finance. `claims.manage` = the same minus finance (finance reads,
because an approved claim becomes a credit note, but does not write).

## Store body

`customer_name` (required), `description` (required, the customer's
complaint), and optionally `customer_email`, `customer_company`, `order_id`
(when the order is in the system), `order_number` (free text — historic and
eBay orders), `type`, `quantity`, `assigned_admin_id`.

## Vocabulary — always render from meta, never hardcode

`meta.types` and `meta.statuses` are `[{key, label}]`; `meta.staff` is the
assignee picker. Statuses: `new → in_review → awaiting_customer → approved |
rejected → closed`. **Only `closed` is terminal** — an approved claim still
owes the customer a credit note, a rejected one still owes them the reasons,
so both stay in the assignee's My Work until closed.

## The queue page

- Default listing is open claims, **oldest first** — the customer who has
  waited longest is on top. Each row carries `age_days`; make it loud past
  a week.
- `meta.counts` (per status), `meta.open_count` and
  `meta.avg_days_to_decision` (logged → approved/rejected, last 90 days)
  are served with every listing — render them as the header stats, do not
  compute your own.
- Deep link contract: `/admin/claims?claim={id}` — notifications link here.
- `meta.claims_available: false` means the migration has not run; render
  the same "not available yet" state as the to-do list.

## My Work

`GET /admin/my-work` gains `data.claim_tasks` and `meta.counts.claim_tasks`.
Same row contract as `todo_tasks`/`finance_tasks`: `editable: true`,
`status_options` travel with the item, `action_url` is
`/admin/my-work?claim={id}` (the claim is worked in place). Extra fields:
`description` (the complaint, read-only), `outcome_note` (the assignee's
half — editable), `customer`, `claim_type`, and `queue_url` (null when the
viewer cannot open the queue page — render no link).

Priority: `urgent` once the claim is 7 days old, `high` while `new`, else
`medium`.

## Notifications

`claim_assigned` → assignee (deduped per day, links to My Work).
`claim_status_changed` → whoever logged the claim (links to the queue).
Both carry `related_type: "claim"`.

## Portal half (Session 120)

| Method & path | Auth | Notes |
|---|---|---|
| `GET /api/v1/auth/claims` | customer Bearer | this account's claims only (`customer_id`); each row carries `status_note` in plain words plus `outcome_note` |
| `POST /api/v1/auth/claims` | customer Bearer | `{ order_ref?, type?, description (min 20 chars), quantity? }` — an order_ref must be the customer's own order (matched by e-mail) or the request is a 422; throttled 10/hour |

Filing marks the claim `source: "portal"` (badged in the admin queue),
notifies every active `claims.manage` holder (`claim_filed`, deduped per
claim per admin), and drops a `claim_received` confirmation in the
customer's in-app inbox. Every later status change by staff sends the
customer a `claim_update` notification with plain-words copy and the
outcome note; both link to `/account/claims`. Staff-logged claims carry no
`customer_id` and notify no portal account. `meta.claims_available: false`
until migration #67 runs.

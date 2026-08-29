# Finance snapshot board — who may open it, and where a tagged task goes

Backend change, two halves. Both were asked for by the business directly.

1. **The board is now closed to everyone but `finance` and `super_admin`.**
   It must also be hidden from the nav for everyone else — that half is yours.
2. **Opening a tagged task must land on the task, not on the whole board.**
   The API now hands you the links to do that.

Nothing else in the finance section changed. Invoice reconciliation,
profitability, liquidity, EC Invoices and the Sales & Order board keep exactly
the audience they had.

---

## 1. The new permission: `finance.snapshot`

```
'finance.snapshot' => ['super_admin', 'finance']
```

It is a **new key**, not a narrowing of `finance.view`. That matters: `admin`
and `order_manager` still hold `finance.view` and still need every other
finance page. Only the snapshot board moved.

| Role | Snapshot board | Rest of the finance section |
|---|---|---|
| `super_admin` | ✅ read + write | ✅ |
| `finance` | ✅ read + write | ✅ |
| `admin` | ❌ 403 | ✅ unchanged |
| `order_manager` | ❌ 403 (**previously could read**) | ✅ unchanged |
| everyone else | ❌ 403 | unchanged |

All nine routes are behind it, read and write alike — `GET /admin/finance-snapshot`
and every `POST`/`PUT`/`DELETE` under `/admin/finance-snapshot/*`.

### What you need to do

**Gate the nav item and the route on `finance.snapshot` being present in the
`permissions` array the auth payload already returns** — not on a hardcoded
role list.

```js
// right
if (permissions.includes('finance.snapshot')) { /* show Finance Snapshot */ }

// wrong — drifts the moment a grant changes server-side
if (['super_admin', 'finance'].includes(user.role)) { ... }
```

`GET /admin/me` and both login paths return `permissions` from the same map the
API enforces, so a grant made server-side reaches the UI on the user's next
login and a page can never be offered for a call that will 403. Session 84
settled this for the operations board after the panel and the API disagreed
about `orders.view`; same rule here.

**Please check whether the board is currently gated on a role list.** The
business's report was that a `super_admin` could not see the page. `super_admin`
has held every finance permission on the API the whole time, so if the page was
missing for him, the panel was gating on something other than `permissions` —
and that is the actual bug behind the complaint.

---

## 2. A tagged task opens the task, not the board

`GET /admin/my-work` → `data.finance_tasks[]` gains two fields and changes one.

```jsonc
{
  "type": "finance_task",
  "id": 41,
  "title": "RC-77 — Muscali Tyres",
  "subtitle": "Pending Receipts · 900.00",
  "priority": "high",
  "due_at": null,

  // CHANGED — was "/admin/finance-snapshot?item=41"
  "action_url": "/admin/my-work?finance_item=41",

  "status": "Pending",
  "editable": true,

  // NEW — render the status select from this, do not hold your own copy
  "status_options": [
    { "value": "Pending",      "label": "Pending" },
    { "value": "Sent",         "label": "Sent" },
    { "value": "In Progress",  "label": "In Progress" },
    { "value": "Under Review", "label": "Under Review" },
    { "value": "Approved",     "label": "Approved" },
    { "value": "Completed",    "label": "Completed" },
    { "value": "Cancelled",    "label": "Cancelled" }
  ],

  // NEW — null for anyone who may not open the board. Render no link when null.
  "board_url": null
}
```

### Why `action_url` moved

Most assignees are **not** finance. An order manager tagged to chase a payment
can no longer open the board at all, so the old link was a call to action that
403s. She answers from My Work instead — which she could always do; the
authorization for `PATCH /admin/my-work/finance-items/{id}` is *being the
assignee*, and it never required a finance permission.

For finance, who can open the board, `board_url` is populated and you can offer
it as a secondary "open in the board" link. `action_url` still points at the
task for them too — the record they were tagged on is what they were asked
about, not the six-category pipeline around it.

### What you need to do

- **Honour `?finance_item={id}` on `/admin/my-work`** — scroll to that row,
  highlight it, and open its status control. Same contract as `?todo=` on the
  to-do list and `?line=` on EC Invoices.
- **Render the status select from `status_options`** rather than a local
  constant. Finance tasks previously had none, so if you were holding your own
  list, drop it.
- **Render the board link only when `board_url` is non-null.**

Unchanged: `PATCH /admin/my-work/finance-items/{id}` with
`{ status, comment? }`. Still open to the assignee with no finance permission.
A bystander still gets 403.

---

## What is deliberately not changed

- **Tagging still notifies once per batch**, pointing at `/admin/my-work`, with
  the itemised report in the daily digest. Finance tags in batches; one
  notification per record would be noise.
- **The "your task moved" notification back to the creator still points at the
  board** (`/admin/finance-snapshot?item=`). Only someone holding
  `finance.snapshot` can create a record, so that link is always openable by
  the person receiving it.
- **`finance.view` and `finance.manage` are untouched.** If a page other than
  the snapshot board disappears for the order manager or for `admin`, that is a
  regression — tell us, do not work around it.

---

## Tests behind this

`FinanceSnapshotTest` — every role walked against read and write (with `admin`
and `order_manager` asserted individually, since both would have been let in
had this ridden on `finance.view`), a proof that the wider finance permissions
were left alone, and an end-to-end pass where an order manager is tagged, gets
the My Work deep link and a null `board_url`, and updates the status holding no
finance permission at all. Full suite: 761 passed, 0 failed.

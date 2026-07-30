# Frontend note — marketing contacts in multiple markets (move / add / remove)

**Session 72. Backend complete and tested. Ships one migration (#26).**

## The report this answers

> "If my email is already in one folder (e.g. TEST), I can't add it to another
> folder (e.g. Germany). I have to remove it from the first folder before moving
> it to the new one. If it's an easy fix, that would be great."

Both halves are now real:

- **Add** — a contact can belong to **several markets at once**. TEST *and*
  Germany, no duplicate row, no removal first.
- **Move** — relocate a contact from one market to another, in bulk.
- **Remove** — take a contact out of one market without deleting it.

Previously `marketing_contacts.market` was a single string column and `email` is
`UNIQUE`, so a contact was in exactly one market and a second row was impossible
— hence "email already exists" with no way forward.

---

## Data model change (what you'll see in payloads)

Membership moved to its own table. On every contact object:

| Field | Meaning |
|-------|---------|
| `market` | **Primary** market — a single string, exactly as before. Unchanged contract. |
| `markets` | **New.** Array of every market the contact belongs to, oldest membership first. |

```jsonc
{ "id": 12, "email": "her@example.com", "market": "test", "markets": ["test", "germany"] }
```

`market` is kept in sync automatically and always holds one of the contact's real
memberships. It never shifts just because another market was added alongside it —
so a list column bound to `market` keeps showing what it always did. **Prefer
`markets` in new UI**; treat `market` as "the one to show when you only have room
for one".

Counting note: `GET /marketing-contacts/markets` and `/stats` count **distinct
contacts per market**, so a contact in two markets is counted once under each.
Those per-market totals can legitimately sum to more than the overall contact
count. Don't render the sum as a total.

---

## 1. `POST /api/v1/admin/marketing-contacts/add-to-market` (new)

**This is the endpoint that answers her actual request.** Adds a market, keeps
every market the contact is already in.

```jsonc
{
  "to_market": "Germany",        // required; slugified server-side → "germany"

  // pick one or more — they're OR'd:
  "contact_ids": [12, 45],       // checkbox selection
  "emails": ["a@b.com"],         // paste-a-list, no id lookup needed
  "from_market": "TEST"          // everyone currently in TEST
}
```

`200 OK`:

```jsonc
{
  "data": {
    "to_market": "germany",
    "added": 1,                     // contacts that gained the market
    "already_in_place": 0,          // were already in it — safe to call twice
    "not_found": [],                // only for the `emails` selector
    "contacts": [ /* updated contact objects */ ]
  },
  "message": "1 contact added to \"germany\"."
}
```

## 2. `POST /api/v1/admin/marketing-contacts/move-market` (new)

Relocates instead of accumulating. Same three selectors, same response shape
(`moved` instead of `added`, plus `from_market`).

- **with `from_market`** — leaves that market for `to_market`, **keeping any
  other markets** the contact is in.
- **without `from_market`** — `to_market` replaces the contact's markets outright.

`from_market` + `to_market` with no ids/emails is effectively a **market rename**.

## 3. `POST /api/v1/admin/marketing-contacts/remove-from-market` (new)

Takes contacts out of one market **without deleting them**.

```jsonc
{
  "market": "test",              // required — the market to leave
  "contact_ids": [12],           // optional
  "emails": ["a@b.com"]          // optional; omit both to clear the whole market
}
```

`200 OK` → `{ data: { market, removed, skipped_last_market[], not_found[], contacts[] }, message }`

⚠️ **A contact always keeps at least one market.** Removing its last one would
leave it invisible to every market-scoped list and campaign filter with no way to
find it again, so that removal is refused and the contact's email is returned in
`skipped_last_market`. Surface those to the user — the fix is to move them to
another market, or delete the contact outright. `removed` counts only real
removals, so `removed: 0` with a non-empty `skipped_last_market` is a
"nothing happened, here's why" response, not a failure.

## 4. Duplicate add now tells you where the contact already is

`POST /admin/marketing-contacts` with an existing email **still returns 422 with
`errors.email` populated** — nothing you have today breaks. It now also carries:

```jsonc
{
  "message": "This contact is already on the marketing list, in \"test\". Add it to \"germany\" as well, or move it there.",
  "errors":  { "email": ["This email is already on the marketing list."] },
  "code":    "contact_exists",
  "data": {
    "existing_contact": { "id": 12, "market": "test", "markets": ["test"], /* … */ },
    "existing_markets": ["test"],
    "target_market":    "germany",
    "can_add_market":   true,      // false when already in the requested market
    "can_move":         true
  }
}
```

## 5. Create a contact in several markets at once

`POST /admin/marketing-contacts` now accepts **either** `market` (string, as
before) **or** `markets` (array, max 20). One of the two is required.

## 6. `PATCH /admin/marketing-contacts/{id}`

- `market` (string) — **replaces** the contact's markets with that one (the
  pre-existing "move" meaning of this field, unchanged).
- `markets` (array) — **new**, replaces the whole membership set.

## 7. Campaigns can target several markets in one send

`POST /admin/bulk-emails` and `GET /admin/bulk-emails/recipient-count` accept
`filters.markets` (array, max 20) alongside the existing `filters.market`
(string). A contact belonging to two of the targeted markets is selected
**exactly once** — nobody can be emailed twice by one campaign.

Also fixed as part of this: the campaign filter now matches **any** of a
contact's markets, not just the primary one. Without that, a contact added to
`germany` alongside `test` would have been silently left out of the germany
campaign — i.e. the new feature would have looked like it worked in the UI while
quietly not sending.

---

## What the frontend needs to build

1. **Add-contact form** — on `422` with `code: "contact_exists"`, replace the
   bare field error with the two real choices:
   *"her@example.com is already in **test**. [Add to germany too] [Move to germany]"* →
   `add-to-market` or `move-market` with
   `{ contact_ids: [data.existing_contact.id], to_market: data.target_market }`.
   Only offer them when `can_add_market` / `can_move` are `true`; otherwise the
   plain field error is correct.

2. **Show all of a contact's markets** — render `markets` as chips/tags rather
   than the single `market` string, with an ✕ per chip calling
   `remove-from-market`, and a "+" opening the market picker to call
   `add-to-market`. This is the main visible change from this session.

3. **Multi-select toolbar on the contact list** — checkboxes + three bulk
   actions: *Add to market…*, *Move to market…*, *Remove from market…*, all
   taking `contact_ids`. The picker is fed by
   `GET /marketing-contacts/markets`; **it must also accept a free-typed new
   name** — markets are auto-discovered from data, so "Germany" won't appear in
   the list until a contact is already in it, and a picker limited to existing
   options can never create the first one.

4. **Market management view** — per market row: *Move all contacts to…*
   (`move-market` with `from_market`), *Add all contacts to…* (`add-to-market`
   with `from_market`), *Clear this market* (`remove-from-market` with no
   ids/emails). This is the tidy-up path for the leftover `test` market. An
   emptied market disappears from `/markets` on its own, since that list is
   derived from live data — there is no delete-market endpoint and none is needed.

5. **Campaign audience picker** — allow selecting several markets and send
   `filters.markets: [...]`. Keep using `recipient-count` to preview the
   audience; it now takes `markets` too.

6. Refresh `/markets` and `/stats` after any market operation, and don't present
   the per-market counts as summing to the total (see the counting note above).

---

## Two behaviour changes worth knowing

- **A CSV re-import no longer relocates existing contacts.** It previously
  overwrote an existing contact's `market` with the market being imported, so
  importing a Germany list containing an existing Asia contact silently moved
  them out of Asia. It now **adds** germany alongside asia and leaves the primary
  market alone. Relocation is `move-market`'s job, explicitly. Worth updating the
  import confirmation copy if it mentions the old behaviour.

- **Any contact written with only a `market` value still lands in that market
  correctly.** The model registers the membership on save, so nothing can produce
  a contact that claims a market in its own row but is missing from that market's
  list.

## Deploy order

The backend degrades safely if this code reaches production before the migration
runs: everything falls back to the old single-column behaviour, `markets` comes
back as a one-element array, and the contact list/markets/stats/campaign
endpoints all keep returning 200. Covered by
`test_contacts_still_work_when_the_membership_table_is_missing`. Multi-market
membership simply doesn't take effect until migration #26 is applied — so no
frontend deploy needs to wait on it, but the ✕/+ chip actions won't do anything
useful until it's run.

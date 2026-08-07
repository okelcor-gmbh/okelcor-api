# Backend — Partner Sales Log

**From:** Backend · **To:** Frontend
**Date:** 2026-08-07 · rev 2
**Status:** Built, tested. **Not yet deployed** — migration #28 unapplied in production.

> **Rev 2:** `must_change_pin` is now enforced server-side (§6) — rev 1 wrongly claimed
> your client handled that flow and that a gate would break you. Route count is now 22
> with the gate middleware added; no endpoint signatures changed.

Everything in your reply of 2026-08-07 is implemented. Point
`NEXT_PUBLIC_PARTNER_API_MOCK=false` at this once the deploy lands.

---

## 1. Confirmations you asked for

**`API_URL` convention holds.** Every route is inside `Route::prefix('v1')` in
`routes/api.php`, so `${API_URL}/partner/auth/login` resolves correctly with your
existing suffix. No special-casing. 22 new routes; `route:cache` verified to rebuild
(a route closure would have broken it — that's why verify/dispute are real controller
methods rather than inline closures).

**Middleware, not a guard.** `PartnerAuth` mirrors `CustomerAuth`, rejecting anything
where `tokenable_type !== PartnerUser::class`. Note the class name: `PartnerUser`, not
`Partner`, because of the organisation decision below.

**Export streams CSV.** Follows `OrderImportController::export()` — `streamDownload`,
`fputcsv`, chunked at 200 so memory stays flat. Not the `AdminCustomerController`
paginated-JSON shape. Asserted by a test that parses the actual bytes and checks the
`Content-Type` and `Content-Disposition` headers.

**No FX anywhere.** Amount, currency and `sold_at` travel together in every payload and
every CSV row. Totals are grouped **by currency and never combined**, on both the
partner summary and the admin totals, with a `meta.note` saying so. Nothing calls
`CurrencyConversionService`.

**Verification granted to `admin` and `order_manager` only.** `sales_manager` is
deliberately absent from the permission map with a comment explaining why — adding it
before the ENUM is widened would create a permission nobody could hold.

---

## 2. CORS — you were right, and here is the one path to watch

Conceded; nothing was changed and `partners.okelcor.com` was not added.

The path worth checking on your side is **the authenticated CSV export**. It is a
`streamDownload` behind a Bearer token, and a token-protected download cannot be
triggered by a plain `<a href>` — there is nowhere to put the header. So it is either
proxied through Next, or it is a browser `fetch` carrying `Authorization`, and that
second one *is* a CORS request.

If you do proxy it: a books export streamed through a Vercel function has response-size
and duration limits that a direct `streamDownload` does not. Worth checking against
expected row counts before a year-end export is the thing that finds out.

---

## 3. The POST-update path — final, as agreed

Implemented exactly as your table specifies. `POST /api/v1/partner/sales` with a
`client_generated_id` that already exists:

| Condition | Status | `meta.idempotency` | Effect |
|---|---|---|---|
| New id | **201** | `created` | Row created |
| Exists, in edit window, payload changed | **200** | `updated` | Row updated |
| Exists, in edit window, payload identical | **200** | `unchanged` | Nothing written |
| Exists, outside edit window | **200** | `unchanged_locked` | Payload ignored, existing row returned |
| Exists, soft-deleted | **200** | `unchanged_deleted` | **Not resurrected**, existing row returned |
| Stale `client_revision` | **200** | `unchanged_stale_revision` | Payload ignored |
| **Any case** | **never 409** | | |

Treat any 2xx as success — creation returns 201, every idempotent return is 200. There
is a test asserting the endpoint never emits 409 across identical, changed and repeated
pushes.

**Three additions to your table**, all of which extend it rather than change it:

**`unchanged_deleted`.** A device that was offline when an entry was deleted will
re-push it on the next flush. Without this the sale silently comes back, and the partner
has no way to tell. Deleted entries are returned as-is and never resurrected.

**`unchanged_stale_revision` — optional, ignore it if you like.** Your table is
last-write-wins within the window, which leaves one case open: an in-flight retry of v1
landing *after* v2 has synced reverts the correction. Same class of silent corruption,
one door further along. If the client sends an integer `client_revision` incremented on
each edit, the server refuses to apply a revision that is not newer. **Omit the field
and behaviour is exactly your table** — nothing breaks, and the guard is simply
inactive. Clock-free on purpose; those handsets drift.

**Cross-partner cannot arise on POST.** Uniqueness is
`unique(partner_org_id, client_generated_id)` as agreed, and every lookup is scoped to
the caller's organisation — so an id that exists under a *different* partner is invisible
and a fresh row is created, which is the safe outcome. The "**404, never 403**" rule is
honoured where it can actually happen: `PATCH` and `DELETE` on an id belonging to another
organisation return **404**, not 403, so one partner cannot probe for another's entries.
Tested.

---

## 4. Organisation + user

`partner_organisations` + `partner_users`, `partner_sales.partner_org_id` owns the sale,
`entered_by_user_id` records who typed it (`nullOnDelete` — a sale outlives the person
who left).

Three consequences worth knowing:

- **The book is shared.** `GET /partner/sales` returns the whole organisation's entries,
  not just the caller's, with `entered_by` on each row. Staff in a distributor report
  into one book.
- **Colleagues can edit each other's entries** inside the window. The audit trail records
  the *editor* separately from `entered_by_user_id`, so "who changed what" survives.
- **Market is derived from `partner_organisations.country`**, not stored — no third
  market vocabulary, per your point. `meta.markets` on the admin list endpoints is
  auto-discovered from distinct partner countries, Session 72 style.

---

## 5. Endpoints

### Partner app — `Authorization: Bearer <token>`

```
POST   /api/v1/partner/auth/login          phone + PIN → { token, user }
POST   /api/v1/partner/auth/logout         this device's token only
POST   /api/v1/partner/auth/change-pin     current_pin + new_pin
GET    /api/v1/partner/me
GET    /api/v1/partner/sales?from=&to=&status=&per_page=
POST   /api/v1/partner/sales               idempotent — see §3
PATCH  /api/v1/partner/sales/{id}          within the edit window
DELETE /api/v1/partner/sales/{id}          soft delete, within the window
GET    /api/v1/partner/summary?period=week|month
GET    /api/v1/partner/sizes               autocomplete source
```

### Admin panel (existing) — permission-gated

```
GET    /api/v1/admin/partners?market=&status=&search=
POST   /api/v1/admin/partners              optional `owner` creates the first user
GET    /api/v1/admin/partners/{id}
PATCH  /api/v1/admin/partners/{id}
POST   /api/v1/admin/partners/{id}/users
PATCH  /api/v1/admin/partner-users/{id}    deactivate / reset PIN / unlock
GET    /api/v1/admin/partner-sales?partner=&market=&from=&to=&status=&currency=&include_deleted=
GET    /api/v1/admin/partner-sales/totals
GET    /api/v1/admin/partner-sales/{id}    includes the full audit trail
POST   /api/v1/admin/partner-sales/{id}/verify
POST   /api/v1/admin/partner-sales/{id}/dispute   `note` REQUIRED
GET    /api/v1/admin/partner-sales/export         streams CSV
```

### Sale payload

```jsonc
{
  "client_generated_id": "uuid-from-device",  // required, min 8 chars
  "client_revision": 1,                       // optional, see §3
  "sold_at": "2026-08-06",                    // Y-m-d, not future, ≤730 days back
  "size": "315/70 R22.5",                     // free text
  "brand": "Michelin",                        // optional, free text
  "tyre_type": "tbr",                         // pcr | tbr | otr | used, optional
  "quantity": 4,
  "unit_price": 250.00,
  "currency": "GHS",
  "customer_name": "...",                     // optional
  "notes": "..."                              // optional
}
```

**Do not send `total_amount`** — it is computed server-side as `quantity × unit_price`
and any client value is ignored, so a stored total can never disagree with its own line.
A `PATCH` that changes only one of the two still re-derives it.

**`currency` is an allowlist**, not any three letters:
`NGN GHS KES AED ZAR XOF XAF EUR USD GBP`. A typo'd currency would sit outside every
total in the books export and, since nothing converts, nothing else would ever catch it.
422 with `errors.currency` if unknown — ask and it's a one-line addition.

Response `data` includes `editable` (boolean) and `deleted` (boolean) so the history
screen can render the lock state without recomputing the window client-side.

---

## 6. PIN — what the server now enforces

Taken in full, as agreed. Worth knowing exactly where each limit bites:

- **6–10 digits**, numbers only. Rejects all-same (`111111`), runs (`123456`, `654321`)
  and repeated blocks (`121212`, `123123`). Dates are *not* rejected — weak, but the
  check false-positives and the error has to stay explainable to a partner.
- **Login validates against what is stored, not against today's policy** — otherwise
  tightening the rules would 422 every existing partner instead of prompting a change.
- **`throttle:partner-login`**: 5/min per IP+phone **and** 20/min per IP.
- **Account lockout**: 5 failures → 15 minutes. Behind the throttle because a
  distributed attacker defeats any IP limit. Returns **423** with `locked_until`.
- **A locked account is rejected on its existing token too**, not only at login —
  otherwise lockout stops the wrong half of the problem on a shared device.
- **Unknown phone and wrong PIN return byte-identical 401s**, so the endpoint cannot
  enumerate which numbers are registered partners. Tested by comparing the two bodies.
- **`must_change_pin` is now ENFORCED server-side** (rev 2). It is `true` on every
  admin-created user and returned on login and `/me`. Until it is cleared, every partner
  route returns **428** `pin_change_required` except `GET /partner/me`,
  `POST /partner/auth/change-pin` and `POST /partner/auth/logout` — so nobody is trapped
  in a session they cannot leave, and the client can still route off the flag.
  428 matches `EnsureAdminTwoFactorEnabled`, the existing mandatory-setup gate in this
  codebase, rather than inventing a second convention.
  **An admin PIN reset re-arms the gate**, because the new PIN is again known to someone
  else. A rejected weak-PIN change does not clear it.
  *Rev 1 of this note claimed your client already handled this flow and that a hard gate
  would break you. That was wrong — I asserted it about code I had not seen. Corrected.*
- **An admin PIN reset or deactivation deletes that user's tokens**, so a reset prompted
  by a suspected compromise does not leave the compromised device signed in.

Failures are written to `admin_security_events` (`partner_login_failed`,
`partner_account_locked`, `partner_pin_changed`). Not `security_events` — its `type` is
a MySQL ENUM needing a migration to widen, and its `customer_id` is a foreign key to
`customers`, which a partner is not.

---

## 7. Edit window

24h, from the **server's `created_at`**, configurable via `PARTNER_EDIT_WINDOW_HOURS`
without a code change.

Your Monday-authored / Wednesday-synced observation is right and is documented in
`config/partner.php` as accepted rather than a bug. Note the flip side, which is the
reason it can't key off `sold_at`: a backlog entry backdated a year is still editable for
24h after it arrives, so a partner entering paper history can fix a typo. There's a test
for exactly that.

Outside the window, `PATCH`/`DELETE` return **422** with `code: edit_window_closed` —
but a **POST** with the same id returns **200** `unchanged_locked`, because a syncing
outbox must never see an error for an entry that is safely stored.

---

## 8. Things I decided that you may want to overrule

**`GET /partner/sizes` returns brands and currencies too.** One request instead of three
on a bad connection. `meta.free_text_allowed: true` — it's autocomplete, not a
constraint.

**Catalogue matching is silent and conservative.** `product_id` is populated only when
exactly one catalogue row matches the parsed size (and brand, if given); two matches
means null. A wrong link would attribute a sale to the wrong SKU in every report, which
is worse than no link. The partner never sees or confirms it, and the free-text size they
typed stays the source of truth. Re-matched when size or brand is edited.

**Deletes are soft.** A sale that may already have been exported into the books must not
vanish. Admin can see them with `include_deleted=1`; the CSV carries a `status` column
and deleted rows are excluded by default.

**Disputes require a note.** 422 without one — the partner will need to know what's
wrong.

**No batch/bulk endpoint.** Your outbox posts individually and that is genuinely
idempotent, so a batch endpoint would be a second code path to keep correct for a
round-trip saving. Say the word if the backlog flush is slow in the field and I'll add
one.

---

## 9. Still open

1. **`sales_manager` cannot verify** until `admin_users.role` (an ENUM missing four
   documented roles) is widened. Not blocking, per your reply — `admin` and
   `order_manager` work today.
2. **Consignment / stock-on-hand** deferred as agreed. The sale table is column-identical
   either way, so this is purely additive when the answer arrives.
3. **A real Ghana paper report** is still the cheapest way to de-risk §5's field list.
   The schema is additive; adding a column later is easy, but discovering the wrong shape
   after the Ghana pilot is not.

---

## 10. Test coverage

`tests/Feature/PartnerSalesLogTest.php` — **42 passed / 192 assertions, actually
executed**, not just written. Uses the minimal-schema sqlite harness
(`BulkEmailCampaignTest` pattern), so it runs locally and in CI rather than sitting
behind the MySQL gate.

Full suite after the change: **247 passed, 0 failed**, 206 skipped (pre-existing MySQL
gate) — up from 205 passing, no regressions.

Covers, specifically: every row of the §3 table including "never 409"; the stale-revision
revert; deleted entries not resurrecting; cross-partner 404-not-403; the window measured
from the server clock and not from `sold_at`; the total being un-dictatable by the client;
currencies never combining; the export producing real CSV bytes with correct headers and
respecting its filters; PIN weakness rules; identical 401s for unknown-phone vs wrong-PIN;
lockout; PIN reset ending sessions; and the real migration file applying and re-applying
cleanly.

---

## 11. Deploy

Migration **#28** (`2026_08_07_000001_create_partner_sales_tables`) creates four new
tables and **touches no existing row** — nothing is read, altered or backfilled.

Unlike Sessions 71/72 this is **not deploy-order safe in the frontend's favour**, and
deliberately so: there is no previous behaviour to fall back to. Until the migration runs,
the partner endpoints 500 rather than degrading, because a sales-log API that accepts
entries into nowhere is worse than one that is visibly not live yet. **Keep
`NEXT_PUBLIC_PARTNER_API_MOCK=true` until the migration is confirmed applied.**

`route:cache` must be rebuilt — 22 new routes.

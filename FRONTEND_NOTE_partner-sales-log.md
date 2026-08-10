# Backend — Partner Sales Log

**From:** Backend · **To:** Frontend
**Date:** 2026-08-07 · rev 3
**Status: ✅ LIVE.** Migration #28 applied to production 2026-08-07 (`d2f1896`).
**You can flip `NEXT_PUBLIC_PARTNER_API_MOCK=false`** — this is the explicit
confirmation you asked for.

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
POST   /api/v1/partner/auth/login          phone + PIN — response shape below
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

#### Login response — corrected

An earlier revision of this note documented login as returning `{ token, user }`
at the top level. **It does not, and never did.** Frontend built against that,
got a 502 on the first genuinely successful sign-in, and found the real shape
only by reading `formatUser()` in the source. The implementation is:

```jsonc
{
  "data": {
    "token": "…",
    "user": {
      "id": 12,
      "name": "Kwame Mensah",
      "phone": "233241234567",
      "role": "owner",
      "must_change_pin": false,
      "last_login_at": "2026-08-10T09:14:22+00:00",
      "organisation": {
        "id": 3,
        "name": "Accra Tyre Distributors",
        "country": "Ghana",
        "country_code": "GH",
        "market": "ghana",
        "default_currency": "GHS"
      }
    }
  },
  "message": "Signed in."
}
```

Transcribed from `PartnerAuthController::formatUser()` — those are all the
fields, and there are no others. `organisation` is `null` only if the record is
missing, which an admin-created partner user cannot be. `GET /partner/me`
returns the same `user` object at `data`, with no `token`.

So: `data.token`, `data.user`, and — the one that bit — **`data.user.organisation.default_currency`**,
nested a level deeper than the old note implied. That is the value the entry
form defaults a partner's currency from.

The response is not changing; the note was wrong. Every other endpoint in this
document follows the same `{ data, meta, message }` envelope, which is the
project-wide convention — read `data` first everywhere.

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
PATCH  /api/v1/admin/partner-sales/{id}           `reason` REQUIRED — see §5a
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

## 5a. Admin correction — `PATCH /admin/partner-sales/{id}`

Built as asked. `dispute` records that a row is wrong; this is what makes it
right, so a known-bad figure no longer has "flagged and uncorrectable" as its
only end state.

```jsonc
PATCH /api/v1/admin/partner-sales/{id}
{
  "quantity": 6,
  "unit_price": 300,        // any subset of: sold_at, size, brand, tyre_type,
  "reason": "Paper report…" // quantity, unit_price, currency, customer_name, notes
}
```

- **`reason` is required**, min 5 characters, same as `dispute`'s `note`.
- **No edit window.** The window protects the partner's own book from drift; an
  admin correcting a known-wrong figure is the escalation the window exists to
  produce.
- **`total_amount` is always re-derived** from whatever quantity and unit price
  now stand — including when you send only one of the two. Never send a total.
- **Same validation bounds as the partner's own create/update.** An admin
  correction is not a way around a rule the partner is held to: an unlisted
  currency or a future `sold_at` is a 422 here too.
- **Written to the audit trail** as `admin_corrected`, with the reason and a
  per-field `{from, to}`. `GET /admin/partner-sales/{id}` returns it unchanged.
- **Permission `partner_sales.correct`** — currently `super_admin`, `admin`,
  `order_manager`, the same list as `verify`, but its own key so it can be
  narrowed later without a code change.

**Three behaviours to build against:**

1. **Correcting the figures clears a prior verification.** If the sale was
   `verified` and anything substantive moves, it returns to `submitted` with
   `verified_by`/`verified_at` nulled, and the reset appears in the trail as a
   `status` change. Reason: "verified by X" must never sit in the CSV next to a
   figure X never saw. **Changing only `notes` or `customer_name` does not
   clear it** — those are not what was signed off. So the flow for a disputed
   row is correct → verify, two deliberate acts. Please show the status change
   in the UI rather than letting a row silently drop out of "verified".
2. **A no-op correction is a 200, not an error**, with `meta.result:
   "unchanged"` and no audit row written. Sending `250.0` against a stored
   `250.00` counts as unchanged — values are compared numerically, so a resave
   with no edits will not litter the trail.
3. **A soft-deleted entry returns 422 `sale_deleted`.** It is already out of
   the books and out of the totals, so there is nothing to correct. If a
   removed entry ever needs to come back, say so and we will add a restore —
   there is deliberately none today.

`meta.changed` lists the field names that actually moved, if that is useful for
a confirmation toast.

**Not covered, flag it if you need it:** the partner sees the corrected numbers
in their own list, but has no view of *why* they changed — the audit trail is
admin-only and `review_note` still holds the verify/dispute note, which
overwriting would destroy. If partners should see correction reasons, that is a
separate decision about what to expose, not a field we should quietly reuse.

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

**Recommendation: raise it to 72 (`PARTNER_EDIT_WINDOW_HOURS=72`).** Config
only, no deploy of code needed, and reversible.

Your Saturday-backlog case is the right test of the number, and 24h fails it —
but only slightly, and the fix is not "as long as possible". The window exists
so the partner's own book stops drifting; every hour it is open is an hour in
which a figure Okelcor may already have exported can still change underneath.
72h covers a weekend of catch-up entry, which is the realistic worst case now
that `PATCH /admin/partner-sales/{id}` exists as the escalation path for
anything older. Before that endpoint, a longer window was the *only* way to fix
a mistake, which is what made 24h feel tight.

So: 72h, and tell partners plainly that corrections after that go through
Okelcor — which is now true rather than a polite way of saying "it can't be
fixed".

Measured from the **server's `created_at`**, configurable via
`PARTNER_EDIT_WINDOW_HOURS` without a code change.

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

**Applied 2026-08-07.** Migration #28 ran in 176ms (batch 98) after a database backup
and a `migrate --pretend` review; `route:cache` rebuilt for the 22 new routes. Four new
tables, no existing row touched.

Verified live, from outside:

```
POST /api/v1/partner/auth/login   → 401 {"code":"invalid_credentials"}   x-ratelimit-limit: 5
GET  /api/v1/partner/{me,sizes,sales,summary}     → 401
GET  /api/v1/admin/{partners,partner-sales}       → 401
```

401 rather than 404 on every one of them means routing and the route cache are correct.

**One gotcha if you smoke-test this host yourself:** a bare `POST` with no body and no
`Content-Type` comes back as a **403 HTML page from LiteSpeed/Cloudflare**, not from
Laravel — the WAF rejects it before PHP is reached. It looks alarming and means nothing.
Send `-H "Content-Type: application/json"` and a real body.

The pre-deploy caveat about the API not degrading gracefully is now moot; that window is
closed.

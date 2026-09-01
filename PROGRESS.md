# Okelcor API — Build Progress

Last updated: 2026-09-01 | Branch: `main` | **Production is at `18bad79` — sessions 86–109 (weekly liquidity with closed weeks + moves + CSV downloads, EC exports, the tagged-task fix, and the to-do list that could finally be opened), migrations #1–61 applied, every deploy verified from outside the same day**

---

## 👥 A department covers for itself (Session 110)

> **Deploy status:** ✅ **deployed 2026-09-01** — see the deploy note at the
> end of this section.

Reported straight after Session 109 shipped: "finance still cannot edit, and
yet it says only Joseph and Solomon can edit it". **Nothing was broken.**
Production has **two** finance accounts — 25 Joseph Rwabu, who raised all 89
to-dos, and **29 Daniel Tuke**, who raised none of them and was tagged on none
of them. The rule was creator-or-assignee-or-super_admin, so Daniel was
correctly refused, and correctly shown a message naming the two people who
qualified. Both halves were working exactly as written; the rule was simply
narrower than how a two-person finance team actually operates.

| Change | Status | Notes |
|---|---|---|
| A department may move its own to-dos | ✅ | `isParticipant()` gains "shares the raising department". Chosen over granting `finance` blanket rights, which would have let finance edit Marketing's work, and over dropping the restriction, which leaves no record of who may act. It generalises — Yelzaveta and Edinah cover each other, Marketing covers Marketing — and it runs on the department stamp shipped hours earlier in #61. |
| Deleting widens the same way, minus one | ✅ | New `mayBeDeletedBy()`: creator, department, or super_admin — **the assignee is still deliberately absent.** They mark an item done; they do not erase that it was asked. Practical payoff: Daniel can now help clear the ~89 duplicate "Share Invoice copy" rows instead of leaving one person to do it alone. |
| **Two nulls must never match** | ✅ | The trap in comparing departments. An unstamped row resolves to null and a viewer with no department resolves to null, so a naive `===` would have opened every legacy row to everybody — the exact opposite of the bug being fixed. Both sides must resolve *and* agree. Pinned by `test_an_unstamped_todo_does_not_become_everybodys`. |
| The creator keeps their own history | ✅ | The to-do's department is frozen, the viewer's is current, so somebody who changes team loses the *department* route into their own old to-dos. They keep the *creator* one. Tested, because losing access to your own history would have been a quiet consequence of #61's freezing decision. |
| The refusal names the department | ✅ | "Only Finance, the person this to-do is tagged to, or whoever created it, can change it." Naming two colleagues was accurate and still unhelpful: the reader was finance himself and could not tell a design decision from a bug. |
| Backend tests (5 new) | ✅ | The colleague editing and deleting (asserting the **served flags** first — the panel hides its controls on those, so a false there is the lockout regardless of what the endpoints permit); another department still refused on both verbs plus the message text; the assignee still unable to delete; the null-department trap; and the creator surviving a team change. Full suite **794 passed**, up from 789. |

---

## 🏷️ Where a to-do came from (Session 109)

> **Deploy status:** ✅ **deployed 2026-09-01** as `18bad79` (backend) and
> `56d73cf` (frontend, Vercel deployment reported `success` for that exact
> SHA — checked, not assumed). `backup:okelcor` taken first (7.36 MB archive,
> 20.06 MB dump), `migrate --pretend --force` reviewed — **exactly two
> migrations pending, nothing else, no destructive statement** — then **#61
> applied (64.80ms)**. All five cache commands exit 0. **The backfill landed
> on every row**: 76 to-dos, `unstamped: 0`, all `finance` → **Finance**.
> From outside: `/api/v1/categories` 200, `/admin/todos` and
> `/admin/todos?department=Finance` both **401, not 404**, so the route cache
> is fresh.

The shared list mixes every department's requests together, so a row reads as
one more line from a name the assignee may not place. Asked for: say where a
to-do came from — "To-dos from Finance" — off the role of whoever raised it.

| Change | Status | Notes |
|---|---|---|
| `AdminPermissions::DEPARTMENTS` | 🔧 | Ten roles → eight departments, kept beside `ROLES` so the two are read together. Coarser than roles on purpose: `admin` + `super_admin` are both **Management**, `editor` + `content_manager` both **Content** — the reader wants the department, not the seniority. **Not `job_title`**: that is free text set per person ("Head of Finance", "Logistics"), so grouping by it puts almost everyone in a group of one. Roles are a controlled vocabulary, which is what makes them groupable. |
| A role with no department is a red test | 🔧 | `test_every_role_has_a_department` walks `ROLES` against the map. Same contract `OrderLog::ACTIONS` has with its ENUM — the eleventh role cannot ship quietly rendering a tidied role name. |
| **#61** `todos.created_by_role` | 🔧 | **Stamped, not derived**, and that is the whole design decision. Deriving the label from the creator's CURRENT role loses information twice: `created_by` is `nullOnDelete`, so deleting an admin account erases the origin of every to-do they ever raised; and people change role — there is an outstanding task in this very file to move the marketer `editor` → `marketing`, which would have silently relabelled all his history from Content to Marketing. The **role** is frozen; the **department label** is still derived from it at read time, so the wording can be corrected later without a data migration. |
| The filter takes the department, not the role | 🔧 | Several roles share one department, so filtering on the role would have returned half of "from Management" — pinned by its own test. An unrecognised department matches nothing rather than everything: a filter that silently stops filtering is the worse failure, because the reader believes they are looking at one department's work. |
| `meta.departments` with counts | 🔧 | Only departments that actually have to-dos are offered. A filter listing eight choices where six return nothing is a worse list than one offering the two that exist. Counted off the whole table, not the filtered query, so the numbers do not move as you filter. |
| Badge + "From:" filter (frontend) | 🔧 | A stable colour per department on both the board and My Work, so the same team reads the same on both screens. The opened My Work panel names it too — "Asked by Joseph Rwabu · Finance". The empty state owns the filter rather than blaming the list. |
| Backend tests (7 new) | ✅ | Every role has a department; the stamp reaching both the board and My Work; the filter and its counts; two roles sharing one department; the stamp surviving a role change **and the creator's account being deleted**; the backfill stamping existing rows and being re-runnable without overwriting a correct stamp; and the column arriving after the code. Full suite **789 passed**, up from 782. |

**The backfill is a clean single-department stamp on production**: all live
to-dos were raised by one person, user 25 (`finance`), so every existing row
becomes **Finance** and no row is left unstamped.

---

## ✅ The to-do nobody could open (Session 108)

> **Deploy status:** ✅ **deployed 2026-09-01** as `18bad79` (backend) and
> `56d73cf` (frontend, Vercel). **#60 applied (12.76ms)**; the running code
> reports `note col: EXISTS` and `supportsSource(): true`. No new routes, but
> `route:cache` was rebuilt anyway and the 401s prove it is fresh.
>
> **`composer install` was deliberately skipped** and that is not a shortcut:
> `git diff 29cf9a4..18bad79 --name-only` shows no `composer.json` or
> `composer.lock` change and no new class files — only edits to existing ones
> plus two migrations, which load by path, not by classmap. (`composer` is
> also not on this host's PATH, which is worth knowing before a release that
> genuinely does change dependencies.)

### Confirmed against production, not inferred

The diagnosis was checked against the live rows before the fix was believed:

```
creator Joseph may edit  : true
creator Joseph may delete: true
assignee Solomon may edit: true
types: created_by=integer userid=integer
```

**The API would have permitted every single action they reported being unable
to do.** No to-do row has a null `created_by` or `assigned_admin_id` (0 of 32),
`grep -i todo laravel.log` is empty — no 403s, no exceptions — and the strict
`===` in `isParticipant()` is safe because Laravel's base `Connector` sets
`PDO::ATTR_EMULATE_PREPARES => false`, so MySQL returns native integers on both
sides. That last one was worth checking rather than assuming: a string/int
mismatch there would have produced these exact symptoms while passing every
SQLite test, which is the trap this project keeps paying for.

**The live data corroborates the UX reading on its own.** All 32 to-dos were
created on 2026-09-01 between 07:12 and 09:26 by **one person** — user 25,
Joseph Rwabu (`finance`) — at roughly one every two minutes, almost all of them
titled some variant of "Share Invoice copy", retagged in turn to users 8, 28, 1
and 9. **Every one of them is still `open`; not a single to-do on production
has ever been moved to `in_progress` or `done`.** That is the signature of
someone creating a task, failing to find any way to open it, concluding it had
not saved, and making another one — and of assignees who never found the status
control either. Nothing was denied; nothing was findable.

**Left for a person, not fixed here:** to-dos **1–31** are near-duplicates of
one real request. They are live rows created by a real user and no rule can say
which one he means to keep, so they are not deleted. Once this deploys he can
delete them himself in seconds — which he could not do before, and which is the
whole point.

Two reports, one root cause. Someone created a to-do and found they could not
click it to edit or delete it. Someone else, tagged on a to-do, clicked it in
My Work and **landed on the To-Do list page** instead of on their task.

**The API was never the problem, and that was worth establishing before
changing anything.** `/admin/todos` has had full CRUD since Session 102,
`staff.self` is held by *every* role, `isParticipant()` correctly admits the
creator and the assignee, and the list already served per-row `you_may_edit` /
`you_may_delete`. All eight existing tests passed untouched. Nothing was being
denied — the panel simply never offered the controls, and the link pointed at
a list.

| Change | Status | Notes |
|---|---|---|
| **A to-do opens where it is worked** | 🔧 | `action_url` was `/admin/todos?todo=N` — the whole list page. It is now `/admin/my-work?todo=N`, with the shared list riding alongside as `list_url`. **This is the Session 107 fault in the sibling feature**: finance tasks were fixed then, to-dos were not, and the assignee's report is almost word for word the one that prompted 107. |
| My Work carries the whole to-do | 🔧 | `details`, `due_on`, `creator` and `assignee_note` are served as fields. They were baked into the `subtitle` string, so the row could *show* the brief but never edit it, and the assignee had to leave My Work to read what was asked. The row now expands in place — brief, who asked, when it is due — and opens already expanded when arrived at from a tagged-task link. |
| **#60** `todos.assignee_note` | 🔧 | The reply travels back. Deliberately **not** `details`: that column is whoever asked saying what they want, and a note that overwrote it would destroy the question while answering it. Two people talking, two fields. Whoever created the to-do is notified, so "the client asked for Thursday" reaches them without a message being written — the same contract as the finance note in Session 107. |
| The row is the affordance | 🔧 | The only way to edit was a **13px unlabelled grey pencil** at the end of a row that wraps on a narrow screen; delete was the trash beside it. The whole row is now clickable, and both controls are labelled buttons. |
| The editor scrolled into view | 🔧 | The add/edit form renders *above* the list, so opening it from a row halfway down the page put it off-screen — the click genuinely did nothing visible. This is most of what "you can't click a to-do to edit it" actually was. |
| A refusal now says who may | 🔧 | A row with no controls and no explanation is indistinguishable from a broken one, which is how this was reported. Non-participants see "Only {creator} or {assignee} can change this" instead of a bare row. |
| Backend tests (3 new, 1 rebuilt) | ✅ | The whole-task payload plus the note round-tripping back to the creator's notification; the note column arriving *after* the code (the status still lands — a to-do nobody can close is worse than one that cannot carry a reason); migration idempotency against the real file. The Session 102 test that asserted `action_url === '/admin/todos?todo=N'` was **rebuilt, not patched** — it pinned the exact behaviour being reported as broken. Full suite **782 passed**, up from 780. |

**Deliberately not changed:** the permissions. Creator-or-assignee to edit,
creator-only to delete, every role holding `staff.self` — all of it was right,
and all of it stays. The bug was that the product never said so.

**Found on the way, NOT fixed:** `PartnerSalesLogTest ::
test_summary_totals_are_grouped_by_currency_and_never_combined` fails on a
clean checkout (`Undefined array key "GHS"`). It is **pre-existing** — verified
by stashing this session's work and re-running — and it was passing at Session
107's 780, so something time-dependent broke it between then and 2026-09-01.
The date is the first of the month and the endpoint under test is
`?period=month`, which makes a month-boundary bug in the partner summary the
first place to look. Money-facing for partners; not investigated further.

---

## 🔎 The tagged-but-locked-out report (Session 107)

> **Deploy status:** **deployed 2026-08-31** as `d75d972` (backend) and
> `22c0dc4` (frontend, Vercel). No migration, no new routes; caches rebuilt.
> Verified against the real case, not a fixture: Victor's (admin 9) actual
> My Work payload on production now carries his tagged item 293 in full —
> `PENDING RECEIPTS`, −147.42, finance's note "Victor to share Invoice
> copy", the `?finance_item=` deep link, and `board_url: null`.

Finance tagged the team on snapshot tasks; the tagged admins reported seeing
the tag but having "no access to the task details or to update it". The logs
settled what actually happened: **the permission system did exactly what
Session 103 was told to do** — `victor@` (role `admin`) was 403'd on
`GET /admin/finance-snapshot` at 12:24, one minute before being tagged. The
tagged people are the admins who used the board daily until the lockdown;
stale tabs, bookmarks and muscle memory still take them there, and what they
got was a dead end that never said where their task now lives. Every coded
path (notification URLs, the digest, the My Work PATCH) was verified correct
before anything was changed — all point at `/admin/my-work`.

Two experience fixes, no permission change:

| Change | Status | Notes |
|---|---|---|
| The board's restricted state names the way out | ✅ | A non-finance visitor now sees "restricted to Finance — if you were tagged, everything you need is in My Work" with the button, instead of a bare error that reads as a broken page. |
| My Work carries the whole task, including a note back | ✅ | The finance-task payload gains structured `category`/`client`/`amount`/`comment` (they were baked into the subtitle string, so the panel could show but not edit them), and the row gains a note field — the assignee writes "client says Thursday" in place, PATCHed alongside the status, and finance hears it through the existing creator notification. My Work is the assignee's WHOLE view of the task, by design; it now behaves like it. |
| Backend tests | ✅ | The tagged-task test asserts the structured fields. Full suite **780 passed, 0 failed**. |

**Not changed, deliberately:** the permissions. `finance.snapshot` stays
`super_admin` + `finance` only — reconfirmed by the boss's wording in the
same report. The tagged admins work from My Work; that is the design, and
now the product says so at every dead end.

---

## 🗓️ Closed weeks, records that move, and CSV downloads (Session 106)

> **Deploy status:** **deployed 2026-08-31** as `ad27717` (backend) and
> `ea0f352` (frontend, Vercel). No migration; `route:cache` rebuilt for the
> two export routes, `config:cache` alongside, both exit 0. Verified on the
> server's own clock: current week 2026-W36, W35 closed, W36 open — the
> exact split finance's live data straddles. From outside: categories 200,
> both export routes 401-not-404.

Finance's follow-up on the weekly liquidity board: moving a record to another
week meant deleting it and retyping it in the destination; a week that has
ended should be closed; and both the snapshot and the liquidity data should
be downloadable.

| Change | Status | Notes |
|---|---|---|
| **One rule: a write must land in an open week** | 🔧 | Closed (past) weeks refuse new entries, in-place edits and deletes — but moving a record FORWARD is a write landing in an open week, so it is naturally allowed. That interaction is the design: the main reason finance moves records is rolling an unpaid item out of the week that just ended, and a fully-sealed past week would have broken the exact workflow the move exists for. Weeks compare as zero-padded strings, correct across year ends. |
| Deletes in closed weeks refused with the way out named | 🔧 | "Closed weeks keep their records. Move it to an open week first if it needs correcting" — the Session 90 rule that a wrong figure nobody can change is the worst end state, satisfied without letting history be erased. |
| `meta.current_week` served | 🔧 | The panel closes columns off the SERVER's clock — the same clock the write refusals use, so a browser in another timezone cannot disagree about which week is open. |
| The move control (frontend) | 🔧 | Every modal row carries a Week selector — open weeks only, including a few beyond the grid so rolling forward never requires creating the destination column first. In a closed week it is the only control on the row; in an open week it saves the delete-and-retype round trip either way. |
| Closed weeks in the UI | 🔧 | Column headers badge "Closed", cells stay readable, the modal banners the state, fields go read-only, the add form and delete disappear. "+ Week" extends from the open horizon, never restarting a past week. |
| `GET /admin/finance-snapshot/export` + `/liquidity/export` | 🔧 | Both `finance.snapshot`, BOM-prefixed for Excel. The liquidity file is written in `liquidity:import`'s OWN column layout — a download is also a restorable backup, proved by a test that feeds the export straight back through the import. Registered before parameterised segments, the Session 76 rule. |
| Backend tests (2 new, 1 rebuilt) | ✅ | The closed-week walk (create/edit/delete refused, the forward move allowed with a correction riding on it, then ordinary once open, `current_week` served) and the export round-trip incl. the permission gate. The Session 105 round-trip test also switched from a hardcoded `'2026-W36'` to the computed current week — a static key would have CLOSED under the test the moment the calendar moved. Full suite **780 passed, 0 failed**, up from 778. |

---

## 📊 The liquidity page becomes finance's weekly file + EC exports (Session 105)

> **Deploy status:** **deployed 2026-08-31** as `f25efc2` (backend) and
> `d09e83d` (frontend, Vercel). `backup:okelcor` taken first, **#59 applied**
> (37ms), both caches rebuilt exit 0. **Finance's data is live:** the file's
> Details ledger went through `liquidity:import --fix --replace` — survey
> totals matched the sheet category for category (Bank 10,440.54 / CoS
> −66,121.17 / Revenue 142,491.87 …), then 64 rows written across weeks
> 2026-W35–40 + W44, the 47 old-format rows superseded, zero legacy rows
> left. Verified from outside: categories 200, snapshot + EC endpoints 401.

Two asks from finance, delivered together:

### 1. The liquidity page is now "Liquidity File V1.xlsx"

Finance sent the file (a Summary grid of categories × ISO weeks 35–44 over a
Details ledger: Item / Supplier / Description / Week / CUR / Amount / Comment)
and asked for the page to match it exactly. The old two-bucket model
(open current month / next month) could not express any of that.

| Change | Status | Notes |
|---|---|---|
| **#59** weekly columns on `finance_liquidity_entries` | 🔧 | `week_key` ('2026-W35'), `supplier`, `currency`, `comment` — additive, guarded, nullable. `period` stays, so the D13 restore path and old rows are untouched; week-keyed rows are the live model. |
| Lines follow the file | 🔧 | `it_expenses` added (the file's "Other Expenses"), the tax label is plain "Tax Obligations" now, and a served `EXPENSE_LINES` constant pins the grid's arithmetic: Cash Position = Bank Balance + every expense line, Forecasted = + Revenue Payment. Served in meta so the panel cannot drift. |
| `liquidity:import` command | 🔧 | Reads the Details ledger as CSV. Survey by default, `--fix` to write, `--replace` because the file IS the working and merging generations would double every figure. Rejects-not-guesses (unknown item, unreadable week, non-numeric amount name their line and abort). |
| The board's liquidity section rebuilt (frontend) | 🔧 | The file's Summary grid: ISO-week columns with date-range subtitles, Total column, the two computed rows, a "+ Week" control, and cells opening the Details behind them (supplier/description/amount/CUR/comment, inline-editable). The old two-column table AND the 4-week LiquidityLadder are gone from the page — both superseded by the weekly grid; the ladder's backend endpoints stay (dormant, zero rows). Old-format entries are counted in a note rather than silently hidden. |
| Production data | ✅ imported 2026-08-31 | The 47 old-format entries (finance's earlier working) superseded by the file's 64 detail rows via `--fix --replace`, after a backup and a survey whose totals matched the sheet. |

**Known divergence from the file, deliberate:** the file's own Summary
formulas omit IT Expenses and Internet & Phone from Cash Position (its
details carry them; its grid does not sum them). Ours includes every
expense category, so our Cash Position is self-consistent with the details
and will differ from the sheet's total by exactly those rows.

### 2. EC Invoice List takes exports, not just EU countries

| Change | Status | Notes |
|---|---|---|
| `transaction_type: export` | 🔧 | New member beside goods/services/triangular — a plain-string column, so **no migration**. Label "Export (Drittland / non-EU)". |
| Country and type must agree | 🔧 | An EU state under `export` is an intra-Community supply filed under the wrong regime; a third country under an intra-EU type has no ZM line to be. Both 422 with the reason, on create AND on edit (the merged pair is checked, so an edit cannot smuggle the mismatch in). |
| **ELSTER XML excludes exports** | 🔧 | § 18a covers intra-EU supplies only — an export line would make the filing wrong. The exclusion is structural: the XML query filters on `TYPE_ART`'s keys, and `export` deliberately has no Art code, so the filter cannot drift from the codes. |
| CSV keeps them | 🔧 | The invoice + delivery-proof pair on every line is exactly the Ausfuhr evidence (§ 6 UStG), so exports share the whole apparatus — lines, documents, assignee chase, audit file — and differ only where § 18a differs. |
| Frontend | 🔧 | The add-group form leads with the type; picking Export swaps the EU dropdown for a free 2-letter country input and relabels the VAT field "Customer / tax reference". Export rows carry a badge, and the ELSTER modal says exports are not in the file and why. |
| Backend tests (3 new EC + 3 new liquidity) | ✅ | Export group round-trip with the badge flag and null Art; both mismatch directions + the edit path; ELSTER excluding / CSV keeping (the first assertion caught its own bug — `UStIdNr` contains "US", so the not-contains had to target `<Landescode>US</Landescode>`). Liquidity: weekly entry round-trip + served arithmetic, migration #59 idempotent against the real file, and the import command's survey/fix/replace/reject paths. Full suite **778 passed, 0 failed**, up from 772. |

---

## 🛡️ Security hardening pass (Session 104)

> **Deploy status:** **deployed 2026-08-29** as `e3dc4e5`. `backup:okelcor`
> taken first (7.34 MB), `migrate --pretend --force` reviewed (exactly one
> CREATE TABLE, nothing else), then **#58 applied as batch 123** (53ms).
> `config:cache` and `route:cache` rebuilt, both exit 0;
> `sanctum:prune-expired` confirmed in `schedule:list`. Verified from
> outside: `/api/v1/categories` 200, and six rapid login guesses at one
> address returned **five 401s then a 429** — the per-account throttle is
> live, which also proves the route cache is fresh. The running code reports
> `login_histories: EXISTS` and a 7-day customer TTL.

The user brought a security checklist (Fortify 2FA, password rules, login
rate limits, session cookies, password-confirm, auditing) and asked what
needs tightening. Audited against the code and the live server rather than
the checklist: most of it already exists — 2FA is mandatory with no grace
period on production, the admin login limiter is already keyed IP+email, and
the session-cookie items are inapplicable to a pure token API. Five real
gaps found, all five fixed.

| Change | Status | Notes |
|---|---|---|
| **#58** `login_histories` finally exists | 🔧 | The table shipped code has written to since the portal launched and production never had (Known Gap since Session 70). Every write sat in a bare catch, so the **10-failures-in-an-hour auto-suspend has never once fired** — its counting query threw into the same catch. Creating the table is the whole fix; the code was right all along. A test proves the suspend now suspends and revokes tokens. |
| Customer tokens expire (7 days) | 🔧 | They lived forever: `sanctum.expiration` null, no per-token TTL, and neither a reset nor a change revoked them — a stolen token outlived the password changed because of the theft. `CUSTOMER_TOKEN_TTL_DAYS` (default 7), same per-token pattern as the admin 5-hour TTL. `sanctum:prune-expired` scheduled daily, 48h retention. |
| A reset revokes every token; a change revokes every OTHER token | 🔧 | The reset is what someone does when they suspect compromise, so all sessions opened with the old password end with it. A change keeps the session doing the changing — the customer is not logged out by their own action. |
| **Found by the new tests: two latent bugs in `CustomerAuth`** | 🔧 | The middleware resolves tokens itself and (1) **never checked `expires_at`** — Sanctum's Guard does, but only admin routes go through it, so the new TTL would have been stamped on every token and enforced on none; and (2) **never called `withAccessToken()`**, so `currentAccessToken()` was null on every customer route — meaning **`POST /auth/logout` has thrown on the null the whole time**. Both fixed in the middleware; both pinned by tests (expired-token 401, logout deletes exactly its own token). |
| One password policy — `Password::defaults()` | 🔧 | Was min(8) plain for customers and min(8)+letters+numbers for admins, drifting per call site. Now one definition in `AppServiceProvider`, used by all five call sites: production gets 12+ chars, mixed case, numbers, symbols, `uncompromised()`; elsewhere plain min(8) so the suite stays hermetic. `uncompromised()` **fails open** — verified in the vendor source, `NotPwnedVerifier` catches its own request exception — so an HIBP outage can never block a signup. Existing stored passwords are unaffected; the rule runs when a password is set. |
| Login throttled per IP+email too | 🔧 | New `auth-login` limiter: the 10/min-per-IP kept, plus 5/min per IP+email, mirroring `admin-login`. Deliberately not keyed on email alone — that would let anyone lock a victim out. Register stays on the plain per-IP limiter. |
| `.env.save` / `.env.save.1` / `.envc` deleted from the server | ✅ | `.env.save.1` was a full 97-line credential copy with `APP_KEY`, from a 2026-07-08 mishap. Checked from outside first: the host 403s dotfiles, so not web-reachable — hygiene, not incident response. Only `.env` and `.env.example` remain. |
| Backend tests (11 new) | ✅ | `LoginSecurityHardeningTest` — the migration idempotent against the real file, every attempt recorded, the auto-suspend end to end, the 7-day expiry, reset revoking all / change sparing itself, the expired-token 401, logout scoped to its own token, the sixth rapid guess 429ing, and both password-policy branches (production strict with HIBP faked, non-production min(8)). Full suite **772 passed, 0 failed**, up from 761. |

**Deliberately not changed:** the session-cookie block from the checklist
(`SESSION_SECURE_COOKIE` etc.) — no route uses sessions; the `session()`
hits in the codebase are the `LiveChatSession` model. And no
password-confirmation middleware on admin deletes — 2FA disable already
re-asks for the password, deletes are super_admin-only behind a 5-hour
token, and the checklist's `password.confirm` is session middleware that
does not exist on a token API.

---

## 🔒 The finance snapshot board is finance's own (Session 103)

> **Deploy status:** **deployed 2026-08-29** as `92b49d8`. **No migration**
> (`Nothing to migrate`). Nine existing routes moved to a new permission key,
> so `route:cache` was the load-bearing step — rebuilt, along with
> `config:cache`, all four commands exit 0. Verified rather than assumed: the
> running code reports `finance.snapshot => super_admin,finance`,
> `can('admin','finance.snapshot')` false and `can('order_manager',
> 'finance.view')` still **true**; from outside `/api/v1/categories` is 200 and
> `/admin/finance-snapshot`, `/admin/ec-invoices` and `/admin/my-work` all
> return **401, not 404**, so the new route cache is live and nothing else in
> the finance block was collateral. Frontend deployed alongside on
> okelcor-website `main` (`1963d3b`), which Vercel picks up automatically.

The business's instruction, via the boss: the finance snapshot dashboard is for
`finance` and `super_admin` only. `admin`, the order manager and everyone else
are denied, and the page is hidden from them rather than offered and refused.
Separately: someone tagged on a record should land **on that record**, not on
the whole board.

| Change | Status | Notes |
|---|---|---|
| **`finance.snapshot`** — a new key, `['super_admin', 'finance']` | 🔧 | Deliberately **not** a narrowing of `finance.view`. That key gates eight other things — invoice reconciliation, profitability, liquidity, EC Invoices, the Sales & Order board — and `admin` and `order_manager` hold it because Sessions 83 and 99 decided operations and finance should read the same numbers. Narrowing it would have taken the order manager out of her own daily work to close one page. One key covers read and write: the board has no read-only audience, and a second key would be two things to keep in step for nobody. |
| All nine board routes moved onto it | 🔧 | `GET /admin/finance-snapshot` left the `finance.view` group and joined the write group, which is now `permission:finance.snapshot`. |
| **Being the assignee is still authorization** | 🔧 | `PATCH /admin/my-work/finance-items/{id}` is unchanged in spirit and its fallback moved `finance.manage` → `finance.snapshot`. This is the only way into a snapshot record from outside finance and it is the point: closing the board must not stop finance tagging an order manager to chase a payment. |
| **A tagged task opens the task** | 🔧 | `action_url` on a My Work finance task was `/admin/finance-snapshot?item=N` — the whole board, and a guaranteed 403 for most assignees now. Now `/admin/my-work?finance_item=N`. A `board_url` rides alongside, populated **only** for someone who may open the board and `null` otherwise, so finance keeps the jump and nobody else is offered a dead link. |
| `status_options` served on the finance task | 🔧 | Finance tasks were the only editable My Work item without them — EC lines and to-dos both carry theirs. The panel had `editable: true` and a `status` and nothing to build the select from. Served rather than hardcoded, so the list cannot drift from `FinanceSnapshotItem::STATUSES`. |
| Backend tests (3 new, 2 replaced) | ✅ | Every role walked against read and write, with `admin` and `order_manager` asserted **by name** because both would have been let in had this ridden on `finance.view` — that is the regression worth pinning. A second test asserts the wider finance permissions were left alone. A third runs it end to end: an order manager is tagged, gets the My Work link and a null `board_url`, and updates the status holding no finance permission at all. `test_order_manager_can_read_but_not_write` was **replaced, not patched** — its name had become a lie. Full suite **761 passed, 0 failed**, 207 skipped. |

**The complaint that started it was probably a frontend bug.** The report was
that the boss, a `super_admin`, could not see the dashboard. `super_admin` has
held every finance permission on the API since the board shipped — so if the
page was missing for him, the panel is gating on a hardcoded role list rather
than on the `permissions` array the auth payload returns. That is the Session 84
divergence again, and it is flagged in the frontend note as the thing to check
first rather than assumed fixed by this change.

**The frontend half shipped in the same session**, on okelcor-website `main`
as `1963d3b`. A new `finance_snapshot` section holding one page, mapped to the
new key and granted to super_admin and finance only — which covers all four
gate points at once, since the nav filter, the shell route guard, the
middleware redirect and the command palette all read the same two tables. The
`finance` section is untouched, so `admin` and the order manager keep the other
four pages in that group.

**And the second half was a real bug on that side, not just missing work.**
`my-work.tsx` was rewriting every finance task's `action_url` to
`/admin/finance-snapshot?item=N` **client-side**, overriding whatever the API
served — so the backend fix alone would have changed nothing on screen and the
assignee would still have been sent to a board she can no longer open. It now
uses `action_url` as served, with the board link as a separate control rendered
only when `board_url` is non-null. Worth keeping: a client that "helpfully"
reconstructs a server-supplied URL is a second source of truth, and this is the
same shape as the Session 78 finding where two renderers drew one set of blocks.

`tsc --noEmit` clean, production build clean.

See `FRONTEND_NOTE_finance-snapshot-access.md`.

---

## 🐞 EC Invoice List — the taxpayer VAT ID could never be saved (fixed, deployed 2026-08-28)

Finance reported a server error when saving the VAT ID at the top of the EC
Invoice List. Not the country group — the field above it, `PUT
/api/v1/admin/ec-invoices/company-vat`. The production log named it exactly:

```
SQLSTATE[01000]: Warning: 1265 Data truncated for column 'type' at row 1
insert into `site_settings` (`key`,`value`,`type`,`group`)
values (company_vat_id, DE343138173, text, finance)
```

`site_settings.type` is `enum('string','boolean','json')`. Session 100's
`setCompanyVat` wrote `'text'`, MySQL in strict mode refused the insert, and the
request returned an unhandled 500. Three attempts across two accounts (user 25
and 27) on 2026-08-28; the seeder and both content migrations have always
written `'string'`, and this was the only place that guessed at a fourth member.

**The row was never written once** — `company_vat_id` did not exist in
`site_settings`. So the ELSTER XML and the CSV audit file have both been
carrying an **empty Melder `<UStIdNr>`** since Session 100 shipped.

Why the suite never caught it: `phpunit.xml` runs on in-memory SQLite, where
Laravel renders `enum()` as an unconstrained varchar and `'text'` stores
happily. `test_the_taxpayer_vat_id_is_saved_and_served` asserted the value
round-trips, which it did — in CI. The test now also asserts the persisted
`type` is a member of the enum, which fails on `'text'` and passes on
`'string'` on every driver. Full suite: 760 passed.

Deployed as `97d05b6` — no migration, no route change; `config:cache` and
`route:cache` rebuilt, live API 200 on `/api/v1/categories` and 401 (not 500)
on the VAT endpoint.

**Confirmed working in production (checked 2026-08-29).** Finance retried after
the deploy and the save went through — `site_settings` now holds
`company_vat_id = DE343138173`, `type = string`, `group = finance` (row 19),
written `2026-08-28 12:46:26 UTC`, an hour after the last failure. The ELSTER
XML and the CSV audit file now carry a real Melder `<UStIdNr>` instead of an
empty one. No further `1265` has been logged in the 24 hours since — the log
runs through `2026-08-29 11:45`, and the only three EC-invoice occurrences
remain the original 11:48/11:49/11:50 on 2026-08-28. Nothing further is
outstanding on this item.

> **Reading production timestamps:** MySQL on this host runs **4 hours behind**
> PHP. `config('app.timezone')` is UTC and `laravel.log` is stamped in UTC, but
> `NOW()` returned `07:50` against PHP's `11:50`. `site_settings.updated_at` is
> `DEFAULT current_timestamp() ON UPDATE current_timestamp()` and `SiteSetting`
> sets `$timestamps = false`, so that column is written by **MySQL's** clock,
> not the app's. A DB timestamp that looks earlier than the log entry it
> belongs to is usually this offset, not a row that predates a fix. Add four
> hours before comparing the two.

### Other unhandled 500s live on production (found in the same log, NOT fixed)

Counted over 2026-08-25 → 28, re-checked 2026-08-29. Listed here so they are not
rediscovered from scratch:

| Count | Still firing? | Error | Where |
|---|---|---|---|
| 85 | **yes — 12 more today** | Scheduled `system:health --snapshot` fails with exit code 1 — every run | cron |
| 7 | not today | `Table 'okelvaxj_okelcor.login_histories' doesn't exist` | a migration that never ran |
| 6 | not today | `AdminOrderController::show(): Argument #2 ($id) must be of type int, string given` | `/admin/orders/OKL-13I8OT5` and similar — looking an order up by **order number** 500s instead of 404ing. The same handler also catches `/admin/customers/undefined/communications` and `/products/NaN`, so the panel is sending bad IDs too |

The health cron is the live one: it is the **only** unhandled exception logged on
2026-08-29, and it has failed on every scheduled run. The other two are
user-triggered, so "not today" means nobody hit that path — not that it is fixed.

The `1265 Data truncated` shape is **not new to Session 100**: the log carries
nine more from 2026-05-29 → 06-10 against other columns. The enum trap recurs
here, and the SQLite test suite cannot see it. Before writing a literal into a
column backed by an `ENUM` — `site_settings.type`, `order_logs.action`, and the
rest — read the migration for its exact members, and have the test assert the
**persisted** value is one of them. A round-trip assertion proves nothing on
SQLite, which renders `enum()` as an unconstrained varchar.

Also on the server: a dozen shell-mishap files (`-H`, `-d`, `ref_number,`)
sit untracked in the app root. Harmless to git. The `.env.save`, `.env.save.1`
and `.envc` credential copies that used to sit beside them were **deleted in
Session 104** — `.env.save.1` was a full 97-line copy including `APP_KEY`,
dated 2026-07-08. Verified not web-reachable first (the host 403s dotfiles),
so this was hygiene, not incident response.

---

## ✅ Sessions 86–95 deployed to production (confirmed 2026-08-22)

Verified from outside rather than from a deploy log: the live API serves
`slug`/`description_html`/`specifications`, the live site renders a product's
Artikelmerkmale sheet at its slug URL, and the Session 95 multi-word and
description search answer correctly. All seven migrations (#37–43) are
therefore applied. The Step 3 data commands (staff backfill, `orders:payment-state
"AB - 1182"`, `customers:stuck`) and the marketer's role flip have **not** been
confirmed run — they remain in the Outstanding table below.

## ✅ Sessions 96–102 + the 2026-08-25 batch deployed (confirmed 2026-08-28)

Four deploys over SSH on 2026-08-28, each verified rather than assumed:
production HEAD is `374b89c`, `migrate:status` shows every migration through
`2026_08_28_000006` as **Ran** (#52–57), `config:cache` and `route:cache`
rebuilt after each, and from outside the live API returns 200 on
`/api/v1/categories` and **401 (not 404)** on every new endpoint —
`/admin/finance/profitability`, `/admin/finance/liquidity`,
`/admin/ec-invoices`, `/admin/sales-orders` and `/admin/todos`. The frontend
half of each session is pushed on okelcor-website `main` (through `45ebbcd`),
which Vercel deploys automatically.

**The real app path is `/home/okelvaxj/public_html/okelcor-api`** — there is
no `domains/` segment; both older paths recorded in this file are wrong on the
current account. The server's git remote is the old repo URL
(`github.com/johnseyi/okelcor-api`), which GitHub redirects to
`okelcor-gmbh/okelcor-api`, so pulls work unchanged.

| Session | What it is | Migration | Routes |
|---|---|---|---|
| **86** | Clients drill-down, month-to-month report with charts, one invoice register for both sides | **#37** | **5 new** |
| **87** | eBay kept beside the website in the report, CSV export, fulfilment queue starting before dispatch | none | **1 new** |
| **88** | `sort` on the order list, so the queue can be worked from the back | none | none |
| **89** | Staff contribution ledger — phases 1 and 2 of the performance system | **#38** | **10 new** |
| **89b** | Job titles separated from roles, team report, monthly digest, admin nav search | **#39** | **1 new** |
| **89c** | Technical work — git commits, media, eBay, account administration | none | none |
| **90** | Correcting a payment state that says paid when it isn't — the only backwards path through the ladder | **#40** | **1 new** |
| **91** | The approved customer who could never log in — a password reset now confirms the email, and the order manager has the controls | **#41** | **2 new** |
| **92** | Product optimization (the marketing brief) — SEO slugs, rich descriptions, the Artikelmerkmale sheet, shipping/returns content | **#42** | **1 new** |
| **93** | Brand-level content defaults — entered once per brand, inherited by all ~15,000 products at read time | **#43** | none |
| **94** | The `marketing` role — content + catalogue + campaigns, nothing operational | none | none |
| **95** | Product search actually works — the panel sent `q`, the API read `search`, pagination was never forwarded; plus tokenized search over specs/brand defaults/description | none | none |
| **96** | The primary-image route the product form has always called and the backend never had | none | **1 new** |
| **97** | Staff-to-staff messaging and forwarding — reach a colleague from the panel instead of Outlook | **#44** | **9 new** |
| **98** | Market intelligence — one row per country joining demand, inquiries, revenue and reach, to answer which market to enter next | **#45** | **2 new** |
| **2026-08-25 batch** | Permission overrides, finance snapshot board, eBay live-listing audit, campaign feedback tracking | **#46–51** | several |
| **99** | Per-order profitability (revenue invoice + costs + sign-off, export, dashboard) and the weekly liquidity ladder | **#52–54** | **16 new** |
| **99b** | Finance's walkthrough: liquidity weeks folded INTO the snapshot board's liquidity working, long names wrap instead of scrolling, My Work "Open" deep-links to the exact record | none | none |
| **100** | EC Invoice List — the ZM/§ 18a portal from `File6.html`: groups × itemized invoices with PDFs + delivery proofs, assignee chase, CSV audit file, ELSTER XML | **#55** | **14 new** |
| **101** | Sales & Order Management board from `OT 3.html`: customer vs supplier lines, computed GP/margin/status, the five KPI cards | **#56** | **9 new** |
| **102** | The shared team to-do list — anyone adds, tag a teammate, it lands in their My Work | **#57** | **4 new** |

The Session 96 fix is therefore live — the marketer can retry the image
replace on product 3834 — and the messaging, market-intelligence, snapshot
and profitability endpoints are all reachable.

### Post-deploy work still outstanding from the 86–95 stack

The migrations are applied; none of the data commands or human steps that
follow them has been confirmed run. In order:

```bash
# Session 91 — who is blocked at login, and whether Theo clicked his link
/opt/alt/php83/usr/bin/php artisan customers:stuck

# Session 90 — AB - 1182 shows paid with no deposit received. Read first;
# the correction line is in the Session 90 section.
/opt/alt/php83/usr/bin/php artisan orders:payment-state "AB - 1182"
/opt/alt/php83/usr/bin/php artisan orders:payment-state --audit

# Session 89 — the staff ledger opens empty until built from history.
# Survey first, read it, then --fix. Re-runnable, cannot double a month.
/opt/alt/php83/usr/bin/php artisan staff:backfill-ledger
/opt/alt/php83/usr/bin/php artisan staff:backfill-ledger --fix
/opt/alt/php83/usr/bin/php artisan staff:sync-job-titles
/opt/alt/php83/usr/bin/php artisan staff:import-commits --since="12 months ago"
/opt/alt/php83/usr/bin/php artisan staff:import-commits --since="12 months ago" --fix
/opt/alt/php83/usr/bin/php artisan staff:digest --dry-run
```

**Human steps, none of them a command:**

1. **The marketer's role** — Admin → Users: `editor` → `marketing`
   (Session 94). He logs out and back in; Products, Brands, Marketing and
   Settings appear.
2. **The marketer owes the returns text** — Settings → `product_returns_info`
   (Session 92 seeded it empty because his draft was eBay wording).
3. **Tell the order manager** the board's `in_transit` figure jumped —
   Session 87, the change she asked for, live since this deploy.
4. Session 86's invoice register does **not** backfill — it fills from the
   next save forward. `invoices:register-existing` offered, not yet asked for.

---

## ⚠️ Outstanding on production (reviewed 2026-08-28 — all still open)

None of today's deploys touched these; they remain waiting on data commands
or human steps. One nuance: item 2's timeout risk is softened — the campaign
send now defers until after the response is flushed (commit `c5a867a`) — but
`QUEUE_CONNECTION=database` + a worker is still the real fix.

Live data and configuration items — none of them is fixed by shipping code.
Items 8 and the ledger/role steps above overlap deliberately: this table is the
long-lived list, the section above is this deploy's checklist.

| # | Action | Why it matters |
|---|--------|----------------|
| 1 | **Import the Wix contact list** — `marketing:import contacts.csv --market=wix` (survey first, then `--fix`) | The `wix` audience the marketing team asked for **does not exist yet**: `marketing:tag-wix --file=contacts.csv` matched **13 of 1,706**. Production holds 1,443 curated B2B contacts across seven markets (`asia` 176, `austria` 252, `croatia` 199, `czech` 300, `france` 300, `germany` 212, `test` 4) — a different population entirely. The Wix consumer export was never imported. Do NOT run `marketing:tag-wix --fix`: it would tag thirteen people and leave the business believing the audience is complete. |
| 2 | **`QUEUE_CONNECTION=database`** + a queue worker under Supervisor | Still `sync`. Outstanding since Session 50 and now the last thing between marketing and their first real send — once item 1 lands, the Wix audience is ~1,700 people and `SendBulkEmailCampaignJob` would run inline in the HTTP request and time out. |
| 3 | **Create the finance admin account** with the new `finance` role | Live since the 2026-08-14 deploy. Until someone holds it, no order confirmation can collect its second signature, and every new order stops at the gate. |
| 4 | **Restore order 10112** — `orders:restore-total 10112 371.88 371.88 --reason="undo bad automated repair, Session 75"` | The first `orders:repair-totals --fix` run cut it from **371.88 → 312.50** on a wrong diagnosis, then died before writing the log. Still at the wrong figure with no record of the change. Migration #30 is applied, so the restore can write its audit row — unblocked. |
| 5 | Re-run `orders:repair-totals` (survey, no `--fix`), then `--fix` | Confirms the rewritten classifier flags only the 2 lump-sum orders, not 21. Then corrects **AB-1150** (16,250 → 8,125) and **AB - 1182** (30,000 → 15,000). Read the survey before the fix. |
| 6 | *(optional, business call)* `PARTNER_EDIT_WINDOW_HOURS=72` in `.env` before `config:cache` | See Session 75 partner-correction note. Config-only, reversible. |
| 7 | *(human, not a command)* Reconcile orders **10075, 10076, 10077, 10079, 10080** | Items exceed the stored total on inconsistent ratios. No tooling will fix these — someone has to compare each against what was actually invoiced. Tracked in Known Gaps. |
| 8 | **Correct AB - 1182's payment state, then sweep** — `orders:payment-state "AB - 1182"` to read it, then `--stage=pending_proforma --reset-status --reason="…"`, then `orders:payment-state --audit` | Reported by the order manager on 2026-08-18: she is waiting on the deposit and the customer's portal says he has paid. Needs the Session 90 deploy first (migration #40). The sweep then names every other order in the same state — it reports rather than repairs, because a backfilled historical order is supposed to look identical to a live one that went wrong. |
| 9 | *(offered, not asked for)* `invoices:register-existing` backfill | Session 86's invoice register fills forward only. Without a backfill the reconciliation shows history from the deploy onward rather than from the start of the year. Small command, dry run first — say the word. |

---

## ✅ Sessions 81–85 deployed to production (2026-08-14)

Deployed as `dad8997`. Migrations **#33–36 applied as batch 103**, confirmed by
`migrate:status`, and by the assertion that actually settles it:

```
$ /opt/alt/php83/usr/bin/php artisan tinker --execute="echo in_array('finance', …::ROLES) && Schema::hasTable('order_signoffs') ? 'live' : 'NOT LIVE';"
live
```

`route:cache` rebuilt for Session 83's nine routes. The `finance` role and the
sign-off tables are now real on production, so the admin panel work can go live
whenever frontend ships.

**Two things this deploy needs from a person, neither of them a command:**
create the finance user's account with the new `finance` role, and tell the
order manager that sending an order confirmation now requires finance's
signature. Orders raised before 2026-08-13 are exempt, so nothing in flight is
frozen, but the first new order will stop and she should know why.
`ORDER_SIGNOFF_REQUIRED=false` stands the control down from `.env` without a
deploy if it blocks something urgent.

---

## 🔴 Production outage — Namecheap, 2026-08-13

Recorded because the diagnosis is reusable, not because the fix was.

**Symptoms:** SSH refused, admin panel reporting "login failed". Both are the
same fault seen from two ends.

**What it actually was, and how that was established from outside the server:**

| Check | Result |
|---|---|
| DNS for `api.okelcor.com` | resolved (Cloudflare is authoritative for the domain) |
| Cloudflare edge | up, returning **HTTP 522** — "origin connection timed out" |
| Origin `business194.web-hosting.com` (`192.64.118.18`) | no ICMP; ports 22, 21098, 443, 80, 2083 all timed out |
| `traceroute` | reached **inside** Namecheap's network (`131.125.129.85` → `10.255.68.18`), then died at the final hop |
| `okelcor.com` frontend (Vercel) | up |
| E-mail (MX → Microsoft 365) | unaffected — nothing to do with Namecheap |

Routing into their datacentre was fine; the host was not answering. That ruled
out our DNS, Cloudflare and the local network in one pass — and ruled out an
IP-level firewall block on the office address, because probes from an unrelated
address failed identically.

**The useful signal was 522.** It says Cloudflare reached its edge and the
origin did not answer, which is a different fault from a 5xx the application
produced. Worth reaching for first on any "the site is down" report.

Namecheap showed a major disruption that day (133 reports in 24h) alongside
published shared-server and VPS maintenance, but **`business194` was never named
in an incident** — so it was specific to this account, and the ticket had to say
so rather than pointing at the published notice.

Service returned on 2026-08-14, verified before anything was announced:
`/api/v1/categories` returned the four fixed slugs and `/admin/campaign-design`
returned Laravel's own 401. **Nothing we had deployed caused it** — production
was at `d7b63a7` and had not changed that day.

---

## ✅ Sessions 78–80 deployed to production (2026-08-13)

Production is at `d7b63a7`. Migration **#32 `search_events`** applied as **batch
102**, confirmed by `migrate:status`. `route:cache` rebuilt — Session 79's
`GET /admin/analytics/behaviour` returns Laravel's own `401`, not a 404 from a
stale cache. Behaviour recording is live and the report fills as traffic
accumulates.

**The deploy caught Sessions 78–80 only.** The `git fetch` ran against a state
that did not yet have Session 81, so `HEAD` came back `d7b63a7` rather than the
tip. Worth recording as a pattern, not an incident: **`migrate:status` looking
right does not prove the deployed code is the code you pushed.** Batch 102
proved a fetch had happened, and the 401 proved the route cache was fresh —
neither says which commit. The check that actually settles it is

```bash
git rev-parse --short HEAD
/opt/alt/php83/usr/bin/php artisan tinker --execute="echo array_key_exists('hero', App\Services\CampaignBlockRenderer::BLOCKS) ? 'live' : 'OLD CODE';"
```

— a SHA plus one assertion against the running code. Sessions 78–80 were only
recorded as deployed after that came back, and the earlier draft of this section
that marked Session 81 deployed on the strength of the migration alone was
wrong.

---

## ✅ Sessions 74–77 deployed to production (2026-08-12)

Deployed as `35d8e12`. All three pending migrations applied and verified via
`migrate:status`:

| Migration | Batch |
|---|---|
| **#29** `2026_08_07_000002_create_campaign_drafts_table` | 99 |
| **#30** `2026_08_10_000001_add_totals_repair_actions_to_order_logs_enum` | 100 |
| **#31** `2026_08_11_000001_add_milestone_and_document_actions_to_order_logs_enum` | 101 |

`route:cache` rebuilt. Verified live: `POST /api/v1/admin/campaign-templates/import`
returns Laravel's own `401 {"message":"Unauthenticated."}` with
`content-type: application/json`, so the Session 77 route is registered and
reachable rather than 404ing from a stale route cache.

**Deploy path — the account is `okelvaxj`**, confirmed from the shell prompt
during this deploy (`[okelvaxj@business194 okelcor-api]$`), which matches the
production database prefix `okelvaxj_okelcor`. The
`/home/u978121777/domains/okelcor.com/public_html/okelcor-api` path recorded in
earlier revisions of this file is the stale one. Still worth a `pwd` before a
deploy, but do not start from the `u978121777` path.

**Live behaviour that changed on this deploy** (Session 76 — both are the fix,
not a side effect): generating a proforma no longer e-mails the customer a
deposit request, and the EU entry certificate now accepts milestone-paid orders
it was refusing. **Tell the order manager the proforma button no longer
notifies anyone**, or she will assume it still does.

---

## Legend

| Symbol | Meaning |
|--------|---------|
| ✅ | Complete & deployed to production |
| 🔧 | Built, pending deploy |
| ⬜ | Not started |
| 🚧 | Partially built |

---

## Core API (Sessions 1–8)

| Feature | Status | Notes |
|---------|--------|-------|
| Laravel 13 setup, CORS, ForceJSON middleware | ✅ | |
| MySQL schema — all tables | ✅ | See schema section below |
| Products CRUD + soft delete + restore | ✅ | |
| Product images gallery | ✅ | |
| Product CSV import (Wix) + image download | ✅ | |
| Product bulk delete + export | ✅ | |
| Articles CRUD + translations (EN/DE/FR/ES) | ✅ | Rich HTML body via TipTap/HTMLPurifier |
| Article image upload (cover + OG + body) | ✅ | |
| Categories CRUD + translations | ✅ | 4 fixed slugs: pcr/tbr/used/otr |
| Hero Slides CRUD + translations | ✅ | |
| Brands CRUD + logo upload | ✅ | |
| Media library | ✅ | Article body-image → Media Library integration + 2 latent bugfixes in Session 51 |
| Site settings (key-value) | ✅ | |
| Admin user management | ✅ | super_admin only |
| Rapid product pricing (cost_price × discount%) | ✅ | PromotionPricingService |
| Promotions + promo codes | ✅ | |
| FET engine | ✅ | |

---

## Authentication (Sessions 9–10)

| Feature | Status | Notes |
|---------|--------|-------|
| Admin auth (Sanctum token, roles) | ✅ | super_admin / admin / editor / order_manager / sales_manager / support / content_manager / viewer |
| Mandatory admin 2FA (TOTP) | ✅ | 5-hour session TTL |
| Admin temp-token bootstrap (no-2FA first login) | ✅ | |
| Customer auth (register / login / verify / reset) | ✅ | |
| CRM-1 controlled onboarding (pending_review → invited → active) | ✅ | Admin must approve + invite |
| Customer address management | ✅ | |
| Role-based permission middleware | ✅ | `permission:X` middleware alias |
| EnsureAdminToken middleware (blocks customer tokens on admin routes) | ✅ | |

---

## Orders & Payments (Sessions 5–8, 10–12)

| Feature | Status | Notes |
|---------|--------|-------|
| Public order creation (`POST /orders`) | ✅ | Manual / B2B inquiry |
| Stripe Checkout integration | ✅ | Active gateway |
| Stripe webhook handler | ✅ | Marks paid, creates invoice, sends email |
| Bank transfer order flow | ✅ | |
| Tax / VAT calculation (TaxService) | ✅ | DE=19%, EU B2B reverse charge, non-EU exempt |
| EU VAT enforcement (VIES validation) | ✅ | |
| Order status management (admin) | ✅ | |
| Order financial correction endpoint | ✅ | PATCH /admin/orders/{id}/financials |
| Order CSV import (Wix) | ✅ | |
| Payment milestones (deposit/balance) | ✅ | |
| Customer Pay Now (Stripe, authenticated) | ✅ | `POST /auth/orders/{ref}/checkout` |
| Order audit log (order_logs) | ✅ | Append-only |
| Container tracking (DHL + ShipsGo sea freight) | ✅ | Auto-detects carrier |
| Adyen (legacy) | ✅ | Present but inactive |
| Mollie (legacy) | ✅ | Returns 410 |

---

## Invoices & Trade Documents (Sessions 11–13, 2C-1 to 2C-6)

| Feature | Status | Notes |
|---------|--------|-------|
| Invoice auto-creation (Stripe webhook) | ✅ | INV-YYYY-NNNN |
| Invoice PDF (DomPDF) | ✅ | |
| Invoice release gating (reverse-charge) | ✅ | Released only after admin acknowledges EU declaration |
| EU Entry Certificate (Gelangensbestätigung) | ✅ | Customer signs via portal |
| Order Confirmation PDF (AB-YYYY-XXXX) | ✅ | Auto-generated on quote conversion |
| Customer acceptance of Order Confirmation | ✅ | Token-based + authenticated |
| Proforma Invoice PDF (PI-YYYY-XXXX) | ✅ | Gated behind AB acceptance |
| Commercial Invoice PDF (CI-YYYY-XXXX) | ✅ | |
| Packing List PDF (PL-YYYY-XXXX) | ✅ | |
| Delivery Note PDF (DN-YYYY-XXXX) | ✅ | |
| Shipment document upload (Bill of Lading etc.) | ✅ | |
| Trade document email (with PDF attachment) | ✅ | |
| Trade document supersede | ✅ | |
| Trade document void | ✅ | |
| Logistics dashboard | ✅ | 18-metric summary + document checklist |

---

## CRM Pipeline (Sessions 32–38)

| Phase | Feature | Status |
|-------|---------|--------|
| CRM-1 | Controlled customer onboarding (pending_review → invited → active) | ✅ |
| CRM-2 | Inquiry quality scoring + spam gate (InquiryQualityService) | ✅ |
| CRM-3 | Lead qualification & sales pipeline (9-stage qualification_status) | ✅ |
| CRM-4 | Customer segmentation & access control (segment, access_level, checkout/doc guards) | ✅ |
| CRM-5 | Customer data quality & deduplication (scoring, normalization, merge-preview) | ✅ |
| CRM-6 | Communication log + follow-up automation + email templates | ✅ |

---

## CRM-7 — Sales Pipeline & Proposal Management (Session 39)

| Feature | Status | Notes |
|---------|--------|-------|
| `quote_request_items` table (new) | 🔧 | Migration ready, deploy pending |
| Quote item CRUD endpoints (admin) | 🔧 | GET/POST/PATCH/DELETE /items |
| Import items from inquiry | 🔧 | `POST /items/import-from-inquiry` |
| Proposal fields on `quote_requests` (18 columns) | 🔧 | QT-YYYY-XXXX sequential numbers |
| Proposal lifecycle endpoints (draft/mark-ready/send/void/link) | 🔧 | |
| Proposal PDF (DomPDF) | 🔧 | |
| Proposal email (ProposalEmail mailable) | 🔧 | Subject: "Proposal from Okelcor — QT-..." |
| Public token acceptance (GET/POST /proposals/{token}) | 🔧 | |
| Authenticated customer acceptance (auth/quotes/{ref}/accept-proposal) | 🔧 | |
| Convert-to-order guard (must be accepted, super_admin override) | 🔧 | |
| Proposal health checks in system health | 🔧 | |
| Fix 3 — `[proposal_items_missing]` diagnostic log on draft | 🔧 | Confirmed draft reads persisted `quote_request_items`; logs request-vs-persisted item counts |

---

## CRM-8 — Buyer Approval & Customer Lifecycle (Session 40)

| Feature | Status | Notes |
|---------|--------|-------|
| Buyer lifecycle fields on `customers` (tier, verification, health, risk, approval audit) | 🔧 | Additive; existing active customers backfilled verified/low-risk |
| `customer_verifications` table + CRUD | 🔧 | company_registration / vat_number / website / import_license / business_address / other |
| `customer_timeline_events` table (append-only) | 🔧 | created/converted/proposal_accepted/approved/tier/risk/block etc. |
| `customer_access_requests` table (customer→admin) | 🔧 | checkout / documents / wholesale_pricing / higher_tier |
| Approval profiles (CustomerApprovalService) | 🔧 | inquiry_only / approved_buyer / wholesale_buyer / restricted / blocked |
| Health scoring + risk bands (CustomerHealthService) | 🔧 | 80+/60+/40+/<40 → low/medium/high/critical |
| `GET /admin/customer-approvals` (queues + cards + filters) | 🔧 | |
| `GET /admin/customers/{id}/timeline` | 🔧 | |
| `POST /admin/customers/{id}/approval-profile` / `approve` / `reject` / `set-tier` / `risk` | 🔧 | approve/reject reuse existing routes, backward-compatible |
| `POST /admin/customers/{id}/health/recalculate` | 🔧 | |
| Verifications endpoints (GET/POST/PATCH) | 🔧 | rolls up customer verification_status + recomputes health |
| Admin access-request review (`/admin/customer-access-requests` + approve/reject) | 🔧 | approve grants the concrete CRM-4 flag |
| Customer portal access requests (`/auth/customer/access-requests`) | 🔧 | no internal risk/health exposed |
| Timeline hooks in convert-to-customer + proposal acceptance | 🔧 | |
| Buyer lifecycle health checks in system health | 🔧 | pending approvals / high-risk / pending access requests |
| **Fix** — approval unlocks customer login | 🔧 | Granting profiles now set onboarding_status=active + is_active + status=active (self-registered); lead-converted stay in invite flow |
| **Fix** — approval email (`ApprovedAccountEmail`) | 🔧 | Sent on approve/approval-profile for approved_buyer/wholesale_buyer only; logs + timelines sent/failed; never rolls back approval |
| **Fix** — `/auth/me` + login return fresh CRM-8 fields | 🔧 | is_active, buyer_tier, verification_status; presenter adds login_ready / pending_email_verification / pending_invitation |
| Backend feature tests (15, MySQL) | ✅ | `Crm8BuyerLifecycleTest` — 15 passed / 75 assertions (incl. end-to-end login after approval) |

---

## CRM-9 — Admin "Add Customer" Onboarding (Session 41)

| Feature | Status | Notes |
|---------|--------|-------|
| `POST /admin/customers` — admin-driven onboarding | 🔧 | New `customers.create` permission (super_admin / admin / sales_manager) |
| B2B/B2C, company required for B2B (422 `required_if`) | 🔧 | Field-level errors for the modal |
| `access_level` → CRM-4 flags via `approveBuyer()` | 🔧 | Defaults to `approved_buyer` (quotes + checkout + documents); stamps approval audit + timeline |
| No-password create + single-use set-password invite | 🔧 | `send_invitation` toggle; invite sent synchronously, send status returned as `data.invitation_email` |
| Duplicate email → 422 `errors.email` | 🔧 | `unique:customers,email` |
| Invitation email failures no longer silent | 🔧 | `sendInvitationEmail()` catches + logs + reports; `invite`/`resend-invite` surface status too |
| `security_events.type` enum widened (audit-trail fix) | 🔧 | Migration adds customer-lifecycle types the code already logged (were silently blank in non-strict MySQL) |
| Feature tests (4 new, MySQL) | ✅ | `Crm8BuyerLifecycleTest` — 19 passed / 92 assertions |

---

## CRM-3 — Admin Notifications (Session 42)

| Feature | Status | Notes |
|---------|--------|-------|
| `admin_notifications` table (new) | 🔧 | Generic per-admin-user feed; `type`/`link` reusable for future event types |
| `GET /admin/notifications` | 🔧 | List current admin's notifications, most recent first, + `unread_count` |
| `POST /admin/notifications/{id}/read` | 🔧 | Marks one as read; scoped to owning user (404 if not owned) |
| `POST /admin/notifications/read-all` | 🔧 | Marks all of current user's unread notifications as read |
| `AdminNotificationService::notify()` | 🔧 | Generic writer, try/catch logged (never throws) |
| `lead_assigned` trigger in `POST /admin/quote-requests/{id}/assign` | 🔧 | Fires when `assigned_to` changes to a new user (not on re-assign to same user) |

---

## CRM-3B — Admin Notification Center & Work Queue (Session 43)

| Feature | Status | Notes |
|---------|--------|-------|
| `admin_notifications` extended (severity/body/action_url/related_type/related_id/dismissed_at/metadata) | 🔧 | Additive; `message`/`link` kept & mirrored from `body`/`action_url` |
| `AdminNotificationService` rebuilt | 🔧 | notifyUser / notifyPermission / notifyRoles / markRead / markAllRead / dismiss / unreadCount; legacy `notify()` wrapper kept |
| Dedupe (metadata `dedupe_key`) | 🔧 | Suppresses duplicate **unread**; recurring events pass `includeRead=true` (one per due-date) |
| `GET /admin/notifications` (filters: unread/type/severity/page) | 🔧 | Paginated, scoped to self, excludes dismissed |
| `GET /admin/notifications/unread-count` | 🔧 | |
| `POST /admin/notifications/{id}/read` / `read-all` / `{id}/dismiss` | 🔧 | Owner-scoped (404 if not owned) |
| `GET /admin/my-work` work queue | 🔧 | assigned leads / due follow-ups / proposals accepted / approvals + access requests (customers.manage) |
| Trigger: `lead_assigned` (assign endpoint) | 🔧 | |
| Trigger: `proposal_accepted` (public + authenticated) | 🔧 | Assigned owner, else `quotes.manage` fan-out; severity success |
| Trigger: `customer_access_requested` (portal) | 🔧 | `customers.manage` fan-out; severity warning |
| Trigger: `customer_approval_needed` (registration) | 🔧 | `customers.manage` fan-out; severity warning |
| Trigger: `quote_needs_review` (CRM-2) | 🔧 | `quotes.manage` fan-out; severity warning |
| `admin:notifications:due-followups` command (hourly) | 🔧 | Notifies assigned owner of due/overdue follow-ups; no customer emails |
| Backend feature tests (16, MySQL) | ✅ | `Crm3bNotificationsTest` — 16 passed / 46 assertions |

---

## Landing Pages — Tyre Wholesaler (Session 44)

| Feature | Status | Notes |
|---------|--------|-------|
| `lead_metadata` JSON column on `quote_requests` | 🔧 | Attribution bag (utm_*/gclid/fbclid/referrer/landing_page + interest/volume) |
| `lead_source` + `lead_metadata` accepted on **`POST /api/v1/quote-requests`** | 🔧 | **The live path the frontend uses.** `quantity` now optional (NOT-NULL-safe fallback); attribution stripped from columns into `lead_metadata`; accepts nested `metadata{}` + flat `utm_*/gclid/fbclid/referrer` |
| EU VAT enforcement gated to `lead_source=website_quote` | 🔧 | Landing/ads leads (no VAT field) not hard-blocked at inquiry stage; website form unchanged |
| `POST /api/v1/leads/tyre-wholesaler` | 🔧 | Typed alternative intake (interest/volume enums, phone optional); not used by current frontend |
| Reuses CRM-2 quality gate + CRM-3 defaults + CRM-3B notifications | 🔧 | Side-effects extracted to shared `dispatchInquirySideEffects()`; `lead_metadata` via shared `buildLeadMetadata()` |
| Backend feature tests (11, MySQL) | ✅ | `WholesalerLandingLeadTest` — 11 passed / 51 assertions (covers `/quote-requests` landing path + VAT gate) |

**Frontend owns:** the `/tyre-wholesaler` page, landing header/footer, inventory overlays, the form UI, `/tyre-wholesaler/thank-you`, and analytics events. **Frontend posts the landing form to the shared `/quote-requests` endpoint** (via its `/api/customer/quote-requests` proxy) with `lead_source=tyre_wholesaler_landing` + `metadata{}` attribution.

---

## Locale Auto-Detection (country → language) (Session 45)

| Feature | Status | Notes |
|---------|--------|-------|
| `config/i18n.php` (single source of truth) | 🔧 | Supported locales `en/de/fr/es` + default `en` + country→locale map + geo-header list |
| `App\Support\LocaleResolver` service | 🔧 | Priority: explicit `?locale=` → country (`?country=` then CDN geo headers) → `Accept-Language` → default `en` |
| `GET /api/v1/i18n/locales` | 🔧 | Returns supported locales, default, and full country→locale map (one fetch → client-side detection) |
| `GET /api/v1/i18n/resolve` | 🔧 | Resolves best locale; honours `?country=XX`, `?locale=`, `CF-IPCountry`/`X-Vercel-IP-Country`, `Accept-Language`. Returns `{ locale, country, source, is_default }` |
| Rule | 🔧 | Country with a supported language → auto-switch; every other country → English default. Anonymised CF `XX` ignored |
| Backend feature tests (15) | ✅ | `LocaleResolutionTest` — 15 passed / 59 assertions (no DB; pure config negotiation) |

**Frontend owns:** detecting the visitor's country (Vercel `request.geo` / Cloudflare), persisting the chosen locale (cookie/localStorage), the language switcher UI, and respecting a manual override. Backend is the authoritative country→language map so the two never drift.

---

## Lead Funnel Analytics (Session 46)

| Feature | Status | Notes |
|---------|--------|-------|
| `GET /admin/quote-requests/funnel?from=&to=` | 🔧 | `quotes.manage`; funnel stages (leads→qualified→proposal_sent→converted) + rates |
| Breakdown by `lead_source`, `lead_customer_type`, month | 🔧 | conversion rate per group |
| UTM attribution from `lead_metadata` | 🔧 | utm_source/campaign/medium top-10 with conversions; only when column exists |
| Deploy-order-safe | 🔧 | Built on always-present `qualification_status`; enrichment guarded by `Schema::hasColumn` |
| Backend feature tests (4, MySQL) | ✅ | `LeadFunnelAnalyticsTest` — 4 passed / 17 assertions |

---

## Localized Emails / Documents — Infrastructure (Session 46)

| Feature | Status | Notes |
|---------|--------|-------|
| `preferred_language` on `customers` (en/de/fr/es, default en) | 🔧 | Additive, guarded migration; in `$fillable` |
| Customer implements `HasLocalePreference` | 🔧 | Laravel auto-localizes any mail/notification sent to the customer |
| `lang/{en,de,fr,es}/emails.php` | 🔧 | EN complete (source); DE/FR/ES **drafted — need native-speaker review**; missing keys fall back to EN |
| Invitation email converted to `__()` (reference pattern) | 🔧 | HTML + text + subject localized; tested in all 4 languages |
| `preferred_language` accepted on register + profile, returned in `/auth/me` | 🔧 | |
| Backend tests (4) | ✅ | `CustomerEmailLocalizationTest` — 4 passed / 12 assertions |

**Follow-up (not done):** convert the remaining ~20 mailables + the trade-document PDFs to `__()`, and get professional DE/FR/ES translations. The plumbing is in place — each converted template starts working the moment its lang keys exist.

---

## Ops / CI (Session 46)

| Feature | Status | Notes |
|---------|--------|-------|
| `DEPLOY_RUNBOOK.md` | 🔧 | Audited 10-migration deploy plan (backup → pretend → migrate → cache) + eBay secret rotation steps |
| `.github/workflows/ci.yml` | ✅ | Runs migrations + full suite against **MySQL 8** on push/PR — closes the SQLite/MySQL schema-drift gap |
| Fixed stale `AdminTokenGuardTest` (CI surfaced it) | ✅ | Predated mandatory 2FA + role→`permission:admins.manage` move; full suite now green on MySQL (**88 passed**) |

---

## Customer Portal Notifications — "Email = Inbox" (Session 47)

The customer-facing twin of the admin CRM-3B feed: every transactional email a
customer receives also writes a `customer_notifications` row with the same
subject/summary, surfaced in the portal bell + `/account/notifications`.
Frontend was already built behind graceful degradation; these endpoints activate
it with no FE deploy.

| Feature | Status | Notes |
|---------|--------|-------|
| `customer_notifications` table + `customers.notification_preferences` JSON | 🔧 | Guarded/additive migration; indexes for polled unread-count + dedupe |
| `CustomerNotification` model | 🔧 | unread/visible/forCustomer scopes |
| `CustomerNotifier` service | 🔧 | notify / notifyByEmail / markRead / markAllRead / dismiss / unreadCount; dedupe (type:related:stage), email_sent_at refresh on resend, relative-URL guard, prefs + wantsEmail gating |
| 5 notification endpoints (list / unread-count / read / read-all / dismiss) | 🔧 | `auth/customer/notifications*`; scoped to self, excludes dismissed, newest first, per_page default 15 |
| 2 preference endpoints (GET/PUT) | 🔧 | `auth/customer/notification-preferences`; email_orders forced on, email_marketing opt-in |
| Trigger: account approved (`account_approved`) | 🔧 | CustomerApprovalService::sendApprovalEmail (email twin) |
| Trigger: access request approved/rejected (`access_request_update`) | 🔧 | In-app only (no email today) |
| Trigger: payment milestones (`payment_milestone`) | 🔧 | PaymentMilestoneEmailService — resolves account by order email |
| Trigger: trade doc sent (`document_ready`) | 🔧 | AdminTradeDocumentController::sendEmail |
| Trigger: proposal sent (`quote_ready`) | 🔧 | AdminProposalController::send |
| Trigger: quote received (`quote_received`) | 🔧 | QuoteRequestController acknowledgement |
| Trigger: password changed (`security_alert`) | 🔧 | CustomerAuthController::changePassword (urgent, always fresh) |
| Trigger: email verified (`welcome`) | 🔧 | CustomerAuthController::verifyEmail |
| Trigger: order placed/paid (`order_placed`) | 🔧 | PaymentController (bank-transfer `received` + Stripe `paid`) + AdminOrderController::markPaid (`paid`); stage-keyed dedupe |
| Trigger: order confirmation requested (`order_confirmation`) | 🔧 | AdminTradeDocumentController::sendAcceptanceRequest (email twin, warning) |
| Trigger: order confirmation accepted (`order_confirmed`) | 🔧 | CustomerQuoteAcceptanceController::acceptOrderConfirmation (in-app) |
| Trigger: shipped / delivered (`order_shipped`/`order_delivered`) | 🔧 | AdminOrderController::notifyShipmentStatus from both update + updateStatus (in-app; no mailable today) |
| Trigger: verification verified/rejected (`verification_update`) | 🔧 | AdminCustomerVerificationController::notifyVerificationOutcome (in-app) |
| Backend feature tests (MySQL) | ✅ | `CustomerNotificationsTest` 15 passed; **full Feature suite 103 passed / 365 assertions** after trigger wiring — no regressions |

**Remaining triggers (no source event yet):** `proposal_reminder` and
`announcement` have no existing email/job to hook onto — wire them when a proposal
reminder scheduler and an announcement broadcast are introduced, using the same
`CustomerNotifier::notify(...)` pattern. Per the contract, account-area i18n of
notification copy is a separate effort.

---

## Customer Invoices — Self-Healing Download (Session 48)

Hardened the customer-facing invoice section. Root cause: when invoice PDF
generation failed once at creation (Stripe webhook), `pdf_url` stayed null and
the customer could **never** self-serve it — the listing skipped regeneration
(invoice row already existed) and the download endpoint hard-404'd. Required a
manual `invoices:generate-missing-pdfs` CLI run by an admin.

| Fix | Status | Notes |
|-----|--------|-------|
| `InvoiceService::ensurePdf()` — single source of truth, self-healing | 🔧 | fast path → adopt canonical file → regenerate from order; repairs `pdf_url` |
| `GET /invoices/{id}/download` regenerates on demand | 🔧 | No more permanent 404 on null/missing pdf_url; 404 only when order truly gone |
| `GET /auth/invoices` self-heals released null-PDF invoices | 🔧 | `download_available` now reflects reality instead of staying false |
| `createForOrder()` PDF step now calls `ensurePdf()` | 🔧 | de-duplicated generation logic |
| Released-invoice email gets in-app twin (`document_ready`) | 🔧 | `AdminEuDeclarationController::acknowledge` — Email = Inbox |
| Order payload exposes invoice state | 🔧 | `GET /orders` + `/orders/{ref}`: `invoice_number` / `invoice_available` / `invoice_pending_release` / `invoice_download_url` (via new `Order::invoice()` relation) — lets FE show download vs "pending EU cert" |
| Compliance gate unchanged | ✅ | reverse-charge invoices still held (released_at null) until EU cert acknowledged; held invoices stay hidden from the customer list |
| Backend feature tests (13, MySQL) | ✅ | `CustomerInvoiceTest` — 13 passed; full suite 116 passed / 391 assertions |

See `FRONTEND_NOTE_invoices.md` for the frontend-facing summary + contract.

---

## Traccar GPS / Fleet Tracking (Session 49) — ❌ REMOVED in Session 52

**Removed 2026-07-03**, superseded by real carrier tracking (GLS/DHL/ocean
freight — see Session 52 below), which made this redundant. Deleted:
`TraccarService`, `DeliveryEtaService`, `GeocodingService`,
`AdminTrackingController`, all `/admin/tracking/*` routes, the
`gps_live` mode on the customer tracking endpoint, `TRACCAR_SETUP.md`,
`TraccarTrackingTest`, and the `traccar`/`nominatim` config blocks. Left
untouched (dormant, harmless): the `orders.tracking_device_id` /
`dest_lat` / `dest_lon` / `route_total_km` columns — no destructive
migration was run, so no data was lost; they're just unused now. Original
session notes below, kept for history.

Open-source GPS tracking integration — Okelcor API as a REST client of a Traccar
server (runs elsewhere; demo server for trials). Admin fleet visibility +
customer-facing per-order delivery tracking. Config-driven, graceful degradation.

| Feature | Status | Notes |
|---------|--------|-------|
| `config/services.php` traccar block | 🔧 | `TRACCAR_URL` + token (Bearer) or email/password (Basic) |
| `TraccarService` (REST client) | 🔧 | devices+positions, route, trips, geofences, status/ping; knots→km/h, m→km; `['error'=>…]` degradation |
| Admin endpoints (`tracking.view`) | 🔧 | `GET /admin/tracking/{status,devices,devices/{id},devices/{id}/route,devices/{id}/trips,geofences}` |
| Assign device to order (`orders.update`) | 🔧 | `PUT /admin/tracking/orders/{id}/device` → sets `orders.tracking_device_id` |
| Customer endpoint | 🔧 | `GET /auth/orders/{ref}/tracking` — scoped to own order, lean payload, `available:false` when none |
| `tracking.view` permission added | 🔧 | super_admin / admin / order_manager / sales_manager |
| Migration `orders.tracking_device_id` | 🔧 | guarded/additive (12th… 13th pending migration) |
| Customer tracking tied to shipment status | 🔧 | live only when order `shipped`; `delivered` state (no live route); reasons `no_device`/`not_shipped`/`order_cancelled`/`unavailable`; returns `order_status`+`delivered` |
| Customer trail = current trip | 🔧 | `currentTripRoute()` bounds route to latest trip start, capped at `TRACCAR_ROUTE_HOURS` (default 12) |
| Admin order payload exposes `tracking_device_id` | 🔧 | links order ↔ fleet device |
| "Track it live" on shipped notification | 🔧 | `order_shipped` notification gains live-tracking copy + `metadata.live_tracking` when a device is assigned |
| Delivery ETA + progress | 🔧 | `eta` block in customer tracking: arrival timestamp, minutes/distance remaining, `progress_percent`. Straight-line (haversine × road factor ÷ recent avg speed). `GeocodingService` (OSM Nominatim, cached) resolves destination; `DeliveryEtaService` computes. New `orders.dest_lat/dest_lon/route_total_km` |
| Admin set-destination override | 🔧 | `PUT /admin/tracking/orders/{id}/device` sibling: `…/destination` accepts a `{lat,lon}` pin or `{address}` (geocoded, 422 if not found) or `{}` to clear; resets `route_total_km` baseline. For sparse addresses where auto-geocode fails. `dest_lat/dest_lon` on admin order payload |
| Carrier type `bus` → `truck` | 🔧 | enum migration (data-safe) + validation + PDF labels ("Truck Freight"); Okelcor runs no bus freight |
| Backend feature tests (23, MySQL) | ✅ | `TraccarTrackingTest` (Http::fake) — 23 passed; full suite 139 passed / 457 assertions |

Setup: `TRACCAR_SETUP.md`. Frontend: `FRONTEND_NOTE_tracking.md`. Distinct from
the freight tracking (DHL + ShipsGo `GET /tracking/{container}`), which stays.

---

## Marketing Contacts & Bulk Email (Session 50)

Order manager needed to (1) import the contact database dropped in the repo
root (`contacts.csv`, Wix export, ~1,720 valid-email rows) and (2) send bulk
marketing emails to that list. New, separate from `customers` (no login
account created) and from `contact_messages` (contact-form inbox).

| Feature | Status | Notes |
|---------|--------|-------|
| `marketing_contacts` table | ✅ | email/name/phone/company/country/vat_id/labels/source + `status` (subscribed/unsubscribed/unknown) + `unsubscribe_token` |
| `MarketingContactImportService` | ✅ | Same Wix CSV column layout as `WixCustomerImportService`; upserts by email; re-import can never silently flip an `unsubscribed` contact back to subscribed |
| `POST /admin/marketing-contacts/import` | ✅ | `marketing.manage` (super_admin/admin/order_manager); multipart CSV upload, same shape as the existing customer import endpoint |
| `GET /admin/marketing-contacts` (+ `/stats`, `DELETE /{id}`) | ✅ | Filters: status/company/country/search |
| `bulk_email_campaigns` + `bulk_email_campaign_recipients` tables | ✅ | Recipient list is snapshotted at send time; per-recipient sent/failed status so a queue retry never double-emails anyone |
| `GET/POST /admin/bulk-emails`, `GET /{id}`, `GET /recipient-count` | ✅ | `marketing.manage`; body_html run through the existing `ArticleHtmlSanitizer` (strips script/style/event handlers); `recipient-count` lets the UI preview audience size before sending |
| `SendBulkEmailCampaignJob` (queued) | ✅ | Resumable — only processes `pending` recipient rows; 150ms pacing between sends; unsubscribed contacts are hard-excluded, not just filtered |
| `BulkCampaignEmail` mailable + unsubscribe footer link | ✅ | `GET /marketing-contacts/unsubscribe/{token}` — public, token-based, same pattern as newsletter confirm |
| `marketing.manage` permission | ✅ | super_admin / admin / order_manager |
| Backend feature tests (8) | ✅ | `BulkEmailCampaignTest` — import/dedupe, unsubscribe-never-resubscribed, permission gating, sanitization, resumable send job, unsubscribe endpoint |

Deployed to production (migrations #16–18 applied).

**⚠️ Production requirement:** `.env` currently has `QUEUE_CONNECTION=sync`,
which means `SendBulkEmailCampaignJob` would run **inline during the HTTP
request** — sending ~1,700 emails synchronously will time out. Before using
this in production, set `QUEUE_CONNECTION=database` and run a persistent
worker (`php artisan queue:work`, under Supervisor) so campaign sends happen
in the background. Nothing else needs to change — the job is already written
to be queue-driver agnostic.

See `FRONTEND_NOTE_bulk-email.md` for the frontend-facing contract.

---

## Media Library ↔ Article Writer Integration (Session 51)

Goal: while writing an article, an editor should be able to browse the
existing Media Library and reuse/copy an image's URL instead of only being
able to upload a brand-new file. The Media Library API already existed
(`GET/POST/DELETE /admin/media`) but was an isolated bucket — none of the
content-specific upload endpoints (article cover/OG/body, hero slides,
brand logos, promotions) wrote into it, so nothing uploaded while writing
content ever became browsable/reusable from the Media panel.

| Feature | Status | Notes |
|---------|--------|-------|
| `MediaLibraryService` (new, shared) | ✅ | Extracted the upload/resize/store logic out of `MediaController::store` so any upload flow can register a `Media` row the same way |
| `POST /admin/articles/{id}/body-image` now registers in Media Library | ✅ | Collection `articles`; response gains `media_id` alongside existing `url`/`path` — this is the "while writing articles" moment the ask was about |
| **Bug fix** — `Image::read()` / `->toJpeg()` calls | ✅ | `intervention/image` is pinned to **v4.0.0** in `composer.lock`, which removed both methods (`read` → `decode`, `toJpeg` → `encode(new JpegEncoder(...))`). This was already broken in production for the existing `POST /admin/media` upload endpoint — silently, since there was no test coverage before this session. Fixed in the shared service; both upload paths now use the correct v4 API. |
| **Bug fix** — `Media.created_at` not Carbon-cast | ✅ | `Media` sets `$timestamps = false`, so Eloquent's automatic date casting (`getDates()`) never applied to `created_at` — `MediaController::formatMedia()`'s `$m->created_at?->toIso8601String()` would fatal on any real row. Added explicit `'created_at' => 'datetime'` cast. Also latent/pre-existing, also uncovered before this session. |
| Backend feature tests (5) | ✅ | `MediaLibraryTest` — upload/list/delete round trip, permission gating, article body-image → Media Library integration |

Cover image and OG image uploads (`uploadImage`/`uploadOgImage`) were left as
direct per-article uploads (not registered in the Media Library) — those are
1:1 canonical assets replaced on re-upload, not something an editor browses
and reuses across articles, so wiring them in wasn't part of this ask.

Deployed to production — no migrations in this session (code-only fix).

See `FRONTEND_NOTE_media-library.md` for the frontend-facing contract.

---

## Proposal→PI Friction Fix + Real Carrier Tracking (Session 52)

Driven by a call with order manager Edinah Agalla (2026-07-02): (1) requiring
a separate Order-Confirmation acceptance after the customer already accepted
the Proposal was redundant friction; (2) she has to log into eBay/GLS
separately to see shipment status that should live in Okelcor's own admin
panel and customer portal — for eBay orders and directly-onboarded customers
alike.

| Feature | Status | Notes |
|---------|--------|-------|
| **Fix** — Commercial Invoice hidden from customer until fully paid | 🔧 | `Order::isFullyPaid()` (new); gates `TradeDocumentController` (list + download) and `OrderController`'s `trade_documents` payload. Previously visible/downloadable as soon as issued (only needed `deposit_paid` to generate) — contradicted what was promised on the call. Admin visibility unchanged. |
| Proposal→PI: Order Confirmation acceptance no longer mandatory | 🔧 | For CRM-7 proposal-driven orders (`quote_requests.proposal_accepted_at` set), `AdminTradeDocumentController::generateProforma()` now skips the OC-acceptance gate — proposal acceptance alone unlocks PI generation. Customer `trade_documents` visibility relaxed the same way. Direct/manual orders (no proposal history) keep the original gate unchanged. OC document itself still auto-generates and remains available, just isn't a hard prerequisite anymore. |
| `GlsTrackingService` (new) | ✅ | GLS parcel Track & Trace client — Track And Trace API v1 (GLS Group Developer Portal). App ID + API Key + API Secret issued together per registered app — no separate "customer ID". **Live and verified** — `GET /tracking/simple/trackids/{unitno}?showEvents=true`, OAuth2 client-credentials auth. Real credentials confirmed working via direct `curl` from production; end-to-end `tinker` test against a real order returned 9 real tracking events. Degrades cleanly to `['error' => ...]` if ever unconfigured, same pattern as `TraccarService`/`DhlTrackingService`. |
| `CarrierTrackingService` (new) | ✅ | Routes an order to GLS / DHL (`DhlTrackingService`, reused) / ocean freight (`ShipsGoService`, reused — aggregates multiple lines incl. Maersk) by `carrier` name / `carrier_type` / `container_number`; normalizes to `{carrier, tracking_number, stage, tracking_url, events[]}`; persists events into the existing `order_shipment_events` table (deduped) so the admin's manual timeline and auto-synced data share one source of truth, and `orders.tracking_status` stays current. Designed to never hard-fail: even if a carrier API were ever down/unconfigured, `events` just stays whatever's already persisted while `tracking_url`/`stage` remain available — only errors when the order has no carrier/tracking info at all. All three carriers (GLS, DHL, ocean freight) now confirmed live. |
| `tracking_url` — public carrier tracking page deep link (new) | 🔧 | Zero-credential fallback: `CarrierTrackingService::publicTrackingUrl()` builds a link to GLS/DHL/Maersk's own public tracking page from `carrier` + `tracking_number`/`container_number` — no API call, always works once those two fields are set. Directly answers "what if we don't know the process yet" — this is the zero-effort layer beneath both auto-sync and manual event entry. |
| eBay carrier/tracking auto-backfill (new) | 🔧 | `EbaySellingService::fetchShippingFulfillments()` (new) + `EbayOrderSyncService::enrichCarrierFromEbay()` (new, private) — on the existing hourly `ebay:sync-orders` job, pulls `shippingCarrierCode`/`shipmentTrackingNumber` from eBay's own shipping-fulfillment record (whatever was used to mark the order shipped, whether via our system or manually in eBay's Seller Hub) and backfills `orders.carrier`/`tracking_number` **only if not already set** — never overrides a manual entry. Runs only when eBay reports the order as shipped/delivered. No new cron job. |
| `GET /admin/orders/{id}/shipment-tracking` (new) | 🔧 | `tracking.view` permission (reused from the Traccar fleet endpoints). Attempts a live carrier-API call + persists new events, but — per the redesign above — always returns a usable response (incl. `tracking_url`) even when the live call fails; 503 only when the order has no carrier/tracking number at all. Works for **eBay-sourced orders too**. |
| `GET /auth/orders/{ref}/tracking` extended with `mode` | ✅ | Originally added a `mode: "carrier"` branch alongside Traccar's `gps_live`; once Traccar was removed (below) this became the only mode. Reads the persisted timeline (no live call on page view) + always includes `tracking_url`. `available:false` reasons unchanged. |
| **Removal** — Traccar GPS/fleet tracking | ✅ | Deleted entirely (`TraccarService`, `DeliveryEtaService`, `GeocodingService`, `AdminTrackingController`, all `/admin/tracking/*` routes, `gps_live` mode, `TRACCAR_SETUP.md`, `TraccarTrackingTest`) — see the Session 49 entry above for full detail. Carrier tracking (GLS/DHL/ocean freight) made it redundant. DB columns left dormant, untouched. |
| `tracking:sync-carriers` command (new) | 🔧 | Hourly (`routes/console.php`, same pattern as `admin:notifications:due-followups`) — syncs shipped orders with a carrier+tracking number and no fleet device, keeping the persisted timeline fresh without a live call per page view. |
| Backend feature tests (18, MySQL) | ✅ | `ProposalToProformaGateTest` (6 tests) + `CarrierTrackingTest` (12 tests, incl. `tracking_url` generation + graceful-degradation behavior) — GitHub Actions CI (real MySQL 8) caught 3 real bugs the local sqlite bootstrap check couldn't: an invalid `role='support'` in a test (see role/ENUM gap below), a missing `NOT NULL` `notes` field in a `QuoteRequest` test helper, and an FK-drop ordering issue in `MediaLibraryTest`/`BulkEmailCampaignTest` (both wrapped in `disableForeignKeyConstraints()`/`enableForeignKeyConstraints()` — the actual root-cause fix, robust regardless of test execution order). All fixed and green. `enrichCarrierFromEbay()` has no test coverage yet (needs eBay OAuth + fulfillment endpoint mocking — deferred given session length; low risk, mirrors the existing, working `fetchOrder()` pattern exactly). |
| **Found via CI** — `admin_users.role` ENUM doesn't match documented roles | 🔧 | The DB column is a MySQL ENUM allowing only `super_admin/admin/editor/order_manager`, but `AdminPermissions.php` (and this doc's own role list) references `sales_manager`/`support`/`content_manager`/`viewer` throughout — those roles can't actually be stored under MySQL strict mode. Pre-existing, unrelated to this session's feature work; not fixed here (needs its own migration + audit of every affected admin account). See Known Gaps. |
| **Fix** — eBay multi-quantity line items showed the line total as the per-item price | 🔧 | eBay's `lineItemCost` is documented as `unit price × quantity` (i.e. already the line total), but `EbayOrderSyncService::importOrder()` treated it as per-unit and multiplied by quantity again — e.g. 2 items at a true €75.14 each showed as "€150.28 each." Only affected lines with quantity > 1 (confirmed against a real order). Fixed for new imports; new `php artisan ebay:audit-line-item-pricing` command (report-only by default, `--apply` to write) finds/corrects historical orders — only touches items where the order's eBay-sourced `subtotal` doesn't match the summed line items, so already-correct data is untouched. Order-level `total`/`subtotal` were never wrong (computed independently from eBay's `pricingSummary`). |
| eBay tracking-event richness — confirmed not available via API, moot now anyway | ✅ | Checked eBay's Fulfillment API docs directly: sellers can only pull `shippingCarrierCode`/`trackingNumber`/ship date (already built), not the detailed per-event history eBay shows buyers — that's eBay's internal carrier integration, not exposed via API. Doesn't matter in practice: now that GLS is live (see below), the events we show for a GLS-carried eBay order are the same events eBay itself is showing — both read from GLS directly. |
| Backend feature tests (5, new) | 🔧 | `EbayOrderPricingTest` — multi-qty division via `importOrder()` (reflection, no OAuth mocking needed since it's pure data transform), single-qty unaffected, and 3 tests for the audit command (dry-run doesn't write, `--apply` corrects, already-correct orders untouched). Not run against real MySQL in this session — see caveat above. |

**GLS — ✅ live and verified end-to-end (2026-07-03).** `ShipIT-Farm API v1` /
`parceldetails` (what we'd wired in first) turned out to be a dead end — its
response only contains ParcelShop pickup-location details, not shipment
status. Found the actual product in the portal: **Track And Trace V1**,
`GET /tracking/simple/trackids/{unitno}?showEvents=true`, with a fully
documented `EventDTO` response schema (`code`, `city`, `postalCode`,
`country`, `description`, `eventDateTime`) — confirmed from GLS's own
published docs, not guessed.

Root cause of the persistent `400 invalid credentials`: a stray `_KEY` suffix
had been copy-pasted onto the end of the actual API key value in production
`.env` — not a code or environment-mismatch issue at all. Confirmed via a
direct `curl` from the server once trimmed: real `access_token` returned.

Verified live via `tinker` against a real order (`OKL-C06OT`, eBay-sourced,
tracking number `50044195855` — the same parcel from Edinah's original
screenshots): **9 real events returned**, wording matching what eBay itself
was showing ("The parcel was handed over to GLS," "provided by the sender
for collection," etc.) — this is genuinely live tracking data, not sandbox
dummy data, even though both endpoints are on the `api-sandbox.gls-group.net`
host (tracking lookups appear to be live regardless of environment; only
shipment-creation-type operations would likely differ — not something this
integration does). `location` comes back `null` on every event in practice
(GLS isn't populating `city`/`postalCode` at the event level for this
account) — handled gracefully, not a bug.

Events auto-persist into `order_shipment_events` on every call (admin
"live sync" endpoint, and the hourly `tracking:sync-carriers` job for
shipped orders). DHL and ocean-freight (incl. Maersk) tracking are also
live — all three carrier integrations are now fully working.

**Decision (2026-07-02, post-deploy):** GLS's token exchange kept returning
`400 invalid credentials` even after correcting the `.env` values, and
debugging stalled on a production logging oddity (fresh calls — confirmed via
direct `tinker` invocation, bypassing any HTTP/CDN caching — wrote no new log
line to `storage/logs/laravel.log`, implying `LOG_CHANNEL` writes elsewhere in
production, e.g. a daily-rotated file). Rather than keep burning time on GLS
credentials/logging, **`CarrierTrackingService` was redesigned around three
independent, non-blocking layers** instead of an all-or-nothing live API:
1. `tracking_url` — a zero-credential deep link to the carrier's own public
   tracking page, present the moment `carrier` + `tracking_number` are set.
   No API, no manual work, always works for GLS/DHL/Maersk.
2. Automatic live sync for carriers with working credentials (DHL, ocean
   freight/Maersk via ShipsGo) — unchanged, already live.
3. Manual shipment-event entry (pre-existing `POST/PUT/DELETE
   /admin/orders/{id}/shipment-events` endpoints, previously undocumented to
   frontend) — optional richer history on top of layer 1, not a prerequisite.

For eBay orders specifically, `carrier`/`tracking_number` now auto-backfill
from eBay's own shipping-fulfillment record on the existing hourly
`ebay:sync-orders` job — so even layer 1 requires no manual typing for eBay
orders. `GlsTrackingService` remains in place, dormant until its credentials
are sorted — no removal needed, and DHL/ocean auto-sync continues to run
unaffected for orders using those carriers.

See `FRONTEND_NOTE_tracking.md` (new sections) for the frontend-facing contract.

---

## Signed document return (Proposal + Proforma) + payment-gated documents (Session 53)

Order manager's ask, across two calls: (1) without a signed copy on file, a
customer could dispute having agreed to a proposal or proforma's price/terms
— nothing on either document (or in the system) captured their acceptance;
this needs to work at **both** stages, not just the Proforma. (2) documents
that only make sense once the balance is paid (per Okelcor's stated terms —
"balance against bill of lading") shouldn't be visible before that point,
same rule as the Commercial Invoice already had.

| Feature | Status | Notes |
|---------|--------|-------|
| Signature block on Proforma Invoice PDF | ✅ | Date / Signature / Company Stamp boxes added to `resources/views/pdf/proforma-invoice.blade.php`, positioned after the bank/payment-reference section and before the "not a final tax invoice" disclaimer. Reuses the existing `.sig-table`/`.sig-box` styles already used on commercial-invoice/delivery-note/packing-list — no new CSS. |
| `POST /auth/orders/{ref}/proforma/signed-copy` (new) | ✅ | Customer uploads a scan/photo of the printed-and-signed proforma (pdf/jpg/jpeg/png, max 20MB). Requires an issued `proforma` document to exist first (422 `no_proforma` otherwise); same `approved_for_documents` (CRM-4) gate as the rest of trade-documents. Re-uploading supersedes the previous signed copy — always at most one current one. Does **not** change order status — evidentiary only. Reuses the existing `TradeDocument` model/storage pattern (same as admin's shipment-document upload) — no schema change, `type`/`status` were already plain strings, not ENUMs. |
| New `TradeDocument` type: `proforma_signed` | ✅ | Shows up in the existing customer `trade_documents` list and downloads via the existing generic download endpoint — no new admin code needed; `AdminTradeDocumentController::indexForOrder`/`download` are already type-agnostic. |
| Admin notification on signed proforma return | ✅ | `orders.update` permission fan-out, `proforma_signed_returned` type. Customer also gets an in-app confirmation twin (`CustomerNotifier`). `OrderLog` entry recorded. |
| Signature block on Proposal PDF | ✅ | Same Date/Signature/Company Stamp treatment added to `resources/views/pdf/proposal.blade.php`. Acceptance paragraph updated to mention both the digital link and the print-sign-upload path. |
| `POST /auth/quotes/{ref}/proposal/signed-copy` (new) | ✅ | Alternative to the digital `accept-proposal` click — **uploading a signed copy IS an acceptance**, same effect as `acceptProposal()` (`proposal_status` → `accepted`, timeline entry, admin notification). Same guards reused (active proposal required, not expired, not already accepted). New nullable columns on `quote_requests` (`proposal_signed_copy_path`/`_original_filename`/`_mime_type`/`_uploaded_at`) via a guarded/additive migration — proposals predate an `Order`/`TradeDocument`, so this couldn't reuse the `TradeDocument` table the way the Proforma flow did. |
| Admin visibility for signed proposal | ✅ | `AdminQuoteRequestController` quote-detail payload gains `proposal_signed_copy_uploaded_at`/`_filename`/`_download_url`; new `GET /admin/quote-requests/{id}/proposal/signed-copy/download` (mirrors the existing proposal-PDF download pattern). |
| **Fix** — payment-gated documents expanded beyond Commercial Invoice | ✅ | `TradeDocumentController` (customer list + download) and `OrderController`'s duplicated `trade_documents` filter both now gate `packing_list`, `delivery_note`, and `shipment_document` behind `Order::isFullyPaid()` — previously only `commercial_invoice` was gated, so a customer could see/download Bills of Lading etc. before paying the balance, contradicting the "balance against bill of lading" terms already stated on the Proposal/Proforma PDFs. |
| Backend feature tests (12, MySQL, written not yet executed) | 🔧 | `SignedProformaUploadTest` (8 tests, incl. the new payment-gate coverage for packing_list/delivery_note) + `SignedProposalUploadTest` (4 tests). Not run against real MySQL in this session — same local environment limitation as prior sessions; verify before deploying. |

See `FRONTEND_NOTE_proforma-signature.md` for the frontend-facing contract.

---

## CRM-8 audit — Tier / Verification / Health (Session 54)

Order manager asked for a review of the customer-approval Tier/Verification/
Health section from CRM-8 (Session 40) to confirm it actually works, rather
than trusting the ✅ in this doc. Real gaps found — same pattern as this
session's other audits (CI catching bugs, the eBay pricing bug):

| Finding | Status | Notes |
|---------|--------|-------|
| **Bug** — health score never recalculated on the events it scores | ✅ fixed | `CustomerHealthService::recalculateAndSave()` only ever fired from a verification change or the manual admin "recalculate" click — never when an order is paid (`completedOrderCount`, worth up to +40) or a proposal is accepted (`hasAcceptedProposal`, +20). Scores/risk bands went stale immediately after initial approval. New `recalculateForEmail()` convenience method (orders link to customers by email, not a FK) wired into `AdminOrderController::markPaid`, `PaymentController::markOrderPaid` (Stripe webhook), and both `CustomerQuoteAcceptanceController::acceptProposal()`/`uploadSignedProposal()`. Best-effort/never blocks the caller's real work. |
| **Bug** — verification roll-up let `verified` mask `rejected` | ✅ fixed | `rollUpVerificationStatus()` prioritized *any* verified record over a rejected one — e.g. a verified company registration hid a rejected VAT check, both showing overall `verified` and silently earning health-score points for it. Priority reordered: rejected > pending_review > verified. |
| **Gap** — Tier is purely decorative | 🔲 needs a decision | `buyer_tier` (bronze/silver/gold/platinum/vip) is set via approval profile / manual override, but nothing in the codebase reads it to affect pricing, credit terms, priority, or any other behavior — it only ever appears in API responses. Not fixed — needs a business decision on what tier should actually *do* before building it (see PROGRESS.md follow-up note below). |
| **Gap** — no customer self-service verification submission | 🔲 needs a decision | `customer_verifications` CRUD is 100% admin-only (`AdminCustomerVerificationController`) — there's no customer-portal endpoint for a buyer to submit their own VAT number/company registration/website for review. Every verification requires an admin to manually type it in after receiving it some other way (email/phone). VAT verification specifically *is* automatic (VIES check on registration/profile update, separate from this table) — the gap is the other four types. Not fixed — bigger build, needs scope confirmation. |
| Risk/health remain informational, not gating | 🔲 flagged | `risk_level` is only ever displayed/counted (system health dashboard, admin filter) — never used to block or flag an action (e.g. hold checkout for a critical-risk buyer). Not necessarily wrong — may be intentional — flagged for the order manager to confirm rather than changed unilaterally. |
| Backend feature tests (5 new, MySQL, written not yet executed) | 🔧 | Added to `Crm8BuyerLifecycleTest`: `recalculateForEmail` (match + no-match), proposal-acceptance auto-triggers recalculation, verification roll-up priority fix. Not run against real MySQL in this session — verify before deploying. |

**Still open — needs the order manager's input:**
1. What should `buyer_tier` actually control? (pricing/discount modifier, credit/deposit terms, priority support, checkout limits, or something else)
2. Should customers be able to self-submit verification info through the portal, or should it stay admin-entry-only?
3. Should `risk_level`/health ever gate a real action (e.g. flag critical-risk buyers for extra review before checkout), or stay purely informational?

---

## DPD tracking gap (Session 55)

Order manager reported `DPD · 06265020852310` showed no tracking events at
all in the shipping overview. Root cause: DPD was never added as a
recognized carrier in `CarrierTrackingService` — unlike GLS/DHL/ocean
freight, there was no branch for it in either the live-sync `fetch()` or the
zero-credential `publicTrackingUrl()` fallback, so a DPD order got nothing:
no events, no tracking link.

| Fix | Status | Notes |
|-----|--------|-------|
| DPD public tracking URL (Layer 1 only, by design decision) | ✅ | `CarrierTrackingService::publicTrackingUrl()` now recognizes `carrier` containing "dpd" → `https://tracking.dpd.de/status/en_US/parcel/{trackingNumber}`. Zero-credential, same pattern as the GLS/DHL/Maersk deep links. Fixes the immediate complaint — a working "Track it" link now always appears for DPD orders. |
| Live DPD event auto-sync | ⬜ not built (explicit scope decision) | Would need a `DpdTrackingService` (like `GlsTrackingService`) plus a registered DPD business API account + credentials — none exist today. Deferred; revisit if the order manager wants full per-event history like GLS/DHL show. |
| Test | ✅ | Added a DPD case to `CarrierTrackingTest::test_public_tracking_url_per_carrier` — not run locally (this suite requires MySQL, same limitation noted in every prior session); relies on CI. |
| Deployed to production | ✅ | Code-only change (no migration) — deployed via the standard Namecheap cPanel path (`/home/u978121777/domains/okelcor.com/public_html/okelcor-api`), no `artisan migrate` needed. |
| `FRONTEND_NOTE_tracking.md` updated | ✅ | Corrected the doc's claim that only GLS/DHL/Maersk get a `tracking_url` — DPD now does too. No frontend code change needed (existing "render `tracking_url` if present" logic already covers it). |

**Decision (2026-07-06):** confirmed with the user to leave DPD at Layer 1 for
now rather than chase Layer 2 immediately. DPD's API isn't self-serve like
GLS's sandbox was — it requires an existing DPD business shipping contract
and a request to DPD's own account manager for tracking-API credentials
(confirmed via DPD's public carrier-integration docs; DPD's own site blocks
automated fetching, so exact field names weren't independently verified).
**Next step, whenever revisited:** order manager (or whoever holds the DPD
shipping contract) contacts DPD's account manager, asks specifically for
**Track & Trace / tracking API access** (not shipment/label API), and passes
along whatever credentials + docs DPD issues — then build `DpdTrackingService`
the same way `GlsTrackingService` was built (Session 52).

---

## Admin customer editing + historical order onboarding (Session 56)

Order manager needed to (1) correct a customer's own record (typo'd name/
e-mail, outdated VAT) — the existing admin `PATCH /admin/customers/{id}`
only allowed `admin_notes`/`customer_type`/`company_name`/`phone`/`country`;
and (2) onboard existing Okelcor customers who already have real
orders/shipments (some still in transit) that predate the system, with their
actual documents (already sent via WhatsApp/e-mail) attached — not
system-generated stand-ins.

| Feature | Status | Notes |
|---------|--------|-------|
| `PATCH /admin/customers/{id}` expanded | ✅ | Now accepts `first_name`/`last_name`/`email` (uniqueness-checked) / `vat_number` / `vat_verified` / `industry`. Changing `vat_number` without confirming it resets `vat_verified` to `false`. Every save writes a plain-language diff to the security audit log **and** the CRM-8 customer timeline (`profile_corrected`). |
| `POST /admin/orders` (new) | ✅ | Manually records an order that already happened — customer by `customer_id` or raw name+email, optional custom `ref`/`order_date` for backdating, items or a flat `total`. A paid order defaults `payment_stage` to `balance_paid` so document upload/visibility isn't blocked for something already settled; still-in-transit orders can set an earlier stage explicitly. Orders link to customers by e-mail (not FK), so the new order is visible in the customer's portal immediately, no linking step. |
| Document upload guidance | ✅ (doc-only) | Existing `POST /admin/orders/{id}/trade-documents/upload` is the right tool — frontend note explicit that historical orders should **upload the real file**, not use the `generate…` endpoints (those build a new PDF from system data). Confirmed with the user: the existing payment-gate (documents hidden until the order is fully paid) stays as-is even for historical backfills — not overridden. |
| Backend feature tests (11, MySQL, written not yet executed) | 🔧 | `AdminOrderCreationTest` (5) + 3 new cases in `Crm8BuyerLifecycleTest` — not run against real MySQL this session (local `.env` points at what looks like a shared/production-style database); relies on CI, same limitation noted in every prior session. |

See `FRONTEND_NOTE_admin-customer-editing.md` and
`FRONTEND_NOTE_historical-orders-onboarding.md`.

---

## Outlook-style compose/reply, signatures, customer messaging (Session 57)

Ask: replicate "compose and reply like Outlook, inside our own system" —
rich-formatted e-mail, a saved signature pasted in once (incl. inline logo)
and auto-appended forever after, attachments, CC, and two-way visibility so
a reply is never lost if the original sender is out. Extends the existing
CRM-6 communication log rather than a new system.

| Feature | Status | Notes |
|---------|--------|-------|
| `RichEmailHtmlSanitizer` (new, shared) | ✅ | Strips Word/Outlook namespace tags (`<o:p>` etc.) before parsing; extracts inline `data:image/...;base64` images to real files on public storage (rewriting `src`) **before** the HTMLPurifier allow-list pass, not after — stricter than the literal spec order, since the purifier never has to trust a `data:` URI at all. Corrupt/oversized/non-image payloads are dropped, not stored broken. Fully automated-tested (11 tests, no DB — actually executed this session, not just written): script/style/iframe stripped, `on*` handlers stripped, `javascript:` URLs stripped, CSS `expression()` stripped, unknown tags unwrapped (content kept), Word namespace tags stripped, valid/corrupt/external images handled correctly. |
| `admin_users.email_signature` (LONGTEXT) + `PUT /admin/profile/signature` | ✅ | Own signature only, no extra permission. Sanitized + images extracted before save; response echoes the stored (sanitized) version. Appended fresh at send time from the DB, never baked into a draft. |
| `customer_communications` extended (`cc`/`attachments`/`channel`/`message_id`/`in_reply_to`/`staff_read_at`/`customer_read_at`; `body` widened TEXT→LONGTEXT) | ✅ | Additive/guarded migration on the existing CRM-6 table — the manual "log an interaction" flow keeps working unchanged. `channel`/other new columns are plain strings, not ENUMs, deliberately (see the `admin_users.role` ENUM gap elsewhere in this doc). |
| `POST /admin/{customers,quote-requests}/{id}/communications/send-email` (new) | ✅ | Real compose/send — subject/body(sanitized)/cc (max 5)/attachments (max 5, 10MB each, mime allow-list)/`in_reply_to_id`. Threading: resolves the parent's `message_id` for real `In-Reply-To`/`References` e-mail headers, prefixes `Re:` on the subject. Reply-To set to the sending admin's own address. Always logs the communication (sent or failed) so nothing is lost on a send failure; failed sends return 502 with the logged row attached. Customer also gets an in-app notification twin (`message_received`), matching the existing "Email = Inbox" pattern. |
| `CustomerAdHocEmail` mailable + `GET .../communications/{id}/attachments/{index}/download` | ✅ | Attachments stored on private disk before the send attempt (survive a failed send); admin can re-download anything previously sent. |
| Customer portal — `GET/POST /auth/customer/communications*` (new) | ✅ | Own thread only (`type=email` rows), reply (plain body, no attachments in v1 — deliberate scope line), mark-read, attachment download. A reply fans out to every `crm.view` admin immediately (CRM-3B notification), not just the original sender — the actual "nothing gets lost" mechanism. |
| **Scope decision, not a gap** — real inbound e-mail capture | ⬜ deferred | A customer replying inside their own Outlook/Gmail does **not** land back in the system — that needs a receiving subdomain + MX + webhook, materially more infrastructure. Two-way visibility is solved via the customer's own portal instead. Documented explicitly in the frontend note so this isn't assumed to work. |
| Backend feature tests (12, MySQL, written not yet executed) | 🔧 | `OutlookStyleEmailTest` — signature save, compose/send, threading, CC/attachment validation, permission gating, portal reply + cross-customer isolation, read receipts. Same MySQL-only limitation as every other session; confirmed to skip cleanly (not fail) under the default sqlite test env. |

See `FRONTEND_NOTE_outlook-style-email.md`.

---

## WhatsApp Business API integration (Session 58)

Ask: integrate WhatsApp Business (Meta Cloud API) across sales,
communication, and data insights. Deliberately reuses the exact
infrastructure just built for Outlook-style e-mail rather than a parallel
system — `customer_communications` already had `type: 'whatsapp'` as a
valid (unused) enum value since CRM-6, and already had `channel`/
`attachments`/`staff_read_at`/`customer_read_at` from the e-mail work, so
only WhatsApp-specific columns were new.

| Feature | Status | Notes |
|---------|--------|-------|
| `WhatsAppService` (new) | ✅ | Meta Graph API client — `sendText` (24h customer-service-window only), `sendTemplate` (business-initiated, needs a Meta-approved template), `sendDocument`. Degrades cleanly (`['error' => ...]`) same as GlsTrackingService/DhlTrackingService; never throws. Fully automated-tested (9 tests, `Http::fake()`, no DB — actually run, not just written): payload shape, auth header, phone normalization, error handling, 24h-window helper. |
| `WhatsAppWebhookController` (new) — `GET/POST /webhooks/whatsapp` | ✅ | Verification handshake + inbound message/status events. POST protected by verifying Meta's `X-Hub-Signature-256` HMAC against the App Secret — same security boundary already applied to the Stripe webhook. De-dupes on Meta's own message id (webhook retries). |
| WhatsApp → lead capture (new) | ✅ | A first-time inbound message with no matching customer/quote auto-creates a `QuoteRequest` (`lead_source: 'whatsapp'`) through the same CRM-2 quality-scoring + CRM-3B notification path the website/landing forms use — not a separate silo. `quote_requests.email` is NOT NULL and WhatsApp gives no e-mail ever, so a deterministic synthetic placeholder (`whatsapp+{phone}@no-email.okelcor.internal`) is used rather than loosening that constraint app-wide. |
| Admin compose/send — `POST /admin/{customers,quote-requests}/{id}/communications/send-whatsapp` (new) | ✅ | Free-form text only, mirrors the e-mail compose endpoint's structure/permission/audit-log conventions exactly. Surfaces the 24h-window rejection from Meta as-is rather than a generic error. |
| `WhatsAppNotifier` (new) — template-based automated notifications | ✅ | Opt-in gated (`CustomerNotifier::wantsWhatsApp()`, default **off** — Meta requires explicit consent for business-initiated messages, unlike e-mail). Small hardcoded template registry (`order_shipped`, `order_delivered`, `payment_reminder`, `proposal_ready`, `quote_ready`) — only `order_shipped`/`order_delivered` actually wired into a live trigger (`AdminOrderController::notifyShipmentStatus`) as a concrete working example; the rest are no-ops until their Meta template is approved (see `WHATSAPP_SETUP.md`) and wired the same one-line way. Not wired into all ~15 `CustomerNotifier` trigger points on purpose — no value calling a template that doesn't exist yet. |
| `customer_communications` extended (`phone_number`, `whatsapp_message_id` unique, `whatsapp_status`, `whatsapp_template_name`) | ✅ | Additive/guarded migration on the same table extended for e-mail. Plain strings, not ENUMs, deliberately. |
| `Customer.notification_preferences` gains `whatsapp_enabled` | ✅ | Defaults `false` (opt-in required, unlike `email_*` keys which default on). `PUT /auth/customer/notification-preferences` accepts it. |
| Data insights — Lead Funnel Analytics | ✅ (no code change) | `AdminLeadFunnelController` already groups by `lead_source` generically — confirmed WhatsApp leads break down automatically, zero extra backend work. |
| **Scope decision, not a gap** — no template CRUD, no catalog/commerce, no interactive buttons/flows, no per-category WhatsApp preference granularity, no document-send admin endpoint (service method exists, unwired) | ⬜ deferred | All explicitly out of v1 scope — documented in `FRONTEND_NOTE_whatsapp-integration.md` and `WHATSAPP_SETUP.md` rather than half-built. |
| Backend feature tests (13, MySQL, written not yet executed) | 🔧 | `WhatsAppIntegrationTest` — webhook signature verification, verification handshake, lead capture, existing-contact logging, duplicate-webhook de-dupe, status updates, admin send, opt-in gating. Confirmed to skip cleanly under the default sqlite env; same MySQL-only limitation as every other session. |

**Found and fixed while building the phone-matching logic:** the initial
`LIKE` match against stored `phone`/`quote_requests.phone` values only
stripped punctuation from the *inbound* WhatsApp number, not the *stored*
one — a customer phone saved as `"+233 24 123 4567"` would never match
`"233241234567"` from the webhook, since the embedded spaces break a plain
substring search. Fixed by stripping the same characters from the stored
column inside the query too. Caught while writing the test for this exact
case, before it shipped.

See `WHATSAPP_SETUP.md` (account-side Meta setup — required before anything
sends/receives for real) and `FRONTEND_NOTE_whatsapp-integration.md`.

---

## Inbound e-mail capture (Session 59)

Order manager: after the Outlook-style composer shipped, customer replies
were only landing in the individual sending admin's personal Outlook — never
reflected in the system. This was the deliberately-deferred piece from that
feature (documented at the time as "not built — needs a receiving subdomain
+ MX + webhook, a materially bigger phase") — which, after two other designs
didn't pan out, is exactly what got built in the end.

**Three designs tried in this session, in order:**

1. **Plain IMAP directly against `support@okelcor.com`** (`webklex/php-imap`)
   — then it turned out `support@okelcor.com` is a **Microsoft 365**
   mailbox, and Microsoft has fully retired Basic Authentication for
   IMAP/POP/SMTP on Exchange Online, so this would never have connected.
2. **Microsoft Graph** (OAuth2 client-credentials via an Azure AD app
   registration) — technically correct, but the user asked to avoid the
   Azure app-registration approach entirely.
3. **Exchange inbox rule redirecting `support@okelcor.com`'s mail to a
   second, non-Microsoft mailbox, read over plain IMAP** — no Azure needed,
   but authentication against the redirect-destination mailbox couldn't be
   gotten working end to end despite troubleshooting (wrong/quoting issues
   in `.env` were ruled out; root cause not conclusively identified).
4. **What actually shipped: a dedicated subdomain (`reply.okelcor.com`)
   with Cloudflare Email Routing pointed at a Cloudflare Email Worker**,
   which parses the mail and POSTs it to a new webhook on this API. No
   Microsoft involvement at all, no polling (event-driven — Cloudflare
   hands off the instant mail arrives), and DNS was already on Cloudflare
   for this domain.

Every pivot only ever touched the *transport* layer (how a message physically
arrives). The matching/lead-capture/notification logic was written from the
start against a plain-array message shape, so it was extracted into a
transport-agnostic `InboundEmailProcessor` service partway through and
survived every subsequent pivot completely unchanged and fully tested.

| Feature | Status | Notes |
|---------|--------|-------|
| `cloudflare-worker/` (new, separate deployable) | ✅ | A small Cloudflare Email Worker (`postal-mime` for MIME parsing) that POSTs parsed inbound e-mail to `POST /webhooks/email-inbound`, HMAC-signed with a shared secret. Deployed independently via `wrangler deploy` from a developer's own machine — not part of the Laravel app's own deploy pipeline. |
| `EmailInboundWebhookController` (new) | ✅ | Verifies the Worker's HMAC-SHA256 signature (`X-Webhook-Signature`) before trusting any payload — same security boundary as the Stripe/WhatsApp webhooks. Normalizes the Worker's JSON into the same plain-array shape `InboundEmailProcessor` always expected. |
| `InboundEmailProcessor` (new — extracted from the now-removed `FetchInboundEmails` command) | ✅ | All the actual logic: own-domain guard, plus-address/In-Reply-To matching, lead capture, admin notification. Transport-agnostic by design — this is the piece that survived all three prior transport attempts unchanged. |
| Plus-addressed Reply-To (`CustomerAdHocEmail`) | ✅ | Outgoing e-mails set Reply-To to `reply+{message-uuid}@reply.okelcor.com` (when inbound capture is enabled) instead of the sending admin's own address. **Falls back to the exact previous behaviour** when disabled — shipped with zero behavior change ahead of setup, unaffected by any of the four design attempts. |
| Reply matching, in order of reliability | ✅ | (1) Plus-address in the reply's own `To:` — reconstructs the original message_id directly. (2) `In-Reply-To` header (standard fallback). (3) Sender e-mail address (last resort). Unchanged since design #1. |
| **Own-domain guard** (`isOwnDomainSender`) | ✅ | Skips (silently) any message sent from Okelcor's own domain (derived from `MAIL_FROM_ADDRESS`, overridable via `MAIL_INBOUND_OWN_DOMAIN`) — relevant if this subdomain is ever also used for anything system-generated. Made `public` specifically so this safety-critical check could be tested directly — caught and fixed a real case-sensitivity bug this way, still holding across every pivot since. |
| Inbound → lead capture for unknown senders | ✅ | Reuses the same CRM-2 quality-scoring + CRM-3B notification pattern built for the WhatsApp webhook — a genuinely new correspondent becomes a `QuoteRequest` (`lead_source: 'inbound_email'`), real e-mail address available (no placeholder needed, unlike WhatsApp). |
| Admin notification on reply (`email_reply_received`) | ✅ | Targets the specific admin who sent the original message when resolvable; falls back to a `crm.view` fan-out otherwise. No new admin-facing endpoint — surfaces through the existing `GET /admin/customers/{id}/communications` thread. |
| `webklex/php-imap` + `ImapInboundMailService` + `MicrosoftGraphMailService` + `FetchInboundEmails` command | ⬜ removed | All added then removed across this session's pivots — none left in `composer.json`/the codebase as dead weight. |
| Backend tests (40, automated, no DB — actually run) | ✅ | `CustomerAdHocEmailReplyToTest` (5), `InboundEmailProcessorGuardTest` (4), `InboundEmailProcessorParsingTest` (5) — all pass unchanged across every pivot, since they exercise the transport-agnostic logic via plain arrays. `EmailInboundWebhookTest` (6, MySQL-gated) — signature verification, known-customer matching, lead capture, own-domain guard, HTML sanitization, all via real HTTP calls to the webhook route. |
| **Known limitation, documented not hidden** — one-way plain-text degradation | 🔲 flagged | Rich HTML replies are sanitized the same way outbound composer bodies are; plain-text-only replies get a simple `nl2br(e(...))` treatment. No attempt to strip quoted "On [date] ... wrote:" history from long reply chains. Acceptable for now; revisit if volume makes this noisy. |

**Depends on account-side setup** — a Cloudflare Email Routing subdomain +
a deployed Cloudflare Worker (needs Node.js + `wrangler` on a developer's
own machine, one time) + a shared webhook secret. See
`EMAIL_INBOUND_SETUP.md`. Code is live and inert until
`MAIL_INBOUND_ENABLED=true` is set.

See `EMAIL_INBOUND_SETUP.md` (mailbox setup this requires) and
`FRONTEND_NOTE_inbound-email-replies.md` (no new endpoints — confirms the
existing thread UI just needs to render `direction: "inbound"` rows it
should already support generically).

---

## Unified admin inbox + portal reply attachments (Session 60)

Once inbound e-mail replies started landing in the system (Session 59), admins
had no way to notice a new reply without opening each customer's profile
individually. Added a single cross-customer feed instead of requiring that.

| Feature | Status | Notes |
|---------|--------|-------|
| `GET /admin/communications/inbox` | ✅ | Paginated, unread-filterable feed of every inbound e-mail across all customers/leads, reusing the existing `staff_read_at` field for unread state (marking read per-customer already clears it here too — same row). |
| Customer portal replies accept attachments | ✅ | `POST /auth/customer/communications/{id}/reply` — previously text-only; same limits as the admin composer (5 files, 10MB each). |
| New-lead inbound e-mail linking bugfix | ✅ | A brand-new correspondent's first inbound row never linked back to the `quote_request` created for it — would have shown up unattributed in the new inbox. |

See `FRONTEND_NOTE_inbound-email-replies.md` for the endpoint contract.

---

## Supplier intel — tyre-type aware (Session 61)

The eBay-search-based supplier/competitor price lookup only recognized
passenger-car tyre sizes (`225/45R18`) — TBR's decimal-rim notation
(`295/80R22.5`) and OTR's completely different notation (`23.5R25`,
`20.5-25`) either matched poorly or not at all, silently degrading two of
Okelcor's four tyre categories.

| Feature | Status | Notes |
|---------|--------|-------|
| Tyre-type-aware size parser | ✅ | `EbayService` now recognizes both PCR/TBR (incl. decimal rim) and OTR notations; an optional `type` param picks the right pattern first. |
| OTR eBay lookup skipped entirely | ✅ | OTR (off-the-road) genuinely isn't sold on eBay in practice — the lookup is skipped with an explanatory `note`, marketplace links still returned, instead of returning near-empty junk results. |
| `GET /admin/supplier/for-product/{id}` (new) | ✅ | Builds the search straight from one of Okelcor's own catalogue products (brand+size+type) instead of requiring manual copy-paste; includes a `price_vs_market_pct` comparison against the eBay average. |
| `GET /admin/supplier/made-in-china-link` (new) | ✅ | Second B2B wholesale marketplace link alongside the existing Alibaba one — the stronger channel specifically for TBR/OTR sourcing. |
| Result summary (min/max/avg price) | ✅ | Added to the existing `search` response alongside the raw listing array. |

---

## Saved fitments + order reorder (Session 62)

From a frontend competitive-UX review (Tire Rack / SimpleTire / ATDOnline).
Two of the four asks (real per-warehouse stock, tyre-batch traceability)
were put on hold — confirmed with the business that neither the
multi-warehouse split nor a tyre grading/inspection workflow actually exists
yet, so nothing was fabricated for either.

| Feature | Status | Notes |
|---------|--------|-------|
| `GET/POST/DELETE /auth/customer/saved-fitments` | ✅ | Simple saved size/brand profiles ("My Garage"). |
| `POST /auth/orders/{ref}/reorder` | ✅ | Re-prices a past order's items against **live** product data — never replays old prices. Does not itself create a new order; hands the frontend a pre-fill payload for the existing checkout flow. Flags any item no longer sold/active instead of silently dropping it. |

---

## AI-generated admin dashboard insights (Session 63)

A periodic Gemini summarization pass over dashboard/security/quotes numbers,
surfaced as a handful of short plain-English observations — not a new data
source, a summarization layer.

| Feature | Status | Notes |
|---------|--------|-------|
| `insights:generate` (scheduled, every 15 min) + `GET /admin/insights` | ✅ | `AdminInsightsService` — aggregate-only snapshot (revenue/orders/inventory/security/quotes; never customer/admin PII) sent to Gemini in JSON mode; endpoint always serves the last successful batch instantly, never calls the AI per-request. |
| Inventory stockout forecast | ✅ | Computed in PHP from real 7-day sales velocity, handed to Gemini as a fact to restate — the model never estimates this number itself. |
| "Traffic" category dropped from original ask | ✅ scoped out | No PostHog integration exists on this backend to summarize — confirmed rather than fabricated; analytics live entirely frontend-side. |
| **Production model gotcha, resolved live (2026-07-20)** | ✅ | `gemini-2.0-flash` (original default) returned `429` (quota **limit: 0** — an account/project entitlement issue, not a rate-limit-from-usage) on the real production API key; `gemini-2.5-flash` then returned `404 "no longer available to new users"`. `gemini-flash-latest` (a rolling alias, not a pinned dated model) works reliably and is now the codebase default — confirmed producing real, grounded output (e.g. correctly flagged 3,039 low-stock products, 30 pending orders, 100% 2FA adoption). |

`GEMINI_API_KEY` unset = feature silently disabled, `insights:generate` no-ops, endpoint returns empty — never a 500.

---

## Order currency (Session 64)

Frontend shipped a Currency (EUR/USD) dropdown on the order edit tab.
**Two-phase**: shipped first as a pure display relabel per the frontend's
own framing ("the USD amount was already what the customer paid, no
exchange-rate math") — then the user explicitly corrected this after testing
("make sure it converts the actual figures using today's rate"), which is a
materially different, real-money feature.

| Feature | Status | Notes |
|---------|--------|-------|
| `orders.currency` column | ✅ | Defaults `EUR` for all existing rows. |
| Real conversion via `CurrencyConversionService` | ✅ | Live daily EUR/USD rate from Frankfurter (free, no API key, ECB-sourced), cached for the rest of the calendar day. Converts every money field on the order **and every line item** — not just the label. Rate + full before/after snapshot logged to `order_logs` (`currency_converted`) for a complete audit trail. |
| Guarded the same as any other financial edit | ✅ | Blocked on eBay-synced orders and on orders with `financials_locked_at` set (an issued commercial document) — same protection the revision-request workflow already enforces. A failed exchange-rate lookup rejects the whole request rather than leaving stale figures under a new currency label. |

---

## Admin/Ops companion mobile app — backend foundations (Session 65)

Planned as a **push-notification-first companion app** (not a full mobile
admin panel) for the existing React Native mobile team, sharing code with
the Next.js web frontend where sensible. See
`FRONTEND_NOTE_admin-mobile-app.md` and
`FRONTEND_NOTE_admin-mobile-app-v2-premium.md` for the full plan (live chat,
in-app quick actions, premium UI pillars).

| Feature | Status | Notes |
|---------|--------|-------|
| `POST`/`DELETE /admin/push-tokens` + `ExpoPushService` | ✅ | Registers a device's Expo push token (upserted by token, not admin — a shared/reissued device re-points to whoever most recently logged in on it). Hooked into the single choke point every existing notification already flows through (`AdminNotificationService::notifyUser`) — every notification type reaches a registered device automatically, no per-type wiring needed. Dead tokens pruned automatically from Expo's own send response. |
| `PUT /admin/presence` (`available_for_chat`) | ✅ | The mobile app's chat-availability toggle; also returned from `GET /admin/me`. |
| Actionable push categories (`AdminPushCategories`) | ✅ | Push payloads now carry a `categoryId` + `related_type`/`related_id`, so the app can render Approve/Reject/Reply action buttons directly on the lock screen. |
| **Real bug found + fixed while wiring this up** | ✅ | Financial revision requests (request/approve/reject) wrote an `OrderLog` entry but **never notified anyone at all** — nothing prompted an approver to act, and the requester never learned the outcome. Both directions now notify correctly. |

---

## Live chat — custom system (Session 66) — superseded, left dormant

Built a full custom live-chat backend (Pusher-based) per the mobile app plan.
**One session later, the frontend team identified that Crisp — already fully
live on the website with its own Next.js admin proxy — is the actual live
chat product, and this custom system had no real traffic.** Per explicit
decision: left in place, not removed (real, working infrastructure, no
reason to delete it), but nothing further should be built against it.

| Feature | Status | Notes |
|---------|--------|-------|
| `live_chat_sessions` / `live_chat_messages` + Pusher broadcasting | ✅ built, 🔲 dormant | Session lifecycle (pending → active → closed), first-admin-to-accept-wins claiming, transcript roll-up into a single `customer_communications` row on close. Real Pusher credentials were configured in production before the pivot to Crisp was identified. |
| Broadcasting infra (`config/broadcasting.php`, `routes/channels.php`) | ✅ | Registered under `api/v1/broadcasting/auth` with Sanctum auth (not the framework's default session-based route) — this piece is reused by nothing else, not Crisp-specific, but the auth wiring itself is sound infrastructure. |

---

## Crisp integration — the real live chat product (Session 67)

Mobile app needs to reach Crisp conversations, but Crisp API credentials
can't ship inside a mobile app bundle (extractable from a decompiled
APK/IPA) the way they can live server-side in the existing Next.js proxy —
so this backend now proxies the same four operations for mobile specifically.

| Feature | Status | Notes |
|---------|--------|-------|
| `CrispService` + 4 admin proxy endpoints | ✅ | `GET conversations`, `GET conversations/{id}/messages`, `POST .../reply`, `POST .../resolve` — thin wrapper over Crisp's REST API v1 (Basic auth via a Crisp private plugin's identifier/key). Degrades to a clean `503` when unconfigured. |
| `POST /webhooks/crisp` (`message:send` event) | ✅ built, 🔲 inactive | HMAC-verified same as every other webhook in this app. **Crisp's free plan doesn't support custom webhooks at all** (confirmed by the user — requires Premium) — the endpoint is fully built and will "just work" the moment Crisp's plan changes, but nothing calls it today. Mobile polls the list endpoint in the meantime. |
| **Real bug found + fixed against the live account** | ✅ | Crisp genuinely returns **HTTP 206** (not 200) for a successful paginated conversations list — the original `->ok()` check (exactly 200) was silently discarding real, successful responses. Switched to `->successful()` (whole 2xx range). |

---

## Admin e-mail signature display fix (Session 68)

A signature set under an admin's profile always rendered correctly in the
actual e-mail a customer received, but never appeared in the admin panel's
own "sent" thread view for that same message.

| Feature | Status | Notes |
|---------|--------|-------|
| Signature appended once, shared by both | ✅ | Root cause: the signature was only ever stitched into the outgoing e-mail inside its own Blade template at send time, and that composed HTML was never what got saved as the `CustomerCommunication.body` — only the plain typed message was. Fixed by building the signed body once in the controller and using that same value for both the Mailable and the stored record. |

---

## Marketing contact market segmentation + Croatia campaign (Session 69)

Existing marketing contacts had no concept of "market" at all — a bulk
campaign filter could only match on company/country/status/search, so once
a second market's contacts existed in the same table there was no reliable
way to scope a send to just one.

| Feature | Status | Notes |
|---------|--------|-------|
| `marketing_contacts.market` | ✅ | Plain string (not an enum) — a new market can be introduced just by importing a CSV or adding a contact under it, no backend change required. Existing rows backfilled to `asia` per the business's confirmation that's what the pre-existing list already was. |
| `GET /admin/marketing-contacts/markets` (new) | ✅ | Auto-discovered distinct markets + counts — powers a dynamic picker with no hardcoded list. |
| `POST`/`PATCH /admin/marketing-contacts` (new) | ✅ | Manual single-contact add + edit — previously the only way to add a contact at all was a full CSV import. |
| `filters.market` on bulk campaign creation | ✅ | "Send to Croatia only" / "send to Asia only" are now real, mutually exclusive selections. |
| **Real bug found + fixed while preparing the campaign** | ✅ | Pasting a full, self-contained HTML e-mail template (its own `<html>`/`<body>`) into a campaign body previously got wrapped inside *another* `<html><body>` by the send template — invalid nested documents — and the template's own unsubscribe link had nothing real to point at. Fixed: a full HTML document now renders as-is, and its own unsubscribe link can use a `[[UNSUBSCRIBE_URL]]` token the send job replaces with the real, personalized link before sending. |
| `campaign:seed-assets` (new artisan command) | ✅ | Uploads a folder of campaign source images (`resources/campaign-assets/{set}/`) into the real Media Library through the same `MediaLibraryService` the admin upload endpoint uses. |
| Croatia market seeded | ✅ | 200 contacts imported (cleaned/deduplicated from a spreadsheet), fully segmented from the existing Asia list. |

**Superseded in places by Session 72** — read that section alongside this one:
a contact is no longer limited to one market, `filters.market` now matches any
of a contact's markets (not just the primary), and a CSV re-import no longer
overwrites an existing contact's market. The `market` column and everything
described above still exist and still behave as written; Session 72 only added
to them.

---

## Order-manager meeting fixes (Session 70)

From a transcript of a call with the order manager — three items raised,
plus a directly-reported bug.

| Feature | Status | Notes |
|---------|--------|-------|
| Manual trade-document upload accepts a real `type` | ✅ | Previously always hardcoded `shipment_document` regardless of what was actually uploaded — there was no way to manually upload an externally-produced order confirmation / commercial invoice / etc. and have it recognized as that real type. Now accepts `order_confirmation \| proforma \| commercial_invoice \| packing_list \| delivery_note \| shipment_document`; uploading an "official" type automatically supersedes any existing issued/sent document of that same type, so a manual upload can never coexist with a stale generated version. |
| `mark-balance-due` 404 | ✅ diagnosed, not a backend bug | The functionality genuinely exists and works (`POST /orders/{id}/payment-milestones/balance-due`) — the frontend was calling a different, never-registered path (`/payments/mark-balance-due`). Flagged for frontend to correct. |
| "Already paid, without marking balance due, how do I get to balance-paid?" | ✅ confirmed already supported | `markBalancePaid` already accepts being called directly from `deposit_paid`, skipping `balance_due` entirely — no backend change needed. Flagged for frontend: the "Mark Balance Paid" action should be shown/enabled at `deposit_paid` too, not gated behind `balance_due` only. |
| Order status missing "Processing" in the UI | ✅ confirmed already supported | `processing` is already a fully valid, already-used backend order status (accepted by every order-status endpoint; already used by the Mollie webhook and order-sync services) — purely a frontend dropdown-options gap. |

---

## Premium UX pass — stock, dispatch ETA, tyre passport (Session 71)

A second frontend competitive review (Tire Rack / SimpleTire / ATDOnline)
arrived asking for per-warehouse stock, tyre batch traceability, and saved
fitments + reorder. Two of those were already settled — **saved fitments +
reorder shipped in Session 62** with exactly the requested contract, and
multi-warehouse/tyre-grading were put on hold that same session because
neither exists in the business. The note's core premise about stock also
turned out to be wrong, which changed the shape of the work.

| Feature | Status | Notes |
|---------|--------|-------|
| **Finding** — `stock` was never the gap | ✅ | The note claimed `in_stock` was "a bare boolean, no quantity". `ProductController::formatProduct()` has returned an integer `stock` on both `GET /products` and `GET /products/{id}` all along. The real gap was the *write* side, not the read side. |
| **Fix** — `stock` is finally writable by an admin | ✅ | It was settable **only** by `WixProductImportService` and the manual `products:sync-rapid` Excel import — absent from `UpdateProductRequest` entirely, and `POST /admin/products/bulk-stock` only ever wrote the `in_stock` boolean. An order manager could not correct a wrong quantity from the panel at all, while the public API published it. Now accepted on create/update and on `bulk-stock` (`stock` or `in_stock`, or both — boolean-only callers unaffected). |
| **Fix** — admin payload was missing `stock` | ✅ | `AdminProductController::formatProduct()` didn't include it, so the panel couldn't even display the number it was publishing publicly. |
| Stock/flag coherence rule | ✅ | Setting `stock` without an explicit `in_stock` derives the flag (`stock > 0`), so "✓ In Stock" can't sit on top of a zero quantity. An explicit `in_stock` still wins — an admin can deliberately show a product with no counted stock. |
| `estimated_dispatch_days` on the public payload | ✅ | New `site_settings` key (group `shop`), surfaced on both product endpoints. **Ships blank on purpose** — the frontend renders it verbatim, so an invented default would become an unapproved delivery promise. Also returns `null` for any out-of-stock product. Created via migration rather than `SiteSettingsSeeder`, whose `updateOrCreate()` would reset an admin-entered value on re-run. |
| **Not built** — `stock_locations[]` per-warehouse split | 🔲 confirmed absent | No multi-warehouse concept exists anywhere (only hit is eBay's own single merchant inventory location). The one real signal is the Rapid import filename — stock *"being Held by Demir Keramic in Solnhofen"*, i.e. one third-party site. An array today would be one hardcoded entry pretending to be a real split. Same conclusion as Session 62, now with the evidence written down. |
| Tyre passport schema (`tyre_batch`) | ✅ | New guarded/additive columns on `products`: `condition_grade` / `tread_depth_mm` / `dot_code` / `inspection_date` / `inspection_photos` (JSON). Confirmed first that **none of this existed anywhere** — genuinely new capability, not a surfacing exercise. `condition_grade` is a plain string, not an ENUM (no grading scale is fixed yet, and see the `admin_users.role` ENUM gap in Known Gaps). |
| `tyre_batch` null until populated | ✅ | `Product::hasTyreBatchData()` gates the whole block out of both public and admin payloads, so the frontend can skip the passport card entirely rather than render one full of nulls. **Will be null on every product until ops starts entering data** — the plumbing is ready, the data-entry process is not. |
| Inspection photo endpoints | ✅ | `POST /admin/products/{id}/inspection-photos` (multipart, max 10/call, 5MB each) + `DELETE .../inspection-photos/{index}` (deletes from disk, re-indexes). Stored separately from `product_images` on purpose — inspection evidence, not carousel shots. |
| §4 search-suggest — assumption flagged as stale | 🔲 flagged | The note deferred it as "catalogue is small enough". `AdminInsightsService` recently reported 3,039 low-stock products, so the live catalogue is ≥ ~3k rows. Flagged back to frontend to measure the real payload before filing as safely deferred. |
| Deploy-order safety verified | ✅ | Frontend flagged the risk of their deploy (which now renders these fields) landing ahead of migrations #24–25. Proved rather than argued: `test_public_payload_survives_code_deployed_before_migration` drops the new columns + settings row and asserts the public endpoints still return 200 with `tyre_batch`/`estimated_dispatch_days` null. Only consequence of code-before-migration is admin-side (saving passport fields errors until migrated); no customer-facing path affected. |
| **Correction issued to frontend** — §3 is shipped, not parked | ✅ | A follow-up frontend note recorded saved-fitments/reorder as "silent / parked". It's live in production since Session 62. Re-flagged prominently in the frontend note so they don't hold a feature back or build a workaround. |
| EU tyre label — feed question answered, nothing built | 🔲 answered | Frontend hypothesised EU label data might already be in the Rapid supplier feed and therefore bulk-populatable (unlike tyre-passport data, which needs a human inspecting a tyre). Checked `SyncRapidProducts::parseExcel()`: it maps exactly ten fields (brand/width/height/rim/load_index/speed_rating/season/size_pattern/stock/price) and skips every non-`Rapid` row — **no fuel efficiency, wet grip, noise or EPREL id**. Their reasoning holds in principle (label values are a published property of a tyre model, not an inspection result) but the source doesn't exist here; populating it means matching SKUs to EPREL, not a column mapping. |
| Backend feature tests (20) | ✅ | `ProductStockAndTyrePassportTest` — **20 passed / 62 assertions, actually executed**, not just written. Uses the minimal-schema sqlite pattern (MediaLibraryTest / BulkEmailCampaignTest) rather than the MySQL-only gate, so it runs locally and in CI. Full suite after the change: 137 passed, 0 failed, 206 skipped (pre-existing MySQL gate). |

**⚠️ Honesty caveat carried into the frontend note:** `stock` is *never
decremented on order* and `products:sync-rapid` isn't scheduled — the number
is "supplier availability as of the last manual import", not live on-hand
stock. It's now correctable, but until ops keeps it current, an exact
"24 in stock" is a stronger claim than the data supports. Frontend was told
to band it (In stock / Low stock) rather than print the raw integer until
the business confirms it's maintained.

**Still open — needs the order manager's input:**
1. What is the real dispatch-days number? (`estimated_dispatch_days` is
   blank until someone sets it; the feature is inert by design until then.)
2. Will ops actually capture tyre condition/grade/DOT/photos, and on which
   grading scale? Nothing renders until they do.
3. Is anyone maintaining `stock` now that it's editable, or should the
   frontend keep it banded?

See `FRONTEND_NOTE_premium-ux-pass.md`.

---

## Marketing contacts in multiple markets — move / add / remove (Session 72)

> **Deploy status:** pushed to `main` as `b8757c7`. **Not yet deployed** —
> migration #26 is unapplied in production, so multi-market membership is inert
> there until `artisan migrate --force` runs (the code falls back to the old
> single-market behaviour in the meantime; see the deploy-order row below).

Digital marketer's report: a contact already in one market (the `TEST` market
created while verifying Session 69's segmentation) couldn't be added to
another (`Germany`) — the add form returned "email exists", and the only
workaround was delete-then-re-add.

Her wording was "**add** it to another folder", so both readings were built
rather than guessing: a contact can now belong to **several markets at once**,
*and* there are real move/remove actions. The single-market constraint was the
actual blocker — `marketing_contacts.market` was one string column and `email`
is `UNIQUE`, so a second row was impossible and there was nothing to move with.

| Feature | Status | Notes |
|---------|--------|-------|
| `marketing_contact_markets` table (migration #26) | 🔧 | Membership is many-to-many. `unique(contact_id, market)` so "add to market" is safely repeatable via `insertOrIgnore`. Backfills one membership per existing contact from its current `market`, so behaviour is identical the moment it runs. |
| `marketing_contacts.market` kept as the **primary** market | 🔧 | Not dropped — respects CLAUDE.md's "do not change column names", and every existing response/query that reads `market` keeps working. Mirrored from the membership table (`refreshPrimaryMarket`): always holds one of the contact's real memberships, and never shifts just because another market was added alongside it. Contact payloads gain a `markets` array; `market` is unchanged. |
| `POST /admin/marketing-contacts/add-to-market` (new) | 🔧 | **The endpoint that answers the actual report.** Adds a market, keeps the existing ones. Three OR'd selectors shared with move: `contact_ids[]` (checkbox selection), `emails[]` (paste-a-list, no id lookup), `from_market` (whole market at once). Idempotent — re-adding reports `already_in_place` rather than erroring. |
| `POST /admin/marketing-contacts/move-market` (new) | 🔧 | Relocates rather than accumulates. **With** `from_market`: leaves that market for the target, keeping the contact's *other* markets. **Without**: the target replaces its markets outright (the original single-market meaning). `from_market` + `to_market` alone is effectively a market rename. |
| `POST /admin/marketing-contacts/remove-from-market` (new) | 🔧 | Takes contacts out of one market without deleting them; called with no ids/emails it retires the market entirely. **Refuses to strip a contact's last market** — that would leave it invisible to every market-scoped list and campaign filter with no way to find it again; those contacts come back in `skipped_last_market` instead of vanishing silently. |
| Move/add/remove never create or delete contacts | 🔧 | An email with no matching contact is reported in `not_found` rather than imported — a move must not quietly become an import. |
| Unsubscribed status preserved by every market operation | 🔧 | Neither `status` nor `unsubscribe_token` is touched — same guarantee `MarketingContactImportService` already makes on re-import; a market change can never re-enter an opted-out contact into a send. |
| **Fix** — campaign filter matched only the primary market | 🔧 | `BulkEmailService::recipientQuery()` now matches **any** of a contact's markets. Without this the whole feature would have looked like it worked in the UI while quietly not sending: a contact added to `germany` alongside `test` would have been left out of the germany campaign. Same fix applied to the admin contact-list `market` filter and the `markets`/`stats` counts. |
| Campaigns can target several markets in one send | 🔧 | `filters.markets[]` (max 20) alongside the existing `filters.market`, on both `POST /admin/bulk-emails` and `recipient-count`. Because the filter narrows contact *rows*, a contact in two targeted markets is selected exactly once — no de-dupe step needed, nobody can be emailed twice by one campaign. Covered by a test asserting exactly that. |
| Create/patch accept several markets | 🔧 | `POST` takes `market` (string) **or** `markets[]`; `PATCH` takes `markets[]` (replaces the set) or `market` (single-market move, unchanged meaning). |
| Duplicate add now says **which markets** hold the contact | 🔧 | Still 422 with `errors.email` populated (no client breakage), plus `code: 'contact_exists'` + `existing_markets` / `can_add_market` / `can_move` / `target_market` so the UI can offer "already in *test* — add to *germany* too, or move it?" instead of a dead end. Checked before validation so the message can name both. |
| Membership registered on save, not just by the endpoints | 🔧 | A `saved` hook keeps the primary `market` column and the membership table consistent, so a contact written the old single-column way anywhere in the codebase — now or in future — still lands in that market's list. Prevents the failure mode where a contact claims a market in its own row but is missing from every list of it. Fires only when `market` actually changed, so a 1,700-row import doesn't pay for a redundant write per contact. |
| **Fix** — CSV re-import silently relocated existing contacts | 🔧 | Importing a Germany list containing an existing Asia contact used to overwrite its `market`, moving it out of Asia as a side effect of an unrelated import. Now **adds** germany alongside asia and leaves the primary alone — relocation is `move-market`'s job, explicitly. |
| Deploy-order safe | ✅ | Proved, not argued: `test_contacts_still_work_when_the_membership_table_is_missing` drops the table and asserts contact list / markets / stats / move / campaign recipient-count all still return 200 on the single-column fallback. Frontend can deploy independently of migration #26. |
| Migration backfill proved against real SQL | ✅ | `test_migration_backfills_one_membership_per_existing_contact` runs the real migration file from the pre-migration state, asserts every contact keeps exactly the market it had (null-market contacts get none), and asserts a re-run is idempotent. The backfill is the only part of this feature that touches live production rows. |
| Backend feature tests (18 new) | ✅ | Added to `BulkEmailCampaignTest` — **46 passed / 169 assertions, actually executed** (uses that file's minimal-schema sqlite harness, not the MySQL-only gate). Full suite after the change: **164 passed, 0 failed**, 206 skipped (pre-existing MySQL gate). |

Confirmed by grep that nothing else in the codebase reads or writes
`marketing_contacts.market` — the only consumers are this controller, the import
service and `BulkEmailService`, all updated. (Other `market*` hits are unrelated:
`customers.market_region`, eBay `marketplace_id`, supplier price comparison.)

See `FRONTEND_NOTE_marketing-contact-market-move.md`.

---

## Campaign builder — no-HTML email design (Session 72)

> **Deploy status:** pushed to `main` as `faa31bd`. **Not yet deployed** —
> migration #27 is unapplied in production. Block-designed campaigns would
> already render and send correctly without it; only saved templates and
> reopen/duplicate need the new columns (see the deploy-order row below).

The email marketers aren't technical and couldn't hand-write HTML for a
well-structured campaign. They supplied screenshots of the Wix-built campaigns
they used to send (teal page, dark centred card, centred title, hero photo,
three benefit sections, teal call-to-action, address/social/website footer) and
asked for that back as something they fill in rather than code. Session 69 had
already made a pasted full-HTML document render correctly — that solved the
*rendering*, not the *authoring*.

A campaign is now a list of **blocks**; the backend renders the house style
around them. `body_html` still works unchanged — this is a second, easier way
to author, not a replacement.

| Feature | Status | Notes |
|---------|--------|-------|
| `CampaignBlockRenderer` (new) | 🔧 | 8 block types (heading / text / image / button / list / divider / spacer / footer) → table-based, inline-styled, Outlook-safe HTML. Two theme presets: `okelcor_dark` (reconstructed from the screenshots — `#2E6E75` page, `#2B2B2B` card) and `light`. The one `<style>` block carries mobile-only overrides; layout never depends on it, so a client that drops it still renders correctly. |
| Marketer text can never become markup | 🔧 | All text is escaped, then a tiny inline syntax (`**bold**`, `*italic*`, `[label](url)`) is re-introduced as tags the renderer generates itself. `javascript:` URLs are rejected in links, buttons, images and footer social links — including the entity-encoded form (`javascript&#58;`). Theme overrides accept only hex colours, a constrained font stack and a clamped width, so nothing arbitrary reaches a `style` attribute. |
| `CampaignMergeTags` (new) — personalization | 🔧 | `[[FIRST_NAME]] [[LAST_NAME]] [[FULL_NAME]] [[COMPANY]] [[EMAIL]] [[COUNTRY]] [[MARKET]] [[UNSUBSCRIBE_URL]]`, usable in block text, a button URL **or the subject line**, substituted per recipient in the send job. Every tag takes a fallback — `[[FIRST_NAME|there]]` — which matters because most of the imported list is an email address and nothing else, so a bare tag would send "Hi ," to a large part of it. A tag with no fallback resolves to empty, never to the raw token; an *unknown* tag is left visible so a typo shows up in the preview. |
| `CampaignStarterTemplates` (new) — 3 built-in designs | 🔧 | `okelcor_classic` (the full Wix layout, landmark-for-landmark), `simple_announcement`, `product_offer`. Deliberately **code, not seeded rows**: always present, can't be deleted by accident, improve with a deploy, and no seeder-re-run gotcha (cf. `SiteSettingsSeeder` overwriting admin-entered values). |
| `GET /admin/campaign-design` (new) | 🔧 | Block catalogue with per-field types/options/defaults, theme presets, merge tags, inline-format syntax — the editor UI is generated from this rather than a hardcoded frontend copy that can drift, same philosophy as auto-discovered markets. |
| `GET /admin/campaign-templates/starters` (new) | 🔧 | So the editor never opens on a blank canvas. |
| `campaign_templates` CRUD (new, migration #27) | 🔧 | The team's own saved/reusable designs. `created_by` is nullable + `nullOnDelete` — a shared design must outlive the admin who saved it. List endpoint stays light (`block_count`), detail carries the blocks. |
| `POST /admin/bulk-emails/preview` (new) | 🔧 | Renders without creating anything: the real HTML, a sample-personalized copy for the preview iframe, the plain-text part, and `unknown_merge_tags` — catching a misspelled tag in the editor instead of after 1,700 emails went out with a blank in them. |
| `POST /admin/bulk-emails/test-send` (new) | 🔧 | Sends one real `[TEST]`-prefixed email to an address the marketer picks. Creates no campaign, touches no contact, and its unsubscribe link is **inert** — a tester clicking through their own test cannot opt a real contact out. The most important control for a non-technical user. |
| `POST /admin/bulk-emails` accepts `blocks` + `theme` | 🔧 | Rendered to HTML **at creation time** into the existing `body_html` column, so the queue, resume logic, per-recipient substitution and send job are all completely unchanged — a new way to author, not a new way to send. Blocks/theme stored alongside so a sent campaign can be reopened or duplicated; payload gains `designed`. |
| Plain-text alternative now sent | 🔧 | `renderText()` → new `body_text` column → text part on the mailable. A bulk HTML-only message is markedly more likely to be filtered as spam, and some recipients read text only. Null for pasted-HTML campaigns, where there's nothing to derive it from. |
| Validation phrased for a non-technical user | 🔧 | 422 `code: invalid_blocks` with `errors.blocks` as plain strings — *"Block 2 (Button): \"Where it goes\" is required."* — each prefixed `Block N` so the editor attaches it to the right block instead of dumping a schema error. |
| **Bug found by its own test** — test-send's unsubscribe link | 🔧 | `applySamples()` ran before the inert-link substitution, so `[[UNSUBSCRIBE_URL]]` picked up the generic *sample* value, which was shaped like a real unsubscribe URL (`…/marketing-contacts/unsubscribe/preview`). Order reversed and the sample changed to something visibly inert. Caught by the assertion that a test send contains no unsubscribe-shaped URL. |
| Deploy-order safe | ✅ | Proved: `test_campaigns_still_send_when_the_design_columns_are_missing` drops `blocks`/`theme`/`body_text` and asserts both pasted-HTML and blocks-designed campaigns still create and render. `BulkEmailService::designColumns()` writes those columns only when they exist — a campaign send is not something to break over a column that stores editor state. |
| Migration proved against real SQL | ✅ | `test_campaign_design_migration_applies_and_is_idempotent` drops the table + columns, runs the real migration file, asserts all four land, and re-runs it to prove the guards. |
| Backend tests (41 new) | ✅ | `CampaignBuilderTest` (24, no DB — rendering, escaping, URL rejection, theme clamping, merge tags, text part, starter integrity, and a `DOMDocument` check that the output is a single well-formed document with balanced tables) + 17 endpoint tests in `BulkEmailCampaignTest`. **All actually executed** — full suite **205 passed, 0 failed**, 206 skipped (pre-existing MySQL gate). |

**Deliberate design decisions worth knowing:** social links render as text
("Facebook · X · Pinterest") rather than the Wix original's icon graphics — those
need hosted images and a broken image in a footer is worse than a word. Rich
inline formatting is limited to bold/italic/link on purpose; anything more and the
escape-everything guarantee stops holding.

See `FRONTEND_NOTE_campaign-builder.md`.

---

## Partner Sales Log — Okelcor Partner (Session 73)

> **Deploy status: ✅ DEPLOYED to production 2026-08-07** as `d2f1896`.
> Migration #28 applied (batch 98, 176ms) after a `backup:okelcor` and a
> `migrate --pretend` review; `route:cache` rebuilt for the 22 new routes.
> Verified live: `POST /api/v1/partner/auth/login` returns the controller's
> `invalid_credentials` 401 with `x-ratelimit-limit: 5`, and all six partner /
> admin-partner routes return 401 rather than 404.
>
> **Note for whoever verifies the next deploy:** a bare `curl -X POST` with no
> body and no `Content-Type` returns a **403 HTML page from LiteSpeed/Cloudflare**,
> not from Laravel — the host's WAF rejects it before PHP is reached. That is
> not an application error. Always send `-H "Content-Type: application/json"`
> and a real body when smoke-testing a POST endpoint on this host.
>
> This was **deliberately not deploy-order safe** — unlike Sessions 71/72 there
> was no previous behaviour to degrade to, so the endpoints 500ed until the
> tables existed rather than accepting entries into nowhere. That window is now
> closed and the frontend can flip `NEXT_PUBLIC_PARTNER_API_MOCK=false`.

Partners selling Okelcor product in other markets (Ghana first) report what
they sold on paper today, and reviewing those reports takes real time. The
brief — *"it's always hard for me to get data on book-related stuff"* — is
about **bookkeeping**, so the deliverable is a clean exportable set of numbers
at the Okelcor end; the partner web app is only the intake. Frontend built the
app against a mock and sent a proposal; this is the backend for it, built to a
contract settled over two rounds of review (see the exchange summarised in
`FRONTEND_NOTE_partner-sales-log.md`).

| Feature | Status | Notes |
|---------|--------|-------|
| `partner_organisations` + `partner_users` (migration #28) | ✅ | A partner is an **organisation**, not a person — a distributor with a shop and staff is the likely shape in these markets. Sales are owned by the org; `entered_by_user_id` records who typed it (`nullOnDelete` — a sale outlives the person who left). Decided up front precisely because collapsing an org model later is trivial while splitting a single-user model later means rewriting every row and every report. |
| `partner_sales` + `partner_sale_audits` | ✅ | The brief's own sentence *"we sold tyre 315-70 rim 22.5, X pieces, at this amount"* is the entire data model. Soft-deleted: a sale that may already have been exported into the books must not vanish because someone tapped delete on a phone. Audit trail is append-only and records the **editor** separately from `entered_by_user_id`, since a colleague in the same org can edit inside the window. |
| **The idempotency contract** | ✅ | `unique(partner_org_id, client_generated_id)` — scoped to the org, not the user, so a re-authenticated colleague on a shared device can't create a second copy. POST with an existing id: update inside the window, `200` + existing row outside it, **never 409**. A 409 tells the device its send failed, so the outbox retries forever or drops the entry. |
| **Collision found by frontend, in my own rule** | ✅ | My review proposed "reject a replay carrying a different payload under the same id". Their client **reuses `client_generated_id` for edits**, so that rule would have silently broken editing — the partner sees "Sent" while Okelcor holds the old figure, which is the exact corruption the mechanism exists to prevent, arriving through the other door. Rule withdrawn; same id + different payload is an **update**. |
| Stale-revision guard (optional `client_revision`) | ✅ | The agreed table is last-write-wins within the window, which leaves an in-flight retry of v1 landing after v2 free to revert the correction. An optional monotonic counter closes it: a revision that is not newer is refused. Clock-free — those are cheap shared Android handsets and their clocks drift. **Omit the field and behaviour is exactly the agreed table**, so this is additive, not a contract change. |
| Deleted entries not resurrected | ✅ | A device offline when an entry was deleted re-pushes it on the next flush. Returns `unchanged_deleted` rather than recreating the row — otherwise the sale silently comes back and nobody can tell. |
| Cross-partner access → **404, not 403** | ✅ | On `PATCH`/`DELETE`. A 403 would confirm the id exists, letting one partner probe for another's entries. On POST the case cannot arise at all, since uniqueness is org-scoped and every lookup is org-scoped. |
| **Books export streams real CSV** | ✅ | The single most valuable catch of the review, and it was found in *existing* code: `AdminCustomerController::export()` returns **paginated JSON at 200 rows/page**, which is not an export. Had partner-sales copied that pattern, the one feature the brief was actually about would have silently failed. Follows `OrderImportController::export()` instead — `streamDownload` + `fputcsv`, chunked at 200 so memory stays flat on a year-end pull. |
| **No FX conversion anywhere** | ✅ | `CurrencyConversionService` uses Frankfurter, which is ECB-sourced and **does not publish NGN, GHS, KES or AED** — exactly the partner markets. So no automated conversion path exists, and inventing one in a bookkeeping tool would be worse than none. Amount + currency + `sold_at` travel together in every payload and CSV row; totals group **by currency and are never combined**, with a `meta.note` saying so. |
| Currency is an allowlist, not any 3 letters | ✅ | A typo'd `NGM` would sit outside every total in the export and, because nothing converts, nothing else would ever catch it. |
| `total_amount` computed server-side | ✅ | Never accepted from the client, so a stored total cannot disagree with its own line. Re-derived on a PATCH that changes only one of quantity/unit price. |
| PIN hardening — frontend conceded outright | ✅ | 4 digits on a public endpoint against a shared-device threat model *they had themselves named*. Now 6–10 digits, rejecting runs/repeats/repeated blocks; `throttle:partner-login` at 5/min per IP+phone **and** 20/min per IP (either alone fails — per-phone lets a botnet through, per-IP lets an attacker rotate phones); account lockout behind both, since a distributed attacker defeats any IP limit; a locked account rejected on its **existing token** too, not just at login. Unknown-phone and wrong-PIN return byte-identical 401s so the endpoint can't enumerate partners. |
| **Correction from frontend** — `must_change_pin` now enforced server-side | ✅ | I shipped it as an advisory flag on the stated grounds that "the client already handles the flow and a hard gate would break it" — an assertion about code I had never seen, and it was false: there was no change-PIN screen at all. Left that way, every admin-created partner would have kept a PIN their admin chose *and knows*, indefinitely, on devices both sides agreed may be shared — and it would have shipped silently because neither side would have looked. New `EnsurePartnerPinChanged` middleware returns **428** on every partner route except `me` / `change-pin` / `logout`, mirroring `EnsureAdminTwoFactorEnabled` (same status, same allowed-path shape) rather than inventing a second convention. An admin PIN reset **re-arms** the gate; a rejected weak-PIN change does not clear it. |
| Security events → `admin_security_events`, not `security_events` | ✅ | `security_events.type` is a MySQL **ENUM** (widening it needs a MySQL-only migration — the same trap that silently corrupted audit rows here before) and its `customer_id` is an FK to `customers`, which a partner is not. `admin_security_events.type` is a plain string with a nullable `admin_id` and a `metadata` JSON column, so partner events needed **no migration at all**. |
| Edit window on the **server clock** | ✅ | Never `sold_at`, which is partner-declared and backdatable — keying off it would let anyone reopen a locked entry by editing the date. Consequence accepted and documented: an entry authored offline Monday and synced Wednesday gets a Wednesday window. Flip side is the point of it — a backlog entry backdated a year is still editable for 24h after arrival. |
| `PartnerAuth` middleware, not a guard | ✅ | Mirrors `CustomerAuth`. `config/auth.php` has only `web` (unused) and `admin`; customer auth has always been a middleware here, so a third of the same shape keeps one pattern. Token-type isolation (`tokenable_type !== PartnerUser::class`) lets three user classes share one token table safely. |
| Market derived from `partner_organisations.country` | ✅ | Not stored. `customers.market_region` and `marketing_contacts.market` are already two market vocabularies; a third stored one would be a third thing to keep in sync. Auto-discovered from distinct partner countries, Session 72 style. |
| Verification granted to `admin` + `order_manager` only | ✅ | `sales_manager` is the natural fit and is documented throughout `AdminPermissions.php`, but `admin_users.role` is an ENUM that **cannot store it** (Known Gaps, High) — granting it would create a permission nobody could hold. Left out with a comment saying to add it in the same change that widens the ENUM. |
| Catalogue matching silent and conservative | ✅ | `product_id` linked only on an unambiguous single match; two matches → null. A wrong link attributes a sale to the wrong SKU in every report, which is worse than no link. Free-text size stays the source of truth. |
| Route closures avoided | ✅ | verify/dispute are real controller methods — a route closure cannot be serialised by `artisan route:cache`, which the production deploy runs on every release. Verified by running `route:cache` against the new routes. |
| **Bug found by its own test** — identical replays reported as edits | ✅ | The change-detection diff compared values as strings, so a client sending `250.00` (stringifies to `"250"`) against a stored `decimal:2` (`"250.00"`) registered a change on **every identical replay** — a spurious audit row and an `updated` response per retry, on the exact path a flaky connection hits most. Now compares numerically. |
| Backend tests (42 new) | ✅ | `PartnerSalesLogTest` — **42 passed / 192 assertions, actually executed**. Uses the minimal-schema sqlite harness (`BulkEmailCampaignTest` pattern) so it runs in CI rather than sitting behind the MySQL gate. Includes a test running the **real migration file** and re-running it for idempotency, and one proving the unique index — not just the controller check — stops a concurrent duplicate. Full suite: **247 passed, 0 failed**, 206 skipped (pre-existing gate), up from 205. |
| CORS — frontend overruled me, correctly | ✅ | I flagged `partners.okelcor.com` as a day-one blocker. It isn't: every request goes through a Next server-side proxy and server-to-server fetch is not subject to CORS. Nothing changed. Flagged back the one path where a browser plausibly *would* hit Laravel directly — the token-protected CSV export, which cannot be triggered by a plain `<a href>`. |

**Deliberately not built:** consignment / stock-on-hand (deferred until the
business answers; the sale table is column-identical either way, so it is
purely additive), and a batch upload endpoint (the outbox posts individually
and that path is genuinely idempotent — a batch endpoint would be a second code
path to keep correct for a round-trip saving).

**Frontend-side gap, recorded for coordination:** their client is currently
write-only — it reads from its own IndexedDB and never pulls — so a partner
today sees only what *that device* created, not a colleague's entries. The
shared-organisation book is correct on the API; pull-and-merge is the next
piece of frontend work. Not a contract problem, but it means the org model
isn't visible end-to-end yet.

**Still open:** `sales_manager` cannot verify until the `admin_users.role` ENUM
is widened; and **no copy of the Ghana paper report has been seen**, so the
field list is an informed reading of the brief rather than a match to what
partners fill today. The schema is additive, but discovering the wrong shape
after the pilot is expensive — getting sight of one real report remains the
cheapest way to de-risk it.

See `FRONTEND_NOTE_partner-sales-log.md`.

### Admin correction of partner sales (Session 75)

> **Deploy status:** built and tested, **not yet deployed**. **No migration.**
> Code-only, deploy-order safe in both directions.

First real partners are live in Ghana and Nigeria. Frontend reported the gap:
`dispute` records that an entry is wrong, but nothing could make it right —
past the partner's edit window neither the partner nor an admin could correct a
figure. In a tool whose output finance relies on, "we know this row is wrong
and nobody can change it" is the wrong end state.

| Change | Status | Notes |
|--------|--------|-------|
| `PATCH /admin/partner-sales/{id}` | 🔧 | `reason` required (min 5), same as `dispute`'s `note`. **No edit window** — the window protects the partner's own book from drift; an admin correcting a known-wrong figure is the escalation it exists to produce. Same shape as DOC-5 order line-item corrections. |
| Same validation bounds as the partner | 🔧 | `CorrectPartnerSaleRequest` mirrors `StorePartnerSaleRequest` exactly. An admin correction must not be a way around a rule the partner is held to — an unlisted currency or a future `sold_at` is a 422 for both. |
| `total_amount` always re-derived | 🔧 | Including on a correction that sent only one of quantity/unit_price, where trusting the stored total would leave the line disagreeing with itself. Same rule as the partner PATCH path. |
| **A correction clears a prior verification** | 🔧 | If the sale was `verified` and anything substantive moves, it returns to `submitted` with `verified_by`/`verified_at` nulled. `verified by X` must never sit in the CSV next to a figure X never saw. **Notes and customer_name do not clear it** — they are not what was signed off. |
| Numeric comparison on the no-op path | 🔧 | `250.0` sent against a stored `250.00` is unchanged, not a change. Returns 200 `meta.result: unchanged` and writes no audit row, so resaving an untouched form does not litter the trail. Same trap already fixed on the partner path. |
| A soft-deleted entry is refused | 🔧 | 422 `sale_deleted`. Already excluded from the books and the totals — a right figure on a row nothing reads is not a correction. There is deliberately no restore endpoint. |
| `partner_sales.correct` permission | 🔧 | Own key rather than reusing `partner_sales.verify`, though the role list is identical today. Rewriting a partner's reported revenue is a stronger act than signing one off; narrowing it later is then one line here. |
| `PARTNER_EDIT_WINDOW_HOURS` → **72** | ⬜ | Recommended, not applied — config-only, business call. 24h fails the realistic worst case (a partner entering a weekend of paper backlog). 72h covers it. Not longer: every open hour is one where a figure Okelcor may already have exported can still move, and the admin PATCH now exists as the escalation for anything older. |
| Login response shape corrected in the note | ✅ | **My documentation error cost frontend a deploy cycle.** The note said login returns `{ token, user }`; it returns `{ data: { token, user }, message }` with `default_currency` at `data.user.organisation.default_currency`. They got a 502 on the first successful sign-in and found the real shape by reading `formatUser()`. Note now transcribed from the source, field for field. |
| Backend tests (10 new) | ✅ | `PartnerSalesLogTest` — **51 passed / 238 assertions**, up from 41. Full suite: **284 passed, 0 failed**, 206 skipped. |

**Still open, unchanged:** the partner sees corrected numbers but not *why*
they changed — the audit trail is admin-only and `review_note` holds the
verify/dispute note, which overwriting would destroy. Flagged to frontend as a
decision about what to expose, not a field to quietly reuse.

**Not a backend issue:** frontend reported a test entry appearing under a newly
created partner. Cause was their IndexedDB store being keyed per-browser with
no link to the signed-in partner, so a second partner on the same handset saw
the first one's rows. Fixed frontend-side. Recorded here only so "sales showing
under the wrong partner" is not re-investigated against the API — org scoping
on every query and the `(partner_org_id, client_generated_id)` unique index
were never in question.

---

## Payment milestones become admin-driven + EU certificate fix (Session 76)

> **Deploy status:** built and tested, **not yet deployed**. Migration **#31**
> (`order_logs.action` ENUM) unapplied. Deploy-order safe: #31 widens an ENUM
> that is already being rejected, so the code is strictly better off with it and
> no worse without it than it is today. `route:cache` must be rebuilt — two new
> routes.

The order manager called with three complaints about one order. They turned out
to be three different mechanisms, and chasing them surfaced a fourth thing that
had been silently broken since the milestone feature shipped.

### "It marked itself paid"

She recorded an order by hand, set it `confirmed`, and it came out paid.

**Cause.** `POST /admin/orders/{id}/mark-paid` required
`payment_method === 'bank_transfer'`. `AdminOrderController::store()` never sets
`payment_method`, so it is **NULL on every admin-created order** and the
endpoint 422'd on all of them. That left ticking "paid" on the creation form as
the only route to a paid order — i.e. the workflow forced her to declare the
money received before it was, and then she was blamed for the result.

| Change | Status | Notes |
|--------|--------|-------|
| `mark-paid` accepts off-platform payments | 🔧 | Bank transfer, admin-recorded, imported. Only `payment_method === 'stripe'` is refused (`gateway_managed_payment`), because there the gateway is the source of truth and the webhook writes it. The old rule named one payment method and excluded the case the endpoint exists for. |
| Creation-form default unchanged | 🔧 | `payment_status: 'paid'` → `payment_stage: 'balance_paid'` still holds for **historical** backfill, as `FRONTEND_NOTE_historical-orders-onboarding.md` documents and the frontend depends on. It is correct there and only there; flagged to frontend that a live order must be created `pending`. |

### "The customer saw a deposit request nobody sent"

A buyer opened his portal to `Deposit Requested — 50%`, `Deposit Paid`,
`Balance Due`, and queried a payment he had not been asked for and had not made.

**Cause, and the one that matters.** `generateProformaForOrder()` called
`setDepositMilestones()`, which advanced `payment_stage` to `deposit_requested`
**and emailed the customer that a deposit was due**. Issuing a document sent a
demand for money. No person decided it.

| Change | Status | Notes |
|--------|--------|-------|
| Proforma no longer starts the ladder | 🔧 | The deposit/balance split is still calculated and stored — it is arithmetic on the total and telling nobody costs nothing. Only the stage advance and the customer email are gated. **Issuing a document and asking a customer for money are two decisions, and the second one belongs to a person.** |
| `PAYMENT_MILESTONES_AUTO_START` | 🔧 | Default `false`. The old behaviour is one `.env` line away if the business disagrees — config-only, reversible, no code change. Proved by a test that flips the flag and asserts the stage advances. |
| `POST /admin/payment-milestones/request-deposit` | 🔧 | The explicit act. Accepts a percentage **or** an agreed round figure (`deposit_amount` wins and the percentage is derived from it — a deposit is more often a negotiated number than a clean fraction). Refuses a deposit above the order total, and refuses to start a ladder twice. |
| `notify_customer` defaults to **false** | 🔧 | The common case is an order manager bringing the record in line with a phone call that already happened. A duplicate "your deposit is due" is worse than silence — and sending one unasked is the exact complaint being fixed. Opt-in, not opt-out. |
| `deposit-paid` accepts `pending_proforma` too | 🔧 | Money arrives against a quote, or after a call, without anyone pressing "request deposit" first. Refusing to record a payment that is already in the bank because of the order the buttons were pressed in helps nobody. The split is backfilled when it was never set. |
| `payment_milestones_active` on every payload | 🔧 | `pending_proforma` is the resting state of every order, not a milestone. The portal gates the whole panel on this now. Without it the frontend has to infer "not started" from a stage name, which is what produced a payment schedule for an order at stage zero. |

### "Check the EU entry certificate still works"

She was right to ask, and it did not.

**`EuDeclarationController::sign()` gated on `payment_status === 'paid'`.** A
milestone order settles through `payment_stage` — `deposit_paid`, then
`balance_paid` — and **nothing on that path ever writes `payment_status`**,
which stays `pending` for the life of the order.

So every reverse-charge EU B2B order taken on deposit-and-balance terms — the
normal way these are paid, and exactly the customers who need a
Gelangensbestätigung — was permanently refused. Paid in full, delivered, and
told payment must be confirmed first. **Money-facing in the worst way: without
the certificate Okelcor cannot evidence the intra-community supply, so the
zero-rating on that invoice is unsupported in a tax audit.**

| Change | Status | Notes |
|--------|--------|-------|
| Gated on `Order::isFullyPaid()` | 🔧 | The predicate already existed and covers both conventions. It was written for this and simply not used here. |
| `declaration_can_sign` on the customer payload | 🔧 | Derived from the same three conditions the endpoint enforces, so the portal's Sign button cannot offer an action that 422s — nor hide one that would succeed. Any client recomputing this from `payment_status` reproduces the bug, so the field exists to stop that happening again. |

### The milestone audit trail never existed (found on the way)

Adding `deposit_requested` as a log action meant checking the `order_logs.action`
ENUM. The four milestone actions beside it — `deposit_paid`, `balance_due`,
`balance_paid`, `shipment_released` — **had never been in it either.**

Every one of those writes sits behind a `try/catch` that logs a warning and
carries on, so MySQL has been rejecting them since the feature shipped and
nobody noticed. **The payment milestone history — the record of who confirmed a
customer's money had arrived — does not exist on production for any order.**
Those rows are not recoverable.

Eleven values in total are written by shipped code and rejected by the column:
the five milestone actions, `payment_milestone_email_sent`/`_failed`,
`declaration_acknowledged`, `document_superseded`,
`document_generation_blocked_payment_stage` and `proforma_signed_returned`.

**Third time this trap has been walked into** — after `security_events.type`
(Session 73) and `order_logs.action` itself (Session 75, which added two values
without auditing the rest of the column). The lesson that keeps not sticking:
**a `try/catch` around an audit write converts a schema error into silence.**
The catch is right — a failed log must not fail the user's action — but nothing
was reading the warnings. Migration #31 adds all eleven, and its `down()`
refuses to run if any row uses one of them rather than truncating an append-only
trail.

### Document upload — nothing should be unfileable

| Change | Status | Notes |
|--------|--------|-------|
| `type: 'other'` catch-all | 🔧 | `type` drives real behaviour (supersede, payment gating, customer visibility) so it stays a controlled vocabulary rather than becoming free text — but it now ends in a plain filing bucket, so there is always somewhere to put an unusual document. |
| Supersede tested against `OFFICIAL_TYPES` | 🔧 | Was `$type !== 'shipment_document'`, which would have made every `other` upload silently retire the previous one — **a filing bucket that holds one document is not a bucket.** Caught by writing the test for it, not by reading the diff. |
| `GET /admin/trade-documents/upload-options` | 🔧 | Serves both dropdowns. `type_label` ("File as") has **always** been free text on the API, max 100 chars, no allowlist — the closed dropdown was purely frontend, so her request needed no backend change at all except to say so. The endpoint also returns previously-used labels so the field can be a combo box instead of a list someone has to keep in sync. |
| Registered before `trade-documents/{id}/download` | 🔧 | Otherwise `upload-options` is captured as an `{id}`. |
| Backend tests (21 new) | ✅ | `PaymentMilestoneControlTest` — **21 passed / 74 assertions, actually executed** on the minimal-schema sqlite harness. Full suite: **305 passed, 0 failed**, 206 skipped (pre-existing gate), up from 284. |

**Still open:** the five stages render in the portal with a `Resend` control
under each, including stages the order has never reached — a stage that has not
happened has no email to resend. Flagged to frontend as presentation, not API.

See `FRONTEND_NOTE_payment-milestone-control.md`.

---

## Campaign autosave — losing work on tab change (Session 74)

> **Deploy status:** built and tested, **not yet deployed**. Migration #29
> unapplied. Deploy-order safe in one direction only: the API tolerates the
> table being absent for everything *except* the six new draft endpoints, which
> 500 until it exists. The campaign editor itself is unaffected — sending has
> never depended on drafts.

A marketer reported that leaving the Mail Campaign tab for the Media Library
and coming back lost everything she had composed, and asked for autosave.

**The cause was not a UI bug.** `POST /admin/bulk-emails` creates a campaign
**and immediately dispatches it** — it is a send button, not a save. There was
no update endpoint and no draft state, so until a campaign was sent it existed
only in browser memory. Nothing was ever going to survive a navigation.

| Feature | Status | Notes |
|---------|--------|-------|
| `campaign_drafts` table (migration #29) | 🔧 | A **separate table**, not nullable columns on `bulk_email_campaigns`. Three reasons: a draft is legitimately incomplete mid-edit and would fail that table's NOT NULL `subject`/`body_html` (and relaxing those means a MySQL-only ALTER on live send history); half-finished editor state would pollute the campaign list, the `status` index and every count of "campaigns"; and drafts are disposable personal scratch, the opposite of `campaign_templates`, which are deliberate shared designs. |
| Every content column nullable | 🔧 | Autosave fires while the marketer is still typing. A save that refuses incomplete work is a save that does not run exactly when it is most needed. |
| Validation deliberately permissive | 🔧 | Blocks are **not** run through `CampaignBlockRenderer::validateBlocks()` on save — a half-built Button with no URL yet is precisely what autosave must store. Block rules stay enforced at preview and at send, where an error can actually be acted on. Only size caps apply (200 blocks, 512KB HTML), to stop a runaway autosave writing megabytes per keystroke. |
| `GET /admin/campaign-drafts/latest` | 🔧 | What the editor calls on load. Returns `data: null` rather than 404 when there is nothing to restore, so a normal empty state is not an error the client has to special-case. |
| An empty draft is never offered for restore | 🔧 | The editor opening and autosaving a blank canvas would otherwise produce a "restore your work" prompt that restores nothing — which trains the marketer to dismiss the prompt, including the times it mattered. |
| Autosave is a **full replace**, not a merge | 🔧 | `PUT` with an absent key means "empty", not "leave alone". Under merge semantics, deleting the last block would be inexpressible and the blocks would reappear on restore. |
| Drafts are private to their author | 🔧 | Every query scoped to the caller; another admin's id returns **404, not 403** — same rule as partner sales, same reason: a 403 confirms the id exists. A colleague's discard cannot destroy someone else's work. |
| Capped at 20 per author, pruned on write | 🔧 | Autosave creates rows casually. Enforced on write rather than by a scheduled command, because nothing guarantees a scheduler runs on this host. |
| **Bug found by its own test** — non-deterministic prune | ✅ | `pruneFor()` ordered by `updated_at` alone. MySQL timestamps have second resolution and autosave writes several rows per second, so "keep the newest 20" was non-deterministic — a prune could have discarded the draft being typed into. Tie-broken by `id` now. |
| Draft retired when the campaign sends | 🔧 | `POST /admin/bulk-emails` accepts an optional `draft_id`. Deleted **only after** the campaign is safely queued — deleting earlier would destroy the marketer's only copy if any step above failed. Scoped to the caller, and silent on an unknown id: the campaign did send, and failing the request over draft bookkeeping would tell her the send failed when it did not. |
| Backend tests (12 new) | ✅ | `CampaignDraftTest` — **12 passed / 90 assertions, actually executed**, including the real migration file applied and re-applied. Full suite: **259 passed, 0 failed**, 206 skipped (pre-existing gate), up from 247. |
| Test-harness trap documented | ✅ | `auth:sanctum` memoises the resolved user on the guard instance, and that instance survives between requests inside one test method — so a second request made as a different admin was still served as the first. The privacy test passed for the wrong reason until `forgetGuards()` was added. Worth knowing for any future multi-admin test; `PartnerAuth` is immune because it resolves the token itself. |

**The autosave is the safety net, not the actual fix.** The marketer left the
tab to fetch an image from the Media Library, and `GET`/`POST /admin/media`
have existed since Session 51 — so an in-place image picker needs no backend
work at all and removes the trigger entirely. Flagged to frontend as the
higher-value half; see `FRONTEND_NOTE_campaign-autosave.md`.

---

## Order totals doubling — €15,000 order shown as €30,000 (Session 75)

> **Deploy status:** the *first* version of this work (`8d141da`) reached
> production — that is how `orders:repair-totals --fix` came to be run there.
> **The corrected version (`1e3ae7b`) has not.** Until it is deployed, the
> command on the live host is still the one that misdiagnoses 19 of 21 orders;
> do not run it with `--fix`. Migration #30 also pending — both commands update
> the order and then fail on the audit-log insert without it. See the
> outstanding-actions table at the top of this file.

An order manager reported an order showing one line — 2,000 tyres at €7.50,
subtotal €15,000 — under a total of **€30,000**. Exactly double.

**Real backend bug, and money-facing.** The total is what goes on the proforma
and the commercial invoice, so an order in this state overcharges the customer
by 100% on paper.

**Cause.** An order recorded without line items (`POST /admin/orders` with a
hand-typed `total` and no `items`) stores that figure in **both** `subtotal`
and `total` as a stand-in for line items that do not exist yet. `Add Item` then
applied the new line as a **delta on top of the stored subtotal** — so the
first item added to such an order counted the same money twice. The
give-away in the report is the `—` in the SKU column: `POST /orders` requires a
SKU on every item, `Add Item` does not, so that line was added after the fact
to an order that began as a lump sum.

| Change | Status | Notes |
|--------|--------|-------|
| `Order::recalculateTotalsFromItems()` | 🔧 | **Items are the source of truth**, not a delta on whatever subtotal happened to be stored. Re-derives `subtotal` from `SUM(line_total)` and carries the change into `total`. |
| Non-line charges preserved, not recomputed | 🔧 | Delivery, tax, discount and whatever an imported order folded in are carried across as `total − subtotal`, rather than rebuilt from columns — that relationship is **not** consistent across the four order sources (website, eBay, Wix, manual). A €150 delivery survives an item price correction instead of being absorbed. |
| No-op for an order with no items | 🔧 | There the hand-typed total is the only record of what the order is worth; recomputing would zero it. This is also why `store()` must keep writing `subtotal = total` for itemless orders — see the comment there. Setting it to `0` would make the entire order value read as "extras" and reintroduce the double count from the other side. |
| Same fix in the revision-approval path | 🔧 | `AdminOrderFinancialsController::approveRevision` had the identical delta logic for locked orders and the identical bug. Totals now re-derived once after all item changes. Only when items actually changed — a delivery-fee-only revision must not quietly restate the subtotal of an imported order whose items were never itemised in full. |
| `php artisan orders:repair-totals` | 🔧 | Surveys orders whose stored subtotal disagrees with their items, **classifies them by cause**, and repairs only the double count. Writes only with `--fix`. **Skips orders with locked financials** unless `--include-locked`: a commercial document has already been issued carrying the wrong figure, and correcting the order does not supersede it. Every repair writes an `OrderLog` (`totals_repaired`). |
| `php artisan orders:restore-total` | 🔧 | Writes explicit figures back onto one named order, with a required `--reason` and a confirmation prompt. Exists to undo a bad automated correction — no detection, no sweep. |
| ENUM widening (migration #30) | 🔧 | `order_logs.action` is a MySQL ENUM and rejected `totals_repaired`. **The same trap already documented for `admin_users.role` and `security_events.type`, walked into anyway.** Adds `totals_repaired` + `totals_restored`. |
| Backend tests (16 new) | ✅ | `OrderTotalFromItemsTest` — **16 passed / 73 assertions, actually executed** on the minimal-schema sqlite harness rather than behind the MySQL gate that skips `AdminOrderItemEditingTest`. Verified to fail against the old logic first, reproducing exactly `30000.0`. Includes all 21 real production rows fed through the classifier, asserting only the 2 lump-sum orders are touched. Full suite: **275 passed, 0 failed**, 206 skipped. |

### The first repair command was wrong, and ran against production

`--fix` was run before the ENUM widening existed. It updated order **10112**
(371.88 → 312.50) and then died on the log insert, leaving one order corrected
with no record of why and the other 20 untouched. **10112 is still at the wrong
figure on production** — restoring it is item 1 in the outstanding-actions table
at the top of this file. Two separate faults:

**1. No transaction.** The order write and its audit log were separate
statements, so a failing log left a silently modified order. Both commands now
write the order and its log in one transaction.

**2. Far worse — the diagnosis was wrong for 19 of the 21 orders.** The command
treated "subtotal ≠ items sum" as proof of a fault. It is not. The survey it
produced, read properly:

| Group | Count | Ratio | What it actually is |
|-------|-------|-------|---------------------|
| `admin_manual`, exactly ×2.0000 | 2 | 2.0000 | **The real bug.** AB-1150 and AB-1182. |
| `website`, exactly ×1.19 | 11 | 1.1900 | German 19% VAT. `WixOrderImportService::mapOrder` stores the **gross** figure the customer paid in `total`/`subtotal`; `mapItems` imports the **net** line prices. Totals are correct. |
| `website`, ×1.3090 | 2 | 1.3090 | Same, plus shipping: `(577.52 + 57.75) × 1.19 = 755.98`. Correct. |
| `website`, items **exceed** total | 5 | 0.43–0.49 | Inconsistent ratios, cause unknown. Needs a person against what was invoiced. |

Running `--fix` to completion would have **cut 13 live customer orders by 19%**
and written five more to figures nothing supports. The command now fixes only
the signature it can positively attribute — source `admin_manual`, no non-line
extras, stored subtotal exactly twice the items, since `admin_manual` is the
only source that can record a lump sum with no line items. A ×2 ratio on a
`website` order is a coincidence, not a diagnosis, and is left alone. VAT-rate
and items-exceed-total groups are named in the output so the survey says what
it is looking at rather than calling everything broken.

**The lesson worth keeping: a mismatch is not a fault.** Four order sources
(website, eBay, Wix, manual) populate these columns on four different
conventions, and no single rule spans them. Anything sweeping money columns
across all four has to prove which convention each row follows before it
writes.

**The audit log was wrong too, in the same direction.** `item_added` recorded
`order total: X → Y` from the same bad arithmetic, so anyone reconciling from
the log would have been sent after a €30,000 order that never existed. Now
logs the figure actually written.

---

## InDesign design import — campaigns without a developer (Session 77)

> **Deploy status:** built and tested, **not yet deployed**. **No migration** —
> reuses `campaign_templates` and `media`. Deploy-order safe in both directions:
> the endpoint 404s until the code lands and nothing else is affected.
> `route:cache` must be rebuilt — one new route.

The marketers design in InDesign, because that is where they can produce
something that looks good, and export **HTML5 (Publish Online)**. Each export
was then handed over to be turned into a campaign by hand — so every new design
was a backend job. The ask was to stop that: the system should take the folder.

**An InDesign HTML5 export is not an email, and no amount of pasting makes it
one.** It is an `<iframe>` on a fixed 595×1089px print canvas; every element is
`position:absolute` under a CSS `transform`; **every individual word is its own
`<span>` at an exact pixel offset** inside a wrapper scaled by 0.05, so the type
is really set at 420px and shrunk; the fonts are `@font-face` TTFs. Outlook
supports none of that and Gmail strips most of it. Sent as-is the design does
not degrade — it collapses into an unreadable pile of words.

So the importer does not embed the export. It **recovers** it into the existing
block model, which `CampaignBlockRenderer` already renders Outlook-safe.

| Feature | Status | Notes |
|---------|--------|-------|
| `InDesignEmailImporter` (new) | 🔧 | ZIP → `blocks[]` + `theme` + Media Library rows. No new storage concept: the output is an ordinary campaign template, so the editor, the preview, the queue and the send job are all untouched. |
| Copy reassembled from per-word spans | 🔧 | Spans sharing a `top` are one visual line, ordered by `left`; one `<p>` is one paragraph, its several `top` values are just where InDesign broke the column. Lines are rejoined **with a space** — a break inside a paragraph is justification, not the author's intent, and hard-wrapping it would give ragged text at every other width. |
| Reading order from the page, not the markup | 🔧 | Document order in the export is InDesign's **stacking** order. Position comes from each container's `transform:translate(x,y)` in the generated stylesheet, accumulated down the tree so a nested frame is absolute on the page. Without this the email opens on whatever the designer drew first. |
| Photographs → Media Library | 🔧 | Through `MediaLibraryService`, so they are browsable and reusable from the existing picker — the "reuse" half of the ask. The next campaign needs no import at all. |
| Hairlines → `divider` blocks, not images | 🔧 | InDesign draws the gold rules under its headings as PNGs. Imported as images they become full-width bars in the email and permanent junk in the Media Library. Recognised by shape (ratio ≥ 6:1 **and** short side ≤ 80px — the ratio alone would catch a legitimate wide banner) and emitted as the divider they were drawn to be. Consecutive ones collapse; an email never opens on one. |
| Bullet glyphs and slivers dropped | 🔧 | Below 200px long / 40px short is furniture, not content. Counted back in `warnings` rather than silently discarded. |
| Bulleted runs recovered as one `list` | 🔧 | InDesign draws bullet marks as separate images, so length is the only signal left. The run is scanned for **stretches** of short paragraphs rather than judged as a whole — a list is normally introduced by a sentence longer than its items, and testing the whole run turned ten bullets back into ten loose paragraphs. Caught by the probe against the real export, not by reading the diff. |
| Heading hierarchy from the document's own type scale | 🔧 | Body size = the size carrying the most characters (mode, not mean — one huge display line would otherwise drag the baseline up and demote every real heading). Distinct larger sizes rank large/medium/small. A line over 120 chars stays a paragraph however it is set, so a large-type pull quote does not become a heading the width of the email. |
| **Colour is checked, not just copied** | 🔧 | The failure this prevents is worth naming: InDesign sets this deck's type in white because it sits on a full-bleed photograph, and that background cannot carry into email. Taking the colours at face value sends **white text on the white page colour** — an email that arrives blank and passes every check that isn't a person looking at it. Below WCAG 4.5:1 the house theme wins and the marketer is told why. |
| `POST /admin/campaign-templates/import` | 🔧 | `marketing.manage`. Multipart ZIP + `name`, or `dry_run: true` to convert and return **without saving anything** — the review step, and it costs nothing. Returns blocks, theme, media, warnings and `preview_html` (the real rendered email) in one call, so the editor needs no second round-trip to find out whether the import was any good. Registered **before** `campaign-templates/{id}`, which has no numeric constraint — the same trap as `trade-documents/upload-options` in Session 76. |
| An admin upload is still an untrusted archive | 🔧 | Zip-slip refused lexically **before any directory is created**, so a traversal never causes a write; entry count, uncompressed size and per-extension filters applied during extraction. Scripts and fonts are never written to disk at all. Remote `src` attributes are skipped, not fetched — an import must not become an outbound request to an address chosen inside the archive. Workspace deleted in a `finally`. |
| Produced blocks pass the same gate a hand-built template does | 🔧 | `validateBlocks()` before saving. A template that cannot render is not worth a row, and finding that out at send time is far more expensive. |
| Backend tests (20 new) | ✅ | `InDesignCampaignImportTest` — **20 passed / 73 assertions, actually executed** on the minimal-schema sqlite harness. Includes an end-to-end conversion of **the marketers' real export**, which skips cleanly where the folder is absent (it is 2MB of marketing source and deliberately not committed). The synthetic fixture reconstructs a real InDesign export down to the 0.05 scale wrapper rather than checking in a large binary. Full suite: **325 passed, 0 failed**, 206 skipped (pre-existing gate), up from 305. |

**The honest limit, carried into the frontend note:** pixel-perfect InDesign
fidelity is not achievable in email by any method. What survives is the
imagery, the words, the order and the colours. The import is a **starting
point** the marketer finishes in the editor — which is also why a mis-grouped
list or a heading read one level too small is a five-second fix rather than a
failed import. The frontend was told to frame it as *"Design imported. Review
it before sending."*; framed as *"your InDesign design, converted"*, the first
person to compare it side-by-side with InDesign reports it as broken.

**Always true of these exports: there is no call-to-action button.** InDesign
carries no links, so every import warns about it. Someone has to add one before
the campaign goes out.

### Frontend review — two findings, both real

Frontend built and verified the screen (three-step flow off the template
picker, `dry_run` review, then save) and came back with two things.

| Finding | Status | Notes |
|---------|--------|-------|
| **Reviewing duplicated the images** | 🔧 fixed | Reviewing one export three times before saving left three copies of its photographs in the Media Library, and the save added a fourth. Reviewing is precisely what a dry run is *for*, so the feature working as intended was filling the library up. Conversions are now keyed on the **archive's own content hash**, cached two hours: same bytes → same conversion and the same media rows, whoever uploads them; edited bytes → a fresh conversion, so an edit is never served a stale design; media since deleted → the reuse is dropped rather than returning blocks pointing at dead URLs. Keyed by content rather than by uploader or name deliberately. A checksum column on `media` would be the more general fix but is a migration on a live table for a problem confined to this one flow. |
| **`invalid_blocks` was missing from my note** | ✅ | My documentation error, found by them reading the controller rather than the note. The endpoint returns two 422 codes and the note described one. Fixed — and it is the second time in three sessions a frontend deploy has been shaped by this note being written from memory instead of from the source (cf. the partner login response shape, Session 75). |

### `dry_run` rejected in production on the first real upload

The marketer zipped the export, clicked *Read the design* and got
**"The dry run field must be true or false."** A 422 on the very first real
use, from a validation rule of mine.

**Cause.** The endpoint is `multipart/form-data`, and multipart carries every
field as a **string** — so the browser's `FormData` sends `"true"`. Laravel's
`boolean` rule accepts `1`, `0`, `"1"` and `"0"` and nothing else, so it
refused. `$request->boolean()` — used for the actual logic — has always
accepted `"true"`, so only the rule was wrong; the feature underneath was
correct the whole time.

**Why the suite did not catch it, which is the part worth keeping.** Every
existing test passed `'dry_run' => true` as a real PHP bool. Laravel's test
client puts that straight into the parameter bag, so it never becomes a string
the way an actual multipart request does. **28 passing tests against a request
shape no browser can send.** A multipart endpoint has to be tested with
multipart values, or the suite is agreeing with itself.

| Change | Status | Notes |
|--------|--------|-------|
| Recognised spellings normalised before validation | ✅ fixed | `filter_var(..., FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)` covers `true/false/1/0/on/off/yes/no` in either case. |
| An unrecognisable value still 422s | ✅ | It becomes `null` and fails the rule rather than being read as `false` — and `false` means *save*. A typo must not quietly write a template the marketer was only previewing. |
| Tests sending the browser's actual wire format (5 new) | ✅ | `"true"`, `"1"`, `"on"` via a data provider; `"false"` still saves; `"banana"` is refused and writes nothing. Full suite **333 passed, 0 failed**, 206 skipped. |

**Upload ceiling — 50MB on the API, 4.5MB in production.** Frontend
established, against Vercel's own docs, that the upload crosses a Next.js route
handler and Vercel caps Function request bodies at 4.5MB with a 413 before any
application code runs. Not tunable: `bodySizeLimit` covers Server Actions only.
Their handling (warn from 4MB, catch 413 on status since the body is Vercel's
HTML page, word it so the marketer knows the limit is the site's not their
file, and no client-side block since a self-hosted deploy really does take 50MB)
is right and stays.

**The cheap fix applies first, and probably settles it.** The importer already
downscales every image to 2000px on its longest side and re-encodes at JPEG 90
— anything above that is discarded on the way in, so a higher-resolution export
costs upload budget and produces a byte-identical email. The real Fuel Eco Tech
export is **1.6MB**, comfortably inside the ceiling. Telling the marketers to
export at *Medium* / 150ppi is a zero-code answer to the common case.

**If a real export ever does exceed 4.5MB, two ways up — neither built, on
purpose:**

1. **Direct browser → API upload.** Needs an upload-ticket endpoint (the
   `admin_token` is httpOnly, so the browser cannot authenticate to Laravel
   itself) plus CORS on the API host. Real, and a real security surface: a
   bearer-equivalent credential outside the normal token path, wanting
   single-use, a short TTL, binding to the issuing admin and this one action,
   and a header rather than a query string so it stays out of access logs.
2. **Split the archive client-side.** Frontend strips the images out, uploads
   the (tiny) HTML+CSS, and sends each image through the **existing**
   `POST /admin/media` — individually well under 4.5MB — then passes a
   filename → media_id map to the importer. No new credential path at all, and
   it reuses an endpoint that has existed since Session 51. Costs an optional
   `media_map` parameter here and archive-splitting there.

Option 2 is the better trade if this becomes real, precisely because it adds no
new way to authenticate. Neither is worth building against a limit their actual
usage is at a third of.

See `FRONTEND_NOTE_indesign-campaign-import.md`.

---

## Campaign blocks learn to hold two things side by side (Session 78)

> **Deploy status:** built and tested, **not yet deployed**. **No migration, no
> new route** — three block types, one preset, and importer inference. Nothing
> existing changes shape.

Frontend's report, and the diagnosis was theirs: three industry photographs sit
in a row in the source deck and stacked vertically after import. **Not an
importer bug.** Every block in `CampaignBlockRenderer::BLOCKS` was a single
full-width element concatenated into one column, so three stacked images was
the only output available. The same gap explained the missing green bands and
the missing benefit grid.

| Feature | Status | Notes |
|---------|--------|-------|
| `image_row` block | 🔧 | Two or three pictures across one row, fixed `image_1/2/3` slots typed `image_url` **exactly as frontend specified** — so it appears in their composer with the Media Library picker attached and needs no frontend deploy. Tables throughout; `.ok-stack` (already in the head for the footer) stacks it on a phone. One image falls through to a full-width `image` rather than being stranded at a third of the width beside two empty cells. |
| `section_header` block | 🔧 | The coloured bands. `full_bleed` / `inset_pill`, and **named tones rather than a colour field** — frontend's call, and the right one: it keeps the colour decision in the theme so a band cannot drift from the campaign one edit at a time. `dark` and `muted` pick their text colour by luminance, since the same tone is near-white in `light` and near-black in `okelcor_dark`. |
| `cards` block + `group_list` field type | 🔧 | The 12-tile grid. Took frontend's option (b) — a list of objects with declared sub-fields — over fixed slots, because fixed slots meant 18 inputs across four blocks every time. `item_fields` is an object keyed by field name whose values are the same field specs already rendered, so `group_list` is a container and its leaves are types eight blocks already use. Errors name the entry, not the index. A short final row is padded so its tiles keep their width. |
| `fet_green` preset | 🔧 | `#1F8A5B`, **read out of the marketers' InDesign file, not chosen.** Deliberately not the `#22c55e` the FET web UI documents: that is an accent tuned for buttons on a dark interface and reads neon on a white email band. The dark tone *is* the documented `#0D2B1A`. One constant either way if the business disagrees. |
| **Importer emits `image_row`** | 🔧 | The images were never stacked in the export — they share a `y` and differ in `x`, which is what "in a row" looks like on a page. Nothing read that until now. |
| **Bug found by its own test** — row order | 🔧 | Row members were emitted in collection order. Items are sorted top-to-bottom first and InDesign nudges pictures in a row a pixel or two apart vertically, so that order shuffled them **across** the row. Ordered by real `x` now. This was wrong on the real export, not just in theory — the middle picture changed when it was fixed. |
| **Importer recovers the bands** | 🔧 | They are in the export as flat `#1F8A5B` rectangles and were being thrown away as dividers. A rectangle of **one flat colour** with a title sitting on it is a band — paired by vertical overlap, `inset_pill` vs `full_bleed` inferred from its width against the page. Flatness is what separates it from the gold hairlines, which stay dividers, and from a band-shaped photograph, which has detail. The rectangle is no longer *also* emitted as a divider, or every section title would carry an empty coloured bar above it. |
| **Importer picks the preset** | 🔧 | By matching the recovered band colour against each preset's own accent. A Fuel Eco Tech deck lands on `fet_green` by itself, card surface and dark tone included — which no amount of per-campaign colour override would have produced. |
| **Bold runs inside paragraphs** | 🔧 | Frontend marked body paragraphs as working, and they were — but the bold runs *inside* them were not. Each paragraph took its single dominant character style and discarded the rest, so "**Fuel Eco Tech (FET)** offers" came through flat. Re-expressed in the existing inline syntax, with the spaces moved outside the markers: InDesign puts the trailing space inside the styled span, so marking it verbatim produced `of** 15%–35% **`, which renders as literal asterisks. |
| Backend tests (18 new) | ✅ | 11 in `CampaignBuilderTest` (row/band/card rendering, escaping, `group_list` validation, every preset declaring the new keys) + 7 in `InDesignCampaignImportTest` (row detection and ordering, band recovery, photograph-not-a-band, preset selection, bold runs). **Full suite 353 passed, 0 failed**, 206 skipped, up from 334. |

**Answered for frontend:** yes, inline markdown applies to list items —
`list()` runs each through the same `inline()` as everything else, so
`**Marine** – Boats, ships…` renders bold, confirmed from source.

**The one thing no block fixes.** In this export InDesign **flattened the card
grids into PNGs** (`19.png`, `20.png` — open one, it is a picture of four
cards). That text is not in the export at all, so no importer can recover those
tiles; they arrive as images, which render but are unselectable and do not
reflow. `cards` is for hand-authoring until a future export keeps the grid as
live text. **Worth telling the marketers: don't flatten the benefit grid on
export.** Same for the hero band — text over a full-bleed photograph has no
email equivalent, and frontend's recommendation stands: flatten that one region
to a single image with strong alt text, hero only, never body copy.

### Reopening a design showed a different design

The import rendered correctly, then "use this design" showed the previous
stacked-and-gold layout again.

**Two renderers, one set of blocks.** The post-upload preview shows the
`preview_html` this backend rendered. The editor preview was drawing the blocks
itself, and had no rendering for `image_row` or `section_header`, so it fell
back to stacked images and plain headings. Only one of the two was what would
actually be sent.

**Not a bug to fix once.** This is where new block types arrive, so the client
being one deploy behind the block vocabulary is the normal, permanent state. A
preview that depends on the client knowing every type breaks again with the
next one — which is the same shape as the campaign-list-vs-detail asymmetry
that produced the empty-preview report a session earlier.

| Change | Status | Notes |
|--------|--------|-------|
| `preview_html` + `preview_text` on `GET /admin/campaign-templates/{id}` | 🔧 | Rendered from the stored blocks by the same renderer the import used. A test asserts it is **byte-identical** to the import response's, so "same as it looked on upload" is a guarantee rather than a hope. |
| `preview_html` on `GET /admin/campaign-templates/starters` | 🔧 | Same reason — "use this design" applies to the built-in starters too. |
| Cache keyed on the converter, not just the archive | ✅ | Found while diagnosing this: the conversion cache used the uploaded archive's hash alone, so for two hours after a deploy that changes how a design is read, re-uploading the same file returned the **previous** reading. Indistinguishable from the deploy not having happened — and re-uploading is the first thing anyone does to check. Now keyed on a fingerprint of the converting source files, derived rather than a constant someone must remember to bump. |
| Backend tests (2 new) | ✅ | A reopened design renders identically to its import, and the cache key carries a version segment. Full suite **355 passed, 0 failed**, 206 skipped. |

**Left to frontend, and deliberately:** the editor's *block list* still shows
"can't edit here, will still send" for unknown types, which is correct and
should stay until `group_list` lands. It just must not drive the preview.

See `FRONTEND_NOTE_indesign-campaign-import.md`.

---

## Customer behaviour analytics — what people look for (Session 79)

> **Deploy status:** built and tested, **not yet deployed**. Migration **#32**
> (`search_events`) unapplied. **Deploy-order safe in both directions** — the
> recorder checks for the table and skips when it is absent, so a catalogue
> search never fails because a reporting table has not been created yet, and
> the report says "not active yet" rather than "no demand". `route:cache` must
> be rebuilt — one new route.

The AI insights tool summarises how the business is doing. The ask was for the
other question: what are customers *looking for*, what do they search most, so
the platform, the range and the UX can be improved on evidence.

**The blocker was that nothing recorded a search.** The catalogue accepts a rich
filter set — term, type, brand, season, size, width/height/rim, price, stock —
and threw every one of them away after answering. So "what do they search for"
was not a query anyone could run; it was data that did not exist. Sessions 63
and 71 had both noted analytics live entirely frontend-side, and this is the
part of it the API is actually in a position to see.

| Feature | Status | Notes |
|---------|--------|-------|
| `search_events` table (migration #32) | 🔧 | One row per catalogue query: normalised term, the filters as sent, the individual dimensions worth grouping lifted out and indexed, the result count, and **whether anything was found**. Deliberately not a general event table — page views and click paths never reach this API, and a table shaped to hold them would imply they were being collected. |
| Recording on the public catalogue | 🔧 | Fires after the count is known, so "searched fifty times, found nothing" is answerable. **No frontend change needed** — the frontend already calls this endpoint with these filters, so collection starts the moment it deploys. |
| Page 2 is not a second search | 🔧 | Counting pagination again would make a result someone had to scroll through look more popular than one they found immediately, which is the opposite of the truth. |
| **No personal data stored** | 🔧 | No IP, no user agent, no referrer. `visitor_hash` is a salted HMAC that **includes the date**, so a day's visitors can be counted and the same person cannot be followed across days — "unique visitors today" is answerable, "everything this person ever searched" is not. Asserted by test, not just intended. The frontend may optionally send `X-Okelcor-Visitor` for accurate counts (every request otherwise carries the Next proxy's address); optional because storing an id in a browser is a consent question in the EU and that is not a decision to make on the customer's behalf. |
| `GET /admin/analytics/behaviour` | 🔧 | `analytics.view`. Chart-ready: a gap-free daily series, top searches, **unmet demand**, demand-vs-stock, brand/size/category/season/country demand, saved-fitment demand, a funnel and the signed-in share. Counts already computed so the client plots rather than aggregates. |
| **Unmet demand — the point of the whole thing** | 🔧 | Terms searched repeatedly that returned nothing every time. Each row is either a product to stock or a word the catalogue does not recognise for something already sold. Nothing below 2 occurrences is reported: one person searching once is not a pattern, and presenting it as one sends someone off to stock a product nobody asked for. |
| **Demand vs stock** | 🔧 | Brands people search for, against whether they can actually be bought — `not_stocked` / `all_out_of_stock` / `available`. Sales figures cannot show this: a product nobody could buy sold nothing, which looks identical to a product nobody wanted. |
| Behaviour added to the AI insights | 🔧 | New `behaviour` category, fed the same aggregates **as facts to restate** — the rule the stockout forecast already follows, so a business decision is never a hallucination. The prompt is explicit that if recording is not live the model must say nothing about behaviour, because silence and "no demand" are different statements. |
| Funnel is honest about what it is not | 🔧 | Searches are anonymous and orders are not, so no row is joined to another. Three counts of three populations over one period, with a `note` in the payload saying exactly that. A funnel implying individual progression from data that cannot support it would be a more confident lie than no funnel. |
| Backend tests (22) | ✅ | `CustomerBehaviourAnalyticsTest` — **22 passed / 140 assertions, actually executed**, including the real migration file. Covers recording, pagination, the no-PII guarantee, the digest not surviving a day change, every aggregation, the empty state, and the AI snapshot. Full suite **377 passed, 0 failed**, 206 skipped, up from 355. |

**What this cannot see, said plainly and returned in `meta.not_covered`:** page
views, scroll depth, click paths, time on page, and which product cards were
looked at but not clicked. None of it reaches the API. That is a frontend
analytics product's job, and claiming it here would mean inventing it.

**It will be empty on day one.** The table starts empty and fills as customers
use the catalogue; a week is the first point at which "top searches" means
anything and "unmet demand" is worth acting on. The report says `available:
false` before the migration runs rather than reporting zeros, because zero
searches and no recording are different claims.

See `FRONTEND_NOTE_behaviour-analytics.md`.

---

## Campaign email on small screens (Session 80)

> **Deploy status:** built and tested, **not yet deployed**. No migration, no
> new route, no contract change. Affects the rendered HTML of every campaign.

The imported design was reported as "still wrong" three times before the actual
condition surfaced: **it reads correctly on a desktop and badly on a phone.**
Every earlier round was chasing a layout that had been right since Session 78.

Three real defects, all mine, all introduced by making `card_width` variable in
Session 78 without moving what depended on it.

| Change | Status | Notes |
|--------|--------|-------|
| **Breakpoint derived from `card_width`** | 🔧 | It was hardcoded at `620px` while `card_width` became a per-theme setting reaching 800 — and `fet_green`, the preset the imported deck selects, is **680**. So every viewport between 621 and 680 kept the full-width card and scrolled sideways instead of collapsing. Now `card_width + 40`, with a test asserting the breakpoint exceeds the card width **for every preset**, so a future preset cannot reintroduce it. |
| **Spacer cells hidden instead of stacked** | 🔧 | `cards` pads a short final row with empty cells so its tiles keep their width on a wide screen. Those cells carried `ok-stack`, so on a phone they became empty full-width blocks — stray gaps in the middle of the grid. New `ok-hide-sm` removes them below the breakpoint. |
| **Full-width images sized to the card** | 🔧 | The image block hardcoded `width="552"`, correct for a 620px card and 60px too narrow in a 680px one, leaving a visible margin down one side of every picture. Derived from `card_width` now. |
| **`full_bleed` bands are now actually full bleed** | 🔧 | Not a mobile bug, found while fixing them, and the most visible one. Every block shared a single cell padded 34px each side, so a band named *full bleed* was inset by 34px — which is the one thing the name promises. `render()` now emits one row per block and a block declares whether it sits inside the card's padding. The source deck's green bars run edge to edge; ours did not. |
| `img { max-width: 100% }` on small screens | 🔧 | A picture wider than the viewport is the commonest cause of an email that scrolls sideways, and an imported photograph is whatever size InDesign exported it at. |
| Backend tests (7 new) | ✅ | `CampaignBuilderTest` — breakpoint vs card width across **every** preset and a custom width, full-bleed placement, spacer hiding, image sizing, and a sweep asserting **every percentage-width cell in any block either stacks or hides**. That last one is the general guard: a column that never collapses is a column squeezed into a third of a phone screen, and it would otherwise be found the same way this was. Full suite **384 passed, 0 failed**, 206 skipped. |

**Worth recording about the diagnosis, not the code.** Three rounds were spent
on "the preview shows the old layout" — a stale deploy, then a client-side
renderer, then a saved template — and the actual condition was never in any of
those. Nobody said which screen size until the fourth report. The preview has a
desktop/mobile toggle; **the first question about a rendering complaint should
be which of the two it was.**

See `FRONTEND_NOTE_indesign-campaign-import.md`.

---

## Text printed on a picture (Session 81)

> **Deploy status:** built and tested, **not yet deployed**. No migration, no
> new route, no contract change. One new block type; nothing existing changes
> shape.

Two complaints, reported together. The second is not a backend problem at all
and is written up for frontend rather than fixed here.

**"The header text in the banner is below the image after export."** Two causes,
and the second is the interesting one.

The importer was not reading the relationship: in the export the masthead
photograph is one frame and each line of type is another, and the type frames sit
*inside* the picture's box. That containment is the only record that the words
are printed on the artwork. Nothing read it, so the only ordering left was
top-to-bottom by `y` — picture at `y=-3`, headline at `y=75`, and down they went.

**But there was also nowhere to put them.** Every block was a single full-width
element stacked on the one before it, so no block held text and an image in the
same space. Even a correct reading of the export had no way to express it. Same
shape as Session 78's side-by-side finding, one layer up: that was two things
beside each other, this is two things on top of each other.

| Change | Status | Notes |
|--------|--------|-------|
| `hero` block | 🔧 | A picture with words on it, in one of **nine positions** (`top_left` … `bottom_right`) — `valign` × `align` on a table cell, which is the entire positioning vocabulary email has that survives Outlook. Plus `text_color`, an `overlay` scrim, a `height` that behaves as a minimum, and a `link` on the text. Full-bleed: a masthead inset by 34px is a picture with a white frame round it. |
| Three renderings of the same banner | 🔧 | `background-image` + `cover` for Apple/Gmail/iOS; a VML `<v:rect>` behind a conditional comment for Outlook 2007–2019, which renders through Word and supports no CSS background image at all; and `bgcolor` for images-off. The fallback colour is chosen from the **text** colour rather than sampled from the picture — a picture that never loads cannot be sampled, and images-off is the normal state of a corporate inbox, not an edge case. |
| A banner with no words is a picture | 🔧 | Falls through to the ordinary image block. An empty coloured strip where a photograph should be is worse than the photograph. |
| **Importer recovers the masthead** | 🔧 | Text frames whose box sits inside a banner-shaped picture's box become its heading and sub-heading, and are **not also emitted underneath** — the duplicate was the actual complaint. Verified against the real marketers' export, not a reconstruction of it. |
| Position and height are read, not defaulted | 🔧 | `middle_center` and `187px` come off the real page — the type is centred and two-thirds down, and 187 is 160pt of a 595pt page rendered into the 680px FET card. Defaulted, every import would land the headline in the middle and the marketer would move it back by hand every time. |
| Two things it deliberately will not do | 🔧 | A caption set immediately beneath a picture stays beneath it (under is where a caption goes). A tall photograph with a label crossing it is not a masthead — a banner must be most of the page wide *and* markedly wider than tall, or a paragraph gets buried in artwork. |
| `control: "position_grid"` hint | 🔧 | `position` is an ordinary `select`, so the editor works with no frontend release. The hint asks for a 3×3 clickable grid instead of a dropdown reading `middle_center`, which is what "easy to move the text anywhere" actually means. Unknown `control` values must fall through to the plain control. |
| Backend tests (16 new) | ✅ | 9 in `CampaignBuilderTest` (all nine positions reaching the rendered cell, an unrecognised position landing centre, the images-off fallback pair, wordless banner, full bleed, escaping, well-formedness with VML, the text part) + 7 in `InDesignCampaignImportTest` (recovery, no duplicate underneath, position read from the page, proportions, the caption guard, the not-a-banner guard, validation) — plus the real-export test extended to assert the masthead end to end. Full suite **400 passed, 0 failed**, 206 skipped, up from 384. |

**The second complaint is frontend's and is not fixed here.** "Highlighting text
in a text box drags the whole box sideways" — sideways, not up or down, which is
a drag ghost following the pointer rather than a layout shift. The drag
affordance is on the whole block card, so a press-and-move starting inside an
input is claimed as a block drag before the browser can begin a selection;
native selection inside a `draggable="true"` subtree is suppressed by the DnD
spec. The fix is a dedicated drag handle plus a pointer activation distance, and
it is written up per-library in the note.

See `FRONTEND_NOTE_campaign-text-position.md`.

### Session 82 — answering the frontend report

Frontend built the position grid and fixed the drag (the whole card was
`draggable`, exactly as diagnosed; this repo uses native DnD, so the dnd-kit
advice in the note did not apply). Three things came back.

| Change | Status | Notes |
|--------|--------|-------|
| **`group_list` served in one shape** | 🔧 | The blocker, and it was mine. `item_fields` was an object keyed by field name while a block's own `fields` were a list of objects carrying `name` — same concept, two shapes, so a renderer for one could not be reused for the other. `design()` now flattens it the same way, recursively. Nothing consumed the old shape, so a fix rather than a break. Frontend was right to stop rather than guess: an invented item-field key would have had nothing to disagree with it until production. |
| **A `url()` injection in `hero`** | 🔧 | Found by taking their CSS finding seriously against my own code. `hero` is the only place a URL enters a CSS declaration rather than an HTML attribute, and `e()` is not sufficient there — the HTML parser decodes `&#039;` back to `'` before the CSS parser sees it, and `filter_var(FILTER_VALIDATE_URL)` accepts apostrophes, brackets and semicolons in a path (**checked, not assumed**). An `image_url` pasted as `https://…/a');background:red;x=('.png` closed the string and injected declarations into the sent email and the admin preview. Percent-encoded rather than rejected — those characters are legal in a URL, so `tyre_(winter).png` still works. Requires `marketing.manage`, so low severity; still a stored injection reachable from a form field. |
| **An empty group row no longer blocks saving** | 🔧 | An "add another" button produces one every time it is pressed. Refusing to save over it makes the editor feel broken, and `cardItems()` already dropped it at render — so what validated and what sent disagreed. A row carrying only a client-side id counts as empty; a partly-filled row is still an error. |
| Backend tests (4 new) | ✅ | The injection (encoded in all three places it appears, brackets preserved), empty and partly-filled rows, `item_fields` shape at every depth, and the `hero` block reaching the catalogue with its nine options. Full suite **404 passed, 0 failed**, 206 skipped. |

**Correction accepted, on index keys.** The mechanism given in the note was
wrong — controlled inputs re-render without remounting, so the caret does not
move. The real cost is each row's local UI state binding to a position, so
deleting block 2 of 5 strands the image field's flags on the wrong block. Same
fix, different failure.

---

## Operations board, dual sign-off, eBay split (Session 83)

> **Deploy status:** built and tested, **not yet deployed**. Migrations **#33–36**
> unapplied, 9 new routes, `route:cache` required. Deploy-order safe in both
> directions — proved by test, not assumed.

From the finance director's sketch (`WhatsApp Image 2026-08-13 at 13.46.11.jpeg`):
a board of one row per sales channel and seven columns, plus four things around
it that the sketch implies rather than draws.

### The blocker was a role the database could not store

Two signatures — operations and finance — need a `finance` role, and
`admin_users.role` was a MySQL ENUM allowing exactly four values. That has been
the top Known Gap since Session 52, and three sessions have granted a permission
to a deliberately narrowed role list with a comment saying to widen it first.
It stopped being a nuisance and became a hard blocker here.

| Change | Status | Notes |
|--------|--------|-------|
| **#33** `admin_users.role` → `VARCHAR(30)` | 🔧 | Not a wider ENUM. Every ENUM widening in this codebase restates the full value list and is one forgotten entry from truncating data — that has now been paid for four times. The authoritative list is `AdminPermissions::ROLES`, which the create/update endpoints already validate against, so the constraint moves to the place already enforcing it. `down()` **refuses** if any account holds a role the old ENUM could not store, rather than blanking it and locking the person out. |
| `finance` role added; deferred grants closed | 🔧 | `sales_manager` added to `analytics.view`, `partners.view`, `partner_sales.view` and `partner_sales.export` exactly as Sessions 73 and 79 said to. **Read and export only** — verify and correct stay where they were, because widening the column was permission to store the role, not a decision to expand what it does. Five roles the admin panel offers have been silently unstorable all along; they now work. |

### The board

| Feature | Status | Notes |
|---------|--------|-------|
| `GET /admin/operations/summary` | 🔧 | `orders.view`. One row per channel + a total, defaulting to the current month. Orders sent, amount, clients, orders confirmed, website invoices, finance invoices, variance, in transit. |
| **Every column declares how it was counted** | 🔧 | `definitions` ships with the numbers. Seven figures two departments will argue over are worthless if "orders sent" means something different to the reader than to the query. |
| `total.clients` is not the sum of the rows | 🔧 | One buyer who ordered on eBay and on the website is one client; adding the rows reports two. Counted distinctly across both channels. |
| Other currencies are named, not converted | 🔧 | Converting at today's rate would make a historic month's revenue change every time the board is opened. |
| `in_transit` — paid, dispatched, not delivered | 🔧 | The order manager's cue that trade documents need sending. One definition (`Order::isInTransit()` + a matching scope), used by the board, the list filter and the row badge, with a test asserting the scope and the accessor agree — otherwise the count and the badge tell different stories about the same order. |

### The two invoice columns exist to disagree

| Feature | Status | Notes |
|---------|--------|-------|
| **#35** `finance_invoices` + CRUD | 🔧 | `finance.manage`. Finance types in what sevDesk raised. **Deliberately not an integration** — one that silently stopped syncing would make the two columns agree by accident, which is the one failure this board exists to catch. |
| `order_ref` is not validated against `orders` | 🔧 | An invoice finance cannot match to an order here is exactly the row worth recording. Refusing it would hide the discrepancy instead of surfacing it; the payload carries `order_known_here: false`. |
| A duplicate entry is a 422, not a DB error | 🔧 | Entering the same sevDesk number twice would make the two sides agree when they do not. |
| `GET /admin/operations/invoice-reconciliation` | 🔧 | Both sides named, so the answer is "SD-201 has no invoice here" rather than "there is a discrepancy of one". Matched on order ref then our invoice number — **never on amount**, which would pair unrelated invoices that happen to be for the same figure. Reports `amount_mismatch` separately: two systems holding the same invoice at different money is worse than one holding it alone, and is invisible from the counts. |

### Dual sign-off

| Feature | Status | Notes |
|---------|--------|-------|
| **#34** `order_signoffs` + 3 endpoints | 🔧 | `unique(order_id, slot, active)` with `active` as 1-or-NULL — MySQL treats NULLs as distinct, so the database permits any number of withdrawn rows and exactly one standing signature per slot. Enforced by the schema rather than by remembering to check. |
| **Two different people** | 🔧 | The whole point. `super_admin` holds both permissions as break-glass, so without this the control is satisfiable alone — a checkbox, not a separation of duties. `admin` deliberately holds neither half. |
| **Editing the money withdraws both signatures** | 🔧 | Approving €10,000 and then sending a confirmation for €10,500 is worse than no approval, because it carries evidence that two people agreed to it. Fires on `PATCH /financials` and on item edits, only when the total actually moves — a control that fires on nothing gets routed around. |
| A withdrawal is itself evidence | 🔧 | Requires a written reason; the row survives as history. A sign-off that can be quietly removed is one nobody can rely on afterwards. |
| The gate is on **sending**, not generating | 🔧 | An unsigned confirmation is the draft the signatories need to read. Applied to `send-acceptance-request` **and** to `trade-documents/{id}/send-email` for `order_confirmation` — without the second the control was one route deep and the obvious way around it was the documents list. |
| Grandfathered from 2026-08-13 | 🔧 | `ORDER_SIGNOFF_APPLIES_FROM`. Shipping this without it would freeze every open order on production, and a control that halts the business on its first day gets switched off. `ORDER_SIGNOFF_REQUIRED=false` stands the whole thing down from `.env` without a deploy. |
| `super_admin` bypass, with a reason, logged | 🔧 | The escape hatch exists so the business is never stuck, and leaves a `signoff_bypassed` mark so it is never quietly routine. |

### The audit trail this project keeps losing

| Change | Status | Notes |
|--------|--------|-------|
| `OrderLog::ACTIONS` is now the source of truth | 🔧 | **#36** builds the ENUM from the constant instead of restating the list. Every previous widening carried its own copy, which is the mechanism behind three separate instances of shipped code writing an action the column rejected — silently, behind a try/catch — including the one that destroyed the payment milestone history for every order on production. |
| A test asserts every action written in `app/` is declared | ✅ | The check the Known Gaps entry has been asking for since Session 76. **Its first run failed** — on `'action' => 'linked'` in a `Log::info` context array, a false positive. Narrowed to the two shapes this codebase actually writes audit rows with, because a check that cries wolf gets deleted. |

### Documents: the order manager decides, not the system

| Change | Status | Notes |
|--------|--------|-------|
| **Uploading is no longer gated at all** | 🔧 | It required `deposit_paid`. An uploaded file records something that already happened outside this system; refusing to store it does not make it not exist, it only means the one place everyone looks is missing it. Also the direct answer to "they will send even after delivery" — the old gate had no upper bound but sat behind a lower one a historical order could easily fail. |
| Generation gates are overridable with a reason | 🔧 | The gates exist for a real reason (Session 76 — a buyer queried a deposit nobody had asked him for), so they stay on and now name their own escape hatch in the 409. Recorded as `document_gate_overridden`. A refusal the accountable person cannot override just moves the work outside the system, where nothing is recorded at all. |
| Customer-facing visibility unchanged | ✅ | A commercial invoice still stays hidden from the buyer until the order is fully paid. Different control, and the ask was about the admin side. |

### eBay separated, without hiding anything

`GET /admin/orders` gains `?channel=normal|ebay|all`, `?in_transit=1`,
`meta.channel_counts`, and `channel`/`in_transit` on every row.

**The default stays `all` deliberately.** Flipping it to `normal` would have
delivered the separation with no frontend work — and silently dropped eBay
orders from every other consumer of this endpoint, including the Session 65 ops
mobile app. A data change dressed up as a feature. The split belongs in the UI;
the counts ride along on every response so the Orders page can say "42 eBay
orders, view separately" rather than the split being something the user has to
already know about.

The existing `GET /admin/ebay/orders` is behind `ebay.manage` (super_admin +
admin), so **an order manager cannot see it** — the eBay page uses
`/admin/orders?channel=ebay` under `orders.view` instead.

### Found while building

| Finding | |
|---|---|
| `match(true)` against an array | `'partial'` was unreachable — `match(true)` compares with `===`, and a non-empty array is not `true`. A half-signed order reported itself as untouched. |
| The service 500'd an unrelated feature | The order-item edit path calls into sign-off, so before the table-existence guard, every item correction would have 500'd between the code deploying and the migration running. **Caught by `OrderTotalFromItemsTest`, which knows nothing about sign-off** — a new control must not be able to break an old feature by arriving first. |
| A date comparison right in production, wrong in CI | `issued_on` is a MySQL `DATE`, but Eloquent's date cast writes a full timestamp on sqlite, and `'2026-08-13 00:00:00'` compares as greater than `'2026-08-13'` as a string — so the last day of every period fell out of the count, on the harness only. A figure that is right in production and wrong in CI is worse than one that is wrong in both. |
| The suite hit the runner's memory ceiling | It passed at 430 tests and exhausted the default 128M at 431. Nothing leaks — the runner holds every booted application for the run. Pinned to 512M in `phpunit.xml` so CI fails on a real regression rather than on suite size. |

Backend tests (27 new): `OrderSignoffAndBoardTest` — the role, both halves,
same-person refusal, read-without-writing, withdrawal history, money-change
invalidation, grandfathering, the bypass, the audit-action scan, every board
column, the reconciliation, the channel split and deploy-order inertness. Full
suite **431 passed, 0 failed**, 206 skipped, up from 404.


### Session 84 — answering the frontend report

Frontend built all six screens and reported three gaps. All three were real.

| Change | Status | Notes |
|--------|--------|-------|
| `you_may_sign` + **`you_may_revoke`** moved into `state()` | 🔧 | The note claimed the embedded block meant no second request, and it did not — `you_may_sign` was added only in the controller. Frontend was right that it cannot be derived client-side: the same-person rule compares `admin_user_id` and the payload carries a display name. `state()` now takes the viewer, and a test asserts the embedded block and the `/signoffs` endpoint return the **identical** object, because one being a superset of the other is how they drift. |
| **`you_may_revoke` was a hole in the instruction, not just the code** | 🔧 | Frontend found Withdraw offered on every signed slot regardless of role — the exact permissions puzzle `you_may_sign` exists to prevent, in the one control that instruction did not cover. They gated it correctly from the payload; the reason it now comes from the server anyway is `orders.signoff_bypass`, which also entitles a withdrawal and is not visible from the slot's own permission. `canRevoke()` extracted so the guard and the payload use one rule. |
| Document state on the order list row | 🔧 | `documents_count`, `documents_sent_count`, `last_document_sent_at` as SQL aggregates — the in-transit queue's "documents sent?" column was otherwise one request per row. `null` rather than `0` when not selected, so a caller can tell "none sent" from "not asked". Frontend shipped a row saying plainly that the list did not carry document state rather than asserting something it had not been told, which was the right call. |
| **`orders.view` granted to `support`** | 🔧 | Frontend found the panel offering Orders to support while the API refused it — the page 403'd, so the divergence was already broken. Granting is the right half to move: a support role that cannot see an order cannot answer the commonest support question there is. Read only; `orders.update` and both sign-off permissions stay off it. The note's omission of `finance` from `orders.view` was a documentation bug and the one that mattered most — a finance admin who cannot open the order page cannot give the signature the feature exists to collect. |
| Backend tests (4 new) | ✅ | `you_may_sign`/`you_may_revoke` per role, the two payloads agreeing, document aggregates on an in-transit row, and support's read-only access. Full suite **435 passed, 0 failed**, 206 skipped. |

**On divergences generally.** The auth payload has always returned `permissions`
for the signed-in user (`AuthController:154`, plus both 2FA paths), from the same
`AdminPermissions::MAP` the API enforces. Frontend keying page visibility off
that rather than off role lists is the structural fix — a grant made server-side
then reaches the UI on the next login, and a page can never be offered for a
call that will 403. Told them so rather than listing `analytics.view`'s roles for
them to hardcode a second time.

See `FRONTEND_NOTE_operations-board.md`.

---

## The Wix audience (Session 85)

> **Deploy status:** built and tested, **not yet deployed**. No migration, no
> new route, no contract change. One new artisan command.

Marketing want to send to "everyone who came across from Wix" as an audience in
its own right. That is a question about where a contact came FROM, not where it
is — so `wix` is a market a contact holds **alongside** its geographic one,
never instead of it. Contacts have been many-to-many with markets since Session
72 precisely so overlapping audiences like this are possible.

| Change | Status | Notes |
|--------|--------|-------|
| Wix exports are detected from their own headers | 🔧 | Wix numbers its repeated fields — `Email 1`, `Phone 1`, `Address 1 - Country` — and nothing else the team uploads does. Detecting the format rather than asking the operator to tick a box means the tagging cannot be forgotten on an upload, which is the only way a "send to all Wix contacts" audience stays true over time. **Three** signature headers required, so a spreadsheet that happens to name a column `Email 1` does not tag a whole list as Wix. |
| The chosen market stays primary | 🔧 | `wix` is appended, so a Croatia upload produces contacts in `croatia` AND `wix` with `croatia` still their primary market. Anything else would look to the operator like their import went to the wrong place. |
| `source` is stamped `wix` | 🔧 | Wix's export carries no source column, so `source` has been null on every contact imported since Session 50. Filled only when the file has no source column of its own. |
| `marketing:tag-wix` for the backlog | 🔧 | The ~1,720 contacts loaded in Session 50 recorded nothing about their origin, so **the command does not guess** — it takes an explicit selector and refuses without one. `--file=contacts.csv` matches on e-mail, which is the only definition of "came from Wix" that is true rather than inferred; `--source`, `--market` and `--all` cover the rest. Reports by default, writes on `--fix`. |
| The market needs no registration | ✅ | Markets are discovered from membership, so `wix` appears in `GET /marketing-contacts/markets` with a count the moment a contact is in it, and `?market=wix` filters and campaign audiences work with no further change. |
| Opt-outs still hold | ✅ | A re-import cannot resubscribe an unsubscribed contact, and the tagging does not become a back door around that. Asserted by test. |
| **Fix** — `markets_applied` could report a duplicate | 🔧 | `array_unique` was applied to the per-row markets but not to the reported list, so importing a Wix export **under the `wix` market** — the sensible choice for a list whose only known segmentation is where it came from, and the exact import recommended for the original export — reported `["wix","wix"]`. Frontend keys their "nothing was moved" explainer on that array being longer than one entry, so the first real use would have told the operator the contacts were added to a market they had just been imported into. Found by reading their implementation report, not by a test. |
| The chosen market is reported first | 🔧 | Frontend's copy depends on the ordering to say "imported into Croatia — still their market — and also added to Wix"; reversed it claims the opposite of what happened. Now asserted explicitly rather than being an emergent property of the array literal. |
| Backend tests (11 new) | ✅ | `BulkEmailCampaignTest` — detection and its specificity, primary market preserved, re-import adding rather than moving, unsubscribed contacts, the market appearing with its count, a campaign addressed to `market=wix`, both command paths, plus the duplicate and the ordering guarantee. Full suite **446 passed, 0 failed**, 206 skipped, up from 435. |

**The Wix list is not on production, and the reason is not what it first
looked like.** `marketing:tag-wix --file=contacts.csv` matched **13 of 1,706**
addresses. The first reading — that the contact list had never been imported —
was wrong: production holds **1,443 contacts** across seven markets
(`asia` 176, `austria` 252, `croatia` 199, `czech` 300, `france` 300,
`germany` 212, `test` 4). Those are curated B2B prospect lists built market by
market in Sessions 69 and 72; the round counts give them away. `contacts.csv`
is a Wix **consumer** export and is a different population entirely — thirteen
people appear in both.

So the curated lists were imported and the Wix export never was. Do NOT run
`marketing:tag-wix --fix`: it would tag thirteen contacts and leave the business
believing the "all Wix contacts" audience exists when it holds thirteen people.
The export has to be imported, which is what `marketing:import` is for.

**`marketing:import` — the CLI half of the upload screen.** The same service the
admin upload uses, without a browser. Not a convenience: ~1,950 rows each cost a
lookup, a write and a membership write, which on shared hosting runs long enough
to hit a web-server timeout while PHP keeps going — a 504 over a list that is
still half-importing, with nobody able to say how far it got. Reports by
default (`valid` / `already present` / `new`, and whether the file reads as a
Wix export); writes on `--fix`.

Contacts already present keep the market they were in and gain the new ones,
which is what makes running this over a file that overlaps the curated markets
safe — the thirteen shared addresses end up in their existing market AND `wix`.

See `FRONTEND_NOTE_wix-audience.md`.

---

## Clients, the report, and one invoice register (Session 86)

> **Deploy status:** built and tested, **not yet deployed**. Migration **#37**
> (additive columns on `finance_invoices`) unapplied. **5 new routes**, so
> `route:cache` must be rebuilt. Deploy-order safe — the register checks for its
> own columns and an invoice raised before the migration runs still succeeds.

Four asks against the operations board built in Session 83: open the Clients
figure, add a month-to-month report, give the section real charts, and let
finance attach the sevDesk PDF — with customer-facing invoices stored in the
same record format so the two sides cannot mismatch.

### Opening the Clients figure

| Feature | Status | Notes |
|---------|--------|-------|
| `GET /admin/operations/clients` | 🔧 | `orders.view`. Names, spend, order count, first/last order, channels held, and the linked customer account where one exists. Sortable by amount / orders / recency / name, searchable, paginated. |
| `GET /admin/operations/clients/detail?email=` | 🔧 | One client's orders in the period, with their in-transit count — the number that tells an order manager there is paperwork to send. Keyed on a query parameter, not a path segment: the identifier is an e-mail, and a path means encoding dots, plus signs and slashes through every proxy in between. |
| **The same definition as the board** | 🔧 | A distinct lower-cased `customer_email` on a confirmed order. Asserted by a test that reads the board and the drill-down and requires them equal — two definitions of "client" that disagree by one is what two departments spend a morning on. |
| No account is not an error | 🔧 | Plenty of confirmed orders belong to buyers who never registered. `customer_id` is null and `has_account` false rather than invented, so the UI does not render a link that 404s. |

### The transaction report

| Feature | Status | Notes |
|---------|--------|-------|
| `GET /admin/operations/report?from=&to=&granularity=&channel=` | 🔧 | Day, week or month. Defaults to six months, because one period is a board and the question this answers needs something to compare against. |
| Gap-free axis | 🔧 | Every bucket in the range is present, empty ones as zero. "We sold nothing in July" and "July is missing from this chart" look identical when the empty bucket is simply absent. |
| `change` — latest period against the one before | 🔧 | Delta, direction and percent. **Percent is null when the previous period was zero**: a change from nothing is not a large number, it is an undefined one, and rendering it as +100% reads as a fact. |
| Clients are not summed across periods | 🔧 | One buyer ordering in two months is one client. The per-period counts are distinct within their period; the total is counted over the whole range by its own query, and the payload carries a `note` saying so. |
| `series` is chart-ready | 🔧 | Parallel arrays on a shared label axis. Two places that aggregate are two places that can disagree about a number the business is reading — the same choice Session 79 made. |
| SQL bucketing written for both drivers | 🔧 | sqlite has no `DATE_FORMAT` and MySQL has no `strftime`. A report that only works on the driver the test harness uses is a report found broken in production. |

### One invoice register, so the two sides cannot mismatch

The reconciliation compared two differently-shaped things — our `invoices`
table against finance's typed-in rows — and anything that needs translating to
be compared is where a mismatch hides. It also left a category out entirely: a
commercial invoice an order manager issues and sends is, to the customer, an
invoice, but it has no `invoices` row, so it appeared on neither side.

| Feature | Status | Notes |
|---------|--------|-------|
| **#37** file + origin columns on `finance_invoices` | 🔧 | Additive and guarded; nothing existing is read or backfilled. |
| `POST /admin/finance-invoices` accepts the PDF | 🔧 | One request, not two — finance has the document in front of them when they type the number, and a separate "now attach it" step is a step that gets skipped. |
| `POST /{id}/file` and `GET /{id}/download` | 🔧 | Private disk. Download asks the disk for the path rather than assembling `storage_path()`: the root is configuration, and hardcoding it silently 404s wherever it differs. Replacing a document deletes the one it replaced. |
| `InvoiceRegistrar` — our side, same shape | 🔧 | Every tax invoice this API raises, and every commercial invoice or proforma issued to a customer, is written into the register as `system = 'okelcor'`. **Driven by model events, not call sites**: there are five places a trade document is created and more than one that raises an invoice, and a guarantee that depends on each future one remembering to call a registrar is not a guarantee. |
| A packing list is not an invoice | 🔧 | Only `commercial_invoice` and `proforma` register. Anything else would inflate our side with documents finance would never have raised in sevDesk, which reads as a discrepancy that is not one. |
| Superseding removes the row | 🔧 | A document that was withdrawn is not an invoice that was issued; leaving it counted invents a discrepancy rather than finding one. |
| Auto-registered rows are read-only | 🔧 | 409 on edit or delete. Deleting one would only mean it reappears the next time the invoice behind it is saved, while the reconciliation reports a gap that is not real. Finance also cannot hand-create an `okelcor` row — that would put a number on our side that nothing on our side issued. |
| **Fix found by test** — the register was polluting finance's column | 🔧 | The board's `finance_invoices` count and the reconciliation's finance side now scope to `MANUAL_SYSTEMS`. Without it our own auto-registered invoices were counted as finance's, so the variance read zero however far apart the two systems actually were — worse than having no column at all. Caught by an existing Session 83 test, not by a new one. |
| Backend tests (18 new) | ✅ | Clients drill-down and its agreement with the board, client detail and the 404, gap-free series, change and the zero-baseline null, clients not summed, registration from both sources, packing lists excluded, supersede cleanup, no double registration, read-only rows, file attach/download, and the register being inert before its migration. Full suite **466 passed, 0 failed**, 206 skipped, up from 448. |

See `FRONTEND_NOTE_operations-detail.md`.

---

## eBay in the report, an export, and a queue that starts earlier (Session 87)

> **Deploy status:** built and tested, **not yet deployed**. No migration.
> **1 new route**, so `route:cache` must be rebuilt. One number already on the
> board changes meaning — see below.

| Change | Status | Notes |
|--------|--------|-------|
| **eBay recorded beside the website, not inside it** | 🔧 | Every period in the report now carries the combined figure AND both channels, and the chart series gain a dataset per metric per channel. Two books — different fulfilment, different paperwork — and a total that hides which one moved answers nothing. Asking for one channel returns only its datasets: three per metric where two are empty is a legend full of lines that are not there. |
| `GET /admin/operations/report/export` | 🔧 | `orders.export`. One line per period per channel plus the combined line — a spreadsheet is read by filtering a column, not by opening three files. Carries a UTF-8 BOM, because Excel reads a UTF-8 CSV as Latin-1 without one and turns every € and every accented customer name into mojibake, and this file goes to a finance team who will open it in Excel. The clients caveat travels inside the file: a spreadsheet outlives the page it came from and is where someone will eventually try to sum that column. |
| **The fulfilment queue starts before dispatch** | 🔧 | `in_transit` meant `shipped` only, which showed the work after the moment to do it had passed — trade documents are issued BEFORE a container leaves as often as after, so an order confirmed and being prepared is exactly the one whose commercial invoice needs raising. Now `confirmed`, `processing` or `shipped`, still requiring payment far enough along, still stopping at `delivered`. |
| Split into the two jobs it now contains | 🔧 | `fulfilment_stage` on every order (`ready_to_ship` / `in_transit`), a matching `?fulfilment_stage=` filter, and `ready_to_ship` / `shipped` counts beside `in_transit` on the board. The single count would otherwise mix "raise the paperwork and dispatch this" with "this is on the water, chase the carrier", and a queue that cannot tell them apart gets worked in the wrong order. |
| The definition string was rewritten with it | 🔧 | `DEFINITIONS['in_transit']` is rendered in the UI, so the column explains its own new meaning where it is read rather than in a note nobody has open. |
| Backend tests (5 new, 1 rewritten) | ✅ | Channel split and its absence when one channel is asked for, the CSV including its BOM and all three row kinds and the caveat, export permission, the widened queue, and the two stages agreeing between accessor, scope, board and list filter. The old `in_transit is paid and shipped and nothing else` was replaced rather than patched — its name had become a lie. Full suite **471 passed, 0 failed**, 206 skipped, up from 466. |

**One number changes meaning on deploy.** `in_transit` on the board will jump,
because it now counts orders that are confirmed and being prepared as well as
dispatched ones. That is the requested change, not a regression — but it is
worth telling the order manager before she sees it, and the `ready_to_ship` /
`shipped` split is there so the old figure is still readable.

See `FRONTEND_NOTE_operations-detail.md`.

---

## A queue that can be worked from the right end (Session 88)

> **Deploy status:** built and tested, **not yet deployed**. No migration, no
> new route — one query parameter on an endpoint that already existed.

Frontend built the three Session 87 changes and reported one thing they could
not do.

| Change | Status | Notes |
|--------|--------|-------|
| `?sort=` on `GET /admin/orders` | 🔧 | `newest` (unchanged default), `oldest`, `total_high`, `total_low`, `updated`. The order was hardcoded `orderByDesc('created_at')`, so the fulfilment queue could only be shown in the least useful direction: a browsable index is read newest-first, but a work queue is worked from the back, because the row that has waited longest is the one someone is chasing. |
| `meta.sort` and `meta.sorts` | 🔧 | Served rather than duplicated, so the control cannot drift from what the endpoint accepts. `meta.sort` reports what was actually applied, not what was asked for — an unrecognised value falls back to `newest` rather than erroring, and echoing it back would render the control in a state the list is not in. |
| Backend tests (3 new) | ✅ | Every sort including the fallback, the declared options, and the combination that matters — the queue filter with `sort=oldest`. Full suite **474 passed, 0 failed**, 206 skipped. |

**Honest limit, stated rather than papered over:** `oldest` sorts by when the
order was RAISED, not by how long it has been in its current state. Nothing
records the latter — `updated_at` moves on any edit and `order_logs` would need
a scan — and a proxy dressed up as the real thing is worse than the plain fact.
For the fulfilment queue the two are close enough to be useful; if they diverge
in practice, the fix is a `status_changed_at` column, not a cleverer query.

**Worth recording about how it was found.** Frontend wrote the control, saw the
parameter do nothing, and **declined to sort the fetched page client-side** —
which would have reordered 25 rows and labelled the result "oldest" while the
genuinely oldest orders sat on page two. Their words: a more convincing version
of the same lie. That is the second time this month a session has been improved
by frontend refusing to fake something (the first was `group_list` in Session
82), and both times the cost of asking was a few hours against a number the
business would have trusted and been wrong about.

---

## Staff contribution ledger (Session 89)

> **Deploy status:** built and tested, **not yet deployed**. Migration **#38**
> (two new tables) unapplied. **10 new routes**, so `route:cache` must be
> rebuilt. Deploy-order safe in both directions, proved by test.

Phases 1 and 2 of a five-phase performance system: a per-person record of work
the API already watched happen, and a place to enter the work it cannot see.
Scorecards, AI-written monthly reports and skills intelligence are phases 3–5
and are deliberately not here — see the bottom of this section.

### The Ledger — work the system watched

| Feature | Status | Notes |
|---------|--------|-------|
| `staff_activities` table | 🔧 | Append-only. `admin_name` and `admin_role` copied onto the row rather than read through the relation, for the same reason `OrderSignoff` copies them: a record of what someone did is a statement about who they were at the time, and reading it live would rewrite last year's report the day somebody changes role. |
| Driven by model events, not call sites | 🔧 | A `RecordsStaffActivity` trait on seven models. There are dozens of places an order log is written and five that create a trade document — a guarantee depending on each future one remembering to call a recorder is not a guarantee. Same reasoning as `InvoiceRegistrar`. |
| Seven sources, all pre-existing | 🔧 | `order_logs` (the richest — it has stamped `admin_user_id` since Session 5), `trade_documents`, `order_signoffs`, outbound `customer_communications`, `bulk_email_campaigns`, hand-entered `finance_invoices`, `admin_user` rows in `partner_sale_audits`. No new instrumentation anywhere. |
| **No person, no row** | 🔧 | Order logs written by a customer accepting something, by a webhook or by a scheduled command are skipped outright. Attributing those to whoever happened to be authenticated would be worse than not recording them. |
| **No presence data, by design** | 🔧 | Logins, session length and page views exist in the database and are excluded. Measuring presence rewards whoever leaves the tab open. Stated in the frontend note so the exclusion is a decision on the record rather than an omission. |
| Idempotent | 🔧 | Keyed on `(source_type, source_id, action)`, the table's own unique index. Re-saving a model updates its row; the backfill can run as many times as needed. |
| `staff:backfill-ledger` | 🔧 | Survey by default, `--fix` to write. The dry run previews through a rolled-back transaction rather than reimplementing the recorder's rules — a survey that disagrees with the write it previews is worse than no survey. |

### The Log — work only the person knows about

| Feature | Status | Notes |
|---------|--------|-------|
| `staff_contributions` table | 🔧 | **A second table, not a `source` column on the first.** The promise is that verified and self-reported work never merge into one figure; a column would make that a convention the next feature can forget, two tables make it structural. Nothing joins them and no endpoint sums across them. |
| Six endpoints (list / create / edit / remove / attach / download) | 🔧 | Evidence stored on the private disk, served through a route that checks who is asking. Replacing a file deletes the one it replaced. |
| `minutes` optional, and staying optional | 🔧 | Making someone account for their hours turns a contribution log into a timesheet, which is a different product with a very different reception. |
| Evidence invited, not required | 🔧 | A supplier phone call has no artifact, and refusing to record it would only mean it goes unrecorded. Each row reports `has_evidence` so a reviewer sees what they are agreeing to. |
| Review — verify or reject | 🔧 | `staff.verify`. **Nobody countersigns their own claim** (422), the same separation that stops one person filling both order sign-off slots. A rejection with no reason is refused — it is not something anyone can act on. |
| Editable only while pending | 🔧 | 409 after review. Rewording an entry a manager agreed with would change what they agreed to; the answer is a correcting entry, and the message says so. |

### Permissions

| Key | Roles | Why |
|---|---|---|
| `staff.self` | **all nine** | Nothing may be measured about a person that the person cannot open. A `viewer` holding no other permission still gets their own record — asserted by a test that walks every role. |
| `staff.view_team` | super_admin, admin, order_manager | Seeing a colleague's record is a manager's act. `sales_manager` deliberately absent — a pipeline role, not a line-management one. |
| `staff.verify` | super_admin, admin | Narrower than viewing, because a verification turns someone's own claim into a countersigned record. |

### Tests

**29 new** — the migration against real SQL and its idempotency, capture from all
seven sources, the four deliberate exclusions, the name/role snapshot,
idempotency on re-save, inertness before the migration, every role reaching its
own page, the 403 on a colleague, the summary keeping both halves apart (asserted
by the *absence* of a combined total), every category present including zeros,
the full contribution lifecycle, self-review, unreasoned rejection, private
evidence, and the backfill's survey/fix/re-run behaviour.

Full suite **503 passed, 0 failed**, 206 skipped — up from 474. **One real bug
found by test:** `StaffContribution::scopeBetween` used `whereBetween` on a date
column that some drivers return with a time on it, so `'2026-08-17 00:00:00'`
sorted after the string `'2026-08-17'` and everything logged on the final day of
a range silently vanished. Now `whereDate` on both bounds.

### Not built, deliberately

Phases 3–5 (role scorecards, the AI-written monthly narrative, skills
intelligence and the bus-factor map) wait on **one business answer: does any of
this ever touch pay, bonus or promotion, or is it purely visibility?** A
visibility tool can be generous and approximate; one that decides money has to be
defensible line by line and needs an appeals route. Building the scoring before
that is settled means building it twice. Phase 4 additionally needs
`QUEUE_CONNECTION=database` — outstanding item 2 below.

Also worth surfacing before this goes live: under §87(1) no. 6 BetrVG a technical
system *suitable for* monitoring employee performance requires works-council
agreement, and the test is suitability rather than intent. If Okelcor has a
Betriebsrat they are a day-one stakeholder. Flagged as an engineering read, not
legal advice.

See `FRONTEND_NOTE_staff-contribution-ledger.md`.

---

## The job is not the role (Session 89b)

> **Deploy status:** built and tested, **not yet deployed**. Migration **#39**
> unapplied. **1 new route**. Additive and guarded; every path falls back to a
> tidied role until a title is set.

The ledger shipped labelling people by `admin_users.role`, and that is wrong
about most of this team. The role is a permission set and has never been a job
description: **edinah@** and **yelzaveta@** are order managers holding `admin`
because they also need customers, campaigns and quote requests, and **victor@**
runs operations on the same role for the same reason. Reading the role as the job
files all three under "Admin" and describes none of them.

| Feature | Status | Notes |
|---------|--------|-------|
| `admin_users.job_title` | 🔧 | Free text, not an enum — this codebase has already been bitten once by a column that could not hold the values its own code used, and job titles change more often than permission sets do. Editable on `POST`/`PATCH /admin/users`. |
| `staff_activities.admin_job_title` | 🔧 | Snapshotted, like the name and role. Somebody promoted next year does not get last quarter's record relabelled. |
| `config/staff.php` + `staff:sync-job-titles` | 🔧 | Matched on **e-mail**, the one identifier that belongs to the person rather than to their access. Will not overwrite a title set by hand without `--force`, and prints everyone still falling back to a role so the gaps are visible rather than silent. |
| Never blank | 🔧 | `jobTitle()` falls back to a tidied role; `job_title_set` says which of the two you are looking at, so the panel can prompt for a real one instead of passing a permission off as a description. |

**Confirmed rather than assumed: super_admin work is recorded too.** The recorder
keys on `admin_user_id` and never looks at the role. Two tests now assert it —
one for two super_admins specifically, one walking every role in `ROLES`.

### The team report and the monthly digest

| Feature | Status | Notes |
|---------|--------|-------|
| `GET /admin/staff/team-report` | 🔧 | `staff.view_team`. Everyone's period side by side. |
| **Alphabetical, never ranked** | 🔧 | A count of ledger rows is not a measure of value — one order manager's month can be sixty documents while another's is a single container negotiation that took three weeks. Sorting by volume would turn a record into a league table and make a claim the data cannot support. Asserted by a test that gives the second person more work and requires the order unchanged. |
| No combined figure, again | 🔧 | Same rule as the per-person endpoints: recorded and self-reported are separate and nothing sums them. |
| `caveats` travel in the payload | 🔧 | The same words go out in the e-mail. A caveat living only in the page template is one half the readers never see. |
| `staff:digest` + `StaffContributionDigest` mailable | 🔧 | Monthly on the 1st. Recipients from `STAFF_DIGEST_RECIPIENTS`, deliberately **not** "everyone with `staff.view_team`" — a performance report landing in an inbox nobody asked for it in is the fastest way to make this feel like surveillance. |
| Monthly, not weekly | 🔧 | A summary arriving every Monday trains people to work for the report, and a month is the shortest window in which "sixty documents" and "one three-week negotiation" are both visible. |
| Sends inline | 🔧 | Two or three e-mails a month does not need the queue worker, so this does **not** wait on `QUEUE_CONNECTION`. Bulk campaigns still do. |
| Fails soft | 🔧 | No recipients, or `STAFF_DIGEST_ENABLED=false`, reports and exits 0 rather than failing every scheduled run on a server nobody has configured. |

### Tests

**13 new** (42 in the file, 516 in the suite, up from 503): super_admin recorded,
every role recorded, the job title captured instead of the role, the fallback,
the snapshot surviving a promotion, e-mail matching case-insensitively, the
no-overwrite rule and `--force`, `--set` for one person, the team report's
permission gate, its alphabetical order under unequal workloads, and four digest
cases — dry run, per-recipient send, no recipients, and stood down by config.

See the addendum in `FRONTEND_NOTE_staff-contribution-ledger.md`.

---

## Technical work is work (Session 89c)

> **Deploy status:** built and tested, **not yet deployed**. **No migration, no
> new routes** — two new category values in a VARCHAR column, four new sources,
> one new command.

The ledger's seven original sources are all business operations. Somebody who
**builds** the system rather than operating it touches almost none of those
tables, so their month came back empty — which was never true about their work,
only about what the ledger knew how to look at. Raised by the person it was
wrong about, which is the fastest way these things get found.

| Feature | Status | Notes |
|---------|--------|-------|
| `development` + `system` categories | 🔧 | `category` is `VARCHAR(30)`, so this needs no migration. Both arrive in `meta.categories` and `by_category`, which are served rather than hardcoded — the frontend displays them with no change. |
| `staff:import-commits` | 🔧 | **Development has a system of record; it is just not this database.** Reads git on the same terms as every other source: attributed to a named person, idempotent, nothing invented. Survey by default, `--fix` to write. |
| Attribution by author e-mail | 🔧 | Plus `staff.git_aliases` (git address → login address). Committing from a personal address is the normal case, not an edge case, and mapping it beats asking anybody to rewrite history. Unmatched authors are reported **by identity with a count** — "412 commits skipped" tells you nothing, "412 from noreply@github.com" tells you exactly what to add. |
| Two repositories, two routes in | 🔧 | `--repo=` reads git directly (the API repo is on the server); `--file=` reads an exported log (the frontend is on Vercel and is not checked out beside the API). The command prints the exact `git log` line that generates the file. |
| `source_id` derived from the sha | 🔧 | git has no integer key and the idempotency index needs one. First 15 hex digits, 60 bits — ample to keep a repository distinct — and the full sha travels in `subject_label` and `metadata`, so a row is always traceable back to the commit. The trade-off is named in the code rather than left for someone to discover. |
| `media.uploaded_by` | 🔧 | Uploading is real work and that column has recorded it since the media library shipped. |
| `ebay_listing_logs.admin_user_id` | 🔧 | Listing actions, already attributed. |
| `admin_security_events` — **whitelisted** | 🔧 | Only `admin_created`, `admin_deleted`, `role_changed`. Logins, 2FA challenges and permission denials stay out: that table is mostly presence data, and counting it would put the one thing this ledger refuses to measure back in through a side door. A whitelist rather than a blocklist, so a new event type has to be deliberately added rather than silently included. |
| `development` contribution category | 🔧 | For technical work that leaves no commit — an architecture decision, a spec, an afternoon pairing on somebody else's bug. Commits are automatic and appear under Recorded, not here. |

### Tests

**8 new** (50 in the file, 524 in the suite, up from 516): both categories exist,
media/eBay/account-administration recorded, **logins still excluded**, commits
imported and attributed, the survey writing nothing, re-running not doubling,
the full sha surviving into metadata, an unmatched author reported rather than
guessed at, an alias mapping a personal address, and — the one that answers the
original question — a developer's summary coming back with a non-zero
`development` count instead of an empty month.

See addendum 2 in `FRONTEND_NOTE_staff-contribution-ledger.md`.

---

## The website said he had paid (Session 90)

> **Deploy status:** built and tested, **not yet deployed**. Migration **#40**
> unapplied. **1 new route**, so `route:cache` must be rebuilt. Frontend built
> in the same session and pushed alongside.

The order manager reported the same symptom for the second time — an order
confirmed, the deposit not yet in, the site telling the customer he had paid —
along with *"it keeps giving automatic and there is no option for us to set it
manually"*. She is right that Session 76 fixed it, and right that it is still
happening. They were two different faults.

Session 76 closed every path that moved an order **forward** on its own. What it
left is the direction of travel: `request-deposit` refuses unless the order is
at `pending_proforma`, `deposit-paid` unless it is at one of two stages,
`balance-paid` unless the deposit is in. Each guard is correct alone. Together
they mean an order that arrived at a paid state — however it got there,
including before Session 76 shipped — **could never be put back by anyone using
the product**. That is the whole of "no option to set it manually": not that
something set it today, but that nothing can unset what was set. Every such
order needed a developer, and until one was free the buyer kept reading that he
had paid.

| Feature | Status | Notes |
|---------|--------|-------|
| `POST /admin/orders/{id}/payment-milestones/correct` | 🔧 | `payments.correct_state`. Stage, an optional reset of `payment_status`, and a required reason. |
| **Only ever backwards** | 🔧 | 409 `use_the_milestone_actions` on a forward move. Recording that money arrived stays with the actions that notify the customer and stamp who confirmed it — a correction tool that could also do that would become the quick way round both, and the ladder's guards would stop meaning anything. |
| Never a Stripe order | 🔧 | 409 `gateway_managed_payment`. The gateway owns that state; a figure typed here would only disagree with Stripe until somebody noticed. |
| Never e-mails anybody | 🔧 | Correcting our own record is not an event in the buyer's order, and "your payment status changed" for a payment that never happened is precisely the confusion being fixed. |
| Rolled-back stages lose their dates | 🔧 | `deposit_paid_at` is a claim that money arrived on a date, not a note. Left behind a rolled-back stage the claim still stands. `*_email_sent_at` is **not** cleared — an e-mail that went out went out, and that is the only record of what the customer was told. |
| **The one log write with no try/catch** | 🔧 | Every other order log write in this codebase swallows its own failure, which is right — a failed log must not fail a user's action. Not here: a correction that recorded nothing would be worse than the state it corrects, so a rejected insert rolls the correction back. `payment_state_corrected` is in `OrderLog::ACTIONS`, which the existing test enforces. |
| Separate permission key | 🔧 | Same holders as `payments.mark_paid` — the order manager is the person who knows whether the money arrived, and making her ask someone else to correct her own record is what produced the complaint. Its own key so it can be narrowed later: withdrawing a claim of payment and making one are different acts. |

### Finding the ones already wrong

`orders:payment-state` — inspect one order, correct one order, or sweep. Survey
by default; nothing writes without `--stage` and a reason.

The sweep flags an order presenting as paid with **nothing** recording who
confirmed it: no Stripe session, no eBay, no `deposit_paid_at`/`balance_paid_at`
stamp, no `payment_status_changed` row. It **reports and never repairs**.
Nothing in the code can tell whether the money actually arrived, only whether
anybody wrote down that it did — a historical order backfilled from paper is
supposed to look exactly like this, a live order is not, and a rule that guessed
would eventually guess against the bank.

### Prevention

`POST /admin/orders` with `payment_status: paid` now writes a
`payment_status_changed` row naming the admin who recorded it. That path is the
one legitimate way an order is born paid and the backfill workflow is unchanged
— what changed is that it is no longer anonymous. Every other route to a paid
state already left a record of who confirmed it; this one wrote nothing, which
is exactly why the orders already on production cannot be told apart and need
the sweep.

### Tests

**16 new** (53 in the file, **540 in the suite**, up from 524): the correction
itself, both columns coming back together, dates cleared for undone stages and
kept for standing ones, the refusal to move forward, Stripe refused, no reason
refused, nothing e-mailed, the audit row with its old→new and reason, the action
being one the column accepts, a `viewer` refused, a no-op refused, and five on
the sweep — flagged when unevidenced, silent for a confirmed order, for Stripe,
for eBay, and for an unpaid order — plus the creation path now naming who
declared an order paid.

See `FRONTEND_NOTE_payment-state-correction.md`.

---

## Product optimization — the marketing brief (Session 92)

> **Deploy status:** built and tested, **not yet deployed**. Migration **#42**
> unapplied. **1 new admin route**, so `route:cache` must be rebuilt.
> Deploy-order safe in both directions. Frontend built in the same session.

The marketer's brief (`Product Optimization.txt`): SEO URLs shaped
`brand+productName+season`, product descriptions "rich text like the blog
post", the Artikelmerkmale block of a German tyre listing (24 attributes — EU
label classes, 3PMSF, EPREL …), and shipping/returns text.

| Feature | Status | Notes |
|---------|--------|-------|
| `products.slug` + backfill | 🔧 | Every existing product slugged in the migration (only NULLs, re-run safe); every new one at creation **on the model**, not the endpoint — the Wix import and eBay sync create products too. `GET /products/{idOrSlug}` resolves both; canonical points at the slug so the two URLs are one page to Google. **A rename never moves the URL** — the slug changes only when edited deliberately. |
| `description_html` | 🔧 | Same TipTap editor, same `ArticleHtmlSanitizer`, same 422-not-500 failure as article bodies. Plain `description` unchanged, required, still the meta description. |
| `TyreSpecs` catalogue + `products.specs` JSON | 🔧 | **One list says where each attribute lives.** Half the brief's fields already existed as columns (width, rim, load index, EAN, tread depth — Sessions 14–71); storing them again in JSON would be two places for one fact. `column` rows read the column, `json` rows the new JSON, Reifenzustand is `derived` from `type`. Served by `GET /admin/products/spec-options` (upload-options pattern) so the admin form renders whatever it says — a new attribute is one backend entry, no frontend deploy. |
| Validation with teeth | 🔧 | EU-label classes A–G only; unknown keys and blanks dropped before storage; sending `specs` replaces the object so a cleared field stays cleared. |
| Shipping/returns, two layers | 🔧 | Site-wide defaults in `site_settings` (`product_shipping_info` seeded from the brief), per-product override columns win. **Returns seeded EMPTY deliberately**: the brief's text is copied from an eBay listing (eBay Plus, eBay return labels) and would be wrong on okelcor.com — the marketer words the site version in Settings. Until then the page keeps its existing translated copy. |
| Admin fields for the column-backed sheet | 🔧 | `width`/`height`/`rim`/`load_index`/`speed_rating`/`ean` were CSV-import-only; the FormRequests now accept them, as strings — "10.5" rims and "91/89" load indexes are real. |
| Frontend | 🔧 | Product form: SEO section, rich editor, sheet rendered from spec-options, shipping/returns overrides. Shop: `/shop/[id]` takes both handles, all links via one `productPath()` helper (cards, specials, compare, related, navbar search — whose backend href now carries the slug too), accordion gains Beschreibung/Artikelmerkmale/Versand sections that render only when content exists. |

### Tests

**21 new** (`ProductOptimizationTest`, suite **576, 0 failed**, up from 555):
the migration against real SQL and its idempotency, the backfill's uniqueness
and its refusal to rename, slug at birth / slug stability under rename /
dedup of a hand-typed collision, resolution by both handles, sanitization,
the plain description untouched, column-vs-json-vs-derived assembly, A–G
refusal, junk-key dropping, replace semantics, Ja/Nein rendering, empties
skipped, the served catalogue, the permission gate, and the
override→setting→null fallback chain.

See `FRONTEND_NOTE_product-optimization.md`.

---

## The shared team to-do list (Session 102)

> **Deploy status:** **deployed 2026-08-28** — migration #57 applied, route
> cache rebuilt, `/admin/todos` answering 401-not-404 from outside.

The ask: a to-do list where anyone can use it and tag a teammate.

| Change | Status | Notes |
|--------|--------|-------|
| **#57** `todos` | 🔧 | One shared list. `assigned_admin_id` nullable — an untagged item is the team's, not nobody's. `completed_at`/`completed_by` follow the status in both directions: a reopened item was NOT done. |
| Behind `staff.self`, decided in the controller | 🔧 | Every role holds `staff.self`, so ANYONE — a viewer included — creates and reads. Who may MOVE an item is not a role question: its creator, its assignee, or super_admin. Deleting is narrower still — the creator only, because the assignee marks things done, they do not erase that it was asked. `you_may_edit`/`you_may_delete` are served per row, never re-derived client-side. |
| The tag is the chase | 🔧 | Same contract as the snapshot board and the EC lines: tagging notifies (one deduped nudge/day, never self-notifies), the item lands in the assignee's **My Work** (`todo_tasks`, deep-linked `?todo=`), and completing a tagged item notifies whoever created it — "done" travels back without a message written. My Work's status select PATCHes the normal `/admin/todos/{id}` endpoint; being a participant IS the authorization, so no my-work-specific route was needed. |
| The list itself | 🔧 | Default view is `active` (open + in progress) — the list people work from; done items leave it but stay reachable. Sorted: done last, dated before undated, high priority first. Filters: scope all/mine/created, status, search. |
| Frontend `/admin/todos` | 🔧 | okelcor-website: "Team To-Dos" in the top nav for every role (`section: null`, deliberately). Add/edit form (title, details, due date, priority, tag picker), Active/Tagged-to-me/Created-by-me/Done pills, rows with priority badge, overdue-red due date, assignee pill, done-by stamp, status select, and the `?todo=` highlight. My Work gains the "To-Dos" section. |
| Backend tests (8 new) | ✅ | `TeamTodoListTest` — migration idempotent, a viewer creating + the shared read with per-row edit rights, tag → notification → My Work with deep link and status options, participant-only moves (bystander 403), completion notifying the creator + the reopen clearing the stamp, delete being the creator's call, retagging notifying the new assignee + the scopes and done filters, and pre-migration inertness of both the page and My Work. Full suite **967 passed, 0 failed**, 206 skipped, up from 959. |

---

## Sales & Order Management board (Session 101)

> **Deploy status:** **deployed 2026-08-28** — migration #56 applied, route
> cache rebuilt, `/admin/sales-orders` answering 401-not-404 from outside.

From finance's `OT 3.html` mockup: hand-entered orders, each itemizing
CUSTOMER lines (revenue + tyre quantity) against SUPPLIER lines (costs +
their documents), with five KPI cards on top.

| Change | Status | Notes |
|--------|--------|-------|
| **#56** `sales_order_entries` + `sales_order_lines` | 🔧 | GP, margin and the status are computed, never stored. `order_no` unique — the same real order twice would double the KPIs; the duplicate is a friendly 422 carrying the existing entry. Decoupled from operational `orders`, like the snapshot board: finance types what their books say, including orders this system never saw. |
| The mockup's arithmetic, verbatim | 🔧 | Revenue and tyres from customer lines; costs from supplier lines; an order is **verified exactly when it has supplier lines and every one carries its document** — revenue with no recorded cost is a missing cost, not a verified margin. A tyre quantity on a supplier line is zeroed: it would double-count the same tyres. A new order is seeded with its customer line, amount 0 — the revenue side always exists. |
| KPIs | 🔧 | Unique customers (case-insensitive), tyres sold, avg price/tyre (**null, not €0.00, over zero tyres**), B2B and B2C GP margins. Computed over the filter scope BEFORE the pending narrowing — "pending" is a worklist view, and margins must not swing when it is clicked. |
| Routes | 🔧 | `GET/POST /admin/sales-orders`, entry PATCH/DELETE, line POST/PATCH/DELETE, line file POST + download. Reads `finance.view`, writes `finance.manage`; documents on the private disk, same upload shape as everywhere. |
| Frontend `/admin/sales-orders` | 🔧 | okelcor-website: the five KPI cards, All/Pending pills + month filter, inline-editable summary rows (order no, customer, segment, month, category), computed status badge and coloured margin, expandable transaction lines with attach/download and per-order Customer/Supplier/GP footer. Everything wraps. |
| Backend tests (10 new) | ✅ | `SalesOrderBoardTest` — migration idempotent against real SQL, the seeded customer line + duplicate 422 (whitespace cannot evade it), month validation, the mockup's own example (14,500/100 tyres vs 10,000+1,800 → GP 2,700, 18.62%, pending until the CMR arrives, then verified), supplier-lines-required rule, the tyre double-count guard, the KPI figures (140 tyres, €126.43, 18.6%/24.2%, case-insensitive customers), the pending filter narrowing rows but not cards, both permission halves, and pre-migration inertness. Full suite **959 passed, 0 failed**, 206 skipped, up from 949. |

---

## EC Invoice List — the ZM portal (Session 100)

> **Deploy status:** **deployed 2026-08-28** — migration #55 applied, route
> cache rebuilt, `/admin/ec-invoices` answering 401-not-404 from outside.

From finance's `File6.html` mockup: the Zusammenfassende Meldung (§ 18a UStG,
BZSt/ELSTER) with its audit trail. Per reporting period ('2026-Q3' or
'2026-05'): ZM groups (EU country × customer VAT ID × transaction type), each
itemizing the invoices behind its aggregate.

| Change | Status | Notes |
|--------|--------|-------|
| **#55** `ec_invoice_periods` + `ec_invoice_groups` + `ec_invoice_lines` | 🔧 | Group totals never stored — the sum of the lines, computed in one place. `unique(period, country, vat, type)` because a duplicated group would double its ZM line; the duplicate is a friendly 422 carrying the existing group. |
| `GET/POST/PATCH/DELETE /admin/ec-invoices/…` | 🔧 | Reads `finance.view`, writes `finance.manage`. Lines carry the invoice PDF **and the delivery proof** (CMR/POD) on the private disk — the proof is what makes a zero-rated intra-EU supply defensible, and the mockup's rule is kept: a proof arriving completes a "Pending Proof" line. |
| The assignee chase | 🔧 | Same contract as the snapshot board: tagging a staff member notifies them (one deduped nudge/day) and the line lands in their **My Work** (`ec_invoice_tasks`, deep-linked `?period=&line=`). The assignee updates status via `PATCH /admin/my-work/ec-invoice-lines/{id}` — being the assignee IS the authorization, no finance permission needed — and the creator is notified back. The EC status options travel on the work item, because they are not the finance-task statuses. |
| Filing status per period | 🔧 | draft → ready → submitted, with `submitted_at` following the status in both directions — a period moved back to draft was NOT submitted. Taxpayer USt-IdNr. lives in `site_settings.company_vat_id`, editable from the page via `PUT …/company-vat` rather than behind `settings.manage`, which finance does not hold. |
| `GET …/export` (CSV) | 🔧 | `finance.view` + `orders.export` stacked. BOM-prefixed, one row per invoice with both documents named — a missing one prints as **"missing"**, because an audit gap should read as one. |
| `GET …/elster` (XML) | 🔧 | The § 18a payload: one `<Zeile>` per group, `Betrag` in whole euros (14500.51 → 14501), `Art` L/S/D from the transaction type, Melder VAT + Zeitraum. Values XML-escaped. Served as a downloadable `.xml`; the panel shows it in a review modal first. |
| Frontend `/admin/ec-invoices` | 🔧 | okelcor-website: expandable ZM table per the mockup, inline editing, attach/download cells, period selector (quarters + months, data-holding periods marked •), filing badge, the CSV/ELSTER buttons, and the `?line=` deep-link with highlight. Everything wraps — the walkthrough rule. My Work gains the "EC Invoice Tasks" section. |
| Backend tests (13 new) | ✅ | `EcInvoiceListTest` — migration idempotent against real SQL, duplicate-group 422 (case/spacing cannot evade it), period validation, totals from lines, document round-trip, proof-completes-pending, both permission halves, filing-status stamp both directions, VAT setting, the CSV's BOM/labels/gaps, the ELSTER rounding + Art codes, tagging → notification → My Work with deep link, the assignee's authorization + refusal of a stranger, and pre-migration inertness of both the page and My Work. Full suite **949 passed, 0 failed**, 206 skipped, up from 936. |

---

## What did each order actually make — profitability & liquidity (Session 99)

> **Deploy status:** **deployed 2026-08-28** — migrations #52–54 applied
> (batch 119), route cache rebuilt, endpoints verified live from outside.

From the finance discussion note, verbatim asks and where each landed:

1. *"Order tracking should know we have a finalized invoice the customer
   agreed to — that's our revenue invoice"* → `order_finance_records`, and a
   `finance` block embedded on the order detail itself.
2. *"Then other invoices from other suppliers"* + *"bank cost from eBay,
   Stripe costs"* → `order_cost_lines` (`supplier_invoice` / `fee`, fee
   categories stripe/ebay/bank/shipping/other).
3. *"See the profitability as soon as the order completes"* → computed in one
   service, shown on the order page, the list, the export and the dashboard.
4. *"Upload the revenue invoice PDF"* → same one-request upload pattern and
   private-disk download as `finance_invoices`.
5. *"Export the list, one reference, sign it off showing what's verified"* →
   verification sign-off per order + BOM-prefixed CSV export.
6. *"Dashboard with summary from January"* → month-by-month, gap-free, from
   January of the year.
7. *"Break liquidity into weeks — current week plus, 4 weeks, rolling"* →
   `liquidity_weeks`, keyed `2026-W35`.

| Change | Status | Notes |
|--------|--------|-------|
| **#52** `order_finance_records` + `order_cost_lines` | 🔧 | Revenue side one-per-order (unique `order_id`), cost side many. Profit is **never stored** — always revenue minus the lines, computed in `OrderProfitabilityService` and nowhere else, so the order page, list, export and dashboard cannot disagree. |
| `POST /admin/orders/{id}/profitability/revenue` | 🔧 | `finance.manage`. Number, amount, optional PDF in the same request. `customer_agreed` defaults **true** — a revenue invoice is by definition the one the customer agreed to; passing false records the figure while being honest the agreement isn't confirmed. Response carries `variance_from_order_total`, the reconciliation figure. |
| Costs: `POST/PATCH/DELETE …/profitability/costs[/{id}]` | 🔧 | A fee **requires a category** (a fee that can't be reported by channel defeats the point of recording it); a supplier invoice requires a supplier name. Files attach and download like finance-invoice PDFs, private disk. |
| **Currencies matched, never converted** | 🔧 | A cost in a currency other than the revenue invoice's is named in `other_currencies` and left out of the profit — the operations-board rule. Dashboard sums are EUR-only and say so in `definitions`. |
| Verification sign-off | 🔧 | `POST/DELETE …/profitability/verify`. Refuses (422 `no_revenue_invoice`) until a revenue figure exists — signing a profit with no revenue signs nothing. Manual withdrawal requires a written reason. |
| **Money moving withdraws the signature** | 🔧 | Same philosophy as the Session 83 order sign-off: any change to revenue amount/currency or a cost line's amount/currency/kind clears `verified_at` and writes `profitability_verification_withdrawn` to the order log — but editing a note does **not**, because a control that fires on typo fixes gets routed around. |
| `finance` block on the order detail | 🔧 | The ask was that order tracking *knows*. Compact block (revenue present, agreed, costs, profit, margin, verified) rides on `GET /admin/orders/{id}` behind `OrderFinanceRecord::available()` — the order page must never fail because a reporting feature arrived before its migration. Proved by a test that drops the tables and reads the order. |
| `GET /admin/finance/profitability` | 🔧 | `finance.view` (order managers hold it — same board logic as Session 83). One row per order ref: order total, revenue, costs split, profit, margin, verified/by/when. Filters: period, channel, `verified=yes|no`, `has_revenue=yes|no`, search. Excludes cancelled orders and `cs_test_` checkouts — the same two exclusions as the board, so the figures agree. |
| `GET /admin/finance/profitability/export` | 🔧 | `finance.view` + `orders.export` stacked, like the operations export. UTF-8 BOM for Excel, title/period/generated rows, one line per order ref with the verification columns, caveat row inside the file. Defaults to January-to-today — the document finance signs runs from January. |
| `GET /admin/finance/profitability/dashboard` | 🔧 | Month-by-month from January (`?year=` for a past year), gap-free, orders bucketed by `created_at` to match the operations report. Revenue/supplier/fees/profit/margin/verified per month + totals, `definitions` shipped with the numbers. |
| **#53** `liquidity_weeks` + `GET/PUT /admin/finance/liquidity` | 🔧 | Keyed `2026-W35` — the same `o-\WW` format the report buckets by. GET returns the current ISO week + 3; **the window rolls by reading**: it always starts at today's week, so a finished week falls out by the calendar moving — no job, no cleanup — and its row survives under `/history`. `projected_closing` chains: each week opens on its entered balance or the previous week's projected close. |
| Past weeks correctable, flagged | 🔧 | Finance reconciles after the fact, so a closed week's PUT is accepted — the response says plainly it has ended and lives in history. A key that isn't a real ISO week, or is more than a year out, is a 422: 2026-W54 filed silently under a different week would be evidence of nothing. |
| **#54** order_logs ENUM widening | 🔧 | Six new actions (`revenue_invoice_set`, `cost_line_added/updated/removed`, `profitability_verified`, `profitability_verification_withdrawn`), built from `OrderLog::ACTIONS` per the standing rule; `down()` refuses if rows would be truncated. |
| Backend tests (18 new) | ✅ | `OrderProfitabilityAndLiquidityTest` — migrations idempotent against real SQL, upload + download, both permission halves, the arithmetic incl. mixed currency, verification and its automatic withdrawal, the order-page embed and its pre-migration inertness, list filters and exclusions, the export's BOM and columns, the gap-free dashboard, the rolling window, history, and the week-key validation. Full suite **689 passed, 0 failed**, 206 skipped, up from 662. |

**Deliberately not built:** automatic Stripe/eBay fee capture. The webhook
never retrieves Stripe's `balance_transaction` and the eBay sync stores no
fee fields, so any "automatic" figure would be an estimate typed by a
developer instead of a fee typed by finance — the sevDesk lesson (Session 83)
says the honest version is finance entering what the statement says. If
automatic capture is wanted later, it is a separate session against the
Stripe API, and the fee lands as a `fee/stripe` cost line like any other.

See `FRONTEND_NOTE_finance-profitability.md`.

---

## Market intelligence — which market to enter next (Session 98)

> **Deploy status:** built and tested, **not yet deployed**. Migration **#45**
> is needed only for *imported* external data — the scorecard itself runs on
> data already in the database, so this is **deploy-order safe**: the report
> works before the migration and simply reports no reference data.

Ask, from the business: the behaviour data is useful but the tool has to be
*valuable* — convertible into a market database the marketing team can use to
decide **which market to penetrate**, not only a developer's diagnostic.

Session 79 already records demand (`search_events`, live on production since
2026-08-13). What did not exist was any join between demand and the commercial
side: quotes, orders, customers and marketing reach all key on country, and
nothing had ever put them in one row.

| Change | Status | Notes |
|--------|--------|-------|
| **`CountryNormaliser`** | 🔧 | The foundation, and the thing most likely to have made this silently wrong. `search_events.country` is ISO-2 from a CDN header; `orders`, `quote_requests`, `customers` and `marketing_contacts` all store free text. Joined raw, Germany becomes four markets with a quarter of the figures each — **no error anywhere**. Handles ISO codes, canonical names, native spellings (`Deutschland`, `Hrvatska`, `Türkiye`), accents and punctuation. |
| Unresolvable values are **returned, not dropped** | 🔧 | `unrecognised` lists every country string that could not be resolved, with its source table and row count. One `"Deutchland"` silently removes orders from Germany's row; the only way anyone finds out is if the report says so. |
| `GET /admin/analytics/markets` | 🔧 | `analytics.view` — which already includes the `marketing` role, deliberately, since marketing is the reader. Default window **90 days**, not 30: a quote needs time to become an order, and 30 days makes every market look worse at converting than it is. |
| **Named signals, not a 0-100 score** | 🔧 | Eight states, each implying different work. `demand_not_served` (searched a lot, found nothing) is a stock gap that campaigns cannot fix; `interest_no_reach` (interest, no contacts) is the clearest *penetrate* signal on the report. A single score would invite an argument about weightings and hide why a market ranks where it does. Thresholds are named constants, not literals, so they can be argued with. |
| Signal legend travels in the payload | 🔧 | `data.signals` carries every label and action string, so the UI does not hardcode them and the two cannot drift. |
| **Revenue is never blended across currencies** | 🔧 | Returned as a per-currency map. Converting a three-month-old order at today's rate is not the money Okelcor received, and a market ranked on it would move when the euro moves. `CurrencyConversionService` also throws on a failed lookup — using it here would let a Frankfurter outage break the whole report. |
| Rates are `null`, not `0`, on a zero denominator | 🔧 | "0% converted" on a market nobody enquired about reads as failure when it means nobody asked. |
| `unmeasured` — the blind spot, named | 🔧 | A country with no traffic scores zero, which on a page is indistinguishable from no demand — and that is precisely the market worth entering. Countries with imported data and no observed signal are listed **outside** the ranked table rather than scored zero. |
| `market_reference_stats` (migration #45) | 🔧 | Where outside evidence lands. Thin key/value (`country_code`, `metric`, `value`, `unit`, `period`, `source`) because nobody has produced this data yet and a schema guess needs a migration the first time it is wrong. `metric` is a plain string, not an ENUM — see the ENUM trap this project has hit three times. |
| `markets:import-reference` | 🔧 | Survey by default, writes only with `--fix`. **Rejects rather than guesses**: an unresolvable country, a non-numeric value or a blank metric is refused and listed with its line number. A statistic filed under the wrong country is worse than one that is absent, because it looks like evidence. Handles Excel's BOM and thousands separators; re-importing a corrected figure replaces it via `updateOrCreate`. |
| CSV export carries the reasoning | 🔧 | The "market database" the business asked for. Signal, recommended action, unrecognised values, unmeasured markets **and** the caveats all travel in the file — a spreadsheet gets forwarded and its caveats have to go with it. BOM-prefixed so Excel does not mangle accented country names. |
| Hands off into the existing campaign builder | 🔧 | `reach.market_slugs` are the same slugs `POST /admin/bulk-emails` already filters on, so "build a campaign for this market" is a deep link, not a new endpoint on either side. |
| Backend tests (30 new) | ✅ | `MarketIntelligenceTest` — **30 passed / 104 assertions, actually executed**. Includes the end-to-end proof that five spellings of Germany merge into one row, every signal, the null-rate rule, cancelled orders excluded, the export's caveats, and six tests on the import command's rejection paths. Full suite **662 passed, 0 failed**, 206 skipped. |

**What it cannot do, returned in `meta.not_covered` rather than left implied:**
rank a market nobody has ever visited from (that is what `unmeasured` and the
import path are for), break below country level, forecast, or say anything
about page views and click paths — none of which reach this API.

**Deliberately not built:** a single opportunity score, and any bundled
market-size data. The import path exists; someone still has to obtain the
figures. Until then `reference` and `unmeasured` are both empty, which is
honest rather than empty-looking.

See `FRONTEND_NOTE_market-intelligence.md`.

---

## Staff-to-staff messaging & forwarding (Session 97)

> **Deploy status:** built and tested, **not yet deployed**. **Migration #44
> required.** Unlike #29 and #42, this is NOT deploy-order safe: there is no
> previous behaviour to degrade to, so the endpoints 500 until it runs.
> Frontend keeps the feature behind a flag until the deploy is confirmed.

Ask, from the order manager: staff e-mail addresses are already in the system,
so a colleague should be reachable from the admin panel without opening
Outlook — and a customer e-mail already in the system should be forwardable to
whoever should actually handle it.

**The gap was total.** Every messaging path built to this point runs admin →
*customer*: `customer_communications` is keyed to a customer or a quote request
and cannot represent "Ada sent this to Ben". Checked for an existing internal
channel first — `staff_activities`/`staff_contributions` (Session 89) is a
performance ledger, not a message channel, and nothing else came close.

**Delivery is both, confirmed with the user before building:** the message
lands in the colleague's panel inbox *and* as a real e-mail to their
`@okelcor.com` address *and* in their notification bell *and* on their phone
via the existing Expo push. Same "Email = Inbox" pattern as customer
notifications (Session 47). Forward targets are staff-only, also confirmed.

| Change | Status | Notes |
|--------|--------|-------|
| `staff_messages` + `staff_message_recipients` (migration #44) | 🔧 | Two new tables. **Deliberately not a nullable `customer_id` on `customer_communications`** — that would make every existing customer-scoped query (the unified inbox, the per-customer thread, the customer's own portal) responsible for remembering to exclude internal mail. One forgotten `whereNotNull` leaks staff correspondence into a customer's portal. |
| Per-recipient delivery status | 🔧 | `email_status` lives on the recipient row, not the message. With three people on a message and one bad address, a single column would have to choose between reporting "sent" (hiding a failure) and "failed" (implying nobody got it). Neither is true. |
| Message row written **before** the send | 🔧 | Inverted from `AdminCommunicationController::composeAndSend`, which sends first and logs after. There the e-mail is the artefact and the log records it; here the panel thread is the artefact and the e-mail is a copy. A mail failure returns **201** with the recipient ids in `meta.email_failures` — the message is already in their inbox. |
| `GET /admin/staff-messages/directory` | 🔧 | Its own endpoint because listing admin accounts otherwise sits behind `admins.manage`, which is **super_admin only** — an order manager could not have seen a single colleague's name to write to. Returns strictly what a compose box needs; no password state, login history or 2FA status. |
| **No permission gate on messaging** | 🔧 | Every authenticated admin can write to a colleague, like editing their own signature. A permission here would mean an account that can log in but cannot be told anything. Visibility is per-message (sender or recipient), not by role. Forwarding *is* gated — `crm.view`, since you must already be allowed to read what you are forwarding. |
| Reply recipients come from the parent, never the request | 🔧 | A `to[]` on a reply is ignored. Otherwise a reply becomes a way to pull a colleague into a thread they were never on and hand them its entire history. Adding someone is a forward. Proved by a test that passes an outsider's id and asserts they still get a 404. |
| Forward quotes the original and **copies** attachments | 🔧 | Quoted, not linked — a link is useless to someone reading it on their phone at a port. Files are copied to new paths, so a forward survives the original communication being deleted (asserted directly). A missing source file is skipped with a log line rather than sinking the forward. |
| Forward targets are staff ids only | 🔧 | No free-text address anywhere. It would make "staff only" unenforceable and let a typo send internal correspondence to a stranger. External forwarding is a real feature needing its own permission and audit trail — flagged to frontend, not smuggled in. |
| Not-yours is **404, not 403** | 🔧 | On show, reply and attachment download. A 403 confirms that a message with that id exists. |
| **Reply-To is the sender, not the capture address** | 🔧 | Unlike `CustomerAdHocEmail`, which plus-addresses replies back into the system. `InboundEmailProcessor::isOwnDomainSender()` silently drops mail from an okelcor.com address — the guard that stops the app's own order/quote notifications spawning fake leads — so it would have swallowed every staff reply sent from Outlook without a trace. Pointing Reply-To at the sender makes an Outlook reply a normal e-mail between two colleagues: delivered, but outside the system. The guard was left alone; a test pins the behaviour. |
| Notification dedupe key made per-message | 🔧 | `AdminNotificationService`'s default key is type+related, which would have collapsed a second message from the same colleague while the first was still unread. Two messages are two events. |
| Backend tests (29 new) | ✅ | `StaffMessagingTest` — **29 passed / 97 assertions, actually executed** on the minimal-schema sqlite harness rather than behind the MySQL gate. Full suite: **632 passed, 0 failed**, 206 skipped. |

**Bug found by its own tests:** `subject` and `note` on the forward endpoint
are `nullable`, which leaves the key *absent* rather than null when not sent —
`$data['subject']` threw a 500 on every forward that didn't override the
subject, i.e. the common case. Three tests caught it before it left the machine.

**Numbering caught late, worth recording:** this was first written as
"Session 76 / migration #31" against a stale reading of this file, and the
migration filename `2026_08_11_000001_…` collided with the existing
milestone-ENUM migration of that exact prefix. Renamed to `2026_08_24_000001`
and renumbered before commit. Check `git log -1` and the tail of the migration
list before assigning either number.

**Deliberately not built:** drafts, delete/archive, group aliases
("all order managers"), read receipts shown to recipients, and external
forwarding. Each is listed in the frontend note with its reason rather than
left as a silent gap. Whether colleagues should see who has read a message is
a culture question, not a technical one — `read_at` is captured either way.

**Needs a frontend route:** the e-mail's "Open in the admin panel" button
points at `{FRONTEND_URL}/admin/messages/{id}`, which does not exist yet.
There is also no UI for any of this — `/admin/partner-sales`-style, the API is
live and nothing calls it until frontend builds the inbox and compose box.

See `FRONTEND_NOTE_staff-messaging.md`.

---

## The primary-image button that never worked (Session 96)

> **Deploy status:** built and tested, **not yet deployed**. No migration,
> **1 new route** — `route:cache` must be rebuilt. Also carries the Session 95
> hardening commit (`02fff7c`, sanitizer failures 422 instead of 500) if that
> was not already pulled.

The marketer replaced a product's primary image and got *"The route
api/v1/admin/products/3834/primary-image could not be found"* — with a
screenshot. He was the first person ever to press that button: the admin form
has POSTed to `/products/{id}/primary-image` since the form was written, and
the backend never had the route. Nobody noticed for months because products
arrive through the CSV import, which downloads its own images.

| Feature | Status | Notes |
|---------|--------|-------|
| `POST /admin/products/{id}/primary-image` | 🔧 | `products.edit`. Validates the media types the form has always advertised (images + MP4/MOV/AVI/WebM, 50 MB), deletes the replaced file rather than orphaning it, returns the full formatted product. Split from `PUT /products/{id}` because the form saves fields as JSON, which cannot carry a file — the same two-step contract article cover images use. |
| No frontend change | 🔧 | The action has been calling the correct URL all along; the backend now answers it. |

### Tests

**3 new** (44 in `ProductOptimizationTest`, suite **603, 0 failed**): the
replace-through-the-form-route round trip including the old file not lingering,
a non-media file refused, and a viewer 403'd.

---

## Search finds what the page shows (Session 95)

> **Deploy status:** built and tested, **not yet deployed**. **No migration,
> no new routes, no frontend change** — all three UIs already send their
> terms; the fix is entirely in what the server matches.

Reported by the marketing manager: cannot search products properly — e.g. by
SKU. The root cause was not the field list at all: **the admin panel has sent
its search as `q` since the page was built, and the admin endpoint only ever
read `search`** — the box silently filtered nothing. Worse, the panel wrote
`?page=` into the URL and read it back into the "Page 2 of 75" label but never
forwarded it to the API, and asked for `per_page=200` against a backend cap of
100. Net effect: **only the ~100 newest of 15,000 products were reachable in
the panel at all**, by search or by paging. Fixed all three: the endpoint
accepts `q` or `search` like the public one, the panel forwards `page`, and
`per_page` matches the cap.

On the way (first read as "SUV"), the search itself was also rebuilt — all
three copies (shop, navbar, admin) matched only brand/name/size/sku and knew
nothing about what Sessions 92–93 added:

| Feature | Status | Notes |
|---------|--------|-------|
| `ProductSearch` — one definition, three callers | 🔧 | Shop, navbar and admin all run the same search, so what the marketer finds in the panel is what a customer finds on the site. Three hand-rolled copies deleted. |
| Every word must match somewhere; each may match anywhere | 🔧 | "continental suv 205" narrows the way a person expects instead of being one literal string nothing contains. Capped at 6 tokens. |
| Fields = what the page displays | 🔧 | brand, name, size, spec, SKU, EAN, slug, season, type, description, the product's spec JSON — and the **brand's default specs**, because a Continental whose Fahrzeugtyp comes from the brand default *displays* SUV, and search must agree with the page, not the row. Brand descriptions deliberately excluded: every word of a brand story matching every product of the brand is noise, not recall. |
| Inactive brands lend nothing | 🔧 | Same rule as resolution, so search and page cannot disagree. |
| LIKE wildcards escaped | 🔧 | Searching "%" is a literal, not match-everything. |
| Deploy-order safe | 🔧 | The Session 92/93 clauses are guarded on their columns existing (cached per request), so a search in the window between code deploy and migration degrades to the old field list instead of erroring. |

### Tests

**10 new** (41 in `ProductOptimizationTest`, suite **600, 0 failed**, up from
590): found by own vehicle-type spec, found by brand-inherited spec, found by
description, multi-word AND semantics across different fields, season + EAN,
the admin panel running the same search, **the `q` parameter the panel
actually sends finding a SKU**, **pagination returning different rows per
page and covering the whole catalogue**, inactive brands excluded, and
wildcard literals.

---

## The marketing role (Session 94)

> **Deploy status:** built and tested, **not yet deployed**. **No migration,
> no new routes** — the role column has been VARCHAR validated against
> `AdminPermissions::ROLES` since #33, so a new role is a constant edit.

The marketer manages articles, product optimization and the e-mail campaigns,
and held `editor` — which could edit products on the API but carried no
campaign permissions, and whose admin-panel nav never offered the Products
page at all (a frontend `ROLE_ACCESS` gap; from his seat, "no access to
products"). One role now says what the job is.

| Grant | Why |
|---|---|
| `products.view` / `products.edit` | The catalogue — and `products.edit` is the gate on every content route: articles, categories, hero slides, brands (incl. Session 93 brand content), media, settings. |
| `articles.manage`, `media.upload`, `promotions.manage`, `fet.manage` | Content. |
| `settings.manage` | The site-wide product shipping/returns texts live there — the thing he was just asked to edit. |
| `marketing.manage`, `newsletter.manage` | Campaigns, marketing contacts, bulk email, and the audience list they send to. |
| `analytics.view` | What people search for is marketing data. |
| **Nothing operational** | No orders, payments, quotes, CRM, customers, finance, partners, eBay, `products.import` (that group carries delete-all), or `staff.view_team`. A marketing role that could touch operations would just be `admin` under a nicer name — asserted by test, permission by permission. |

`editor` is deliberately unchanged: the marketer moves off it rather than it
being widened — other people hold it, and their access was not this change's
to grow.

### Tests

**5 new** (suite **590, 0 failed**, up from 585): the role is valid, holds
every content/campaign key, holds none of sixteen operational keys, editor
unchanged, and an end-to-end route check — a marketing admin saving a rich
description and reading the spec sheet. The existing every-role walks
(staff.self, signoff board) pick the tenth role up automatically and pass.

**To finish (human step):** in Admin → Users, change the marketer's role from
`editor` to `marketing` after the deploy.

---

## Brand-level content defaults (Session 93)

> **Deploy status:** built and tested, **not yet deployed**. Migration **#43**
> unapplied. **No new routes.** Deploy-order safe. Frontend built in the same
> session.

The marketer's follow-up to Session 92, and he is right: with ~15,000
products, entering the optimization content product by product is not a
workflow. Entered once per brand instead, inherited by every product without
its own value.

| Feature | Status | Notes |
|---------|--------|-------|
| `brands` gains the same four content columns | 🔧 | The chain only works when every level speaks the same shape. |
| **Resolution at read time, never copied** | 🔧 | `product → brand → site setting → null`. A brand edit is instant on all its products, there is never a stale copy on 15,000 rows, and a product's own value always wins. |
| Only json-backed specs default per brand | 🔧 | A brand does not have one width or one EAN — per-tyre physical facts stay on the product (and are mostly CSV-imported anyway). |
| Same rules wherever a value enters | 🔧 | `PUT /admin/brands/{id}` runs the same sanitizer, the same A–G validation and the same junk-dropping as the product path. |
| Case-insensitive brand match | 🔧 | `products.brand` is free text from three import sources; "CONTINENTAL" and "Continental" inherit the same defaults — the rule the logo lookup has always used. Inactive brands lend nothing. |
| Admin → Brands → content button | 🔧 | `/admin/brands/{id}`: brand description (article editor), json half of the spec sheet, shipping/returns. Warns that a wrong blanket value is worse than an empty field. |

### Tests

**9 new** (30 in `ProductOptimizationTest`, suite **585, 0 failed**, up from
576): the migration + idempotency, one brand entry filling two products, the
product's own value winning, description fallback, the full shipping chain,
case-insensitive matching, inactive brands excluded, the admin form under
product rules, and A–G validation on brand defaults.

See the Session 93 addendum in `FRONTEND_NOTE_product-optimization.md`.

---

## The approved customer who could never log in (Session 91)

> **Deploy status:** built and tested, **not yet deployed**. Migration **#41**
> unapplied and **not deploy-order safe** — see below. **2 new routes**, so
> `route:cache` must be rebuilt. Frontend built in the same session.

A buyer wrote to the order manager: *"I can see the account was approved, but
each time I try to log in, I keep getting the attached message. I have completed
a few of these verifications that prompt a password change, but the login still
prompts me to verify my email again."* The attachment was the "Please verify
your email" page. He was not doing anything wrong — three faults were holding a
closed loop shut.

| Feature | Status | Notes |
|---------|--------|-------|
| **A password reset now confirms the address** | 🔧 | It stamped `email_verified_at` only for `onboarding_status = 'invited'`. An admin-approved self-registered buyer is set to `active`, so for exactly the population approved by hand, completing a reset proved mailbox control and recorded nothing. The token is generated by us, mailed to that address and returned by whoever opened it — the same evidence a verification link collects, by the same mechanism. Gating it on onboarding status confused who the customer is with what they had just proved. |
| An earlier verification keeps its date | 🔧 | This records when the mailbox was first proved, not when it was last used. |
| **The approval email carries a link** | 🔧 | It said "one last step: please verify your email address first" and enclosed nothing. The only link the customer ever had was from registration, and those expire in 24 hours — a B2B account is routinely approved weeks later, so the sentence named a step he had no way to take. Generated at send time so the clock starts then. |
| `POST /admin/customers/{id}/resend-verification` | 🔧 | `customers.manage`. Fresh 24-hour link. 409 if already confirmed — sending anyway would tell the order manager she had fixed something when the problem is elsewhere. |
| `POST /admin/customers/{id}/verify-email` | 🔧 | Vouching for an address when the link cannot reach it. Requires a reason. Logged as `email_verified_by_admin`, a distinct type from `email_verified`: one is the customer proving it, the other is Okelcor asserting it. Deliberately does not touch account or onboarding status — one control that cleared all three would make it impossible to tell which had been the problem. |
| `customers:stuck` | 🔧 | Walks the same gates `login` checks, in the same order, and names the button that clears each. Reports, never repairs — a rejected application and an unconfirmed address look identical from here. |
| The panel says so | 🔧 | `email_verified` was already on the payload and nothing rendered it. An approved, active, apparently healthy account that could not be used, with nothing on the page explaining why. |

### Found on the way: password reset links have never expired

The check was `now()->diffInMinutes($record->created_at) > 60`. **Carbon 3
returns a signed difference**, so for a token created in the past that
expression is negative — three hours old gives `-180`, and `-180 > 60` is false.
Every reset link this system has ever sent has been valid indefinitely, and an
old one in an inbox or a mail archive still works today. Now expressed as
"created_at plus the window is in the past", which has no sign to get wrong.

Found by a test written for something else, which is the only way it was going
to be found — nothing about the broken version fails visibly. It also mattered
more from this commit onward, since a reset now confers verification too.
`app/` has one other `diffIn*` comparison and it reads the correct way round.

### Closed: `security_events.type` — the last of the four

The Known Gap left open by Session 83. `SecurityEventService::log()` does **not**
wrap its insert in a try/catch, so unlike `order_logs.action` a missing type does
not lose an audit row quietly — it throws and fails the customer action that
triggered it. Writing `email_verified` without a migration would have broken
completing a password reset for everybody.

The list now lives on `SecurityEvent::TYPES` beside its writers, migration #41
builds the ENUM from it, and **a test asserts every literal passed to
`SecurityEventService::log()` in `app/` appears in it**. Both audit columns in
this project are now guarded the same way.

### Tests

**15 new** (**555 in the suite**, up from 540): the reset confirming the address,
the customer then actually logging in, the gate still refusing an unverified
account, an earlier date preserved, the invite flow still activating, an expired
token verifying nothing (**the test that caught the Carbon bug**), the approval
email carrying a link and not carrying one when unnecessary, resend working and
refused when already confirmed, an admin confirmation working and requiring a
reason and recorded as its own event type, a `viewer` refused, and the ENUM
guard.

See `FRONTEND_NOTE_customer-login-gates.md`.

---

## eBay Integration (Sessions 15–25)

| Phase | Feature | Status |
|-------|---------|--------|
| EB-1 | OAuth token storage (ebay_tokens, encrypted) | ✅ |
| EB-2 | Listing status tracking + ebay_listing_logs | ✅ |
| EB-3 | Price/title update sync + enhanced validation | ✅ |
| EB-4 | Settings readiness checklist (12 checks) | ✅ |
| EB-5 | eBay order sync (Sell Fulfillment API) | ✅ |
| — | eBay supplier search (Browse API proxy) | ✅ |
| — | eBay production credentials rotation | ⬜ | `EBAY_CLIENT_SECRET` needs rotation in eBay portal |

---

## Security (Sessions 9–10, 28)

| Feature | Status |
|---------|--------|
| EnsureAdminToken middleware | ✅ |
| Layered rate limiting (13 named limiters) | ✅ |
| Structured rate-limit logging | ✅ |
| Critical exception logging (bootstrap/app.php) | ✅ |
| SecurityEventService audit trail | ✅ |
| Admin 2FA enforcement (mandatory, no bypass) | ✅ |
| 5-hour admin session TTL | ✅ |

---

## System Health & Monitoring (Session 24)

| Feature | Status |
|---------|--------|
| `GET /admin/system/health` (9 check groups) | ✅ |
| `GET /admin/system/errors` (merged log/event/job errors) | ✅ |
| `php artisan system:health` CLI | ✅ |
| Hourly health snapshot (cached) | ✅ |
| Proposals group (CRM-7) | 🔧 |

---

## Multilingual Content (Sessions 31–31c)

| Feature | Status | Notes |
|---------|--------|-------|
| Articles EN/DE/FR/ES translations | ✅ | EN fallback |
| Hero slides EN/DE/FR/ES | ✅ | EN fallback |
| Categories EN/DE/FR/ES | ✅ | EN fallback |
| `translations:repair-public-content` command | ✅ | |
| `articles:missing-translations` command | ✅ | |
| Products translation table | ⬜ | No translation table exists |
| Site settings per-locale | ⬜ | |
| Transactional emails in customer's language | ⬜ | All emails English-only |

---

## Backup (Session 23a)

| Feature | Status |
|---------|--------|
| `backup:okelcor` command | ✅ |
| Daily 02:00 schedule | ✅ |
| Server cron registered | ✅ |

---

## Known Gaps / Not Yet Built

| Item | Priority | Notes |
|------|----------|-------|
| `GET /admin/products?trashed=only` | Low | Restore works, no dedicated trashed list |
| Admin customer edit / deactivate (PUT/DELETE per customer) | Medium | List + create + onboarding actions exist |
| Rejection email to customer (CRM-1) | Low | |
| Bulk approve/reject customers | Low | |
| Product translation table | Low | No multilingual products |
| Preferred language on customers | Low | All emails English |
| eBay production credentials rotation | **High** | `EBAY_CLIENT_SECRET` was exposed in a prior session — must rotate in eBay Developer Portal before listing live products |
| ~~`storage/logs/laravel.log` doesn't receive writes on production~~ | ~~Medium~~ Resolved | Confirmed resolved in Session 63/70 — used this file repeatedly to diagnose real production issues (Gemini quota errors, Crisp API errors) and it received writes correctly both times |
| GLS production API access | Low | Currently running on the sandbox host (`api-sandbox.gls-group.net`) for both auth and tracking — verified to return real live data for real parcels, so not urgent, but production access requires a separate GLS approval step if sandbox ever proves unreliable long-term |
| ~~`admin_users.role` ENUM missing documented roles~~ | ~~**High**~~ Resolved | **Fixed in Session 83 (migration #33)** — the column is now `VARCHAR(30)`, validated against `AdminPermissions::ROLES`, and `finance` was added. The deferred `sales_manager` grants in `analytics.view` and the partner-sales permissions were closed in the same change, as their comments instructed. Original entry: | Column only allows `super_admin/admin/editor/order_manager`; `sales_manager`, `support`, `content_manager`, `viewer` are referenced throughout `AdminPermissions.php` and this doc but can't be stored under MySQL strict mode — creating an admin with any of those roles fails outright. Found via CI in Session 52; needs a migration widening the ENUM (or switching to a plain string column) plus a check for any admin accounts already silently affected. **Now shaping new work rather than just blocking old**: `partner_sales.verify` (Session 73) and `analytics.view` (Session 79) were both granted to a narrower role list than they should have, with a comment on each saying to add `sales_manager` in the same change that widens the ENUM. Every session that adds a permission pays this again |
| 5 orders where line items exceed the stored total | **High** | Orders **10075, 10076, 10077, 10079, 10080** (all `source = website`). Items sum to roughly 2× the recorded total on ratios of 0.43–0.49 — inconsistent, so not one mechanism. Surfaced by `orders:repair-totals` in Session 75 and deliberately **not** repaired: no rule can say which of the two figures is right, and one of them is what the customer was charged. Needs a person to compare each against the issued invoice. Money-facing, and unlike the double count it is not self-evident which direction the error runs |
| Payment milestone history missing for all pre-#31 orders | Medium | `order_logs.action` never accepted the milestone values, and the writes are wrapped in a `try/catch` that only logs a warning — so no order on production has a record of who confirmed its deposit or balance. Migration #31 fixes it going forward; the lost rows cannot be reconstructed. If any order's payment confirmation is ever disputed, `storage/logs/laravel.log` warnings are the only trace, and only for as long as that file is retained |
| ~~Audit-log writes fail silently across the codebase~~ | ~~**High**~~ **Closed (Session 91)** | **Session 83** covered `order_logs.action`; **Session 91** covers `security_events.type` the same way — `SecurityEvent::TYPES` is now the single source its ENUM is built from, with a test asserting every literal passed to `SecurityEventService::log()` in `app/` appears in it. Both audit columns are now guarded, so a fourth instance is a red test rather than a silent hole. Note the two fail differently: order-log writes sit in a try/catch and lose the row, while `SecurityEventService` does not catch, so a mismatch there throws and fails the user's action outright. Original entry: | **Session 83** — `OrderLog::ACTIONS` is now the single source the ENUM is built from, and a test asserts every action literal written in `app/` appears in it. The pattern (try/catch around every log write) is unchanged and still correct; what changed is that a schema mismatch is now a red test rather than a silent hole. `security_events.type` is NOT yet covered by an equivalent check. Original entry: | Not the ENUM itself — the pattern. Every `OrderLog` write is inside a `try/catch` that logs a warning and continues, which is right (a failed log must not fail the user's action) but means a schema mismatch is invisible until someone reads the column definition. Three separate instances found this way now (`security_events.type` Session 73, `order_logs.action` Sessions 75 and 76). Wants a test asserting every action string written in `app/` is accepted by the column, or a CI check — otherwise there will be a fourth |
| ~~`login_histories` table doesn't exist~~ | ~~**Medium**~~ **Closed (Session 104)** | **Fixed by migration #58** — the table now exists, the reading and writing code was correct all along, and `LoginSecurityHardeningTest` proves the 10-failures-in-an-hour auto-suspend actually suspends and revokes tokens. Original entry: | **Session 91 adds context:** every read and write of it in `CustomerAuthController` sits inside a bare `catch (\Throwable) {}`, so the miss is invisible. Two consequences neither of which breaks login: the **10-failed-logins-in-an-hour auto-suspend has never once fired** (its counting query throws and is swallowed), and when a customer reports being locked out there is **no record of the attempts** to confirm it from our side. The 5-failure lockout still works — it reads `customers.failed_login_count`, not this table. Original entry: | Found in production logs (Session 70 investigation) — viewing a customer's login history in the admin panel throws `PDOException: Table 'login_histories' doesn't exist`. Distinct from the working `admin_login_histories` table (admin-side logins) — this is the customer-portal-side equivalent, apparently never migrated. Not yet fixed — flagged, not investigated further this session |
| Crisp webhook inactive (free plan) | Low | `POST /webhooks/crisp` is fully built and HMAC-verified but Crisp's free plan doesn't support custom webhooks at all (requires Premium) — mobile polls the conversations list in the meantime. Will "just work" the moment the plan changes, no code change needed |
| Custom live-chat system unused | Low | Session 66's Pusher-based `live_chat_sessions` system has zero real traffic — Crisp (Session 67) is the actual live chat product. Left in place rather than removed; candidate for deletion once Crisp is confirmed as the permanent choice |

---

## Database Tables

| Table | Purpose |
|-------|---------|
| `admin_users` | Admin accounts |
| `personal_access_tokens` | Sanctum tokens (admin + customer) |
| `customers` | Customer accounts |
| `customer_addresses` | Saved delivery addresses |
| `products` | Tyre catalogue |
| `product_images` | Product gallery |
| `articles` | Blog/news articles |
| `article_translations` | EN/DE/FR/ES translations |
| `categories` | Tyre categories (4 fixed) |
| `category_translations` | EN/DE/FR/ES |
| `hero_slides` | Homepage carousel |
| `hero_slide_translations` | EN/DE/FR/ES |
| `brands` | Tyre brands |
| `media` | Uploaded media files |
| `settings` | Key-value site settings |
| `orders` | All orders (live + manual + eBay) |
| `order_items` | Line items per order |
| `order_logs` | Append-only audit trail |
| `invoices` | Tax invoices (INV-YYYY-NNNN) |
| `trade_documents` | AB / PI / CI / PL / DN / uploads |
| `eu_declarations` | Gelangensbestätigung records |
| `quote_requests` | B2B tyre inquiries / leads |
| `quote_request_items` | Admin-curated line items per quote 🔧 |
| `customer_verifications` | CRM-8 buyer verification records 🔧 |
| `customer_timeline_events` | CRM-8 append-only buyer lifecycle timeline 🔧 |
| `customer_access_requests` | CRM-8 customer-initiated access requests 🔧 |
| `customer_communications` | CRM communication log |
| `admin_notifications` | CRM-3/3B per-admin-user notification feed + work queue 🔧 |
| `customer_notifications` | Customer portal notification feed ("Email = Inbox") 🔧 |
| `ebay_tokens` | Encrypted eBay OAuth tokens |
| `ebay_listing_logs` | eBay listing action audit |
| `ebay_order_sync_logs` | eBay order sync audit |
| `promotions` | Promotional pricing rules |
| `newsletter_subscribers` | Newsletter opt-ins |
| `contact_messages` | Contact form submissions |
| `marketing_contacts` | Imported mailing list for admin bulk-email campaigns 🔧 |
| `marketing_contact_markets` | Market membership per marketing contact (many-to-many; `marketing_contacts.market` remains the primary market) |
| `bulk_email_campaigns` | Bulk email sends (subject/body/filters/progress; + `blocks`/`theme`/`body_text` from the campaign builder) 🔧 |
| `campaign_templates` | Saved reusable campaign designs (blocks + theme). Built-in starters are code, not rows |
| `campaign_drafts` | Autosaved campaign editor state — disposable, private to its author, deleted when the campaign sends 🔧 |
| `bulk_email_campaign_recipients` | Per-recipient send status per campaign 🔧 |
| `admin_security_events` | Security audit events |
| `password_reset_tokens` | Customer password reset + invite tokens |
| `failed_jobs` | Laravel queue failures |
| `saved_fitments` | Customer-saved size/brand profiles ("My Garage") |
| `staff_activities` | Per-person record of work the API watched happen (Session 89) 🔧 |
| `staff_contributions` | Self-reported work the API cannot see, kept in its own table on purpose 🔧 |
| `admin_insights` | AI-generated dashboard insights, one row per insight per generation batch |
| `search_events` | One row per public catalogue query — term, filters, result count, and whether anything was found. No IP or user agent; the visitor digest rotates daily 🔧 |
| `admin_push_tokens` | Expo push tokens for the admin/ops mobile app, one row per device |
| `live_chat_sessions` / `live_chat_messages` | Custom live-chat system — built, dormant (Crisp is the real product; see Session 66/67) |
| `partner_organisations` | Partner distributors selling Okelcor product in other markets. Market is derived from `country`, not stored 🔧 |
| `partner_users` | People at a partner who log sales — phone + PIN auth, Sanctum token 🔧 |
| `partner_sales` | Reported sale lines. Soft-deleted; `unique(partner_org_id, client_generated_id)` is the offline idempotency key 🔧 |
| `partner_sale_audits` | Append-only trail of every change to a partner sale 🔧 |

---

## Active .env Keys Required on Production

```env
# App
APP_KEY=
APP_URL=https://api.okelcor.com
FRONTEND_URL=https://okelcor.com
APP_ENV=production
APP_DEBUG=false

# Database
DB_HOST=
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

# Mail (SMTP)
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=noreply@okelcor.com
ORDER_EMAIL=support@okelcor.com
QUOTE_EMAIL=support@okelcor.com
CRM_DIGEST_EMAIL=support@okelcor.com

# Stripe
STRIPE_SECRET_KEY=
STRIPE_WEBHOOK_SECRET=
STRIPE_CURRENCY=eur

# eBay (Sell API)
EBAY_CLIENT_ID=
EBAY_CLIENT_SECRET=        # ⚠ ROTATE — was exposed in a prior session
EBAY_RU_NAME=
EBAY_ENVIRONMENT=production
EBAY_MARKETPLACE_ID=EBAY_DE
EBAY_CATEGORY_ID=10183
EBAY_SELLER_POSTAL_CODE=
EBAY_SELLER_LOCATION=Germany

# Tracking
SHIPSGO_API_KEY=
DHL_API_KEY=

# WhatsApp Business (Meta Cloud API) — see WHATSAPP_SETUP.md for how to get these
WHATSAPP_PHONE_NUMBER_ID=
WHATSAPP_BUSINESS_ACCOUNT_ID=
WHATSAPP_ACCESS_TOKEN=
WHATSAPP_APP_SECRET=
WHATSAPP_VERIFY_TOKEN=

# Inbound e-mail capture (Cloudflare Email Worker) — see EMAIL_INBOUND_SETUP.md
MAIL_INBOUND_ENABLED=false
MAIL_INBOUND_ADDRESS=reply@reply.okelcor.com
MAIL_INBOUND_WEBHOOK_SECRET=
MAIL_INBOUND_MESSAGE_ID_DOMAIN=okelcor.com

# AI-generated admin dashboard insights (Gemini, free tier — aistudio.google.com/apikey)
# Blank = feature silently disabled; insights:generate no-ops, GET /admin/insights returns empty.
# Use the -latest alias, not a dated model — a pinned dated model
# (gemini-2.0-flash, gemini-2.5-flash) can 429/404 depending on the key's
# project even with a valid key; confirmed live 2026-07-20.
GEMINI_API_KEY=
GEMINI_MODEL=gemini-flash-latest

# Live chat real-time transport (Pusher, free tier — dashboard.pusher.com,
# create a "Channels" app). BROADCAST_CONNECTION=null (the default here)
# means live chat sessions/messages still work over plain HTTP, they just
# won't push in real time until this is set to "pusher" with real keys.
# NOTE (2026-07-20): this is for the custom live_chat_sessions system,
# which turned out to have no real traffic — Crisp (below) is the actual
# live chat product. Left configured/dormant rather than removed.
BROADCAST_CONNECTION=null
PUSHER_APP_ID=
PUSHER_APP_KEY=
PUSHER_APP_SECRET=
PUSHER_APP_CLUSTER=mt1

# Crisp — the real live chat product. website_id/identifier/key: reuse
# whatever the existing Next.js admin-panel proxy already has configured
# (same Crisp private plugin), don't mint a second plugin. webhook_secret
# is generated when you add the webhook URL in Crisp's dashboard
# (Settings → Integrations → Webhooks → subscribe to message:send).
CRISP_WEBSITE_ID=
CRISP_API_IDENTIFIER=
CRISP_API_KEY=
CRISP_WEBHOOK_SECRET=

# Admin session
ADMIN_SESSION_TTL_MINUTES=300

# Backup
BACKUP_ENABLED=true
BACKUP_RETENTION_DAYS=14

# Partner Sales Log (Session 73) — ALL OPTIONAL, sensible defaults in
# config/partner.php. Listed so the tunables are discoverable rather than
# needing a code read. Accepted currencies and the PIN policy live in that
# config file, not in .env.
PARTNER_EDIT_WINDOW_HOURS=24     # how long a partner may edit their own entry
PARTNER_MAX_BACKDATE_DAYS=730    # how far back a sale may be dated (paper backlog)
PARTNER_TOKEN_TTL_DAYS=90        # long by design — the app must work offline,
                                 # and a partner who can't reach the network
                                 # also can't re-authenticate

# Payment milestones (Session 76) — OPTIONAL, defaults in config/payment.php.
# Leave AUTO_START unset/false: true restores the old behaviour where issuing a
# proforma silently advanced the order to deposit_requested AND e-mailed the
# customer that a deposit was due, with no admin deciding it. That is the bug
# the order manager reported — a buyer queried a payment he'd never been asked
# for. The deposit/balance amounts are calculated either way; only the stage
# advance and the customer e-mail are gated. Start the ladder explicitly with
# POST /admin/orders/{id}/payment-milestones/request-deposit.
PAYMENT_MILESTONES_AUTO_START=false
PAYMENT_DEPOSIT_PERCENT=50       # used when an order carries no percentage of its own
```

---

## Production Deploy Command

```bash
cd /home/u978121777/domains/okelcor.com/public_html/okelcor-api
git fetch origin && git reset --hard origin/main
composer install --no-dev
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan config:clear
/opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
/opt/alt/php83/usr/bin/php artisan view:clear
```

**Migrations 1–18 — deployed to production (2026-07-01):**
1. `2026_06_02_000001_add_proposal_fields_to_quote_requests_table`
2. `2026_06_03_000001_create_quote_request_items_table`
3. `2026_06_08_000001_add_buyer_lifecycle_fields_to_customers_table` (CRM-8)
4. `2026_06_08_000002_create_customer_verifications_table` (CRM-8)
5. `2026_06_08_000003_create_customer_timeline_events_table` (CRM-8)
6. `2026_06_08_000004_create_customer_access_requests_table` (CRM-8)
7. `2026_06_10_000001_extend_security_events_type_enum` (CRM-9 — audit-trail fix)
8. `2026_06_15_000001_create_admin_notifications_table` (CRM-3 — admin notifications)
9. `2026_06_22_000001_extend_admin_notifications_for_crm3b` (CRM-3B — notification center)
10. `2026_06_22_000002_add_lead_metadata_to_quote_requests_table` (tyre-wholesaler landing attribution)
11. `2026_06_25_000001_add_preferred_language_to_customers_table` (localized emails/documents)
12. `2026_06_28_000001_create_customer_notifications_table` (customer portal notifications + notification_preferences)
13. `2026_06_28_000002_add_tracking_device_to_orders_table` (Traccar GPS — orders.tracking_device_id)
14. `2026_06_29_000001_change_carrier_type_bus_to_truck_on_orders` (carrier_type bus → truck, data-safe)
15. `2026_06_29_000002_add_delivery_eta_fields_to_orders` (dest_lat/dest_lon/route_total_km for ETA + progress)
16. `2026_07_01_000001_create_marketing_contacts_table` (Session 50 — bulk email)
17. `2026_07_01_000002_create_bulk_email_campaigns_table` (Session 50 — bulk email)
18. `2026_07_01_000003_create_bulk_email_campaign_recipients_table` (Session 50 — bulk email)
19. `2026_07_03_103842_add_proposal_signed_copy_to_quote_requests_table` (Session 53 — proposal sign-and-return)
20. `2026_07_14_000001_add_email_signature_to_admin_users_table` (Session 57 — Outlook-style e-mail)
21. `2026_07_14_000002_extend_customer_communications_for_composer` (Session 57 — Outlook-style e-mail)
22. `2026_07_15_000001_extend_order_logs_action_enum` (order item editing — widens the ENUM to include several action values already used in shipped code, plus the new item-correction actions)
23. `2026_07_15_000002_add_whatsapp_fields_to_customer_communications_table` (Session 58 — WhatsApp)
24. `2026_07_29_000001_add_tyre_batch_fields_to_products_table` (Session 71 — tyre passport; guarded/additive)
25. `2026_07_29_000002_add_estimated_dispatch_days_setting` (Session 71 — inserts one blank `site_settings` row via `insertOrIgnore`, idempotent)
26. `2026_07_30_000001_create_marketing_contact_markets_table` (Session 72 — multi-market marketing contacts; guarded/additive + idempotent backfill from `marketing_contacts.market`. Backfill proved against real SQL by `BulkEmailCampaignTest::test_migration_backfills_one_membership_per_existing_contact`; code is deploy-order safe and falls back to the single column until this runs)
27. `2026_07_30_000002_create_campaign_templates_table` (Session 72 — campaign builder; creates `campaign_templates` + adds `blocks`/`theme`/`body_text` to `bulk_email_campaigns`. All guarded, no data touched. Proved by `test_campaign_design_migration_applies_and_is_idempotent`; code is deploy-order safe — campaigns still render and send without these columns)
28. `2026_08_07_000001_create_partner_sales_tables` (Session 73 — Partner Sales Log; creates `partner_organisations`, `partner_users`, `partner_sales`, `partner_sale_audits`. All four are NEW: nothing existing is read, altered or backfilled, so this cannot affect a live row. Every table guarded with `Schema::hasTable`. Proved by `PartnerSalesLogTest::test_the_migration_applies_against_real_sql_and_is_idempotent`, which runs the migration file itself and re-runs it. **Unlike #24–27 the code is NOT deploy-order safe, deliberately** — there is no previous behaviour to degrade to, so the partner endpoints 500 until this runs rather than accepting entries into nowhere; the frontend keeps its mock transport on until it is confirmed applied)
29. `2026_08_07_000002_create_campaign_drafts_table` (Session 74 — campaign autosave; creates `campaign_drafts`. One new table, nothing existing read, altered or backfilled. Guarded with `Schema::hasTable`. Proved by `CampaignDraftTest::test_the_migration_applies_against_real_sql_and_is_idempotent`, which runs the migration file itself and re-runs it. The campaign editor's existing send path does not depend on this table, so only the six new draft endpoints are affected while it is unapplied)

Migrations 1–18 verified to apply cleanly on MySQL via CI (`migrate:fresh`) and `LeadFunnelAnalyticsTest`'s `RefreshDatabase`; #16–18 were additionally exercised against sqlite in `BulkEmailCampaignTest`. Applied to production via `artisan migrate --force` as part of the 2026-07-01 deploy (which also shipped Session 51's code-only Media Library fix — no new migrations there). #19–23 are guarded/additive (`Schema::hasColumn` checks) and ready to deploy via the same command — not yet confirmed run against production as of this note. #21 also widens `customer_communications.body` from TEXT to LONGTEXT via raw SQL (no doctrine/dbal in this project). See `DEPLOY_RUNBOOK.md` for the ordered deploy + rollback plan.

30. `2026_08_10_000001_add_totals_repair_actions_to_order_logs_enum` (Session 75 — widens the `order_logs.action` ENUM with `totals_repaired` and `totals_restored`. Same MySQL-ENUM widening pattern as #2026_07_15_000001 and #2026_07_17_120845: `ALTER ... MODIFY COLUMN ENUM(...)` needs the FULL value list every time, not just the addition. Skipped on non-MySQL drivers so the sqlite test harness is unaffected. **Needed before `orders:repair-totals --fix` or `orders:restore-total` can write their audit log** — without it MySQL rejects the insert with "Data truncated for column 'action'". Both commands now write the order and its log in one transaction, so a rejected log rolls the order back rather than leaving it corrected and unexplained)

**#26–27 (Session 72) are pushed but NOT yet applied to production.** Both are
guarded/additive and each is exercised against real SQL by a test that runs the
migration file itself (`test_migration_backfills_one_membership_per_existing_contact`,
`test_campaign_design_migration_applies_and_is_idempotent`), including a re-run to
prove idempotency. #26 is the only one of the two that touches existing rows — it
backfills one `marketing_contact_markets` row per contact from its current
`market`, changing no column on `marketing_contacts` itself. Take a backup first
(`artisan backup:okelcor`), then `migrate --pretend` before `migrate --force`.
Both features degrade to their previous behaviour while unapplied, so the code
being live ahead of the migration is safe — verified by test, not assumed.
`route:cache` must be rebuilt on that deploy: Session 72 adds 8 routes.

**#26, #27 and #28 are all now APPLIED to production (2026-08-07).**
`migrate:status` shows batches 96, 97 and 98 respectively — #26/#27 had been
applied at some earlier point, so only #28 actually ran on the 2026-08-07
deploy (176ms, after `backup:okelcor` and a `migrate --pretend` review).
`route:cache` was rebuilt for Session 73's 22 new routes.

31. `2026_08_11_000001_add_milestone_and_document_actions_to_order_logs_enum` (Session 76 — widens `order_logs.action` with **eleven values shipped code already writes and MySQL has been rejecting all along**: `deposit_requested`, `deposit_paid`, `balance_due`, `balance_paid`, `shipment_released`, `payment_milestone_email_sent`, `payment_milestone_email_failed`, `declaration_acknowledged`, `document_superseded`, `document_generation_blocked_payment_stage`, `proforma_signed_returned`. Every one of those writes sits behind a `try/catch` that logs a warning and continues, so the failures were invisible — the payment milestone history does not exist on production for any order and those rows are not recoverable. This is a backlog fix, not a new feature. Same `ALTER ... MODIFY COLUMN ENUM(...)` full-list pattern as #22, #30 and `2026_07_17_120845`; skipped on non-MySQL drivers. `down()` **throws** rather than running if any row uses one of the added values, since reverting the ENUM would truncate an append-only audit trail)

**#29, #30 and #31 are pushed but NOT yet applied to production.** #29 creates one
new table and nothing else reads it, so the code is deploy-order safe — only the
six campaign-draft endpoints are affected while it is unapplied. #30 is the
opposite: it is a prerequisite, not an addition. Until it runs, both
`orders:repair-totals --fix` and `orders:restore-total` will update an order and
then fail on the audit-log insert. They now wrap both writes in one transaction
so the order rolls back rather than being left corrected and unexplained — but
the work simply cannot be done until the ENUM is widened. Apply #30 before
touching order 10112 or the two lump-sum orders.

33. `2026_08_13_000001_widen_admin_users_role_column` (Session 83 — `admin_users.role` ENUM → `VARCHAR(30)` + an explicit index. Closes the top Known Gap since Session 52. Data-safe: every existing value is a valid string, so no row changes and none can be lost. `down()` **throws** rather than running if any account holds a role the old four-value ENUM could not store, since reverting would blank it and lock that person out. MySQL-only; skipped on other drivers.)
34. `2026_08_13_000002_create_order_signoffs_table` (Session 83 — one new table, nothing existing read or altered. `unique(order_id, slot, active)` with `active` as 1-or-NULL is what enforces "one standing signature per slot" in the database rather than in code. Exercised against real SQL by `OrderSignoffAndBoardTest`, which runs the migration file itself.)
35. `2026_08_13_000003_create_finance_invoices_table` (Session 83 — one new table for sevDesk invoice entries. Nothing existing read or altered. Same real-SQL exercise as #34.)
36. `2026_08_13_000004_add_signoff_actions_to_order_logs_enum` (Session 83 — adds `signoff_given`, `signoff_revoked`, `signoff_bypassed`, `document_gate_overridden`. **The first widening that does not restate the list by hand**: the ENUM is built from `OrderLog::ACTIONS`, so the schema and the code that writes to it cannot drift. `down()` throws if any row uses an added value. MySQL-only.)

60. `2026_09_01_000001_add_assignee_note_to_todos_table` (Session 108 — adds `todos.assignee_note`, nullable TEXT. Additive and guarded on both `Schema::hasTable` and `Schema::hasColumn`, so a re-run is a no-op; nothing existing is read, altered or backfilled. **Deploy-order safe in both directions, proved rather than assumed**: `Todo::supportsAssigneeNote()` gates every read and write, readers get null and `AdminTodoController::update()` drops the field rather than failing the whole update — the status is the load-bearing half and must still land. Exercised against real SQL by `TeamTodoListTest`, which runs the migration file itself, re-runs it, and separately drops the column to prove the code survives without it.)
61. `2026_09_01_000002_add_created_by_role_to_todos_table` (Session 109 — adds `todos.created_by_role` VARCHAR(30) + an index, and backfills it from each creator's current role. Additive, nullable, guarded on `hasTable`/`hasColumn`. **The backfill is re-runnable and cannot overwrite an existing stamp**: it writes only where the column is still null and a creator resolves, so a second run after somebody has changed role leaves the frozen value alone — asserted by `test_the_backfill_stamps_existing_rows_and_is_re_runnable`. Written in PHP with `chunkById` rather than a JOIN UPDATE so it runs identically on MySQL and on the sqlite harness. `down()` drops the index before the column, which sqlite requires. Deploy-order safe via `Todo::supportsSource()`: creating a to-do still works without the column, it just carries no badge.)


**#33–36 are pushed but NOT applied to production.** All four are additive or
widening; none rewrites an existing row. The code is **deploy-order safe in both
directions**, proved rather than assumed: `OrderSignoffService` checks for its
table and reports `not_required` when it is absent, so the order list, the order
detail and the item-edit path all work unchanged before the migration runs — an
existing test (`OrderTotalFromItemsTest`) caught the one path where that was not
true. The finance column on the board reads a structural zero and says so in
`meta.finance_recording_available`. `route:cache` must be rebuilt: 9 new routes.

39. `2026_08_17_000002_add_job_title_to_admin_users_table` (Session 89b — adds `admin_users.job_title` and `staff_activities.admin_job_title`. Both additive, guarded and nullable; nothing existing is read or rewritten, and every path falls back to a tidied role until a title is set. The second column is guarded on the ledger table existing, so #38 and #39 do not depend on each other's order.)

38. `2026_08_17_000001_create_staff_activity_tables` (Session 89 — creates `staff_activities` and `staff_contributions`. Both NEW: nothing existing is read, altered or backfilled, so this cannot affect a live row. Each guarded with `Schema::hasTable`. Proved by `StaffContributionLedgerTest::test_the_migration_applies_against_real_sql_and_is_idempotent`, which runs the migration file itself and re-runs it. **Deploy-order safe in both directions**, and unusually load-bearing: the recording hooks sit on the order, document and invoice paths, so `StaffActivity::ledgerAvailable()` no-ops every one of them until the table exists — a reporting table must never be able to fail the thing it reports on. A test drops the table and asserts an order log still writes.)

37. `2026_08_14_000001_add_file_and_origin_to_finance_invoices` (Session 86 — adds `file_path`/`original_filename`/`mime_type`/`file_size`/`uploaded_at` so finance can attach the sevDesk PDF, and `source_type`/`source_id` so a row auto-registered from an invoice or a trade document can be traced back to it rather than being an orphan number. Every column guarded with `Schema::hasColumn`; nothing existing is read, altered or backfilled. The code is deploy-order safe — `FinanceInvoice::registerAvailable()` checks for `source_type` and registration silently no-ops without it, so raising an invoice still works before this runs.)

⚠️ Bulk email is deployed but **not yet safe to use for a real send**: `.env`
still has `QUEUE_CONNECTION=sync`, so `SendBulkEmailCampaignJob` would run
inline during the HTTP request. Set `QUEUE_CONNECTION=database` and run a
queue worker before the order manager sends to the full contact list — see
Session 50 note above.

59. `2026_08_31_000001_add_weekly_fields_to_finance_liquidity_entries` (Session 105 — adds `week_key`, `supplier`, `currency`, `comment` to `finance_liquidity_entries` so the liquidity working can hold finance's weekly file. Every column guarded with `Schema::hasColumn`, all nullable; nothing existing read, altered or backfilled. **Deploy-order safe in both directions** — old rows keep working through the legacy `period` path and new fields are simply absent until it runs. Proved idempotent by `FinanceSnapshotTest`, which runs the migration file itself from the pre-migration state and re-runs it.)

58. `2026_08_29_000001_create_login_histories_table` (Session 104 — creates `login_histories`, the customer login-attempt log shipped code has written to since the portal launched and production never had. One NEW table, guarded with `Schema::hasTable`; nothing existing read, altered or backfilled. **Deploy-order safe in both directions** — the writers sit in a bare catch, which is exactly how the miss stayed invisible for months. Creating it re-arms the 10-failures-in-an-hour auto-suspend, which has never once fired on production. Proved idempotent by `LoginSecurityHardeningTest`, which runs the migration file itself and re-runs it.)

57. `2026_08_28_000006_create_todos_table` (Session 102 — the shared team to-do list; creates `todos`. One NEW table, guarded with `Schema::hasTable`; nothing existing read, altered or backfilled. **Deploy-order safe** — `Todo::available()` answers "not yet" and the My Work section sits in the same try/catch as the other task sources. Proved idempotent by `TeamTodoListTest`.)

56. `2026_08_28_000005_create_sales_order_board_tables` (Session 101 — the Sales & Order Management board; creates `sales_order_entries` + `sales_order_lines`. Two NEW tables, guarded with `Schema::hasTable`; nothing existing read, altered or backfilled. **Deploy-order safe** — `SalesOrderEntry::available()` answers "not yet". Proved idempotent by `SalesOrderBoardTest`, which runs the file itself and re-runs it.)

55. `2026_08_28_000004_create_ec_invoice_tables` (Session 100 — the EC Invoice List / ZM portal; creates `ec_invoice_periods`, `ec_invoice_groups`, `ec_invoice_lines`. Three NEW tables, guarded with `Schema::hasTable`; nothing existing read, altered or backfilled. **Deploy-order safe** — `EcInvoiceGroup::available()` answers "not yet" and the My Work section is inside the same try/catch pattern as the finance tasks. Proved idempotent by `EcInvoiceListTest`, which runs the file itself and re-runs it.)

54. `2026_08_28_000003_add_profitability_actions_to_order_logs_enum` (Session 99 — widens `order_logs.action` for the six profitability actions, built from `OrderLog::ACTIONS` per the standing rule; `down()` refuses if any row uses an added value. MySQL-only, no-op on sqlite. Until it runs on MySQL, profitability log writes fall into the try/catch and warn rather than fail the request — run it in the same deploy as #52.)

53. `2026_08_28_000002_create_liquidity_weeks_table` (Session 99 — creates `liquidity_weeks`, one row per ISO week keyed `2026-W35`. NEW table, nothing existing read, altered or backfilled; guarded with `Schema::hasTable`. **Deploy-order safe** — `LiquidityWeek::available()` answers "not yet" until it runs and the planner returns an empty, labelled payload. Proved idempotent by `OrderProfitabilityAndLiquidityTest`, which runs the file itself and re-runs it.)

52. `2026_08_28_000001_create_order_profitability_tables` (Session 99 — creates `order_finance_records` (revenue invoice + verification, unique per order) and `order_cost_lines` (supplier invoices + fees). Both NEW, guarded with `Schema::hasTable`; nothing existing read, altered or backfilled. **Deploy-order safe and load-bearing the same way #38 was**: the order DETAIL endpoint embeds a finance block behind `OrderFinanceRecord::available()`, and a test drops the tables and asserts the order page still answers with `finance: null` — a reporting table must never be able to fail the thing it reports on.)

46–51. The `2026_08_25_*` batch (parallel sessions, 2026-08-25 — permission overrides on `admin_users`, the finance snapshot board tables + assignee, eBay live listings + title, campaign recipient tracking. Documented in their own sessions' notes rather than itemised here; listed so the numbering above stays truthful.)

45. `2026_08_24_000002_create_market_reference_stats_table` (Session 98 — external per-country market data for the market intelligence report; creates `market_reference_stats`. One new table, nothing existing read, altered or backfilled. Guarded with `Schema::hasTable`. Proved by `MarketIntelligenceTest::test_the_migration_applies_against_real_sql_and_is_idempotent`, which runs the migration file itself and re-runs it. **Deploy-order safe, unlike #44** — the market report checks for the table and simply reports no reference data while it is absent, so the scorecard works before this runs. `metric` is a plain string, not an ENUM, and `unique(country_code, metric, period)` is what makes re-importing a corrected figure a replacement rather than a duplicate.)

44. `2026_08_24_000001_create_staff_messages_tables` (Session 97 — staff-to-staff messaging; creates `staff_messages` + `staff_message_recipients`. Both NEW: nothing existing is read, altered or backfilled, so this cannot affect a live row. Every table guarded with `Schema::hasTable`. Proved by `StaffMessagingTest::test_the_migration_applies_against_real_sql_and_is_idempotent`, which runs the migration file itself and re-runs it. **Like #28 and unlike #42/#43, the code is NOT deploy-order safe, deliberately** — there is no previous behaviour to degrade to, so the messaging endpoints 500 until this runs rather than silently accepting messages into nowhere. Note the filename: first written as `2026_08_11_000001`, which collided with the milestone-ENUM migration of that exact prefix; renamed before commit.)

43. `2026_08_19_000001_add_content_defaults_to_brands_table` (Session 93 — adds `description_html`, `specs`, `shipping_info`, `returns_info` to `brands`; the same four content fields products gained in #42, because the product → brand → setting chain only works when every level speaks the same shape. Guarded and additive; nothing existing read, renamed or rewritten. **Deploy-order safe**: resolution reads whole brand rows, so before this runs the attributes are simply absent and behaviour is unchanged. Proved by `ProductOptimizationTest::test_the_brand_migration_applies_against_real_sql_and_is_idempotent`, which runs the migration file itself and re-runs it.)

42. `2026_08_18_000003_add_seo_and_specs_to_products_table` (Session 92 — adds `slug` (unique, backfilled for every existing product inside the migration, only-NULLs so re-runs cannot rename a live URL), `description_html`, `specs` JSON, `shipping_info`, `returns_info`; seeds `product_shipping_info`/`product_returns_info` site settings via insertOrIgnore. All guarded and additive; nothing existing read, renamed or rewritten. **Deploy-order safe in both directions**: the model checks for the slug column before writing it (cached per request — the Wix import creates thousands of rows), and the public API resolves numeric ids regardless. Proved by `ProductOptimizationTest::test_the_migration_applies_against_real_sql_and_is_idempotent`, which runs the migration file itself and re-runs it.)

41. `2026_08_18_000002_add_email_verification_types_to_security_events_enum` (Session 91 — adds `email_verified`, `email_verification_sent`, `email_verified_by_admin`, and rebuilds the ENUM from the new `SecurityEvent::TYPES` constant instead of a hand-copied list. Closes the Known Gap left open by Session 83. **Not deploy-order safe, and this one bites harder than #40**: `SecurityEventService::log()` does not swallow its exceptions, so on strict MySQL a type the column rejects does not lose an audit row quietly — it throws and fails the customer action that wrote it. Deploying Session 91's code without this migration would break completing a password reset for every customer. Apply it before or with the code. `down()` throws if any row uses an added value. MySQL-only.)

40. `2026_08_18_000001_add_payment_state_corrected_to_order_logs_enum` (Session 90 — adds `payment_state_corrected`. Second widening built from `OrderLog::ACTIONS` rather than a hand-copied list; see #36. **Not deploy-order safe, deliberately.** `PaymentStateCorrectionService` is the only thing that can move a payment state backwards and its whole claim to being safe in an order manager's hands is that it cannot be done quietly, so its log write is the one in this project NOT wrapped in a try/catch. Until this runs, MySQL rejects the action and the correction rolls back rather than happening unrecorded — the endpoint refuses to work instead of working silently, which is the intended failure of the two. `down()` throws if any row uses the added value. MySQL-only.)

32. `2026_08_12_000001_create_search_events_table` (Session 79 — customer behaviour analytics; creates `search_events`. One new table: nothing existing is read, altered or backfilled, so this cannot affect a live row. Guarded with `Schema::hasTable`. Proved by `CustomerBehaviourAnalyticsTest`, which runs the real migration file in its own setup. **Deploy-order safe in both directions, unlike #28** — `SearchEventRecorder` checks for the table and skips when it is absent, so the public catalogue never fails because a reporting table has not been created yet, and the report returns `available: false` rather than reporting zeros. The consequence of code-before-migration is only that nothing is recorded until it runs)

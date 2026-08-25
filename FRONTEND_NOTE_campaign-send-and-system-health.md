# Frontend note — campaign send errors, test send, and the system-health section

**Backend fixes for the marketer's three reports. No migration. `route:cache`
must be rebuilt (the system routes moved to a new permission group).**

---

## 1. "Sending a market campaign shows an error, but the emails arrive"

Root cause, backend-side: production runs `QUEUE_CONNECTION=sync`, so
`POST /admin/bulk-emails` executed the **entire send inside the HTTP request**.
A market-sized list (Croatia is ~200 contacts at roughly a second each) outlives
the web server's timeout: the browser gets a gateway error while PHP quietly
finishes delivering every email. The error the marketer screenshotted was the
web server giving up on the response, not the send failing.

Fixed: on the sync driver the send is now deferred until **after the response
is flushed**. The endpoint answers in normal request time:

- `201` with `data.status: "queued"` — this now comes back within a second or
  two even for a big list.
- The send then runs server-side; **poll `GET /admin/bulk-emails/{id}`** while
  `status` is `queued`/`sending` and show `sent_count + failed_count` out of
  `total_recipients`. The progress bar described in
  `FRONTEND_NOTE_bulk-email.md` was unobservable before (the send finished
  before the response existed); it is now real.

**What to change on your side:** nothing structurally — but if the send button
currently treats a slow/failed response as "send failed", that message has been
lying to the marketer. Treat `201` as "queued", and drive completion state from
polling, not from the POST.

## 2. Test send to a single address

`POST /admin/bulk-emails/test-send` had two ways to 500 where it should have
explained itself. Both are now 422s with a `code` the UI can switch on:

| Status | `code` | Meaning | Show the marketer |
|---|---|---|---|
| 422 | `empty_body` | No blocks and no pasted HTML | `message` verbatim |
| 422 | `body_unprocessable` | Pasted HTML the sanitizer cannot process | `message` verbatim |
| 422 | `invalid_blocks` | unchanged | as before |
| 502 | `test_send_failed` | unchanged — SMTP rejected it | as before |

Same two new codes apply to `POST /admin/bulk-emails/preview` and
`POST /admin/bulk-emails` (store), which shared the 500s.

Also: `blocks: null` and `theme: null` alongside a pasted `body_html` are now
accepted on all three endpoints. Previously `blocks: null` failed the `array`
rule with *"The blocks field must be an array."* — if your serializer includes
unused editor state as `null`, that alone was enough to make every test send of
a pasted campaign fail validation. If you worked around it by deleting keys,
the workaround can stay or go; both shapes are valid now.

## 3. System health section

Root cause: when `security.view` was hardened to super_admin-only, the two
system routes went with it, and the health section has returned **403 for
every other role since** — that is the "looks broken" report.

Fixed by splitting the permission:

| Endpoint | Old permission | New permission |
|---|---|---|
| `GET /admin/system/health` | `security.view` (super_admin only) | **`system.view`** (super_admin, admin) |
| `GET /admin/system/errors` | `security.view` | **`system.view`** |
| `GET /admin/security/*` (audit dashboard) | `security.view` | unchanged — super_admin only |

**What to change on your side:**

1. Gate the System Health section on **`system.view`** from the login
   payload's `permissions` array (it now contains `system.view` for
   super_admin and admin). Hide the section entirely for roles that don't
   hold it — a marketer should see no health section, not a broken one.
2. The health payload has a new group: `data.groups.queue` (two checks:
   `queue_driver`, `stuck_campaigns`), same row shape as every other group.
   If groups are rendered dynamically nothing is needed; if group names are
   hardcoded, add it. The `queue_driver` check currently reports a
   **warning** on production — that is correct and intentional: it names the
   sync-queue configuration behind report #1, and clears once
   `QUEUE_CONNECTION=database` with a worker is live.

---

## Deploy order

Safe both ways, with one caveat: until the API deploys, `system.view` is not
in any login payload, so a frontend gating on it hides the section for
everyone — acceptable, it is currently a 403 wall anyway. Rebuild
`route:cache` on deploy; run `composer dump-autoload` (a stale duplicate
controller file was deleted).

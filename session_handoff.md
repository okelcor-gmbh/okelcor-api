# Session Handoff — Okelcor API
Last updated: 2026-06-03 (session 39 — CRM-7 Sales Pipeline & Proposal Management)

---

## Session 39 — CRM-7: Sales Pipeline & Proposal Management (complete)

### What was built

A full proposal lifecycle layer on top of the existing quote/lead pipeline, connecting
qualified leads to orders via a controlled Proposal → Customer Acceptance → Order flow.

### Proposal status flow

```
Quote Request submitted
  → qualification_status = qualified (CRM-2/3)
  → Admin creates proposal draft (proposal_status = draft)
  → Admin marks ready (proposal_status = ready) — PDF generated
  → Admin sends proposal (proposal_status = sent) — email sent, token issued
      Customer accepts (proposal_status = accepted) — via token link or auth portal
      Customer rejects (proposal_status = rejected) — reason captured
      Proposal expires (proposal_status = expired) — auto on token load past expiry
  → Admin converts to order (proposal_status = converted)
      → Order created → AB auto-generated
      → Customer accepts AB → Proforma unlocked
      → PI sent → deposit requested
```

### Migration

**`2026_06_02_000001_add_proposal_fields_to_quote_requests_table`**

New columns on `quote_requests`:

| Column | Type | Purpose |
|---|---|---|
| `proposal_status` | ENUM(none/draft/ready/sent/accepted/rejected/expired/converted) DEFAULT 'none' | Proposal lifecycle stage |
| `proposal_number` | VARCHAR(50) nullable | QT-YYYY-XXXX sequential number |
| `proposal_items` | JSON nullable | Snapshot of line items at draft time |
| `proposal_total` | DECIMAL(10,2) nullable | Total from proposal items |
| `proposal_currency` | VARCHAR(3) DEFAULT 'EUR' | Currency |
| `proposal_acceptance_token` | VARCHAR(128) nullable unique | 64-char hex token for public acceptance |
| `proposal_sent_at` | TIMESTAMP nullable | When sent to customer |
| `proposal_accepted_at` | TIMESTAMP nullable | When customer accepted |
| `proposal_rejected_at` | TIMESTAMP nullable | When customer rejected |
| `proposal_expires_at` | TIMESTAMP nullable | Token expiry (default 30 days from send) |
| `proposal_voided_at` | TIMESTAMP nullable | When voided by admin |
| `proposal_voided_by` | FK → admin_users nullable | Admin who voided |
| `proposal_void_reason` | TEXT nullable | Void reason |
| `proposal_rejection_reason` | TEXT nullable | Customer rejection reason |
| `proposal_pdf_path` | VARCHAR(500) nullable | Private disk path to PDF |
| `proposal_accepted_ip` | VARCHAR(45) nullable | Acceptance IP (hidden from API) |
| `proposal_accepted_user_agent` | TEXT nullable | Acceptance UA (hidden from API) |
| `proposal_acceptance_note` | VARCHAR(500) nullable | Optional customer note |

### Proposal number format

`QT-YYYY-XXXX` — sequential per year, locked via `lockForUpdate()` on `quote_requests.proposal_number` column.
Independent counter from trade document numbers (AB/PI/CI/PL/DN).

### Admin endpoints

All under `permission:proposals.manage` (roles: super_admin, admin, order_manager, sales_manager):

| Endpoint | Description |
|---|---|
| `POST /admin/quote-requests/{id}/proposal/draft` | Create/update proposal draft. Body: `{ items[], currency?, expires_days?, notes? }` |
| `POST /admin/quote-requests/{id}/proposal/mark-ready` | Mark draft as ready; generates PDF |
| `POST /admin/quote-requests/{id}/proposal/send` | Send proposal email + generate token. Body: `{ recipient_email?, message?, expires_days? }` |
| `POST /admin/quote-requests/{id}/proposal/generate-link` | Generate/rotate acceptance link without emailing |
| `POST /admin/quote-requests/{id}/proposal/void` | Void proposal. Body: `{ reason }` (required) |
| `GET /admin/quote-requests/{id}/proposal/download` | Download proposal PDF |

### Public (token-based) endpoints

Rate limited: `throttle:acceptance-links` (20/min):

| Endpoint | Description |
|---|---|
| `GET /api/v1/proposals/{token}` | Safe proposal summary — no PII, no internal notes. Auto-expires if past expiry. |
| `POST /api/v1/proposals/{token}/accept` | Customer accepts proposal. Body: `{ note? }` |
| `POST /api/v1/proposals/{token}/reject` | Customer rejects proposal. Body: `{ reason? }` |

Token: 64-char hex (`bin2hex(random_bytes(32))`). Default TTL: 30 days. Token is invalidated (set to null) after accept/reject.

### Authenticated customer endpoints

| Endpoint | Description |
|---|---|
| `POST /api/v1/auth/quotes/{ref}/accept-proposal` | Logged-in customer accepts by quote ref_number |
| `POST /api/v1/auth/quotes/{ref}/reject-proposal` | Logged-in customer rejects by quote ref_number |

### Convert-to-order guard (CRM-7)

`POST /admin/quote-requests/{id}/convert-to-order` now enforces:

| Condition | Behaviour |
|---|---|
| `proposal_status = 'none'` (pre-CRM-7 quote) | Allowed — backwards compatible |
| `proposal_status = 'accepted'` | Allowed — normal flow |
| Any other status + non-super_admin | 409 `code: proposal_not_accepted` |
| Any other status + super_admin | Allowed with warning log entry |

On successful conversion: `proposal_status` is set to `'converted'`.

### formatList / formatDetail new proposal fields

Both admin quote list and detail responses now include:
- `proposal_status`, `proposal_number`, `proposal_total`, `proposal_currency`
- `proposal_sent_at`, `proposal_accepted_at`, `proposal_rejected_at`, `proposal_expires_at`
- `proposal_voided_at`, `proposal_voided_by`, `proposal_void_reason`
- `proposal_rejection_reason`, `proposal_acceptance_note`
- `has_proposal_pdf` (bool), `proposal_expired` (computed bool), `proposal_download_url`
- `proposal_items` (detail only)

### summary() new counts

`GET /admin/quote-requests/summary` now returns:
- `proposals_draft_count`, `proposals_ready_count`, `proposals_sent_count`
- `proposals_accepted_count`, `proposals_rejected_count`, `proposals_expired_count`
- `proposals_pending_conversion` — accepted proposals not yet converted to order

### index() new filter

`GET /admin/quote-requests?proposal_status=accepted` — filter by proposal_status
`GET /admin/quote-requests?proposals_pending_conversion=true` — accepted, no order yet

### Email template

`ProposalEmail` mailable:
- Subject: `Proposal from Okelcor — {proposal_number}`
- HTML + plain text views
- Includes: customer name/company, line items table, total, valid-until date
- Accept + Decline CTA buttons pointing to `{FRONTEND_URL}/proposals/accept/{token}`
- Reply-To: sender admin email

### Proposal PDF

`resources/views/pdf/proposal.blade.php` — DomPDF template:
- Matching Okelcor brand style (orange accent, partials reused)
- Bill-to block, proposal details table, sequential items table with totals
- Delivery terms (incoterm), lead time, payment terms, bank block
- Validity disclaimer
- Stored at `proposals/QT-YYYY-XXXX.pdf` on local (private) disk

### Communication log

All proposal actions automatically log to `customer_communications`:
- `proposal_drafted`, `proposal_ready`, `proposal_sent` (type: system/email)
- `proposal_voided` (type: system)
- `proposal_accepted`, `proposal_rejected` (type: system, direction: inbound, from public/customer controllers)

### System health — proposals group

New `proposals` group in `GET /admin/system/health`:
- `proposals_expired_unsent`: warning if any sent proposals are past expiry with no response
- `proposals_pending_conversion`: warning if any accepted proposals not yet converted to order

### Audit log events

All via `Log::info('[event_name]', [...])`:
- `proposal_drafted` — `POST /proposal/draft`
- `proposal_ready` — `POST /proposal/mark-ready`
- `proposal_sent` — `POST /proposal/send` (includes email success/failure flag)
- `proposal_send_failed` — email failure in send (error level)
- `proposal_voided` — `POST /proposal/void`
- `proposal_accepted` — both public token and authenticated routes
- `proposal_rejected` — both public token and authenticated routes
- `proposal_expired` — auto-expire on token load

### Files changed

| File | Change |
|---|---|
| `database/migrations/2026_06_02_000001_add_proposal_fields_to_quote_requests_table.php` | New — 18 proposal columns |
| `app/Models/QuoteRequest.php` | Added all proposal_* to `$fillable`, `$casts`, and `$hidden` |
| `app/Services/TradeDocumentService.php` | Added `'proposal' => 'QT'` to PREFIXES; added `generateProposalNumber()` and `generateProposalPdf()` |
| `app/Http/Controllers/Admin/AdminProposalController.php` | New — draft/markReady/send/generateLink/void/download |
| `app/Http/Controllers/ProposalController.php` | New — public show/accept/reject (token-based) |
| `app/Http/Controllers/CustomerQuoteAcceptanceController.php` | Added `acceptProposal()` and `rejectProposal()` (auth customer, by ref) |
| `app/Mail/ProposalEmail.php` | New mailable |
| `resources/views/emails/proposal-email.blade.php` | New HTML email template |
| `resources/views/emails/proposal-email-text.blade.php` | New plain-text email template |
| `resources/views/pdf/proposal.blade.php` | New DomPDF proposal template |
| `app/Http/Controllers/Admin/AdminQuoteRequestController.php` | CRM-7 guard in `convertToOrder()`; proposal fields in `formatList()`/`formatDetail()`/`summary()`; `proposal_status` + `proposals_pending_conversion` filters in `index()` |
| `app/Support/AdminPermissions.php` | Added `proposals.manage` permission |
| `app/Http/Controllers/Admin/SystemHealthController.php` | Added `proposals` group: `proposals_expired_unsent` + `proposals_pending_conversion` checks |
| `routes/api.php` | 11 new proposal routes (2 use imports + 6 admin + 3 public + 2 auth customer) |

### Deploy steps

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

**1 migration:** `2026_06_02_000001_add_proposal_fields_to_quote_requests_table`
— additive ALTER TABLE on `quote_requests`. No data loss.
All existing rows default to `proposal_status = 'none'` (backwards compatible).

### Test checklist

```
# 1. Draft proposal
POST /admin/quote-requests/{id}/proposal/draft
  { "items": [{"name":"Michelin 205/55R16","brand":"Michelin","quantity":200,"unit_price":49.50}] }
→ 201, proposal_number=QT-2026-0001, proposal_status=draft

# 2. Mark ready (generates PDF)
POST /admin/quote-requests/{id}/proposal/mark-ready
→ 200, proposal_status=ready, has_proposal_pdf=true

# 3. Send proposal
POST /admin/quote-requests/{id}/proposal/send
→ 200, proposal_status=sent, qualification_status=proposal_sent
→ ProposalEmail sent to quote.email with accept/reject links
→ CustomerCommunication row created (type=email, status=sent)

# 4. Public token preview
GET /api/v1/proposals/{token}
→ 200, safe proposal info (no internal_notes, no admin data)
→ expires_at, proposal_total, proposal_items

# 5. Customer accepts via token
POST /api/v1/proposals/{token}/accept
→ 200, proposal_status=accepted, token nulled

# 6. Admin convert-to-order now allowed
POST /admin/quote-requests/{id}/convert-to-order { items, payment_method, ... }
→ 201, order created, proposal_status=converted

# 7. Rejected proposal blocks non-super_admin conversion
POST /admin/quote-requests/{id}/proposal/send → send
(Customer rejects)
POST /admin/quote-requests/{id}/convert-to-order (as admin, not super_admin)
→ 409, code=proposal_not_accepted

# 8. Super admin override
POST /admin/quote-requests/{id}/convert-to-order (as super_admin, proposal_status=rejected)
→ 201 (warning logged), order created

# 9. Void proposal
POST /admin/quote-requests/{id}/proposal/void { "reason": "Pricing to be revised" }
→ 200, proposal_status=none, token cleared

# 10. Health check shows pending conversion
GET /admin/system/health
→ proposals.proposals_pending_conversion: warning if accepted proposals not yet ordered

# 11. Auth customer accepts
POST /api/v1/auth/quotes/{ref}/accept-proposal
→ 200, proposal_status=accepted

# 12. Old quote (proposal_status=none) converts as before
POST /admin/quote-requests/{id}/convert-to-order (proposal_status=none)
→ 201 — no proposal check applied
```

---

## CRM Pipeline — Status Summary (as of 2026-05-30)

All 6 CRM phases deployed to production on `main`. Deploy command:

```bash
cd /home/u978121777/domains/okelcor.com/public_html/okelcor-api
git fetch origin && git reset --hard origin/main
composer install --no-dev
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan config:clear && config:cache && route:cache && view:clear
```

Post-deploy backfill (run once):
```bash
/opt/alt/php83/usr/bin/php artisan customers:recalculate-data-quality --all
```

| Phase | Description | Commit | Status |
|---|---|---|---|
| CRM-1 | Controlled customer onboarding (onboarding_status, invite flow, admin actions) | `98c2333` | ✅ Live |
| CRM-2 | Inquiry quality scoring + spam gate (InquiryQualityService, review_status) | `e3b3dbf` | ✅ Live |
| CRM-3 | Lead qualification & sales pipeline (qualification_status, assign, convert-to-customer) | `67c54c0` | ✅ Live |
| CRM-3 fix | convert-to-customer 409 structured response with customer data | `a188755` | ✅ Live |
| CRM-4 | Customer segmentation & access control (customer_segment, access_level, checkout/doc guards) | `8d0f17a` | ✅ Live |
| CRM-5 | Customer data quality & deduplication (scoring, normalization, duplicate detection) | `02e773a` | ✅ Live |
| CRM-6 | Communication log + follow-up automation + email templates | `3bb7ae1` | ✅ Live |
| CRM-6 fix | 500 on send-follow-up-email (Mailable::$subject conflict) + blank dropdown (name→label) | `45ff5fc` | ✅ Live |

### Key .env variables added across CRM phases

```
# CRM-4: access control
# (no new env vars — behavior driven by DB fields)

# CRM-6: digest email (optional)
CRM_DIGEST_EMAIL=support@okelcor.com
```

### Artisan commands added

| Command | Description |
|---|---|
| `customers:recalculate-data-quality --all` | Bulk CRM-5 quality scoring backfill |
| `customers:recalculate-data-quality --id=N` | Single customer re-score |
| `customers:recalculate-data-quality --unscored` | Score only unscored customers |
| `crm:follow-ups-digest` | Daily follow-up digest (scheduled 08:00) |
| `crm:follow-ups-digest --dry-run` | Preview without logging |

### New permission keys (AdminPermissions.php)

| Permission | Roles | Purpose |
|---|---|---|
| `quotes.view` | super_admin, admin, order_manager, sales_manager | Read-only quote access |
| `quotes.update` | super_admin, admin, order_manager | Mutate quotes/pipeline |
| `crm.view` | super_admin, admin, order_manager, sales_manager | Read follow-ups/comms/templates |
| `crm.update` | super_admin, admin, order_manager | Write comms, send emails, complete follow-ups |

### New DB tables (all CRM phases)

| Table | Added in | Purpose |
|---|---|---|
| `customer_communications` | CRM-6 | Communication log (calls, emails, notes, system events) |

### Customer model — all new columns since CRM-1

| Column | Phase | Purpose |
|---|---|---|
| `onboarding_status` | CRM-1 | Account lifecycle (pending_review → invited → active) |
| `customer_segment` | CRM-4 | Buyer type (dealer/fleet/exporter/etc.) |
| `access_level` | CRM-4 | What customer can do (inquiry_only/approved_buyer/etc.) |
| `market_region` | CRM-4 | Trade region |
| `approved_for_checkout` | CRM-4 | Gates Stripe checkout |
| `approved_for_quotes` | CRM-4 | Gates quote submission |
| `approved_for_wholesale_pricing` | CRM-4 | Reserved for wholesale tier |
| `approved_for_documents` | CRM-4 | Gates trade document access |
| `data_quality_score` | CRM-5 | 0-100 completeness score |
| `data_quality_flags` | CRM-5 | Array of flag strings |
| `normalized_email` | CRM-5 | Lowercase/trimmed email for dedup |
| `normalized_company_name` | CRM-5 | Normalized company for dedup |
| `duplicate_group_id` | CRM-5 | Groups related duplicate records |
| `possible_duplicate_of` | CRM-5 | FK to most likely duplicate |
| `data_review_status` | CRM-5 | clean/needs_review/duplicate_suspected/merged/ignored |

### quote_requests model — all new columns since CRM-2

| Column | Phase | Purpose |
|---|---|---|
| `review_status` | CRM-2 | Quality gate (new/needs_review/qualified/rejected/spam) |
| `quality_score` | CRM-2 | 0-100 quality score |
| `quality_flags` | CRM-2 | Array of quality flag strings |
| `reviewed_by` | CRM-2 | Admin who reviewed |
| `reviewed_at` | CRM-2 | When reviewed |
| `rejection_reason` | CRM-2 | Admin rejection note |
| `assigned_to` | CRM-3 | Sales owner (admin_user FK) |
| `assigned_at` | CRM-3 | When assigned |
| `follow_up_at` | CRM-3 | Scheduled follow-up date |
| `lead_priority` | CRM-3 | low/normal/high/urgent |
| `lead_source` | CRM-3 | website_quote/ebay/phone/email/referral |
| `lead_customer_type` | CRM-3 | Admin buyer classification |
| `qualification_status` | CRM-3 | Sales pipeline stage (9 values) |
| `qualification_reason` | CRM-3 | Reason for status |
| `internal_notes` | CRM-3 | Admin-only notes |
| `possible_customer_id` | CRM-5 | Guest quote email match to existing customer |
| `follow_up_completed_at` | CRM-6 | When last follow-up was completed |
| `follow_up_completed_by` | CRM-6 | Admin who completed it |

---

## Session 38 — CRM-6 Fix: send-follow-up-email 500 + blank template dropdown (complete)

### Root causes

**Bug 1 — HTTP 500 on POST /admin/quote-requests/{id}/send-follow-up-email**

`CrmFollowUpEmail` declared `public readonly string $subject` in its constructor. Laravel's `Mailable` base class already has `public $subject;` (non-readonly, untyped). PHP 8.1+ throws a **Fatal Error** when a child class tries to redeclare an inherited non-readonly property as readonly:
> *"Cannot redeclare non-readonly property Illuminate\Mail\Mailable::$subject as readonly"*

This error fires at class instantiation — before the `try/catch` in `sendFollowUpEmail()` — so it surfaced as an uncaught 500 instead of being handled.

**Fix:** Renamed `$subject` → `$emailSubject` in `CrmFollowUpEmail` constructor and `envelope()` method. Updated the call site in `AdminCrmEmailController`.

**Bug 2 — Blank template dropdown**

`templates()` returned the field as `name`. Frontend dropdown bound to `label`. Added `label` field to the response (identical value to `name`; both now returned for compatibility).

### Additional improvements in this fix

- Added `{company}`, `{admin_name}`, `{message}` placeholder substitution (was only doing `{name}` and `{ref}`)
- `{admin_name}` resolves from `$request->user()->first_name + last_name`, falls back to "The Okelcor Team"
- Invalid template key now returns structured `422 code:invalid_email_template` instead of generic Laravel validation error
- Empty recipient email returns `422 code:missing_recipient_email`
- Mail failure returns `502 code:email_send_failed` (was already there, now has `code` field)

### Files changed

| File | Change |
|---|---|
| `app/Mail/CrmFollowUpEmail.php` | Renamed `$subject` → `$emailSubject` |
| `app/Http/Controllers/Admin/AdminCrmEmailController.php` | `label` field in templates(); all 5 placeholders; structured errors; emailSubject in Mailable call |

### Final template response shape

```json
{
  "data": [
    {
      "key": "follow_up_quote",
      "label": "Follow Up on Quote",
      "name": "Follow Up on Quote",
      "subject": "Following up on your tyre inquiry — {ref}",
      "body": "Dear {name},\n\n..."
    }
  ]
}
```

### Placeholder reference

| Placeholder | Resolves to |
|---|---|
| `{name}` | `quote.full_name` or "valued customer" |
| `{company}` | `quote.company_name` or empty string |
| `{ref}` | `quote.ref_number` |
| `{admin_name}` | Logged-in admin's name or "The Okelcor Team" |
| `{message}` | Optional custom message body from request |

---

## Session 37 — CRM-6: Customer Communication & Follow-up Automation (complete)

### Migrations

**`2026_05_29_000007_create_customer_communications_table`** — new `customer_communications` table:

| Column | Type | Purpose |
|---|---|---|
| `customer_id` | FK nullable | Linked customer |
| `quote_request_id` | FK nullable | Linked quote/lead |
| `order_id` | FK nullable | Linked order |
| `admin_user_id` | FK nullable | Admin who created the entry |
| `type` | ENUM(email/call/whatsapp/note/system) | Communication channel |
| `direction` | ENUM(inbound/outbound/internal) | Flow direction |
| `subject` | VARCHAR(300) nullable | Email subject / call subject |
| `body` | TEXT nullable | Full content |
| `status` | ENUM(planned/sent/failed/completed/skipped) | Outcome |
| `scheduled_at` | TIMESTAMP nullable | For future planned comms |
| `completed_at` | TIMESTAMP nullable | When completed/sent |
| `metadata` | JSON nullable | Template key, error message, previous dates |

**`2026_05_29_000008_add_follow_up_completed_at_to_quote_requests_table`** — adds:
- `follow_up_completed_at` — when admin last completed a follow-up (follow_up_at → null + this set)
- `follow_up_completed_by` — FK admin_users, who completed it

### Follow-up status (computed dynamically)

No extra DB column needed. Computed from `follow_up_at` + `qualification_status`:

| Condition | follow_up_status |
|---|---|
| `qualification_status` in closed set | `none` |
| `follow_up_at IS NULL` + `follow_up_completed_at IS NULL` | `none` |
| `follow_up_at IS NULL` + `follow_up_completed_at IS NOT NULL` | `completed` |
| `follow_up_at.isToday()` | `due` |
| `follow_up_at < now()` | `overdue` |
| `follow_up_at > now()` | `scheduled` |

### Admin endpoints

**Follow-up management (`crm.view` / `crm.update`):**

| Endpoint | Description |
|---|---|
| `GET /admin/crm/follow-ups` | Follow-up list with `follow_up_status` computed. Filters: `due=today\|overdue\|upcoming`, `assigned_to`, `qualification_status`, `customer_id` |
| `POST /admin/crm/follow-ups/{id}/complete` | Clears `follow_up_at`, sets `follow_up_completed_at`, logs communication note |
| `POST /admin/crm/follow-ups/{id}/reschedule` | Updates `follow_up_at`, logs communication note |

**Communication log (`crm.view` / `crm.update`):**

| Endpoint | Description |
|---|---|
| `GET /admin/customers/{id}/communications` | All comms for a customer |
| `POST /admin/customers/{id}/communications` | Log new comm (call/note/email/whatsapp) |
| `GET /admin/quote-requests/{id}/communications` | All comms for a quote/lead |
| `POST /admin/quote-requests/{id}/communications` | Log new comm for a quote |

**Email templates (`crm.view` / `crm.update`):**

| Endpoint | Description |
|---|---|
| `GET /admin/crm/email-templates` | List 6 templates (key, name, subject, body) |
| `POST /admin/quote-requests/{id}/send-follow-up-email` | Send template email to lead. Body: `{ template, message?, custom_subject? }` |

### Email templates (static, in-code)

| Key | Name |
|---|---|
| `follow_up_quote` | Follow Up on Quote |
| `request_more_information` | Request More Information |
| `invite_to_register` | Invite to Register |
| `quote_ready` | Quote Ready |
| `payment_reminder` | Payment Reminder |
| `document_available` | Document Available |

Templates have `{ref}` and `{name}` placeholders replaced at send time. Admin can add a custom `message` that appends after the template body. All sends logged to `customer_communications` regardless of success/failure (`status=sent` or `failed`).

### `crm:follow-ups-digest` Artisan command

```bash
php artisan crm:follow-ups-digest             # run digest + log
php artisan crm:follow-ups-digest --dry-run   # print summary only
```

- Counts overdue + due-today follow-ups, groups by `assigned_to`
- Logs structured `[crm_followup_digest]` entry
- Creates `system` type `CustomerCommunication` row for audit trail
- If `CRM_DIGEST_EMAIL` env is set, emails digest to that address (not customer emails)
- Scheduled: daily at 08:00 via `routes/console.php`

Add to `.env` if desired:
```
CRM_DIGEST_EMAIL=support@okelcor.com
```

### Permissions

| Permission | Roles | Access |
|---|---|---|
| `crm.view` | super_admin, admin, order_manager, sales_manager | Read follow-ups + communications + templates |
| `crm.update` | super_admin, admin, order_manager | Write comms, complete/reschedule, send emails |

### System health — CRM group

New `crm` group in `GET /admin/system/health`:
- `crm_overdue_followups`: warning if any overdue follow-ups exist
- `crm_due_today`: warning if any due today
- `crm_unassigned_qualified`: warning if qualified leads have no owner
- `crm_failed_emails`: warning if any `status=failed` emails in last 7 days

### Audit log events

All use structured `Log::info('[event_name]', ...)`:
- `follow_up_completed` — `POST /crm/follow-ups/{id}/complete`
- `follow_up_rescheduled` — `POST /crm/follow-ups/{id}/reschedule`
- `communication_logged` — `POST /customers/{id}/communications` or `/quote-requests/{id}/communications`
- `crm_email_sent` — email delivered successfully
- `crm_email_failed` — email failed (still logged in CustomerCommunication)

### Files changed

| File | Change |
|---|---|
| `database/migrations/2026_05_29_000007_*` | New — customer_communications table |
| `database/migrations/2026_05_29_000008_*` | New — follow_up_completed_at/by on quote_requests |
| `app/Models/CustomerCommunication.php` | New model |
| `app/Models/Customer.php` | communications() HasMany relation |
| `app/Models/QuoteRequest.php` | follow_up_completed_at/by fillable/casts + communications() relation |
| `app/Http/Controllers/Admin/AdminCrmFollowUpController.php` | New — follow-ups list/complete/reschedule |
| `app/Http/Controllers/Admin/AdminCommunicationController.php` | New — per-customer + per-quote communication log |
| `app/Http/Controllers/Admin/AdminCrmEmailController.php` | New — templates + send follow-up email |
| `app/Mail/CrmFollowUpEmail.php` | New mailable |
| `resources/views/emails/crm-follow-up.blade.php` | New HTML template |
| `resources/views/emails/crm-follow-up-text.blade.php` | New plain-text template |
| `app/Console/Commands/CrmFollowUpsDigest.php` | New — daily digest command |
| `routes/console.php` | Added daily digest schedule |
| `app/Support/AdminPermissions.php` | Added crm.view + crm.update |
| `app/Http/Controllers/Admin/SystemHealthController.php` | crm group: 4 new checks |
| `config/mail.php` | Added crm_digest_email key |
| `routes/api.php` | 10 new CRM routes |

### Deploy steps

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

**2 migrations:** communications table (new) + follow_up_completed_at/by on quote_requests (additive).

Optional — add to `.env`:
```
CRM_DIGEST_EMAIL=support@okelcor.com
```

### Test checklist

```
# Assign follow-up for yesterday → overdue
PATCH /admin/quote-requests/{id}/qualification { follow_up_at: "yesterday" }
GET /admin/crm/follow-ups?due=overdue → appears with follow_up_status=overdue

# Complete follow-up
POST /admin/crm/follow-ups/{id}/complete { note: "Called customer, confirmed quantity" }
→ follow_up_at=null, follow_up_completed_at=now, communication log entry created

# Reschedule
POST /admin/crm/follow-ups/{id}/reschedule { follow_up_at: "next week", note: "Follow up next week" }
→ follow_up_at updated, communication note logged

# Add internal note
POST /admin/customers/{id}/communications { type: "note", direction: "internal", body: "VIP customer" }
GET /admin/customers/{id}/communications → note visible, not exposed to customer portal

# Send follow-up email
POST /admin/quote-requests/{id}/send-follow-up-email { template: "request_more_information" }
→ email sent to quote.email
GET /admin/quote-requests/{id}/communications → communication entry status=sent

# Email failure logged
(break SMTP config)
POST /admin/quote-requests/{id}/send-follow-up-email → 502, but CustomerCommunication status=failed

# Daily digest (no customer emails)
php artisan crm:follow-ups-digest --dry-run → prints overdue/due-today counts
php artisan crm:follow-ups-digest → logs entry, system communication row created
```

---

## Session 36 — CRM-5: Customer Data Quality & Deduplication (complete)

### Migrations

**`2026_05_29_000005_add_data_quality_fields_to_customers_table`**

| Column | Type | Default | Purpose |
|---|---|---|---|
| `data_quality_score` | SMALLINT UNSIGNED nullable | null | 0–100 completeness score |
| `data_quality_flags` | JSON nullable | null | Array of flag strings |
| `normalized_email` | VARCHAR(255) nullable | null | `lower(trim(email))` |
| `normalized_company_name` | VARCHAR(255) nullable | null | Normalized for duplicate detection |
| `duplicate_group_id` | VARCHAR(36) nullable | null | Groups related duplicate records |
| `possible_duplicate_of` | FK → customers nullable | null | Most likely duplicate candidate |
| `data_review_status` | ENUM DEFAULT `needs_review` | needs_review | Admin-facing quality state |

`data_review_status` values: `clean`, `needs_review`, `duplicate_suspected`, `merged`, `ignored`

Backfill in migration SQL: `normalized_email = LOWER(TRIM(email))` for all rows. Company normalization + full scoring done by Artisan command post-deploy.

**`2026_05_29_000006_add_possible_customer_to_quote_requests_table`**

Adds `possible_customer_id` (FK → customers, nullable) to `quote_requests`. Set when a guest quote submission email matches an existing customer.

### CustomerDataQualityService (`app/Services/CustomerDataQualityService.php`)

**Scoring (0–100):**

| Signal | Points |
|---|---|
| Email present (structural) | +20 |
| Phone present | +15 |
| Company name present + non-trivial | +20 |
| Country present | +15 |
| VAT number present | +15 |
| Has saved delivery address | +10 |
| Segment/access configured | +5 |
| Personal email for B2B | −5 |

**Completeness flags:**
`missing_phone`, `missing_country`, `missing_company` (b2b only), `missing_address`, `weak_company_name` (< 3 chars), `personal_email_for_b2b`, `incomplete_profile` (score < 50)

**Duplicate flags (auto-detected):**

| Flag | Method | Confidence |
|---|---|---|
| `duplicate_email` | `LOWER(TRIM(email))` match | High |
| `duplicate_phone` | Stripped phone digits match | High |
| `duplicate_company_country` | `normalized_company_name` + `country` match | Medium |

**Company normalization:**
1. Lowercase + trim
2. Strip punctuation → spaces
3. Collapse whitespace
4. Remove trailing legal suffixes: `ltd`, `limited`, `gmbh`, `llc`, `inc`, `bv`, `sarl`, `co`, `company`, `corp`, `ug`, `ag`, `kg`, and more

**`data_review_status` determination:**
- Any duplicate found → `duplicate_suspected`
- Score < 50 → `needs_review`
- Otherwise → `clean`
- Admin overrides (`ignored`, `merged`) are never overwritten by recalculation

### Admin endpoints

All under `permission:customers.manage`:

| Endpoint | Description |
|---|---|
| `GET /admin/customers/data-quality/summary` | Counts: total/clean/needs_review/duplicate_suspected/incomplete/personal_email/unscored |
| `GET /admin/customers/data-quality/issues` | Paginated issue list. Filters: `review_status`, `flag`, `min_score`, `max_score`, `duplicate_only` |
| `POST /admin/customers/{id}/data-quality/recalculate` | Re-run scoring + duplicate detection for one customer |
| `POST /admin/customers/{id}/data-quality/mark-clean` | Admin clears flags, sets status=clean |
| `POST /admin/customers/{id}/data-quality/ignore-duplicate` | Admin dismisses duplicate flag (status=ignored) |
| `POST /admin/customers/{id}/data-quality/link-duplicate` | Body: `{ possible_duplicate_of: id }` — admin-confirmed duplicate link + shared `duplicate_group_id` |
| `POST /admin/customers/{id}/data-quality/merge-preview` | Body: `{ merge_into: id }` — read-only field diff, no changes written |

No destructive merge endpoint. `merge-preview` shows field conflicts + record counts (quotes/orders/addresses) to prepare for manual admin DB action.

### Quote possible customer detection (CRM-5)

`QuoteRequestController::store()` — after saving a guest quote, checks if `email` matches an existing customer:
```php
$existing = Customer::whereRaw('LOWER(TRIM(email)) = ?', [lower(trim($quote->email))])->first();
if ($existing) $quote->update(['possible_customer_id' => $existing->id]);
```

`AdminQuoteRequestController::formatDetail()` now includes `possible_customer_id`.

Frontend shows: "This inquiry may belong to an existing customer." + View Customer button.

### System health — data quality group

New `data_quality` group in `GET /admin/system/health`:
- `duplicate_customers`: warning if any `data_review_status=duplicate_suspected`
- `unscored_customers`: warning if any customers have no score yet (run backfill command)

### Artisan command

```bash
# Full backfill (run once after deploy)
php artisan customers:recalculate-data-quality --all

# Single customer
php artisan customers:recalculate-data-quality --id=42

# Only unscored customers
php artisan customers:recalculate-data-quality --unscored

# Preview without saving
php artisan customers:recalculate-data-quality --id=42 --dry-run
```

### Files changed

| File | Change |
|---|---|
| `database/migrations/2026_05_29_000005_*` | New — quality fields on customers |
| `database/migrations/2026_05_29_000006_*` | New — possible_customer_id on quote_requests |
| `app/Services/CustomerDataQualityService.php` | New — scoring engine |
| `app/Http/Controllers/Admin/AdminCustomerDataQualityController.php` | New — all data quality endpoints |
| `app/Console/Commands/RecalculateCustomerDataQuality.php` | New — bulk backfill command |
| `app/Models/Customer.php` | Quality fields fillable/casts + possibleDuplicateOf() relation |
| `app/Models/QuoteRequest.php` | possible_customer_id in fillable |
| `app/Http/Controllers/QuoteRequestController.php` | possible_customer_id detection on guest submission |
| `app/Http/Controllers/Admin/AdminQuoteRequestController.php` | possible_customer_id in formatDetail |
| `app/Http/Controllers/Admin/SystemHealthController.php` | data_quality group (duplicate + unscored checks) |
| `routes/api.php` | 7 new data quality routes |

### Deploy steps

```bash
cd /home/u978121777/domains/okelcor.com/public_html/okelcor-api
git fetch origin && git reset --hard origin/main
composer install --no-dev
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan config:clear
/opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache

# Run scoring backfill (do this after deploy — chunked, safe on large datasets)
/opt/alt/php83/usr/bin/php artisan customers:recalculate-data-quality --all
```

**2 migrations run:** customers quality fields + quote_requests possible_customer_id. Both additive, no data loss.

### Test checklist

```
# Same email (different casing) → duplicate_email flag
Create customer john@company.com, then John@Company.com (via import)
POST /admin/customers/{id}/data-quality/recalculate → flags=["duplicate_email"], status=duplicate_suspected

# Same company + country → duplicate_company_country
"Okelcor GmbH" Germany + "Okelcor" Germany → duplicate_company_country (after normalization both → "okelcor")

# Missing phone/country
Customer with no phone/country → flags=["missing_phone","missing_country","incomplete_profile"], score<50

# Guest quote matches existing customer
POST /api/v1/quote-requests with email=existing@customer.com (no auth)
→ quote.possible_customer_id = existing customer ID
GET /admin/quote-requests/{id} → possible_customer_id populated

# Admin marks ignored
POST /admin/customers/{id}/data-quality/ignore-duplicate → status=ignored
POST /admin/customers/{id}/data-quality/recalculate → status stays ignored (not overwritten)

# Merge preview — no changes
POST /admin/customers/{id}/data-quality/merge-preview { merge_into: other_id } → diff shown, no records changed
```

---

## Session 35 — CRM-4: Customer Segmentation & Access Control (complete)

### Migration — `customers` table

`database/migrations/2026_05_29_000004_add_segmentation_fields_to_customers_table.php`

| Column | Type | Default | Purpose |
|---|---|---|---|
| `customer_segment` | ENUM | `unknown` | Buyer classification (admin-set) |
| `access_level` | ENUM | `inquiry_only` | What the customer is allowed to do |
| `market_region` | ENUM | `unknown` | Primary trade region |
| `approved_for_checkout` | BOOLEAN | `false` | Can initiate Stripe checkout |
| `approved_for_quotes` | BOOLEAN | `true` | Can submit/view quote requests |
| `approved_for_wholesale_pricing` | BOOLEAN | `false` | Reserved for wholesale tier |
| `approved_for_documents` | BOOLEAN | `false` | Can list/download trade documents |

**`customer_segment` values:** `private_buyer`, `dealer`, `workshop`, `fleet`, `exporter`, `distributor`, `partner`, `unknown`

**`access_level` values:** `inquiry_only`, `quote_only`, `approved_buyer`, `wholesale_buyer`, `restricted`, `blocked`

**`market_region` values:** `eu`, `africa`, `middle_east`, `global`, `unknown`

**Backfill (non-destructive):** existing `onboarding_status=active` + `is_active=true` customers → `access_level=approved_buyer`, `approved_for_checkout=true`, `approved_for_quotes=true`, `approved_for_documents=true`. All others keep defaults (inquiry_only, no checkout/docs).

### Access rules

| Endpoint | Guard | Error on fail |
|---|---|---|
| Any auth.customer route | `access_level != blocked` | 403 `code:access_blocked` |
| `POST /auth/orders/{ref}/checkout` | `approved_for_checkout=true` | 403 `code:checkout_not_approved` |
| `GET /auth/orders/{ref}/trade-documents` | `approved_for_documents=true` | 403 `code:documents_not_approved` |
| `GET /auth/trade-documents/{id}/download` | `approved_for_documents=true` | 403 `code:documents_not_approved` |

`restricted` access_level: customer can log in and view their account, but `approved_for_checkout` and `approved_for_documents` will be false — these individual flags gate the actions. No middleware-level block for restricted.

### Customer auth/me response (new fields)

`GET /api/v1/auth/me` and login response now include:
```json
{
  "customer_segment": "unknown",
  "access_level": "inquiry_only",
  "market_region": "unknown",
  "approved_for_checkout": false,
  "approved_for_quotes": true,
  "approved_for_wholesale_pricing": false,
  "approved_for_documents": false
}
```
Frontend uses these to conditionally show/hide checkout button, documents section, etc.

### Admin access control endpoint

`PATCH /api/v1/admin/customers/{id}/access` — permission: `customers.manage`

```json
{
  "customer_segment": "dealer",
  "access_level": "approved_buyer",
  "market_region": "africa",
  "approved_for_checkout": true,
  "approved_for_quotes": true,
  "approved_for_wholesale_pricing": false,
  "approved_for_documents": true
}
```
All fields optional — only updates what's sent. Setting `access_level=blocked` automatically revokes all customer tokens. Logs via SecurityEventService.

### Convert-to-customer segment mapping (CRM-3 integration)

`POST /admin/quote-requests/{id}/convert-to-customer` now maps `lead_customer_type` → `customer_segment`:

| lead_customer_type | customer_segment |
|---|---|
| private_buyer | private_buyer |
| dealer | dealer |
| workshop | workshop |
| fleet | fleet |
| exporter | exporter |
| unknown | unknown |

New customers from conversion default to `access_level=inquiry_only`, `approved_for_quotes=true`. Admin must explicitly upgrade via `PATCH /customers/{id}/access`.

### Admin customer list/detail

`formatSummary()` now includes all 7 new fields. Frontend can show segment + access badges on the customer list and a dedicated "Access Control" card on detail.

### Files changed

| File | Change |
|---|---|
| `database/migrations/2026_05_29_000004_add_segmentation_fields_to_customers_table.php` | New |
| `app/Models/Customer.php` | 7 new fillable fields + 4 boolean casts |
| `app/Http/Middleware/CustomerAuth.php` | Block `access_level=blocked` at middleware level |
| `app/Http/Controllers/CustomerAuthController.php` | `formatCustomer()` exposes all 7 new fields |
| `app/Http/Controllers/CustomerOrderController.php` | Checkout guard: `approved_for_checkout` check |
| `app/Http/Controllers/TradeDocumentController.php` | Document list + download guard: `approved_for_documents` check |
| `app/Http/Controllers/Admin/AdminCustomerController.php` | `updateAccess()` method; `formatSummary()` includes new fields |
| `app/Http/Controllers/Admin/AdminQuoteRequestController.php` | `convertToCustomer()` maps `lead_customer_type` → `customer_segment` |
| `routes/api.php` | Added `PATCH customers/{id}/access` |

### Deploy steps

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

**Migration:** `2026_05_29_000004` — additive ALTER TABLE + backfill UPDATE. Existing active customers unaffected.

### Test checklist

```
# Existing active customer can still checkout:
GET /api/v1/auth/me → approved_for_checkout=true, approved_for_documents=true

# New pending_review customer cannot checkout:
POST /api/v1/auth/orders/{ref}/checkout → 403, code=checkout_not_approved

# Admin upgrades access:
PATCH /api/v1/admin/customers/{id}/access { access_level: "approved_buyer", approved_for_checkout: true, approved_for_documents: true }
→ 200, approved_for_checkout=true

# Customer can now checkout:
POST /api/v1/auth/orders/{ref}/checkout → proceeds to Stripe

# Blocked customer cannot use any authenticated endpoint:
PATCH /admin/customers/{id}/access { access_level: "blocked" } → tokens revoked
GET /api/v1/auth/me → 403, code=access_blocked

# Convert-to-customer maps segment:
Quote with lead_customer_type=dealer
POST /admin/quote-requests/{id}/convert-to-customer
→ Customer created with customer_segment=dealer, access_level=inquiry_only
```

---

## Session 34 — CRM-3: Lead Qualification & Sales Ownership Pipeline (complete)

### What was built

#### 1. Migration — lead pipeline fields on `quote_requests`

`database/migrations/2026_05_29_000003_add_lead_pipeline_fields_to_quote_requests_table.php`

| Column | Type | Purpose |
|---|---|---|
| `assigned_to` | FK → admin_users nullable | Sales owner |
| `assigned_at` | TIMESTAMP nullable | When assigned |
| `follow_up_at` | TIMESTAMP nullable | Scheduled follow-up date |
| `lead_priority` | ENUM(low/normal/high/urgent) DEFAULT normal | Priority flag |
| `lead_source` | VARCHAR(60) nullable | Origin: website_quote/ebay/phone/email/referral/contact_form |
| `lead_customer_type` | ENUM(private_buyer/dealer/workshop/fleet/exporter/unknown) DEFAULT unknown | Admin-set buyer classification |
| `qualification_status` | ENUM(9 values) DEFAULT new | Sales pipeline stage |
| `qualification_reason` | TEXT nullable | Admin note on qualification decision |
| `internal_notes` | TEXT nullable | Internal team notes (not sent to customer) |

`qualification_status` values: `new`, `needs_review`, `qualified`, `proposal_sent`, `customer_invited`, `converted`, `rejected`, `spam`, `closed`

**Backfill:** All existing rows get `lead_source=website_quote` + `qualification_status` derived from `review_status` (qualified→qualified, spam→spam, rejected→rejected, needs_review→needs_review, others→new).

`lead_customer_type` is separate from `customer_type` (b2b/b2c) — it's the sales classification (dealer vs fleet vs exporter etc.), admin-set during qualification.

#### 2. New admin endpoints

All under `/api/v1/admin/...`:

| Endpoint | Permission | Description |
|---|---|---|
| `GET /quote-requests/summary` | quotes.manage | Pipeline counts dashboard |
| `POST /quote-requests/{id}/assign` | quotes.update | Assign owner + follow-up date |
| `POST /quote-requests/{id}/qualification` | quotes.update | Update pipeline stage, priority, type, notes |
| `POST /quote-requests/{id}/notes` | quotes.update | Update internal_notes only |
| `POST /quote-requests/{id}/convert-to-customer` | customers.manage | Create/link customer from lead |

#### 3. Summary endpoint

`GET /api/v1/admin/quote-requests/summary` returns:
```json
{
  "data": {
    "new_count": 5,
    "needs_review_count": 3,
    "qualified_count": 8,
    "proposal_sent_count": 2,
    "converted_count": 12,
    "spam_count": 41,
    "follow_up_due_count": 4,
    "unassigned_count": 6,
    "high_priority_count": 3
  }
}
```

#### 4. Assign endpoint

`POST /admin/quote-requests/{id}/assign`
```json
{ "assigned_to": 3, "follow_up_at": "2026-06-01T10:00:00Z" }
```
Sets `assigned_to`, `assigned_at=now()`, `follow_up_at`. Logs `lead_assigned`.

#### 5. Qualification endpoint

`POST /admin/quote-requests/{id}/qualification`
```json
{
  "qualification_status": "qualified",
  "lead_priority": "high",
  "lead_customer_type": "dealer",
  "follow_up_at": "2026-06-03T09:00:00Z",
  "qualification_reason": "Verified dealer in Lagos, regular orders expected",
  "internal_notes": "Call +234... to confirm container size"
}
```
All fields are optional — only updates what's provided. Logs `lead_qualification_updated`.

#### 6. Convert-to-customer endpoint

`POST /admin/quote-requests/{id}/convert-to-customer`

**If customer email already exists:**
- Links `quote.customer_id = existing.id`
- Sets `qualification_status=converted`
- Returns `action=linked`

**If customer email is new:**
- Creates `Customer` from quote data (name split, email, phone, country, company, VAT)
- Sets `onboarding_status=pending_review`, `is_active=false` (CRM-1 rules — admin must invite)
- Links quote, sets `qualification_status=converted`
- Returns `action=created`

Both paths log `lead_converted_to_customer` via SecurityEventService. After conversion, use `POST /admin/customers/{id}/invite` to send activation email.

#### 7. Permission changes

`app/Support/AdminPermissions.php` — two new permissions added:

| Permission | Roles |
|---|---|
| `quotes.view` | super_admin, admin, order_manager, sales_manager |
| `quotes.update` | super_admin, admin, order_manager |

Route split:
- `quotes.manage` → GET routes (summary, index, show, attachment download) — sales_manager can read
- `quotes.update` → all write routes (assign, qualification, notes, qualify, reject, spam, convert-to-order, update)
- `customers.manage` → convert-to-customer

#### 8. CRM-2 qualify/reject/spam sync

The existing CRM-2 `qualify`, `rejectInquiry`, `markSpam` methods now ALSO update `qualification_status` in sync with `review_status`. No breaking change — both columns track the same value after admin review.

#### 9. Index filters (all new)

`GET /admin/quote-requests` now accepts:
```
?qualification_status=qualified
?assigned_to=3
?unassigned=true
?lead_priority=high
?lead_customer_type=dealer
?lead_source=website_quote
?follow_up_due=true
```

#### 10. formatList/formatDetail updates

Both formatters now include all pipeline fields:
`qualification_status`, `lead_priority`, `lead_source`, `lead_customer_type`, `assigned_to`, `assigned_at`, `follow_up_at`, `follow_up_overdue` (computed bool), `qualification_reason`, `internal_notes`

`follow_up_overdue=true` when `follow_up_at` is in the past AND status is not converted/closed/spam/rejected.

#### 11. Public submission

`QuoteRequestController::store()` now sets:
- `lead_source=website_quote` on every public submission
- `qualification_status` mirrors `review_status` at creation

### Pipeline status flow

```
New inquiry submitted
  → qualification_status = new (or needs_review/spam from quality scoring)

Admin reviews
  → assign to sales owner
  → update qualification + priority + customer_type
  → set follow_up_at

Pipeline stages:
  new → needs_review → qualified → proposal_sent → customer_invited → converted
                                                                    ↘ rejected
                                                                    ↘ closed
  (any stage) → spam (admin override)

Converted:
  → POST /convert-to-customer → Customer created (pending_review) or linked
  → POST /admin/customers/{id}/invite → Activation email sent
  → Customer activates → onboarding_status=active → can log in
```

### Audit log events

| Log event | Trigger |
|---|---|
| `lead_assigned` | `POST /assign` |
| `lead_qualification_updated` | `POST /qualification` |
| `lead_note_updated` | `POST /notes` |
| `lead_converted_to_customer` | `POST /convert-to-customer` |

### Files changed

| File | Change |
|---|---|
| `database/migrations/2026_05_29_000003_add_lead_pipeline_fields_to_quote_requests_table.php` | New |
| `app/Models/QuoteRequest.php` | Pipeline fields fillable/casts + assignedTo() relation |
| `app/Support/AdminPermissions.php` | Added quotes.view + quotes.update |
| `app/Http/Controllers/Admin/AdminQuoteRequestController.php` | summary/assign/updateQualification/updateNotes/convertToCustomer methods; updated filters+formatters; CRM-2 methods sync qualification_status |
| `app/Http/Controllers/QuoteRequestController.php` | Sets lead_source=website_quote + qualification_status on store |
| `routes/api.php` | Restructured quotes routes: quotes.manage (read) + quotes.update (write) + customers.manage (convert) |

### Deploy steps

```bash
cd /home/u978121777/domains/okelcor.com/public_html/okelcor-api
git fetch origin && git reset --hard origin/main
composer install --no-dev
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan config:clear
/opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
```

**Migration that will run:** `2026_05_29_000003_add_lead_pipeline_fields_to_quote_requests_table`
— additive ALTER TABLE + backfill UPDATE. No data loss.

### Test checklist

```
# Submit "Need 200 Michelin 205/55R16 to Ghana"
POST /api/v1/quote-requests → 201, review_status=qualified, lead_source=website_quote

# Admin views summary
GET /api/v1/admin/quote-requests/summary → counts including new/qualified/etc.

# Admin assigns lead to self
POST /api/v1/admin/quote-requests/{id}/assign { assigned_to: 1, follow_up_at: "2026-06-01..." } → 200

# Admin updates qualification
POST /api/v1/admin/quote-requests/{id}/qualification { qualification_status: "qualified", lead_priority: "high", lead_customer_type: "dealer" } → 200

# Admin adds notes
POST /api/v1/admin/quote-requests/{id}/notes { internal_notes: "Spoke to buyer, genuine dealer" } → 200

# Admin converts to customer
POST /api/v1/admin/quote-requests/{id}/convert-to-customer → 201, action=created, onboarding_status=pending_review

# Admin invites customer
POST /api/v1/admin/customers/{customer_id}/invite → 200, invitation email sent

# Spam inquiry — still visible, not emailed
GET /api/v1/admin/quote-requests?qualification_status=spam → spam leads listed, no email in logs
```

---

## Session 33 — CRM-2: Inquiry Quality Filtering (complete)

### Audit findings

| Area | Finding |
|---|---|
| Quote storage | `quote_requests` table — fully stored in DB already |
| Quote controller | `QuoteRequestController::store()` — public, no quality gate |
| Admin quote list | Filtered by `status` only (sales workflow: new/reviewed/quoted/closed) |
| Quote email | `QuoteRequestReceived` mailable → `QUOTE_EMAIL` env; no quality differentiation |
| Contact form | `ContactController` — only logs to file, no email at all |
| No spam gate | Any text, including "test", "asdf", "111111111" accepted and emailed |

### What was built

#### 1. Migration — quality fields on `quote_requests`

`database/migrations/2026_05_29_000002_add_quality_fields_to_quote_requests_table.php`

| Column | Type | Purpose |
|---|---|---|
| `quality_score` | TINYINT UNSIGNED nullable | 0–100 score from InquiryQualityService |
| `quality_flags` | JSON nullable | Array of string flags explaining score |
| `review_status` | ENUM DEFAULT `new` | Quality gate status |
| `reviewed_by` | FK → admin_users nullable | Admin who reviewed |
| `reviewed_at` | TIMESTAMP nullable | When reviewed |
| `rejection_reason` | TEXT nullable | Admin rejection note |

`review_status` values: `new`, `needs_review`, `qualified`, `rejected`, `spam`

Note: `review_status` is separate from the existing `status` column (sales workflow: new/reviewed/quoted/closed). Both coexist.

#### 2. InquiryQualityService (`app/Services/InquiryQualityService.php`)

Two-layer approach — hard spam check first, then signal scoring.

**Hard spam flags (any flag → `review_status=spam`):**

| Flag | Condition |
|---|---|
| `message_too_short` | notes < 8 chars |
| `repeated_chars` | single char > 70% of non-whitespace content |
| `keyboard_smash` | notes contains known sequence (asdf/qwerty/zxcv) AND length < 25 or > 55% similarity |
| `only_numbers_symbols` | notes contains only digits/punctuation |
| `url_spam` | 2+ URLs in notes |
| `disposable_email` | email domain in known disposable list (28 domains) |

**Positive signals (add to score):**

| Signal | Points |
|---|---|
| Tyre size pattern (205/55R16 etc.) in notes or tyre_size | +20 |
| Tyre keyword (tire/tyre/TBR/PCR/brand name) without specific size | +10 |
| Quantity detected in notes or quantity field | +15 |
| Country field present | +15 |
| Destination detected in notes (country/city from list) | +12 |
| Buying intent keyword (need/quote/order/looking for/etc.) | +10 |
| Brand name in notes or brand_preference field | +10 |
| company_name present | +10 |
| phone present | +10 |
| Notes > 60 chars | +5 |
| Business email domain | +5 |
| VAT number present | +5 |

**Negative signals:**

| Signal | Points |
|---|---|
| No tyre details at all | -15 |
| No destination/country anywhere | -10 |
| Free email domain (gmail/yahoo/etc.) | -5 |

**Score bands:**

| Score | review_status | Admin email |
|---|---|---|
| 60–100 | `qualified` | Normal email: "New quote request — ref" |
| 0–59 | `needs_review` | Tagged email: "[Needs Review] New quote request — ref" |
| Hard spam flags | `spam` | No email — spam stored for audit only |

#### 3. Public submission behavior change

`QuoteRequestController::store()`:
- Runs `InquiryQualityService::score()` before saving
- **Spam path:** saves to DB (review_status=spam, for audit) → returns HTTP 422:
  ```json
  {
    "message": "Please provide a clear business inquiry including tire size, quantity, destination country, and your contact details.",
    "code": "low_quality_inquiry",
    "flags": ["message_too_short"]
  }
  ```
- **needs_review path:** saves → returns 201 "Your inquiry has been received and will be reviewed by our team." → sends [Needs Review] admin email
- **qualified path:** saves → returns 201 "Quote request received. Our team will respond within 1 business day." → sends normal admin email
- 201 response now includes `review_status` field

#### 4. QuoteRequestReceived mailable update

`app/Mail/QuoteRequestReceived.php`:
- New `$isNeedsReview` constructor param (default false)
- Subject changes to `[Needs Review] New quote request — ref` when `$isNeedsReview=true`
- Template receives `$isNeedsReview` variable for optional banner

#### 5. Admin review actions

New endpoints under `permission:quotes.manage`:

| Endpoint | Controller method | Description |
|---|---|---|
| `POST /admin/quote-requests/{id}/qualify` | `qualify()` | Sets review_status=qualified, logs reviewed_by/at |
| `POST /admin/quote-requests/{id}/reject` | `rejectInquiry()` | Sets review_status=rejected, accepts optional `reason` |
| `POST /admin/quote-requests/{id}/spam` | `markSpam()` | Sets review_status=spam |

All three log the action with `[quote_review_*]` prefix for searchable log entries.

#### 6. Admin quote list improvements

`GET /admin/quote-requests` now supports `?review_status=new|needs_review|qualified|rejected|spam` filter.

Both `formatList` and `formatDetail` now include:
- `review_status`
- `quality_score`
- `quality_flags` (array)
- `reviewed_by`, `reviewed_at`, `rejection_reason`

#### 7. System health — inquiry queue checks

`GET /admin/system/health` → new `inquiries` group:

| Check | Status | Condition |
|---|---|---|
| `pending_review_inquiries` | pass / warning / fail | 0 = pass, 1-9 = warning, 10+ = fail |
| `spam_inquiries` | pass / warning | >20 spam in last 7 days = warning |

### Test case verification

| Input | Expected | Hard flag | Score |
|---|---|---|---|
| "test" | spam | `message_too_short` | 0 |
| "asdfasdf" | spam | `keyboard_smash` | 0 |
| "111111111" | spam | `repeated_chars` | 0 |
| "hello" | spam | `message_too_short` | 0 |
| "........" | spam | `repeated_chars` | 0 |
| "buy now http://spam.com http://spam2.com" | spam | `url_spam` | 0 |
| "Need 200 Michelin 205/55R16 to Ghana" | qualified | — | ~65 |
| "We are a tire dealer in Lagos. Please quote 500 summer tires, 205/55R16." | qualified | — | ~65 |
| "Looking for TBR tires for fleet use in Uganda. Quantity 120." | needs_review | — | ~55 |

### Email notification summary

| review_status | Admin email | Subject |
|---|---|---|
| `qualified` | ✅ sent | `New quote request — OKL-QR-...` |
| `needs_review` | ✅ sent (tagged) | `[Needs Review] New quote request — OKL-QR-...` |
| `spam` | ❌ not sent | — |

### Files changed

| File | Change |
|---|---|
| `database/migrations/2026_05_29_000002_add_quality_fields_to_quote_requests_table.php` | New — quality fields |
| `app/Services/InquiryQualityService.php` | New — scoring engine |
| `app/Models/QuoteRequest.php` | Added quality fields to fillable + casts |
| `app/Http/Controllers/QuoteRequestController.php` | Integrated quality scoring; spam→422; review_status in response |
| `app/Mail/QuoteRequestReceived.php` | Added `$isNeedsReview` flag; subject prefix |
| `app/Http/Controllers/Admin/AdminQuoteRequestController.php` | qualify/rejectInquiry/markSpam methods; review_status filter; quality fields in formatters |
| `routes/api.php` | 3 new review action routes |
| `app/Http/Controllers/Admin/SystemHealthController.php` | inquiries group: pending_review + spam count checks |

### Deploy steps

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

**Migration that will run:** `2026_05_29_000002_add_quality_fields_to_quote_requests_table`
— additive ALTER TABLE on `quote_requests`. No data loss. Existing rows get `review_status=new` (DEFAULT).

### Admin workflow after deploy

```
GET /api/v1/admin/quote-requests?review_status=needs_review
→ Review each inquiry
→ POST /api/v1/admin/quote-requests/{id}/qualify  (good inquiry)
→ POST /api/v1/admin/quote-requests/{id}/reject   { reason: "..." }
→ POST /api/v1/admin/quote-requests/{id}/spam     (obvious spam)

GET /api/v1/admin/system/health → inquiries.pending_review_inquiries
→ shows how many need attention
```

---

## Session 32 — CRM-1: Controlled Customer Onboarding Foundation (complete)

### Audit findings (what existed before)

| Area | Finding | Risk |
|---|---|---|
| Registration | `POST /api/v1/auth/register` public, auto-creates `status=active` customer | Any person could self-register and access platform after email verify |
| Login guard | Only checked `status` (active/suspended/banned/locked) + `email_verified_at` | No B2B approval gate |
| CustomerAuth middleware | Only checked `is_active` — not `status` column | `status=suspended` with `is_active=true` could bypass |
| Admin customer creation | No `POST /admin/customers` endpoint | Admin could not manually onboard a customer |
| Admin invite | No invite flow — only `force-password-reset` | No controlled onboarding path |
| Guest orders | `POST /api/v1/orders` fully public, no auth | Any person can place order (intentional for B2B inquiry flow) |
| Quote email | Already had logging; root cause: `MAIL_MAILER=log` default OR `QUOTE_EMAIL` unset | Emails log to file but never deliver unless SMTP configured |

### What was built

#### 1. Migration — `onboarding_status` column

`database/migrations/2026_05_29_000001_add_onboarding_status_to_customers_table.php`

Adds `onboarding_status` ENUM to `customers` table:

| Value | Meaning |
|---|---|
| `pending_review` | Self-registered, awaiting admin decision |
| `approved` | Admin approved, invite not yet sent |
| `invited` | Invite email sent, customer hasn't activated yet |
| `active` | Fully active — can log in and access platform |
| `rejected` | Admin rejected application |
| `blocked` | Admin blocked — cannot log in |

- DEFAULT `active` → all existing customers remain active after migration (non-destructive)
- Backfill: `status=banned/suspended` → `onboarding_status=blocked`
- This column is **separate** from `status` (which handles security lockouts: active/suspended/banned/locked)

#### 2. Registration flow change

`CustomerAuthController::register()`:
- New customers now get `onboarding_status=pending_review`, `is_active=false`
- No verification email sent (no point verifying until approved)
- Response changed to: "Your request has been received and is under review."
- Logs `customer_pending_review_created` security event

#### 3. Login onboarding gate

`CustomerAuthController::login()` — after the security `status` check, new gate:

| onboarding_status | HTTP | Message |
|---|---|---|
| `pending_review` | 403 | "Your account request is under review..." |
| `approved` | 403 | "Your account has been approved. Please check your email for an invitation..." |
| `invited` | 403 | "You have a pending invitation. Please check your email to activate..." |
| `rejected` | 403 | "Your account application was not approved..." |
| `blocked` | 403 | "Your account access has been restricted. Contact support." |
| `active` | proceed | (existing flow continues) |

Response includes `onboarding_status` field so frontend can show appropriate UI.

#### 4. CustomerAuth middleware

`app/Http/Middleware/CustomerAuth.php` — now also blocks token access for `pending_review`, `rejected`, `blocked`.

#### 5. Invitation activation flow

`CustomerAuthController::resetPassword()` — when a customer with `onboarding_status=invited` completes the password set:
- `onboarding_status → active`
- `is_active → true`
- `email_verified_at → now()` (invite serves as email verification)
- Logs `customer_activated` event

Frontend should use `/activate?token=...&email=...` as the route for invitation links (same mechanism as password reset but different page UX).

#### 6. New admin endpoints

All under `POST /api/v1/admin/...`, permission: `customers.manage`.

| Endpoint | Method | Controller | Description |
|---|---|---|---|
| `POST /admin/customers` | store() | Creates customer + sends invite | Admin manually onboards a customer |
| `POST /admin/customers/{id}/approve` | approve() | Sets `onboarding_status=approved` | Mark pending application as approved |
| `POST /admin/customers/{id}/reject` | reject() | Sets `onboarding_status=rejected` | Decline application (reason optional) |
| `POST /admin/customers/{id}/invite` | invite() | Sets `invited` + sends invite email | Send activation email |
| `POST /admin/customers/{id}/resend-invite` | resendInvite() | Resends invite email | Re-send to `invited` or `approved` customers |
| `POST /admin/customers/{id}/block` | blockOnboarding() | Sets `onboarding_status=blocked` | Block access (different from security ban) |

**Admin create customer flow:**
```
POST /api/v1/admin/customers  { customer_type, first_name, last_name, email, ... }
→ Customer created with onboarding_status=invited
→ Invite email sent immediately
→ Customer clicks link → sets password → onboarding_status=active
```

**Approve pending registration flow:**
```
Admin sees onboarding_status=pending_review in customer list
POST /admin/customers/{id}/approve  → onboarding_status=approved
POST /admin/customers/{id}/invite   → sends invite email, onboarding_status=invited
Customer clicks link → sets password → onboarding_status=active
```

**Reject flow:**
```
POST /admin/customers/{id}/reject  { reason: "Not a registered business" }
→ onboarding_status=rejected, is_active=false, reason appended to admin_notes
→ All tokens revoked
```

#### 7. CustomerInvitation mailable + views

| File | Purpose |
|---|---|
| `app/Mail/CustomerInvitation.php` | Mailable — activation link |
| `resources/views/emails/customer-invitation.blade.php` | HTML email template |
| `resources/views/emails/customer-invitation-text.blade.php` | Plain-text fallback |

Invitation link format: `{FRONTEND_URL}/activate?token={token}&email={email}`
Token TTL: 48 hours (stored in `password_reset_tokens` table, same mechanism as password reset).

#### 8. Quote email fix + health checks

`QuoteRequestController::store()`:
- Now logs `[quote_email_sent]` / `[quote_email_failed]` / `[quote_email_misconfigured]` with structured context
- Logs `warning` when `QUOTE_EMAIL` not set (was silently skipping)

`SystemHealthController::checkMail()` — two new checks:
- `quote_email` — **fail** if `QUOTE_EMAIL` not set (high severity)
- `order_email` — **warning** if `ORDER_EMAIL` not set (medium severity)

#### 9. Admin customer list — new fields + filter

`GET /admin/customers` now accepts `?onboarding_status=pending_review|approved|invited|active|rejected|blocked`

`formatSummary()` now includes:
- `onboarding_status`
- `phone`
- `country`
- `vat_number`

### Audit log events used

| Event | When |
|---|---|
| `customer_pending_review_created` | New self-registration |
| `customer_approved` | Admin approves application |
| `customer_rejected` | Admin rejects application |
| `customer_invited` | Admin sends/resends invite |
| `customer_activated` | Customer sets password via invite link |
| `customer_blocked` | Admin blocks customer |

### Customer flow: before vs after

**Before (open registration):**
```
Customer registers → email verified → immediately active → can log in
```

**After (controlled onboarding):**
```
Customer registers → pending_review (cannot log in)
Admin reviews → approve → invite (email sent)
Customer clicks link → sets password → active → can log in

OR:

Admin creates customer directly → invite email sent immediately
Customer clicks link → sets password → active
```

**Existing customers:** Unaffected. `onboarding_status=active` (set by migration DEFAULT).

### Files changed

| File | Change |
|---|---|
| `database/migrations/2026_05_29_000001_add_onboarding_status_to_customers_table.php` | New — adds onboarding_status column |
| `app/Models/Customer.php` | Added onboarding_status to fillable + casts |
| `app/Http/Controllers/CustomerAuthController.php` | register: pending_review; login: onboarding gate; resetPassword: activate on invite; formatCustomer: exposes onboarding_status |
| `app/Http/Middleware/CustomerAuth.php` | Block pending_review/rejected/blocked at token level |
| `app/Http/Controllers/Admin/AdminCustomerController.php` | Added store/approve/reject/invite/resendInvite/blockOnboarding; updated formatSummary + index filter |
| `app/Mail/CustomerInvitation.php` | New — invitation mailable |
| `resources/views/emails/customer-invitation.blade.php` | New — HTML invite email |
| `resources/views/emails/customer-invitation-text.blade.php` | New — plain-text invite email |
| `routes/api.php` | Added POST customers, approve, reject, invite, resend-invite, block routes; moved export before {id} |
| `app/Http/Controllers/QuoteRequestController.php` | Structured log events; warning when QUOTE_EMAIL unset |
| `app/Http/Controllers/Admin/SystemHealthController.php` | Added quote_email + order_email health checks |

### Deploy steps

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

**Migration that will run:** `2026_05_29_000001_add_onboarding_status_to_customers_table`
— additive ALTER TABLE. No data loss. Existing customers get `onboarding_status=active` (column default).

**Post-deploy: verify quote email health**
```bash
# Check health endpoint for quote_email status:
curl https://api.okelcor.com/api/v1/admin/system/health \
  -H "Authorization: Bearer {ADMIN_TOKEN}"
# Look for mail.quote_email — should be "pass"
```

**If QUOTE_EMAIL shows fail:**
```
# On server, add to .env:
QUOTE_EMAIL=support@okelcor.com
MAIL_MAILER=smtp   # (if currently set to 'log')
# Then:
php artisan config:clear && php artisan config:cache
```

### Test checklist

```
# Existing customer login still works:
POST /api/v1/auth/login { email: existing@..., password: ... } → 200

# New registration → pending_review:
POST /api/v1/auth/register { ... } → 201, message "under review", onboarding_status=pending_review

# Pending customer cannot log in:
POST /api/v1/auth/login { email: pending@..., password: ... } → 403, onboarding_status=pending_review

# Admin sees pending_review customer:
GET /api/v1/admin/customers?onboarding_status=pending_review → list includes new customer

# Admin approves:
POST /api/v1/admin/customers/{id}/approve → 200, onboarding_status=approved

# Admin invites:
POST /api/v1/admin/customers/{id}/invite → 200, email sent, onboarding_status=invited

# Admin creates new customer directly:
POST /api/v1/admin/customers { first_name, last_name, email, customer_type, ... } → 201, invite sent

# Invited customer cannot log in yet:
POST /api/v1/auth/login → 403, onboarding_status=invited

# Customer sets password via invite link:
POST /api/v1/auth/reset-password { token, email, password, password_confirmation } → 200

# Customer can now log in:
POST /api/v1/auth/login → 200, token issued

# Rejected customer cannot log in:
POST /api/v1/admin/customers/{id}/reject { reason: "..." }
POST /api/v1/auth/login → 403, onboarding_status=rejected

# Blocked customer cannot log in or use existing token:
POST /api/v1/admin/customers/{id}/block
POST /api/v1/auth/login → 403, onboarding_status=blocked
GET /api/v1/auth/me (with token) → 403, onboarding_status=blocked

# Quote email health:
GET /api/v1/admin/system/health → mail.quote_email = pass (if QUOTE_EMAIL set)
```

### Known gaps / future phases (CRM-2+)

- Frontend: registration page should explain B2B-only access + show "under review" message
- Frontend: admin customer list needs onboarding_status badge + action buttons
- Frontend: `/activate` page needed for invitation link (same as reset-password page but different copy)
- Invitation token TTL is 48h — same `password_reset_tokens` table used; frontend reset-password flow works as-is
- No email sent to customer on rejection (could add a rejection mailable in CRM-2)
- No bulk-approve/reject action (could add in CRM-2)
- `preferred_language` field not yet on customers — invitation email is English only

---

## Session 31c — LANG-1C: Hero Slide Audit + Repair Command Enhancement (complete)

### Root cause of slides 5/6/7 not translating
Slides 5, 6, 7 were created via the admin UI **after** the seeder ran. Their EN titles are not in the `$heroTranslations` seed map inside `RepairPublicTranslations`. The repair command already skipped them with "no approved seed data" — they need their EN content retrieved and translations added to the map.

### What was done
`translations:repair-public-content` now has an `--audit` flag (read-only) that dumps:
- Every hero slide: ID, sort_order, active flag, EN title, EN subtitle, EN CTAs, present locales, missing locales, whether it's in the seed map
- Every category: same summary in table form
- Clear marker `⚠ NO — add to seed map` for any slide not yet covered

### Workflow to fix slides 5/6/7 on production
```bash
# Step 1 — run audit on production to see the EN content of ALL slides:
/opt/alt/php83/usr/bin/php artisan translations:repair-public-content --audit

# Step 2 — the output for slides not in the seed map looks like:
# ┌─ Slide ID 5 | sort_order=5 | active
# │  EN title:    <ACTUAL TITLE HERE>
# │  EN subtitle: <ACTUAL SUBTITLE HERE>
# │  EN CTAs:     [Shop Now] / [Learn More]
# │  Locales:     present=[en]  missing=[de, fr, es]
# │  In seed map: ⚠ NO — add to seed map

# Step 3 — share that output with backend dev (or paste into the $heroTranslations
# array in RepairPublicTranslations.php with de/fr/es translations added).

# Step 4 — re-run repair after seed map is updated:
/opt/alt/php83/usr/bin/php artisan translations:repair-public-content --dry-run
/opt/alt/php83/usr/bin/php artisan translations:repair-public-content
```

### PENDING: translations for slides 5/6/7
The EN titles of slides 5/6/7 are not visible locally (local MySQL not running).
**Action required:** run `--audit` on production, paste output back here, and the seed map will be updated.
Until then, the LANG-1 fallback logic (session 31) means the public API returns the EN text for those slides — no broken/null content.

### Files changed
| File | Change |
|---|---|
| `app/Console/Commands/RepairPublicTranslations.php` | Added `--audit` flag; improved warning messages; added inline comment block for adding unknown slides |

### Deploy steps (no migrations)
```bash
cd /home/u978121777/domains/okelcor.com/public_html/okelcor-api
git fetch origin && git reset --hard origin/main
/opt/alt/php83/usr/bin/php artisan config:clear
/opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan translations:repair-public-content --audit
```

---

## Session 31b — LANG-1B: Backend Translation Coverage Cleanup (complete)

### New Artisan commands

#### `php artisan translations:repair-public-content`
Fills missing hero-slide and category translations from approved seed data. Safe to run on production.

| Flag | Behaviour |
|---|---|
| _(none)_ | Write missing translations |
| `--dry-run` | Show what would be filled without writing |

Rules:
- Never overwrites an existing translation row
- Matches hero slides by their EN title (resilient to sort-order changes)
- Matches categories by slug
- Slides/categories with no approved seed data are skipped and reported
- Contains translations for all 3 known hero slides × 3 non-EN locales (de/fr/es) and all 4 categories × 4 locales

#### `php artisan articles:missing-translations`
Read-only report of articles that have missing locale translations.

| Flag | Behaviour |
|---|---|
| _(none)_ | Check all locales, all articles |
| `--locale=fr` | Filter to a single locale |
| `--published-only` | Only check published articles |

Output: table of article ID / slug / EN title / published status / missing locales.
**Never auto-translates** — all translations require human editor via admin UI.

### New files

| File | Purpose |
|---|---|
| `app/Console/Commands/RepairPublicTranslations.php` | `translations:repair-public-content` |
| `app/Console/Commands/ArticleMissingTranslations.php` | `articles:missing-translations` |

### Deploy steps (no migrations)
```bash
cd /home/u978121777/domains/okelcor.com/public_html/okelcor-api
git fetch origin && git reset --hard origin/main
/opt/alt/php83/usr/bin/php artisan config:clear
/opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache

# Dry-run first to see what's missing:
/opt/alt/php83/usr/bin/php artisan translations:repair-public-content --dry-run

# If output looks correct, apply:
/opt/alt/php83/usr/bin/php artisan translations:repair-public-content

# Check which articles need human translation:
/opt/alt/php83/usr/bin/php artisan articles:missing-translations
/opt/alt/php83/usr/bin/php artisan articles:missing-translations --published-only
```

### New article missing translations
If a new article was created with English only, `articles:missing-translations` will list it.
**Do not auto-translate.** The editor must open the article in the admin editor and fill FR/DE/ES tabs.
The `missing_locales: ["fr","de","es"]` field already appears on `GET /admin/articles/{id}` (added in session 31).
The public `GET /api/v1/articles?locale=fr` endpoint already falls back to EN gracefully.

### Fallback logic validation (all three cases verified)
| Scenario | Result |
|---|---|
| FR row exists → `?locale=fr` | Returns French translation |
| FR row missing, EN row exists → `?locale=fr` | Returns EN translation (correct fallback) |
| No translation rows at all → `?locale=fr` | Returns `hero_slides.title` base column (last resort) |
| Category no FR row → `?locale=fr` | Returns EN translation, never null |

---

## Session 31 — LANG-1: Backend Translation Foundation Fixes (complete)

### Root causes fixed
Two public controllers had broken locale fallback behaviour (identified in the LANG audit):

| Controller | Bug | Fix |
|---|---|---|
| `HeroSlideController` | Loaded only the requested locale; if FR row absent fell back to `hero_slides.title` column (EN text), not the EN translation row | Load `[$locale, 'en']` with `whereIn`; resolve with `firstWhere($locale) ?? firstWhere('en')` |
| `CategoryController` | Loaded only the requested locale; no fallback at all — returned `null` for title/label/subtitle | Same fix; final fallback to `''` (empty string, never null) |

### New `missing_locales` field on admin responses

All three admin content controllers now include a `missing_locales` array in their response to signal which translations are absent. Does **not** block saving.

```json
// GET /admin/articles/{id}
// GET /admin/hero-slides/{id}
// GET /admin/categories (each item)
{
  "missing_locales": ["fr", "es"]
}
```

An empty array `[]` means all four locales (en/de/fr/es) are present.

### Files changed

| File | Change |
|---|---|
| `app/Http/Controllers/HeroSlideController.php` | `whereIn([$locale,'en'])` + `firstWhere` cascade fallback |
| `app/Http/Controllers/CategoryController.php` | Same fix; returns `''` not `null` when no translation |
| `app/Http/Controllers/Admin/AdminArticleController.php` | Added `missing_locales` to `formatArticle()` |
| `app/Http/Controllers/Admin/AdminHeroSlideController.php` | Added `missing_locales` to `formatSlide()` |
| `app/Http/Controllers/Admin/AdminCategoryController.php` | Added `missing_locales` to `formatCategory()` |

### Locale support summary (post LANG-1)

| Public endpoint | `?locale=` | EN fallback |
|---|---|---|
| `GET /api/v1/hero-slides` | ✅ | ✅ EN translation row → base column |
| `GET /api/v1/categories` | ✅ | ✅ EN translation row → empty string |
| `GET /api/v1/articles` | ✅ | ✅ (was already correct) |
| `GET /api/v1/articles/{slug}` | ✅ | ✅ (was already correct) |
| `GET /api/v1/search` | ✅ | ✅ articles only |
| `GET /api/v1/products` | ❌ no translation table | N/A |

### Deploy steps (no migrations)
```bash
cd /home/u978121777/domains/okelcor.com/public_html/okelcor-api
git fetch origin && git reset --hard origin/main
/opt/alt/php83/usr/bin/php artisan config:clear
/opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
```

### Test checklist
```
GET /api/v1/hero-slides?locale=fr   → each slide has non-null title + cta labels in French
GET /api/v1/hero-slides?locale=de   → German text
GET /api/v1/categories?locale=fr    → title/label/subtitle all non-null in French
GET /api/v1/categories?locale=xx    → invalid locale → falls back to en silently
GET /admin/articles/{id}            → response includes missing_locales: []  (seeded articles have all 4)
GET /admin/hero-slides/{id}         → response includes missing_locales: []
GET /admin/categories               → each item has missing_locales: []
```

### Known remaining backend gaps (future phases)
- No `preferred_language` on `customers` table → emails always English
- All mailables hardcoded English
- All PDF templates hardcoded `<html lang="en">`
- Products have no translation table
- Site settings have no per-locale values
- Admin article/hero/slide editors do not enforce EN locale presence on save

---

## Session 30 — URGENT: Stop Auto-Proforma Before Customer Acceptance (complete)

### Root cause
`PaymentController::createSession()` bank_transfer path automatically called
`generateProformaForOrder()` immediately after creating the order — before any
customer acceptance. This issued a PI before the customer had even seen the AB.

### Changes made

**`app/Http/Controllers/PaymentController.php`**
- Bank transfer path (was lines 225-233): replaced `generateProformaForOrder` with
  `generateOrderConfirmationForOrder(order, null)`
- Stripe path: added `$order->load('items')` + `generateOrderConfirmationForOrder(order, null)`
  right after the DB transaction, before the Stripe session is built

**`app/Http/Controllers/Admin/AdminQuoteRequestController.php`**
- Removed `if ($paymentMethod === 'bank_transfer')` guard; AB is now generated for
  ALL payment methods (Stripe path was previously missing AB auto-generation)

**`app/Http/Controllers/OrderController.php`**
- Added `use App\Services\TradeDocumentService` + `use Illuminate\Support\Facades\Log`
- Added `generateOrderConfirmationForOrder(order, null)` after `$order->load('items')` in `store()`
- `formatOrder()`: proforma is now filtered from `trade_documents[]` when
  `customer_acceptance_status !== 'accepted'` (prevents frontend from displaying
  the PI before acceptance, even if one somehow exists)

**`database/migrations/2026_05_22_000002_add_proforma_fix_actions_to_order_logs.php`** (NEW)
- Extends `order_logs.action` enum: adds `premature_proforma_superseded` and
  `order_confirmation_auto_generated` — additive ALTER TABLE, no data loss

**`app/Console/Commands/FixPrematureProformas.php`** (NEW)
- `php artisan orders:fix-premature-proformas --dry-run` — list affected orders
- `php artisan orders:fix-premature-proformas --apply` — supersede premature proformas
- Finds orders with `customer_acceptance_status IN (pending, rejected)` that have an
  `issued/sent` proforma; sets status=superseded, fills `supersede_reason`, logs
  `premature_proforma_superseded` to `order_logs`

### AB generation coverage after session 30

| Path | Before | After |
|---|---|---|
| `PaymentController` bank_transfer | Auto-PI (BUG) | Auto-AB |
| `PaymentController` Stripe checkout | No doc | Auto-AB |
| `AdminQuoteRequestController` bank_transfer | Auto-AB | Auto-AB (unchanged) |
| `AdminQuoteRequestController` Stripe | No doc (BUG) | Auto-AB |
| `OrderController::store()` manual | No doc | Auto-AB |
| `AdminTradeDocumentController` endpoints | On-demand admin trigger | On-demand admin trigger (unchanged) |

### Deploy steps (session 30)
```bash
cd /home/u978121777/domains/okelcor.com/public_html/okelcor-api
git fetch origin && git reset --hard origin/main
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan route:cache
/opt/alt/php83/usr/bin/php artisan view:clear

# One-time cleanup of any premature proformas already in production:
/opt/alt/php83/usr/bin/php artisan orders:fix-premature-proformas --dry-run
# If dry-run output looks correct:
/opt/alt/php83/usr/bin/php artisan orders:fix-premature-proformas --apply
```

**Migration that will run:** `2026_05_22_000002_add_proforma_fix_actions_to_order_logs`
— additive ALTER TABLE on `order_logs.action`, no data loss.

---

---

## Session 29b — DOC-6 Route Alignment (complete)

**Commits:** `7c2da1d` (session 29 fixes) + `05910f7` (route alignment)

### Changes

| Item | Before | After |
|---|---|---|
| Admin send-acceptance-request | `POST /admin/orders/{id}/send-acceptance-request` only | Added alias `POST /admin/orders/{id}/acceptance/send` (frontend-matching). Old route kept. |
| Public token preview | `GET /orders/{ref}/accept-confirmation?token=` (ref + query) | Added `GET /documents/acceptance/{token}` (token-only, canonical). Old route kept. |
| Public token accept | `POST /orders/{ref}/accept-confirmation` body `{action,token,note}` | Added `POST /documents/acceptance/{token}/accept` body `{note}` (optional). Old route kept. |
| Public token reject | Same as accept with `action:reject` | Added `POST /documents/acceptance/{token}/reject` body `{note}` (optional). Old route kept. |
| Authenticated customer reject | Not implemented | Added `POST /auth/orders/{ref}/reject-order-confirmation` |
| Admin order detail `acceptance_token` | Not exposed | Now included when `customer_acceptance_status = pending`; null after accept/reject |
| Frontend URL in emailed link | `/orders/{ref}/accept-confirmation?token=` | Changed to `/documents/acceptance/{token}` in both `generateAcceptanceLink` and `sendAcceptanceRequest` |

### Complete final route list (acceptance only)

**Admin (auth:sanctum + permission:trade_documents.manage):**
```
POST /api/v1/admin/orders/{id}/acceptance/send           ← frontend canonical (new)
POST /api/v1/admin/orders/{id}/send-acceptance-request   ← kept for backwards compat
POST /api/v1/admin/orders/{id}/generate-acceptance-link  ← returns URL without emailing
```

**Authenticated customer (auth.customer):**
```
POST /api/v1/auth/orders/{ref}/accept-order-confirmation
POST /api/v1/auth/orders/{ref}/reject-order-confirmation  ← new
```

**Public token — canonical (throttle:acceptance-links):**
```
GET  /api/v1/documents/acceptance/{token}          ← preview, no PII
POST /api/v1/documents/acceptance/{token}/accept   ← body: { note? }
POST /api/v1/documents/acceptance/{token}/reject   ← body: { note? }
```

**Public token — legacy (throttle:acceptance-links, kept):**
```
GET  /api/v1/orders/{ref}/accept-confirmation?token=
POST /api/v1/orders/{ref}/accept-confirmation      body: { action, token, note? }
```

### Admin order detail response — acceptance fields
```json
{
  "customer_acceptance_status":  "pending | accepted | rejected",
  "customer_accepted_at":        "ISO 8601 | null",
  "customer_acceptance_note":    "string | null",
  "acceptance_token":            "64-char hex string | null (null once actioned)",
  "acceptance_token_expires_at": "ISO 8601 | null"
}
```

---

## Session 29 — DOC-6: Customer Order Confirmation Acceptance Flow (fix + complete)

### Audit findings (what already existed)

| Item | Status |
|---|---|
| Migration `2026_05_20_000003_add_customer_acceptance_to_orders` — 7 fields on `orders` | Existed |
| `CustomerQuoteAcceptanceController` — `acceptOrderConfirmation()`, `acceptConfirmationByToken()`, `acceptQuote()`, `rejectQuote()` | Existed |
| Admin `AdminTradeDocumentController::generateAcceptanceLink()` — generates 64-char token + frontend URL | Existed |
| Admin `AdminTradeDocumentController::generateOrderConfirmation()` — generates AB-YYYY-XXXX PDF | Existed |
| Admin proforma gate: `customer_acceptance_status !== 'accepted'` → 409 (super_admin can override) | Existed |
| `TradeDocumentService::generateOrderConfirmationForOrder()` — AB- prefix, `order_confirmation` type | Existed |
| `AdminOrderController` response: `customer_acceptance_status`, `customer_accepted_at`, `customer_acceptance_note` | Existed |
| Route `POST /auth/orders/{ref}/accept-order-confirmation` | Existed |
| Route `POST /orders/{ref}/accept-confirmation` (public token, throttle:acceptance-links) | Existed |
| Route `POST /admin/orders/{id}/generate-acceptance-link` | Existed |

### Bugs and gaps found

| # | Issue | Severity |
|---|---|---|
| 1 | `order_logs.action` enum missing `order_confirmation_accepted`, `order_confirmation_rejected`, `customer_proposal_accepted`, `customer_proposal_rejected`, `acceptance_link_generated`, `acceptance_request_sent`, `proforma_generation_blocked_no_acceptance`, `document_voided` — controller writes these but MySQL silently fails (try/catch swallows error) | **Critical** |
| 2 | No GET endpoint for token acceptance preview — frontend can't display order/document details before customer clicks Accept | **Missing** |
| 3 | `customer_acceptance_status` / `customer_accepted_at` absent from public customer order response (`OrderController::formatOrder()`) — account order page can't show acceptance banner | **Missing** |
| 4 | `generateAcceptanceLink` returns the URL but never emails it — admin must copy/paste manually; no mailable, no acceptance request email | **Missing** |

### What was built

**Fix 1 — Migration: extend `order_logs.action` enum**
`database/migrations/2026_05_22_000001_add_acceptance_actions_to_order_logs.php`
Adds 8 new enum values (additive ALTER TABLE — no data loss):
`order_confirmation_accepted`, `order_confirmation_rejected`, `customer_proposal_accepted`,
`customer_proposal_rejected`, `acceptance_link_generated`, `acceptance_request_sent`,
`proforma_generation_blocked_no_acceptance`, `document_voided`

**Fix 2 — GET public endpoint: acceptance token preview**
`GET /api/v1/orders/{ref}/accept-confirmation?token={token}`
- Returns order_ref, order_total, currency, customer_acceptance_status, already_actioned, expires_at, document (type + number)
- Uses same throttle:acceptance-links group (20/min)
- Same token + ref validation as the POST endpoint
- No PII exposed beyond what the link holder already has from their email
- Controller: `CustomerQuoteAcceptanceController::confirmationTokenInfo()`

**Fix 3 — `customer_acceptance_status` in public customer order response**
`app/Http/Controllers/OrderController.php` — `formatOrder()` now includes:
```
customer_acceptance_status  pending | accepted | rejected
customer_accepted_at        ISO 8601 or null
```

**Fix 4 — "Send Acceptance Request" email**
New endpoint: `POST /api/v1/admin/orders/{id}/send-acceptance-request`
- Permission: `trade_documents.manage`
- Guards: 409 if already accepted; 422 if no issued/sent order_confirmation document exists
- Generates / rotates acceptance token (7-day TTL, same as generateAcceptanceLink)
- Sends `OrderConfirmationAcceptanceRequest` mailable to `recipient_email` (defaults to `order.customer_email`)
- Attaches the Order Confirmation PDF from private disk
- Advances document lifecycle: `issued → sent` (stamps `sent_at`)
- Logs `acceptance_request_sent` to `order_logs`
- Returns `{ accept_url, expires_at, recipient_email, order_ref }`
- Controller: `AdminTradeDocumentController::sendAcceptanceRequest()`

### New files
| File | Purpose |
|---|---|
| `database/migrations/2026_05_22_000001_add_acceptance_actions_to_order_logs.php` | Extend order_logs.action enum |
| `app/Mail/OrderConfirmationAcceptanceRequest.php` | Mailable — AB PDF + accept link |
| `resources/views/emails/order-confirmation-acceptance-request.blade.php` | HTML email |
| `resources/views/emails/order-confirmation-acceptance-request-text.blade.php` | Plain-text fallback |

### Changed files
| File | Change |
|---|---|
| `routes/api.php` | Added GET `/orders/{ref}/accept-confirmation`; added `POST /admin/orders/{id}/send-acceptance-request` |
| `app/Http/Controllers/OrderController.php` | Added `customer_acceptance_status` + `customer_accepted_at` to `formatOrder()` |
| `app/Http/Controllers/CustomerQuoteAcceptanceController.php` | Added `confirmationTokenInfo()` method |
| `app/Http/Controllers/Admin/AdminTradeDocumentController.php` | Added `use OrderConfirmationAcceptanceRequest`; added `sendAcceptanceRequest()` method |

### Full flow after fix

**Admin flow:**
1. Admin generates Order Confirmation: `POST /admin/orders/{id}/trade-documents/order-confirmation` → returns AB-YYYY-XXXX
2. Admin sends acceptance request: `POST /admin/orders/{id}/send-acceptance-request` → emails customer with AB PDF attached + accept/reject link
3. Admin can also just copy the link: `POST /admin/orders/{id}/generate-acceptance-link` → returns `accept_url` without emailing

**Customer (account) flow:**
1. Customer loads `/account/orders/{ref}` → response includes `customer_acceptance_status: "pending"`
2. Customer sees AB PDF in `trade_documents[]` (type `order_confirmation`, status `sent`)
3. Customer clicks "Accept Order Confirmation" → `POST /auth/orders/{ref}/accept-order-confirmation`
4. `customer_acceptance_status` → `accepted`; `acceptance_token` cleared

**Customer (public link) flow:**
1. Customer opens emailed link: `{FRONTEND_URL}/orders/{ref}/accept-confirmation?token={token}`
2. Frontend fetches order details: `GET /api/v1/orders/{ref}/accept-confirmation?token={token}` → shows order ref, total, AB number, expiry
3. Customer clicks Accept or Decline → `POST /api/v1/orders/{ref}/accept-confirmation` body: `{ action: "accept"|"reject", token: "...", note: "..." }`
4. `customer_acceptance_status` → `accepted` or `rejected`; token cleared

**After customer accepts:**
- Admin page updates: `customer_acceptance_status: accepted`
- Generate Proforma button unlocks (409 gate lifted)
- `POST /admin/orders/{id}/trade-documents/proforma` → generates PI-YYYY-XXXX

### Deploy steps
```bash
cd /home/u978121777/domains/okelcor.com/public_html/okelcor-api
git fetch origin && git reset --hard origin/main
composer install --no-dev --optimize-autoloader
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan config:clear && /opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
/opt/alt/php83/usr/bin/php artisan view:clear
```

**Migration that will run:** `2026_05_22_000001_add_acceptance_actions_to_order_logs` — additive ALTER TABLE on `order_logs.action`, no data loss.

### Frontend integration notes

**Account order page (`/account/orders/{ref}`):**
- Check `order.customer_acceptance_status === 'pending'` AND `order.trade_documents` contains an `order_confirmation` with `status: 'sent'`
- Show banner: "Please review and accept the Order Confirmation before the Proforma Invoice is issued."
- Show AB PDF download button (from `trade_documents` array)
- Show "Accept Order Confirmation" button → `POST /api/v1/auth/orders/{ref}/accept-order-confirmation`
- Optionally show "Decline" button → show rejection reason input → same endpoint body `{ note }`... (no separate reject endpoint for account users — not implemented yet)

**Public token page (`/orders/{ref}/accept-confirmation?token=...`):**
1. On load: `GET /api/v1/orders/{ref}/accept-confirmation?token={token}` — show order ref, total, document number, expiry
2. If `already_actioned: true` → show already-accepted/rejected message
3. Accept button → `POST /api/v1/orders/{ref}/accept-confirmation` body `{ action: "accept", token: "...", note: "" }`
4. Decline button → show reason input → `POST` with `action: "reject"`
5. On 403 → show "This link is invalid or has expired" message

---

## Session 28 — SEC-3: Layered Rate Limiting & Abuse Protection (complete)

**Commit:** `3bca20d` — no migration needed, code-only.

### What was audited first

Before writing code, a full read of `bootstrap/app.php`, `routes/api.php`, `AppServiceProvider.php`, and all middleware aliases confirmed the existing state. Key findings:

- 9 named limiters already existed (auth, auth-email, checkout, tracking, search, vat, payments, public-form, quote-form)
- Admin login and 2FA had inline `RateLimiter::hit()` counters only — no middleware, no response headers
- `POST admin/2fa/setup/enable` and `POST admin/2fa/setup/confirm` had **zero protection**
- `documents/verify/{number}` was using the wrong limiter (`search`/30min instead of its own)
- `orders/{ref}/accept-confirmation` was using the wrong limiter (`auth`/10min instead of its own)
- All public content reads (products, articles, categories, etc.) had no throttle
- Article uploads, eBay sync, admin-sensitive operations had no throttle

### Named limiters — created / updated

| Limiter | Limit | Key | Status |
|---|---|---|---|
| `admin-login` | 5/min | IP + email | New |
| `admin-2fa` | 10 per 5min | IP | New |
| `password-reset` | 5 per 15min | IP | New |
| `public-doc-verify` | 60/min | IP | New |
| `acceptance-links` | 20/min | IP | New |
| `api-public` | 120/min | IP | New |
| `ebay-sync` | 10/min | admin user ID | New |
| `article-upload` | 20/min | admin user ID | New |
| `admin-sensitive` | 30/min | admin user ID | New |
| `quote-form` | 10/hour | IP | Updated (was 5/hour) |

### Routes protected for the first time

| Route | Limiter | Note |
|---|---|---|
| `POST admin/login` | `admin-login` | Complements existing per-failure inline counter |
| `POST admin/login/2fa` | `admin-2fa` | |
| `POST admin/2fa/setup/enable` | `admin-2fa` | Was completely unprotected |
| `POST admin/2fa/setup/confirm` | `admin-2fa` | Was completely unprotected |
| `POST auth/reset-password` | `password-reset` | Split out of `auth` group |
| `GET documents/verify/{number}` | `public-doc-verify` | Was using `search`/30min |
| `POST orders/{ref}/accept-confirmation` | `acceptance-links` | Was using `auth`/10min |
| All public content reads | `api-public` | Products, articles, categories, brands, settings, etc. |
| Article image uploads (3 routes) | `article-upload` | Nested inside `products.edit` group |
| `ebay/sync-all`, `ebay/orders/sync`, `ebay/orders/{id}/sync` | `ebay-sync` | Nested inside `ebay.manage` group |
| `admins.manage` group | `admin-sensitive` | All admin user management |
| `products.import` group | `admin-sensitive` | Bulk import/export/delete |
| `security.manage` group | `admin-sensitive` | 2FA notices etc. |

### Response headers
`X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Retry-After` are automatic on all routes using throttle middleware. Laravel provides these natively — no custom middleware needed.

### Structured rate-limit logging
`ThrottleRequestsException` now logs a structured `warning` entry:
```
[rate_limit_exceeded] { route, method, ip, user_id }
```
Also added to the skip list in the general critical-exception reporter so it does not appear as a false CRITICAL log event.

### Integrations confirmed unaffected
- Stripe webhook — no throttle (correct; Stripe signature validates every call)
- eBay OAuth callback — existing inline `throttle:10,1` unchanged
- Laravel scheduler — console-only, not HTTP

### Files changed
| File | Change |
|---|---|
| `app/Providers/AppServiceProvider.php` | All new named limiters + quote-form update |
| `bootstrap/app.php` | ThrottleRequestsException structured warning log |
| `routes/api.php` | Throttle middleware applied to all newly protected routes |

### Deploy commands (no migration)
```bash
cd /home/u978121777/domains/okelcor.com/public_html/okelcor-api
git fetch origin && git reset --hard origin/main
composer install --no-dev --optimize-autoloader
/opt/alt/php83/usr/bin/php artisan config:clear
/opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
```

### Frontend note
On any 429 response, read the `Retry-After` header and show:
> "Too many attempts. Please wait X seconds and try again."
Do not show a generic server error for 429s.

---

## Session 27 — Track Order "Page Not Found" (Frontend Issue + Backend Shape Fix)

**Frontend (TRK-1 — done by frontend team):** `app/account/orders/[ref]/track/page.tsx` created, "Track Order" button fixed.

**Backend — a response shape mismatch was discovered but reverted at user request.** The backend tracking endpoint exists at `GET /api/v1/tracking/{container}` (public, 30/min). The shape change was reverted; the endpoint still returns its original shape. If a shape alignment is needed in future, see commits `176e1ac` (change) and `0ead0e6` (revert) for the full diff.

Current tracking response shape (original, as of this session):
```json
{
  "data": { "status": "...", "location": "...", "eta": "...", "events": [...] },
  "carrier": "DHL",
  "message": "success"
}
```

---

## Session 26 — Article Publish 500 Fix (Backend)

**Symptom:** Content editor hit "Server Error" when publishing an article after the TipTap rich editor was wired up.

**Root causes (all backend):**

| # | Cause | Impact |
|---|-------|--------|
| 1 | `ArticleHtmlSanitizer` used a file-based HTMLPurifier definition cache (`Cache.SerializerPath`). On production (Namecheap), if `storage/app/htmlpurifier` is not writable, HTMLPurifier throws during initialization. | **Primary 500 cause** |
| 2 | `ArticleHtmlSanitizer::sanitize()` had `throw $e` in its catch block — re-threw the raw exception with no wrapping. The controller's `syncTranslations()` had zero try/catch, so the exception became an unhandled 500. | **500 escalation** |
| 3 | No structured logging in the controller — article_id, admin_id, route, and exception class were never logged, making the crash invisible in Laravel logs. | **Observability gap** |
| 4 | `update()` used `array_filter(fn($v) => $v !== null)` on the payload — `published_at = null` (to unpublish/clear) was silently dropped and never written to the DB. | **Minor correctness bug** |

**Files changed:**

| File | Change |
|------|--------|
| `app/Services/ArticleHtmlSanitizer.php` | Replaced file-based cache with `Cache.DefinitionImpl = null` (no permissions needed); added `code[class]` to allowed list (TipTap code blocks); catch now wraps as `RuntimeException` with user-readable message instead of raw re-throw |
| `app/Http/Controllers/Admin/AdminArticleController.php` | Added `use Log`; wrapped `syncTranslations()` in try/catch in both `store()` and `update()` — returns 422 JSON with message on failure, logs article_id/admin_id/route/exception; fixed `update()` to use `array_key_exists` per field instead of `array_filter(fn($v)=>$v!==null)` so `published_at=null` is honoured |

**Error flow after fix:**
```
TipTap HTML → sanitize() → HTMLPurifier (no file cache, no permission error) → clean HTML saved
If sanitizer fails → RuntimeException → controller catch → 422 JSON { message: "..." } → frontend shows it
```

**Deploy steps (no migration needed):**
```bash
cd /home/u978121777/domains/okelcor.com/public_html/okelcor-api
git fetch origin
git reset --hard origin/main
composer install --no-dev
/opt/alt/php83/usr/bin/php artisan config:clear
/opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
```

**Verify on production after deploy:**
```bash
# Check no file-cache related errors after an article save:
tail -n 50 storage/logs/laravel.log | grep -i "purifier\|article"
```

**Frontend notes from session 26:**

1. **TipTap duplicate extensions** — StarterKit already includes `Link` and `Underline`. Do not register them again explicitly; it causes "extension already registered" console warnings.
2. **Customer auth 401 on admin pages** — Admin pages are calling the customer auth check (`/api/auth/customer/me`). Skip that call on routes under `/admin/*`.
3. **Article save error display** — On 422, the backend returns `{ message: "..." }` with a user-readable string. Show `error.response.data.message` — not a generic "Server Error".
4. **Article editor — SEO fields** — Backend stores `meta_title` (max 160), `meta_description` (max 300), `cover_alt` (max 200) per locale. Add input fields and include in the `translations[locale]` payload.
5. **Article editor — OG image upload** — `POST /api/v1/admin/articles/{id}/og-image` (multipart, field `image`, max 5 MB, jpeg/png/webp). Returns full article with absolute `og_image` URL.
6. **Article editor — body image upload** — TipTap Image extension must POST to `POST /api/v1/admin/articles/{id}/body-image` with the admin Bearer token. Inject returned `data.url` as `<img src>`. External image URLs are stripped by the sanitizer.
7. **429 handling (SEC-3)** — On any 429, read `Retry-After` header and show "Too many attempts. Please wait X seconds and try again." Do not show a generic server error.

---

## Session 25 — EB-5 Completion, Token Fix, Rich Article Editor, Logistics Dashboard v2

---

### 25a — EB-5: eBay Order Status Sync (complete)

**Goal:** Sync eBay seller orders into Okelcor via the Sell Fulfillment API (not Trading API). Import new eBay orders, update existing ones, never overwrite non-eBay orders.

**New migrations:**
| Migration | What it adds |
|---|---|
| `2026_05_21_000003_add_ebay_fields_to_orders` | `source`, `ebay_order_id` (unique), `ebay_order_status`, `ebay_payment_status`, `ebay_fulfillment_status`, `ebay_buyer_username`, `ebay_last_synced_at`, `ebay_raw_summary` (json) on orders |
| `2026_05_21_000004_create_ebay_order_sync_logs_table` | `ebay_order_sync_logs` — append-only audit log (no `updated_at`) |

**New files:**
| File | Purpose |
|---|---|
| `app/Models/EbayOrderSyncLog.php` | Append-only model (`UPDATED_AT = null`) |
| `app/Services/EbayOrderSyncService.php` | Core sync: `syncRecent()`, `syncOne()`, `importOrder()`, `updateOrder()`, status mapping, non-PII `buildPayloadSummary()` |
| `app/Http/Controllers/Admin/EbayOrderController.php` | 4 admin endpoints: index, sync (bulk), syncOne, order-sync-logs |
| `app/Console/Commands/SyncEbayOrders.php` | `ebay:sync-orders --days=30`; silently skips if no eBay token |

**Changed files:**
- `app/Services/EbaySellingService.php` — added `sell.fulfillment` + `sell.fulfillment.readonly` scopes, `fetchOrders()`, `fetchOrder()`, `fulfillmentBaseUrl()`, `handleFulfillmentApiError()`
- `app/Models/Order.php` — 8 new eBay fillable fields + casts
- `app/Http/Controllers/Admin/AdminOrderController.php` — `source` in list format; full eBay metadata block in detail format
- `routes/api.php` — 4 new routes under `permission:ebay.manage`
- `routes/console.php` — hourly `ebay:sync-orders` schedule

**Admin endpoints (all: permission `ebay.manage`):**
```
GET  /api/v1/admin/ebay/orders                      List eBay-sourced orders
POST /api/v1/admin/ebay/orders/sync                 Bulk sync (?days=30)
POST /api/v1/admin/ebay/orders/{ebayOrderId}/sync   Single order sync
GET  /api/v1/admin/ebay/order-sync-logs             Sync audit log
```

**Safety rules enforced in `updateOrder()`:**
- Never downgrade `payment_status` from `paid`
- Never downgrade `status` from `shipped` or `delivered`
- Only match by `ebay_order_id` — website orders with no eBay ID are never touched

**Status mapping:**
| eBay `orderPaymentStatus` | Okelcor `payment_status` |
|---|---|
| PAID | paid |
| FAILED | failed |
| FULLY_REFUNDED | refunded |
| PARTIALLY_REFUNDED | paid |
| (default) | pending |

| eBay `orderFulfillmentStatus` | Okelcor `status` |
|---|---|
| NOT_STARTED | confirmed |
| IN_PROGRESS | processing |
| FULFILLED | shipped |
| CANCELLED | cancelled |

---

### 25b — Fix: eBay Token Refresh Scope Bug (complete)

**Symptom:** Status and readiness pages showed "Token refresh failed — the seller account may need to be reconnected."

**Root cause:** Adding `sell.fulfillment` and `sell.fulfillment.readonly` to `self::SCOPES` caused `callRefreshGrant()` to request those scopes in every refresh call. eBay rejects refresh requests that ask for scopes beyond what was originally granted. The existing DB token was authorized for 3 inventory scopes only.

**Fix (`app/Services/EbaySellingService.php`):**
```php
// callRefreshGrant() now uses stored scopes from the DB record, not self::SCOPES
$scopes = ($record && ! empty($record->scopes)) ? $record->scopes : self::SCOPES;
```

**Behaviour after fix:**
- Existing tokens refresh with their original scopes (status/readiness work immediately)
- Fulfillment endpoints return clear 403 "reconnect via auth-url" for pre-EB-5 tokens
- New connections (via auth-url) get all 5 scopes including fulfillment

**One-time action required on production:** Call `GET /api/v1/admin/ebay/auth-url`, open the URL in a browser logged in as the eBay seller, approve → new token with fulfillment scope issued.

---

### 25c — Rich Article Editor — Backend (complete)

**Goal:** Replace the plain JSON-array body format with sanitized rich HTML to support TipTap/Quill/Lexical editors on the frontend.

**Audit findings (what existed before):**
- `body` was stored as a JSON array of plain strings (`['paragraph one', 'paragraph two']`)
- Zero HTML sanitization — raw input written straight to DB
- No `meta_title`, `meta_description`, `og_image`, `cover_alt` fields
- No inline body image upload endpoint
- Public API returned `body` as a JSON array — frontend had to join manually

**New package:** `mews/purifier` (`^3.4`) — Laravel wrapper for HTMLPurifier.

**New migration:** `2026_05_21_100000_add_rich_content_fields_to_article_translations`
- `body_format` ENUM(`json_array`, `html`) DEFAULT `json_array` on `article_translations`
- `meta_title` VARCHAR(160) nullable on `article_translations`
- `meta_description` VARCHAR(300) nullable on `article_translations`
- `cover_alt` VARCHAR(200) nullable on `article_translations`
- `og_image` VARCHAR(500) nullable on `articles`

**New file:** `app/Services/ArticleHtmlSanitizer.php`
- HTMLPurifier config: allowlist of safe tags (`h1-h6`, `p`, `ul/ol/li`, `table`, `a`, `img`, `blockquote`, `pre`, `figure`, `div[data-type|data-cta-*]`, etc.)
- Blocked: `script`, `iframe`, `on*` event handlers, `javascript:` / `data:` URLs, external image `src`
- `img src` restricted to app domain only (editors must use body-image upload endpoint)
- Purifier cache stored in `storage/app/htmlpurifier/`
- `jsonArrayToHtml()` utility for one-way legacy conversion

**Zero-downtime body format transition:**
`ArticleTranslation` model `body_html` accessor: reads `body_format`, converts old JSON arrays to `<p>` tags on the fly. No data migration needed. New saves write sanitized HTML and set `body_format = 'html'`.

**New endpoint:**
```
POST /api/v1/admin/articles/{id}/body-image   Upload inline body image → returns { url, path }
```
Permission: `products.edit` (same as article write). Files stored at `articles/body/{uuid}.ext`.

**Changed files:**
| File | Change |
|---|---|
| `app/Models/ArticleTranslation.php` | Removed `array` cast on body; added `body_html` accessor; new fillable fields |
| `app/Models/Article.php` | Added `og_image` to fillable |
| `app/Http/Requests/Admin/StoreArticleRequest.php` | `body` now `string` (not `array`); SEO field rules added |
| `app/Http/Requests/Admin/UpdateArticleRequest.php` | Same |
| `app/Http/Controllers/Admin/AdminArticleController.php` | Sanitizes body on every save via `ArticleHtmlSanitizer`; adds `uploadBodyImage()`; exposes all SEO fields |
| `app/Http/Controllers/ArticleController.php` | Public `body` returns sanitized HTML string; all SEO fields exposed |

**Public API article shape now includes:**
```json
"body": "<h2>Heading</h2><p>Rich HTML...</p>",
"cover_alt": "Alt text for cover image",
"meta_title": "SEO title",
"meta_description": "160-char description",
"og_image": "https://.../storage/articles/og.jpg"
```

**Allowed HTML tags (full list):** `h1–h6`, `p`, `br`, `hr`, `strong`, `em`, `u`, `s`, `code`, `mark`, `sub`, `sup`, `pre[class]`, `blockquote`, `ul`, `ol`, `li`, `table`, `thead`, `tbody`, `tfoot`, `tr`, `th`, `td`, `a[href|target|rel|title]`, `img[src|alt|width|height|class|loading]`, `figure`, `figcaption`, `div[class|data-type|data-cta-*]`, `span[class|style]`

---

### 25d — Logistics Dashboard v2 (complete)

**Goal:** Fix the logistics dashboard that showed empty/useless data because it was built before payment milestones, customer acceptance, financial locks, and eBay orders existed.

**Root causes fixed:**
| Bug | Detail |
|---|---|
| `computeMissing()` tied to `payment_status='paid'` | New milestone orders have `payment_stage=deposit_paid` but `payment_status` may still be `pending` — zero docs flagged for ~100% of active orders |
| `next_action()` had no milestone awareness | Every active order got "No action required" |
| Summary cards wrong | Counted `payment_status='unpaid'` (empty) and `payment_status='paid'` — meaningless with milestones |
| `Invoice` model dead query | Per-page DB query on the old Invoice table (old system, never populated) — adds latency for nothing |
| No eBay source support | eBay orders had no `source` field, no eBay-specific action logic |
| Old orders hidden | Null `payment_stage` had no fallback — legacy orders appeared with wrong context |

**Key design: `resolveStage()`** — infers effective payment stage from `payment_status`/`status` for pre-milestone orders so old data is never lost:
```
payment_status=paid + status=shipped/delivered → 'shipment_released'
payment_status=paid                            → 'balance_paid'
status=processing                              → 'deposit_paid'
(default)                                      → 'pending_proforma'
```

**`next_action()` full switch (milestone-first):**
| Stage / Condition | next_action |
|---|---|
| `pending_proforma` | Finance: generate proforma invoice |
| `deposit_requested` | Awaiting deposit payment from customer |
| `deposit_paid` + docs missing | Generate commercial invoice + packing list |
| `balance_due` | Awaiting balance payment from customer |
| `balance_paid` | Full payment received — finance to release shipment |
| `shipment_released` + no tracking | Add tracking number / container number |
| eBay paid + not FULFILLED | Prepare and ship eBay order — mark fulfilled on eBay |
| EU declaration not acknowledged | Acknowledge signed EU declaration |

**New summary cards (18 total):**
`total_active_orders`, `total_ebay_orders`, `awaiting_proforma`, `awaiting_customer_acceptance`, `awaiting_deposit`, `deposit_paid_docs_needed`, `balance_due`, `ready_for_shipment_release`, `shipment_released`, `ebay_needing_fulfillment`, `missing_commercial_invoice`, `missing_packing_list`, `missing_shipment_document`, `pending_eu_declarations`, `high_risk_orders`, `orders_shipped`, `orders_delivered`

**New filters:**
| Param | Values |
|---|---|
| `source` | `all` / `website` / `ebay` |
| `payment_stage` | `pending_proforma` / `deposit_requested` / `deposit_paid` / `balance_due` / `balance_paid` / `shipment_released` |
| `ebay_fulfillment_status` | `NOT_STARTED` / `IN_PROGRESS` / `FULFILLED` / `CANCELLED` |
| `logistics_ready_only` | `true` — restricts to orders needing logistics action |

**New checklist row fields:**
`source`, `ebay_order_id`, `ebay_payment_status`, `ebay_fulfillment_status`, `payment_stage`, `customer_acceptance_status`, `financials_locked`, `financials_revision_required`, `deposit_amount`, `balance_amount`, `deposit_paid_at`, `balance_paid_at`, `shipment_released_at`, `updated_at`

**File changed:** `app/Http/Controllers/Admin/AdminLogisticsController.php` — complete rewrite (273 → 371 lines)

---

### Session 25 — Deploy Steps

```bash
cd /home/u978121777/domains/okelcor.com/public_html/okelcor-api
git fetch origin
git reset --hard origin/main
composer install --no-dev
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan config:clear
/opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
```

**Migrations that will run (5 new):**
1. `2026_05_21_000001` — payment milestone fields on orders (DOC-7, previous session)
2. `2026_05_21_000002` — milestone email sent_at timestamps (DOC-8, previous session)
3. `2026_05_21_000003` — eBay fields on orders (EB-5)
4. `2026_05_21_000004` — `ebay_order_sync_logs` table (EB-5)
5. `2026_05_21_100000` — rich content fields on article_translations + og_image on articles

**Post-deploy eBay action:** Reconnect eBay seller account once via `GET /api/v1/admin/ebay/auth-url` to issue a new token with fulfillment scope.

---

---

## Session 24 — System Health Monitor (complete)

**Phase done:** System Health, Vulnerability & Endpoint Monitor — backend only.

**What was built:**

| Artifact | Path |
|---|---|
| Health controller | `app/Http/Controllers/Admin/SystemHealthController.php` |
| CLI command | `app/Console/Commands/SystemHealthCommand.php` |
| Routes | `routes/api.php` — GET `admin/system/health`, GET `admin/system/errors` |
| Hourly schedule | `routes/console.php` — `system:health --snapshot` hourly |
| Exception logging | `bootstrap/app.php` — structured CRITICAL log on every unhandled exception |

**Endpoints:**
- `GET /api/v1/admin/system/health` — 6 check groups (application, database, backups, mail, security, endpoints); overall status: `pass | warning | fail | critical`; caches snapshot 90 min; permission: `security.view`
- `GET /api/v1/admin/system/errors?limit=N` — merged recent errors from `admin_security_events`, `failed_jobs`, and parsed `laravel.log`; permission: `security.view`

**CLI:**
```bash
php artisan system:health                # full colored report
php artisan system:health --group=security  # single group
php artisan system:health --errors       # recent errors
php artisan system:health --errors --limit=50
php artisan system:health --snapshot    # run + store cache
```

**Exception handler** (`bootstrap/app.php`):
Every unhandled exception (excluding 4xx HTTP types) now emits a `[unhandled_exception]` CRITICAL log entry with: `exception`, `file:line`, `route`, `method`, `url`, `ip`, `user_id`, `request_id` (from `X-Request-Id` header or auto-generated).

**Deploy steps:**
```bash
git reset --hard origin/main
composer install --no-dev
/opt/alt/php83/usr/bin/php artisan config:clear
/opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
```
No new migrations — cache-only health snapshot.

**Verify on server:**
```bash
/opt/alt/php83/usr/bin/php artisan system:health
/opt/alt/php83/usr/bin/php artisan route:list | grep system
```

---

## Session 23c — Mandatory Admin 2FA + 5-Hour Session TTL (complete)

**Goal:** All admin users must complete 2FA setup before getting a session token. Sessions expire after 5 hours.

**Architecture (bootstrapping problem solved):**
Admins who have no 2FA can't call the protected 2FA-setup endpoints (they need a token, but can't get one without 2FA). Solution: login returns a short-lived `temp_token` (10-minute UUID in cache), and two new unauthenticated endpoints let them complete setup using that token.

**Login flow — no 2FA configured:**
1. `POST /api/v1/admin/login` — credentials valid, but no `two_factor_confirmed_at` → returns HTTP 200 with `requires_2fa_setup: true` + `data.temp_token` (UUID). No Sanctum token issued.
2. `POST /api/v1/admin/2fa/setup/enable` — uses `temp_token`, generates TOTP secret, returns `secret + otpauth_uri`
3. `POST /api/v1/admin/2fa/setup/confirm` — uses `temp_token + 6-digit code`, activates 2FA, issues full session token + recovery codes → HTTP 201

**Login flow — 2FA already configured:**
1. `POST /api/v1/admin/login` → `session_token` challenge (existing flow)
2. `POST /api/v1/admin/login/2fa` → issues full session token

**Session TTL:** 5 hours (300 minutes default). All token-issuing paths use:
```php
$ttl       = (int) config('auth.admin_session_ttl_minutes', 300);
$expiresAt = now()->addMinutes($ttl);
$token     = $admin->createToken('admin-token', ['*'], $expiresAt)->plainTextToken;
```
Override with `ADMIN_SESSION_TTL_MINUTES=300` in `.env`.

**EnsureAdminTwoFactorEnabled middleware:** Always enforces (no bypass env flag). Returns HTTP 428 with `code: 'two_factor_required'`. Allows through: `/admin/me`, `/admin/logout`, `/admin/profile`, all `/admin/2fa/*`, all `/admin/security/*`.

Grace period (staged rollout only): `ADMIN_2FA_GRACE_UNTIL=YYYY-MM-DD` in `.env` bypasses enforcement until that date.

**Files changed:**
- `app/Http/Controllers/Admin/AuthController.php` — no-2FA login path now issues `temp_token` instead of full token
- `app/Http/Controllers/Admin/AdminTwoFactorController.php` — added `setupEnable()`, `setupConfirm()`, `formatUser()` methods
- `app/Http/Controllers/Admin/AdminLoginTwoFactorController.php` — token creation now includes `$expiresAt`; response includes `expires_at`
- `app/Http/Middleware/EnsureAdminTwoFactorEnabled.php` — removed `ADMIN_2FA_ENFORCED` env check; added grace period; HTTP 428
- `config/auth.php` — removed `admin_2fa_enforced` key; added `admin_session_ttl_minutes`
- `routes/api.php` — added `POST admin/2fa/setup/enable` and `POST admin/2fa/setup/confirm` (unauthenticated)

**New .env key (optional):**
```
ADMIN_SESSION_TTL_MINUTES=300   # 5 hours — omit to accept default
ADMIN_2FA_GRACE_UNTIL=          # leave blank for immediate enforcement
```

**Deploy steps (no migration needed):**
```bash
git reset --hard origin/main
composer install --no-dev
/opt/alt/php83/usr/bin/php artisan config:clear && /opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
```

---

## Session 23b — Trade Document Supersede + Order Financial Correction (complete)

**Context:** Order OKL-548XDW had delivery fee entered as €2.50 instead of €2500.00 and a proforma invoice had already been issued. Required: correct the financials without destroying the audit trail.

### Part 1 — Trade document supersede support

**New DB columns** (`trade_documents` table):
```
superseded_at      TIMESTAMP NULL
superseded_by_id   FK → admin_users (NULL on delete)
supersede_reason   TEXT NULL
```
A superseded document keeps its PDF on disk and its row in the DB. It is hidden from customer-facing endpoints (they already filter `status='issued'` only). Admins can see it via the admin trade documents endpoint.

**New endpoint:** `POST /api/v1/admin/orders/{orderId}/trade-documents/{documentId}/supersede`
- Permission: `trade_documents.manage`
- Only supersedable types: `proforma_invoice`, `commercial_invoice`
- Only supersedes documents with `status='issued'`
- Sets `status='superseded'`, `superseded_at`, `superseded_by_id`, `supersede_reason`
- Logs `document_superseded` to order_logs

### Part 2 — Order financial correction

**New endpoint:** `PATCH /api/v1/admin/orders/{id}/financials`
- Permission: `orders.update`
- Validates `delivery_fee` (numeric, min:0, max:999999.99) + `reason`
- Surgical recalculation: `new_total = old_total - old_delivery_cost + new_delivery_cost`
- Updates `delivery_cost` and `total` atomically
- Logs `financial_corrected` with old/new values to order_logs

### Part 3 — One-time Artisan correction command

```bash
php artisan orders:correct-delivery-fee {ref} {amount} {--supersede-proforma} {--reason=} {--dry-run}
```
- `--dry-run`: shows what would change without touching DB
- `--supersede-proforma`: automatically marks the current issued proforma as superseded
- `--reason`: stored in both the supersede record and the order log

**Files changed:**
- `database/migrations/2026_05_18_134205_add_supersede_fields_to_trade_documents_table.php` (new)
- `app/Models/TradeDocument.php` — added supersede fields to `$fillable`, `$casts`, and `supersededBy()` relation
- `app/Http/Controllers/Admin/AdminTradeDocumentController.php` — added `supersede()` method; updated `formatDocument()`
- `app/Http/Controllers/Admin/AdminOrderController.php` — added `patchFinancials()` method
- `app/Console/Commands/CorrectOrderDeliveryFee.php` (new)
- `routes/api.php` — two new routes added

**Deploy steps:**
```bash
git reset --hard origin/main
composer install --no-dev
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan config:clear && /opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
```

**To correct OKL-548XDW on production:**
```bash
/opt/alt/php83/usr/bin/php artisan orders:correct-delivery-fee OKL-548XDW 2500 --supersede-proforma --reason="Delivery fee entered as 2.50 instead of 2500.00 — corrected" --dry-run
# Review output, then run without --dry-run
```

---

## Session 23 — eBay EAN fix (complete)

**Root cause:** errorId 25002 "Das Feld EAN fehlt" — eBay DE category 10183 requires an EAN (European Article Number / barcode). Products table had no `ean` column.

**Fix applied:**
- Migration `2026_05_18_121827_add_ean_to_products_table.php` — adds nullable `ean` VARCHAR(20) column to products
- `Product::$fillable` — added `ean`
- `EbaySellingService.upsertInventoryItem()` — sends `product.ean: [$product->ean]` if EAN is stored; falls back to `["Does not apply"]` (eBay GTIN exemption) when none is set
- `EbaySellingService.syncInventory()` — same EAN logic
- `EbaySellingService.diagnoseProduct()` — same EAN in Step D inventory PUT

**If EAN "Does not apply" is rejected by eBay for category 10183:**
- The `ean` column is ready — populate it via the admin product edit form or a bulk import
- RAPID tyre EANs can be obtained from the supplier data sheet or barcode on the tyre sidewall

**Files changed:**
- `database/migrations/2026_05_18_121827_add_ean_to_products_table.php` (new)
- `app/Models/Product.php`
- `app/Services/EbaySellingService.php`

**Deploy steps:**
```bash
git reset --hard origin/main
composer install --no-dev
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan config:clear && /opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
```
Then test: `/opt/alt/php83/usr/bin/php artisan ebay:debug-product 235776 --publish`

---

**Chain of eBay bugs fixed in sessions 20–23 (all proven by diagnoseProduct diagnostic):**
1. Locale mismatch: `Content-Language: en-US` → inventory stored as `locale:en_US` → 25751 when POST /offer used EBAY_DE. Fix: `marketplaceLocale()` returns `de-DE` for EBAY_DE.
2. `->ok()` false for HTTP 201: POST /offer returns 201 Created, not 200. Fix: `->successful()` (200-299).
3. `->ok()` false for HTTP 204: PUT /offer and PUT inventory_item return 204. Fix: `->successful()` everywhere.
4. Missing merchantLocationKey: eBay couldn't determine Item.Country for EBAY_DE. Fix: `ensureMerchantLocation()` auto-creates OKELCOR-MAIN location; `merchantLocationKey` added to offer body.
5. English aspect names: EBAY_DE category 10183 requires German names (`Marke` not `Brand`, etc.). Fix: `buildAspects()` uses German names for EBAY_DE/AT/CH.
6. Missing EAN: category 10183 requires EAN field. Fix: send `product.ean` (or "Does not apply" fallback).

---

## Project
Laravel 13.2 / PHP 8.3 REST API for Okelcor B2B tyre wholesale.

- Local: `http://localhost:8000`
- Production API: `https://api.okelcor.com`
- Frontend production: `https://okelcor.com`
- DB: `okelcor_cms` on MySQL 8
- Auth: Laravel Sanctum token (Bearer) — admin routes and customer routes
- All responses: `application/json` via ForceJsonResponse middleware
- GitHub: `https://github.com/johnseyi/okelcor-api.git`
- Active deploy branch: `main`

Important:
- `okelcor.com` is the canonical frontend domain.
- `api.okelcor.com` is the canonical API domain.
- Old `.de` references are legacy — do not use.

---

## Namecheap Deploy Command

Run after every backend deployment:

```bash
cd /home/u978121777/domains/okelcor.com/public_html/okelcor-api
git fetch origin
git reset --hard origin/main
composer install --no-dev
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan config:clear
/opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
```

**Session 22 — eBay error 25751 fix: inventory indexing race condition (no migrations):**

**Root cause:** eBay returns error 25751 ("SKU not found for marketplace EBAY_DE") when `POST /offer` fires before eBay has finished indexing the just-PUT inventory item. This is an async race condition on eBay's side, not a config issue.

**Fix applied:**
- `EbaySellingService.createOrUpdateListing()`: now calls `waitForInventoryItem()` between `upsertInventoryItem()` and `upsertOffer()`. This GET-verifies the SKU is reachable (up to 3 attempts with 1 s gap), then throws a clear RuntimeException if still not available after all attempts.
- `EbaySellingService`: applied `rawurlencode()` to SKU in URL path segments in `upsertInventoryItem()`, `syncInventory()`, `deleteListing()` — prevents 400 errors for SKUs containing spaces or special chars.
- `EbaySellingService.diagnoseProduct()`: new public 6-step diagnostic method (validation → token → PUT inventory → GET verify → offer check → optional publish). Returns structured `$report` for the Artisan command.
- `EbayListingController.safeError()`: added pattern for 25751 ('error 25751' / 'was not available after') → returns user-readable "retry in a few seconds" message.
- Added `app/Console/Commands/EbayDebugProduct.php` — `php artisan ebay:debug-product {product_id} {--publish}` — runs full diagnostic and displays results in table format.

**No .env changes needed for this session's fix** — the retry logic is automatic.

**Files changed (session 22):**
- `app/Services/EbaySellingService.php`
- `app/Http/Controllers/Admin/EbayListingController.php`
- `app/Console/Commands/EbayDebugProduct.php` (new)

**Deploy steps (no migration needed):**
```bash
git reset --hard origin/main
composer install --no-dev
/opt/alt/php83/usr/bin/php artisan config:clear && /opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
```

**Debug command usage (SSH on production):**
```bash
/opt/alt/php83/usr/bin/php artisan ebay:debug-product 235776
/opt/alt/php83/usr/bin/php artisan ebay:debug-product 235776 --publish
```

---

## Backup system (scheduler wired — session 23a)

The backup command existed since session 8 but was never connected to the Laravel scheduler. Confirmed and wired up in session 23a.

**Commands available:**
- `php artisan backup:okelcor` — creates `storage/app/backups/okelcor-backup-<timestamp>.zip` (DB dump + file paths)
- `php artisan backup:status` — shows last backup time, size, archive list, disk space
- `php artisan backup:test` — pre-flight checks (DB, mysqldump, ZipArchive, disk space, paths)

**Files changed (session 23a):**
- `routes/console.php` — added `Schedule::command('backup:okelcor')` with all options (was missing entirely; only `Artisan::command('inspire')` placeholder existed)

**Schedule registered** (`routes/console.php`):
```php
Schedule::command('backup:okelcor')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/backup-schedule.log'));
```
Output appends to `storage/logs/backup-schedule.log` on the server.

**Server cron required** — add this once on Hostinger (cPanel → Cron Jobs):
```
* * * * * cd /home/u978121777/domains/okelcor.com/public_html/okelcor-api && /opt/alt/php83/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```
Without this cron, the scheduler never fires and backups never run.

**Config** (`config/backup.php`):
- `BACKUP_ENABLED` — defaults to `true`; set `false` to disable without removing the command
- `BACKUP_RETENTION_DAYS` — defaults to 14; prunes old archives after each run
- `MYSQLDUMP_PATH` — auto-detected; set explicitly if mysqldump is not in PATH
- Paths backed up: `storage/app/private`, `storage/app/public/invoices`, `storage/app/public/brands`, `storage/app/public/products`, `storage/app/public/promotions`, `storage/app/public/media`

**Note:** `backup:test` on local shows DB + mysqldump failures — MySQL is not running locally. Both pass on the production server.

---

**Session 21 — eBay 502 diagnosis: category mismatch + error surfacing (no migrations):**

**Root cause confirmed:** `EBAY_CATEGORY_ID=179680` is from **ebay.com (US)** (`ebay.com/b/Car-Truck-Tires/179680`) and is **NOT valid for EBAY_DE**. eBay rejects the offer create/update call with an API error. Backend returns 502 with the safe message but frontend was displaying a generic "eBay action failed" instead of reading the `message` field.

**Correct EBAY_DE category IDs for tyres:**
- `10183` — PKW-Reifen (passenger car tyres)
- `10209` — LKW/Bus-Reifen (truck/bus/TBR tyres)

**Action required on production — update .env:**
```
EBAY_CATEGORY_ID=10183   # for PCR tyres
```
Also verify EBAY_FULFILLMENT_POLICY_ID / EBAY_PAYMENT_POLICY_ID / EBAY_RETURN_POLICY_ID were fetched with EBAY_DE marketplace set. If policies were configured while wrong marketplace was active, re-fetch from GET /admin/ebay/policies after updating the category.

**Backend changes (session 21):**
- `EbayListingController`: added `extractEbayErrors(\Throwable $e): array` — parses eBay JSON response body embedded in exception messages; returns `[{errorId, domain, category, message, longMessage, parameters}]`
- `EbayListingController`: `listProduct`, `updateProduct`, `removeListing` 502 responses now include `data.ebay_errors[]` — frontend can read exact eBay error codes and messages
- `EbayListingController.safeError()`: added specific detection before generic patterns:
  - Category invalid for marketplace (errorIds 25002/25003/21917182/95500 + text patterns) → names the bad category ID and suggests EBAY_DE alternatives (10183/10209)
  - Policy not found / doesn't belong to marketplace (errorIds 20400/20402/25004/25005 + text) → directs to /admin/ebay/policies
  - Seller permission error → directs to Seller Hub
  - Image not accessible → mentions storage symlink
- `EbayListingController.readiness()`: added `category_marketplace_mismatch` check — fails if EBAY_MARKETPLACE_ID contains "DE" and EBAY_CATEGORY_ID is a known US-only category (179680, 6030); names the correct EBAY_DE alternatives

**Files changed (session 21):**
- `app/Http/Controllers/Admin/EbayListingController.php`

**Deploy steps (no migration needed):**
```bash
git reset --hard origin/main
composer install --no-dev
/opt/alt/php83/usr/bin/php artisan config:clear && /opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
```
Then on production update .env: `EBAY_CATEGORY_ID=10183`

---

**Session 20 — eBay error 932 diagnosis + backend hardening (no migrations):**

**Root cause confirmed:** Error 932 is a Trading API (XML/SOAP) error code. The backend uses only the Sell REST API — it cannot emit error 932. The source is the frontend `lib/ebay.ts` calling the old Trading API with an expired `EBAY_ACCESS_TOKEN` env var. Frontend audit and fix is a separate task.

**Backend gaps closed:**
- `EbaySellingService`: added `$tokenSource` property, set on every `getAccessToken()` call (`cache` | `db_token_{id}` | `env_fallback` | `none`)
- `EbaySellingService`: added `logEbayApiError()` private helper — called before every `throw` in API methods; logs `api_family=Sell API (REST)`, `operation`, `endpoint`, `http_status`, `token_source`, `ebay_errors` (parsed from response body)
- `EbaySellingService`: added `parseEbayErrors()` private helper — parses eBay REST `{"errors":[{"errorId":...}]}` and OAuth `{"error":"invalid_grant"}` shapes; returns raw snippet for non-JSON
- `EbayListingController.safeError()`: added detection for eBay REST 401 patterns (`errorId 1001`, `Invalid access token`, `invalid_token`, `IAF token`, `token is expired`, `invalid_grant`) → returns reconnect-prompt message
- `routes/api.php`: removed legacy route aliases `POST products/{id}/list-on-ebay` and `DELETE products/{id}/ebay-listing` — canonical routes are the only paths now

**Route count: 172** (was 174 — two legacy aliases removed)

**How to confirm 932 source on production:**
```bash
tail -n 200 storage/logs/laravel.log | grep "eBay"
```
If error 932 appears, it will NOT be in Laravel logs (backend never calls Trading API).
Check browser Network tab: if the failed request URL is a Next.js `/api/admin/...` route handler
(not `/api/v1/admin/...`), the Trading API call is happening client-side in `lib/ebay.ts`.

**Files changed (session 20):**
- `app/Services/EbaySellingService.php` — token source tracking, structured error logging before every throw, `logEbayApiError()` + `parseEbayErrors()` helpers
- `app/Http/Controllers/Admin/EbayListingController.php` — `safeError()` extended with eBay REST 401 patterns and `invalid_grant`
- `routes/api.php` — legacy `list-on-ebay` and `ebay-listing` aliases removed

**Deploy steps (no migration needed):**
```bash
git reset --hard origin/main
composer install --no-dev
/opt/alt/php83/usr/bin/php artisan config:clear && /opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
```

---

**Session 19 — Policies endpoint verification (no code changes):**

Confirmed `GET /api/v1/admin/ebay/policies` is working correctly end-to-end:
- Route registered ✓
- Token refresh + eBay Account API calls succeed (OAuth connected, `sell.account.readonly` scope active)
- `POST /api/v1/admin/ebay/test-connection` confirmed working — returns `"eBay connection is working. Token is valid and the API is reachable."`
- Response shape matches frontend spec exactly:
  ```json
  {
    "data": {
      "payment":     [{ "id": "...", "name": "..." }],
      "fulfillment": [{ "id": "...", "name": "..." }],
      "return":      [{ "id": "...", "name": "..." }]
    },
    "message": "success"
  }
  ```
- Partial failure behaviour confirmed: if one policy group fails, returns `[]` for that group; others succeed
- No backend changes needed — frontend can consume `data.payment`, `data.fulfillment`, `data.return` directly

---

**Session 18 deploy note (Phase EB-4 — eBay Settings Readiness Checklist):**

**No new database migrations.**

**Files changed (session 18):**
- `config/services.php` — added `seller_postal_code` (`EBAY_SELLER_POSTAL_CODE`) and `seller_location` (`EBAY_SELLER_LOCATION`) keys to `ebay_sell` config block
- `.env.example` — added `EBAY_SELLER_POSTAL_CODE` and `EBAY_SELLER_LOCATION`
- `app/Services/EbaySellingService.php` — added `accountBaseUrl()` private helper (eBay Account API); new `pingConnection(): array` calls `GET /inventory_item?limit=1` to verify token is valid; new `fetchPolicies(): array` calls eBay Account API for payment/fulfillment/return policies (sell.account.readonly scope), returns `[id, name]` per policy
- `app/Http/Controllers/Admin/EbayListingController.php` — new `readiness()` method (12 checks: credentials, connection, marketplace, category, 3 policy IDs, seller postal code, environment warning, live token test; returns structured checks + missing_config array; never exposes credential values); new `testConnection()` method (delegates to pingConnection, returns ok bool + safe message); new `policies()` method (delegates to fetchPolicies, returns payment/fulfillment/return arrays); extended `safeError()` with connection-test-failed pattern
- `routes/api.php` — added `GET ebay/readiness`, `POST ebay/test-connection`, `GET ebay/policies`

**New .env keys to set on production:**
```
EBAY_SELLER_POSTAL_CODE=your_postal_code
EBAY_SELLER_LOCATION=Germany
```

**Deploy steps (no migration needed):**
```bash
git reset --hard origin/main
composer install --no-dev
/opt/alt/php83/usr/bin/php artisan config:clear && /opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
```

---

**Session 17 deploy note (Phase EB-3 — eBay Price/Title Update Sync & Enhanced Validation):**

**No new database migrations.**

**Files changed (session 17):**
- `app/Services/EbaySellingService.php` — extracted `buildOfferBody(Product): array` private helper (shared by upsertOffer/updateListing/syncFull); new `updateListing(Product): array` — strict update (requires existing offer, calls guardProduct, upserts inventory item + PUT offer, does NOT re-publish, returns offer_id + listing_id); new `syncFull(Product): void` — permissive full sync (stock + price + title + description) used by sync-all batch; expanded `guardProduct()` with 7 new checks: eBay connection (`EbayToken::active()->exists()`), non-empty title, stock > 0, absolute image URL validation (http/https), marketplace ID configured, category ID configured
- `app/Http/Controllers/Admin/EbayListingController.php` — new `updateProduct(int $id)` method for PATCH endpoint; `listProduct()` now catches `\InvalidArgumentException` separately (logged as `validation_failed`, returns 422); `syncAll()` changed from `syncInventory()` to `syncFull()` (syncs price + title + description too); log payloads include `price` in sync actions; `safeError()` extended with 8 new patterns (no-offer, no-title, no-stock, invalid-image, marketplace/category not configured, syncFull failed)
- `routes/api.php` — added `PATCH products/{id}/ebay/update`

**Deploy steps (no migration needed):**
```bash
git reset --hard origin/main
composer install --no-dev
/opt/alt/php83/usr/bin/php artisan config:clear && /opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
```

---

**Session 16 deploy note (Phase EB-2 — eBay Listing Status Tracking & Sync Logs):**

**New database migrations:**
- `2026_05_14_000002_add_ebay_status_fields_to_products_table` — adds `ebay_offer_id`, `ebay_status`, `ebay_last_synced_at`, `ebay_sync_error` to `products`
- `2026_05_14_000003_create_ebay_listing_logs_table` — append-only audit log for all eBay listing actions

**Files changed (session 16):**
- `database/migrations/2026_05_14_000002_add_ebay_status_fields_to_products_table.php` — **NEW**
- `database/migrations/2026_05_14_000003_create_ebay_listing_logs_table.php` — **NEW**
- `app/Models/EbayListingLog.php` — **NEW** — append-only model (`UPDATED_AT = null`); BelongsTo Product + AdminUser
- `app/Models/Product.php` — added `ebay_offer_id`, `ebay_status`, `ebay_last_synced_at`, `ebay_sync_error` to `$fillable`; added `ebay_last_synced_at` datetime cast
- `app/Services/EbaySellingService.php` — `createOrUpdateListing()` return type changed `string → array` (`['listing_id', 'offer_id']`); new `getListingStatus(string $sku): array` fetches offer status from eBay and maps to internal values
- `app/Http/Controllers/Admin/EbayListingController.php` — `listProduct()` + `removeListing()` now update all 4 new product fields and write log entries; `syncAll()` updates `ebay_last_synced_at`, does best-effort status refresh, logs per-product success/failure; new `refreshStatus(int $id)` method; new `logs()` method with filters; `listings()` includes new status fields; `safeError()` helper maps exception messages to safe user-readable strings; `writeLog()` helper (try-catch — never blocks primary action)
- `routes/api.php` — added `GET ebay/logs`, `POST products/{id}/ebay/refresh-status`

**Deploy steps:**
```bash
git reset --hard origin/main
composer install --no-dev
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan config:clear && /opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
```

---

**Session 15 deploy note (Phase EB-1 — eBay OAuth & Token Stability):**

**New database migration:**
- `2026_05_14_000001_create_ebay_tokens_table` — creates `ebay_tokens` table with encrypted `access_token` + `refresh_token`, expiry timestamps, `is_active` flag, marketplace_id, connected_at, last_refreshed_at

**Files changed (session 15):**
- `database/migrations/2026_05_14_000001_create_ebay_tokens_table.php` — **NEW**
- `app/Models/EbayToken.php` — **NEW** — encrypted casts for access/refresh tokens; `scopeActive()`
- `app/Services/EbaySellingService.php` — `getAccessToken()` now loads from DB (active token) → persists any rotated refresh_token on every refresh call; fallback to `EBAY_REFRESH_TOKEN` env var (legacy only); `getAuthUrl()` renamed to `buildAuthUrl(string $state)`; new `exchangeCodeForTokens(string $code)` method exchanges auth code and creates DB token record; `cacheKey()` helper extracted
- `app/Http/Controllers/Admin/EbayListingController.php` — `authUrl()` now generates secure state (stored in cache 15 min); new `callback()` (public — redirects browser after eBay OAuth); new `status()` returns connection status + missing config; new `disconnect()` deactivates token + clears cache
- `routes/api.php` — added `GET admin/ebay/callback` (public, throttle:10,1); added `GET admin/ebay/status`, `POST admin/ebay/disconnect` inside `permission:ebay.manage`
- `.env.example` — `EBAY_REFRESH_TOKEN` marked as legacy fallback only

**IMPORTANT — security action required before deploy:**
- Rotate `EBAY_CLIENT_SECRET` in the eBay Developer Portal (it was exposed in a prior session)
- Set `EBAY_RU_NAME` to the redirect URI registered in the eBay Developer Portal
- The callback URL to register in the eBay Developer Portal is: `https://api.okelcor.com/api/v1/admin/ebay/callback`

**Deploy steps:**
```bash
git reset --hard origin/main
composer install --no-dev
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan config:clear && /opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
```

---

**Session 14 deploy note (Phase 2C-6 — Logistics Dashboard):**

**No new database migrations.**

**Files changed (session 14):**
- `app/Http/Controllers/Admin/AdminLogisticsController.php` — **NEW** — `dashboard()` method; builds 10-metric summary via COUNT queries; paginates non-cancelled orders with eager-loaded `tradeDocuments` (issued only) + `euDeclaration`; batch-loads `Invoice` records by `order_ref` to avoid N+1; per-order checklist: `checkDocuments()` (5 doc types), `computeMissing()` (business rules), `computeRiskLevel()` (high/medium/low/none), `computeNextAction()` (priority-ordered action string)
- `routes/api.php` — added `GET logistics/dashboard` under `permission:orders.view`

**Deploy steps (no migration needed):**
```bash
git reset --hard origin/main
composer install --no-dev
/opt/alt/php83/usr/bin/php artisan config:clear && /opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
```

---

**Session 13 deploy note (Phase 2C-5 — Send Trade Document by Email):**

**New database migration:**
- `2026_05_13_090256_add_document_sent_to_order_logs_action` — extends `order_logs.action` enum to include `document_generated`, `document_uploaded`, `document_deleted` (backfill — these were used in code but missing from original migration) and new `document_sent` value

**Files changed (session 13):**
- `app/Http/Controllers/Admin/AdminTradeDocumentController.php` — added `sendEmail()` method; validates `recipient_email` (nullable email) + `message` (nullable string max:1000); checks `pdf_path ?? file_path` exists on disk; defaults recipient to `order.customer_email`; sends `TradeDocumentEmail` mailable with attachment; stamps `sent_at`; logs `document_sent` to order_logs
- `app/Mail/TradeDocumentEmail.php` — new mailable; builds subject + label from document type; attaches file from private disk; passes `documentLabel` + `adminMessage` to views
- `resources/views/emails/trade-document-email.blade.php` — transactional HTML email; orange top border; document details table; optional admin note block (orange left border); attachment notice
- `resources/views/emails/trade-document-email-text.blade.php` — plain-text fallback
- `routes/api.php` — added `POST trade-documents/{id}/send-email` under `permission:trade_documents.manage`
- `database/migrations/2026_05_13_090256_add_document_sent_to_order_logs_action.php` — new

**Deploy steps:**
```bash
git reset --hard origin/main
composer install --no-dev
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan config:clear && /opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
/opt/alt/php83/usr/bin/php artisan view:clear
```

---

**Session 12 deploy note (Phase 2C-4 — Commercial Invoice):**

**No new database migrations** — uses existing `trade_documents` table.

**Files changed (session 12):**
- `app/Services/TradeDocumentService.php` — added `generateCommercialInvoiceForOrder()` method; idempotent on `commercial_invoice` + `status=issued`; stores PDF to `trade-documents/commercial-invoice/CI-YYYY-XXXX.pdf`
- `app/Http/Controllers/Admin/AdminTradeDocumentController.php` — added `generateCommercialInvoice()` method; wraps service in try/catch; writes `document_generated` order log; returns 201 new / 200 existing
- `routes/api.php` — added `POST orders/{id}/generate-commercial-invoice` under `permission:trade_documents.manage`
- `resources/views/pdf/commercial-invoice.blade.php` — new DomPDF template with export notice, seller/buyer, trade terms bar (incoterms, country of export, destination, carrier, tracking), items table with HS code + country of origin placeholders, totals, VAT/customs declaration block (reverse-charge / exempt / standard), signature + stamp blocks

**Deploy steps (no migration needed):**
```bash
git reset --hard origin/main
composer install --no-dev
/opt/alt/php83/usr/bin/php artisan config:clear && /opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
/opt/alt/php83/usr/bin/php artisan view:clear
```

---

**Session 11 deploy note (Phase 2C-1/2/3 — Trade Documents):**

**New database migration:**
- `2026_05_12_163809_add_type_label_to_trade_documents_table` — adds nullable `type_label` column to `trade_documents`

**Files changed (session 11):**
- `app/Services/TradeDocumentService.php` — added `packing_list` (PL-) and `delivery_note` (DN-) to PREFIXES; added `generatePackingListForOrder()` and `generateDeliveryNoteForOrder()` methods; fixed Invoice lookup from wrong `order_id` column to correct `order_ref` column
- `app/Http/Controllers/Admin/AdminTradeDocumentController.php` — added `generatePackingList()`, `generateDeliveryNote()`, `uploadShipmentDocument()`, `destroy()` methods; added `type_label` to `formatDocument()`; accepts `document_label` as alias for `type_label` on upload
- `app/Http/Controllers/OrderController.php` — added `delivery_note` and `shipment_document` to customer trade_documents whitelist; added `type_label`, `has_file`, `sent_at`, `original_filename`, `mime_type`, `file_size` to response shape
- `app/Http/Controllers/TradeDocumentController.php` — added `delivery_note` and `shipment_document` to customer whitelist; fixed download to check `file_path` as fallback for uploaded docs (was only checking `pdf_path`); added `type_label`, `has_file`, `mime_type`, `file_size` to response shape
- `app/Models/TradeDocument.php` — added `type_label` to `$fillable`
- `routes/api.php` — added `POST orders/{id}/generate-packing-list`, `POST orders/{id}/generate-delivery-note`, `POST orders/{id}/trade-documents/upload`, `DELETE trade-documents/{id}`
- `resources/views/pdf/packing-list.blade.php` — new DomPDF template (PL)
- `resources/views/pdf/delivery-note.blade.php` — new DomPDF template (DN) with EU reverse-charge Gelangensbestätigung notice
- `database/migrations/2026_05_12_163809_add_type_label_to_trade_documents_table.php` — new

**Deploy steps:**
```bash
git reset --hard origin/main
composer install --no-dev
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan config:clear && /opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
/opt/alt/php83/usr/bin/php artisan view:clear
```

---

**Session 10 deploy note (P1 Security Fixes):**

**No new database migrations.**

**Files changed (session 10):**
- `routes/api.php` — auth public group split into `throttle:auth` (register, login, reset-password) and `throttle:auth-email` (forgot-password, resend-verification); `GET /orders` and `GET /orders/{ref}` moved from public to `auth.customer`; `throttle:checkout` added to checkout route; `throttle:tracking` added to tracking route
- `app/Providers/AppServiceProvider.php` — added 4 named rate limiters: `auth` (10/min), `auth-email` (5/min), `checkout` (10/min by customer ID), `tracking` (30/min)
- `app/Http/Controllers/CustomerAuthController.php` — `recordLogin()` no longer accepts client-supplied `last_login_ip` or `last_login_at`; server IP and `now()` always used
- `app/Http/Controllers/OrderController.php` — `index()` uses `$request->user()->email` (token-derived) instead of `?email=` param; `show()` adds ownership check `WHERE customer_email = token email`

**Deploy steps (no migration needed):**
```bash
git reset --hard origin/main
composer install --no-dev
php artisan config:clear && php artisan config:cache
php artisan route:cache
```

---

**Session 9 deploy note (Security Audit Phase 1 + P0 fix):**

**No new database migrations** — this session's changes are middleware and migration-file-only.

**Files changed (session 9):**
- `app/Http/Middleware/EnsureAdminToken.php` — **NEW** — rejects any Sanctum token that is not an AdminUser instance (403)
- `bootstrap/app.php` — registered alias `auth.admin` → `EnsureAdminToken`
- `routes/api.php` — changed admin group from `middleware('auth:sanctum')` to `middleware(['auth:sanctum', 'auth.admin'])` — ALL 100+ admin routes now require AdminUser token
- `tests/Feature/AdminTokenGuardTest.php` — **NEW** — 16 passing tests covering customer-rejected/admin-passes/role-stacking
- `database/migrations/2026_03_30_000011_create_quote_requests_table.php` — renamed `idx_status` → `quote_requests_status_idx`, `idx_email` → `quote_requests_email_idx` (SQLite test compat only — no production effect)
- `database/migrations/2026_03_30_000012_create_contact_messages_table.php` — same pattern
- `database/migrations/2026_03_30_000013_create_orders_table.php` — same pattern
- `database/migrations/2026_03_30_000015_create_newsletter_subscribers_table.php` — same pattern
- `database/migrations/2026_04_19_185023_create_invoices_table.php` — same pattern

**Deploy steps (no migration needed):**
```bash
git reset --hard origin/main
composer install --no-dev
php artisan config:clear && php artisan config:cache
php artisan route:cache
```

**Session 8 migrations (all previously ran on production):**
- `2026_05_11_140000_backfill_rapid_cost_price_and_recalculate_prices` — ran, did nothing
- `2026_05_11_150000_force_rapid_prices_at_35pct` — applied 35% to Rapid price
- `2026_05_11_160000_fix_rapid_price_b2b_b2c_to_match_price` — aligned price_b2b/price_b2c

---

## Current Route Count: 174

### Customer Auth routes (public — no token)
```
POST   /api/v1/auth/register
POST   /api/v1/auth/login
POST   /api/v1/auth/forgot-password
POST   /api/v1/auth/reset-password
POST   /api/v1/auth/resend-verification
GET    /api/v1/auth/verify-email/{id}/{hash}    ← signed URL, redirects to frontend
```

### Customer routes (auth.customer middleware — Bearer token)
```
POST   /api/v1/auth/logout
GET    /api/v1/auth/me
PUT    /api/v1/auth/profile
PUT    /api/v1/auth/change-password

GET    /api/v1/auth/quotes       ← customer's own quote requests
GET    /api/v1/auth/invoices     ← customer's own invoices (released_at IS NOT NULL only)
GET    /api/v1/invoices/{id}/download  ← download invoice PDF (auth.customer Bearer token)

GET    /api/v1/auth/addresses
POST   /api/v1/auth/addresses
PUT    /api/v1/auth/addresses/{id}
DELETE /api/v1/auth/addresses/{id}

POST   /api/v1/auth/orders/{ref}/checkout              ← Pay Now — creates/refreshes Stripe Checkout Session for pending order
POST   /api/v1/auth/orders/{ref}/declaration           ← sign EU entry certificate (Gelangensbestätigung)
GET    /api/v1/auth/orders/{ref}/declaration/download  ← download signed declaration PDF

GET    /api/v1/orders/{ref}/trade-documents            ← customer's trade documents for an order (issued docs only)
GET    /api/v1/trade-documents/{id}/download           ← download a trade document PDF (auth.customer)

GET    /api/v1/orders                      ← customer's own orders (email from token — no ?email= param)
GET    /api/v1/orders/{ref}               ← single order — ownership verified via token email
```

#### GET /api/v1/auth/quotes — response shape
```json
{
  "data": [
    {
      "id": 1,
      "ref": "QT-2024-001",
      "created_at": "2024-04-01T10:00:00+00:00",
      "status": "pending",
      "product_details": "Michelin — 205/55R16",
      "quantity": "200",
      "notes": "Urgent delivery needed"
    }
  ]
}
```
Status mapping (internal → customer-facing):
`new` → `pending` | `reviewed` → `reviewed` | `quoted` → `approved` | `closed` → `rejected`

#### GET /api/v1/auth/invoices — response shape
```json
{
  "data": [
    {
      "id": 1,
      "invoice_number": "INV-2024-0042",
      "issued_at": "2024-04-01T00:00:00+00:00",
      "due_at": "2024-04-30T00:00:00+00:00",
      "released_at": "2024-04-01T00:00:00+00:00",
      "amount": 4850.00,
      "status": "paid",
      "order_ref": "OKL-AB123",
      "tax_treatment": "standard",
      "is_reverse_charge": false,
      "download_available": true,
      "pdf_url": "https://api.okelcor.com/api/v1/invoices/1/download"
    }
  ]
}
```
Status values: `paid` | `unpaid` | `overdue`

**Visibility gate:** only invoices where `released_at IS NOT NULL` are returned. For EU reverse-charge orders, `released_at` is `null` until admin acknowledges the EU Entry Certificate. Non-reverse-charge invoices have `released_at = now()` set at payment time (immediately visible).

**Lazy invoice creation:** `GET /auth/invoices` auto-creates any missing invoices for paid orders linked to the customer's email (covers the case where payment webhook fired before the customer account existed). Idempotent — safe to call on every request.

#### GET /api/v1/invoices/{id}/download
- Middleware: `auth.customer` — requires `Authorization: Bearer {customer_token}`
- Verifies `invoice.customer_id === authenticated customer.id` — 403 if mismatch
- Returns file as `Content-Disposition: inline; filename="INV-YYYY-NNNN.pdf"` — opens in browser tab, not forced download
- Error responses (all JSON):
  - 401 `"Unauthenticated."` — no/invalid token
  - 403 `"You do not have access to this invoice."` — wrong customer
  - 423 `"Invoice is not available until the EU Entry Certificate has been reviewed and acknowledged."` — `released_at` is null (reverse-charge orders pending admin acknowledgement)
  - 404 `"Invoice PDF is not available yet."` — `pdf_url` is null
  - 404 `"Invoice PDF file was not found."` — file missing on disk
- Controller: `InvoiceDownloadController@download`
- All 403/404/423 cases write `Log::warning` with `invoice_id`, `invoice_customer_id`, `auth_customer_id`, `pdf_url`

#### GET /api/v1/orders/{ref}/trade-documents
- Middleware: `auth.customer`
- Ownership: matches `order.customer_email` to `customer.email` (case-insensitive); 404 if no match
- Returns issued docs only: types `proforma`, `commercial_invoice`, `packing_list`, `delivery_note`, `shipment_document` with `status=issued`
- Response shape per item: `{ id, type, type_label, number, status, has_pdf, has_file, issued_at, sent_at, original_filename, mime_type, file_size }`

#### GET /api/v1/trade-documents/{id}/download
- Middleware: `auth.customer`
- Ownership: resolved via `order.customer_email`; 404 if no match (does not leak doc existence)
- Checks `pdf_path` first, falls back to `file_path` (for uploaded shipment docs)
- Returns file as download using `original_filename` as the filename
- Controller: `TradeDocumentController@download`

#### POST /api/v1/auth/orders/{ref}/checkout — Customer Pay Now
- Middleware: `auth.customer` — requires `Authorization: Bearer {customer_token}`
- Ownership: matches `order.customer_email` to `customer.email` (case-insensitive); returns 404 if no match (does not leak order existence)
- Guards:
  - 404 — order not found or wrong customer
  - 422 `"This order cannot be paid by Stripe."` — `payment_method ≠ stripe`
  - 409 `"This order is not awaiting payment."` — `payment_status ≠ pending`
  - 502 — Stripe API error
- Creates a new Stripe Checkout Session via `StripeService::createCheckoutSessionForOrder($order)`, saves `payment_session_id`
- Does NOT create an invoice — invoice is deferred to the Stripe webhook `checkout.session.completed`
- Controller: `CustomerOrderController@checkout`

Response (200):
```json
{
  "data": {
    "checkout_url": "https://checkout.stripe.com/...",
    "checkout_session_id": "cs_...",
    "order_ref": "OKL-XXXXX"
  }
}
```

### Public routes (no auth)
```
GET    /api/v1/products/brands
GET    /api/v1/products/specs
GET    /api/v1/articles
GET    /api/v1/articles/{slug}
GET    /api/v1/categories
GET    /api/v1/hero-slides
GET    /api/v1/brands
GET    /api/v1/settings/public
GET    /api/v1/settings
GET    /api/v1/search
POST   /api/v1/vat/validate
POST   /api/v1/payments/create-session
POST   /api/v1/payments/tax-preview        ← tax calculation preview (no order/session created)
POST   /api/v1/payments/webhook            ← Stripe webhook handler
GET    /api/v1/tracking/{container}        ← auto-detects DHL vs sea freight (throttle:tracking)
POST   /api/v1/orders
POST   /api/v1/contact
POST   /api/v1/newsletter/subscribe
GET    /api/v1/newsletter/confirm/{token}
POST   /api/v1/quote-requests
POST   /api/v1/admin/login
```

### Product catalogue — requires auth.customer
```
GET    /api/v1/products                    ← requires customer Bearer token
GET    /api/v1/products/{id}               ← requires customer Bearer token
```

### Product filter query params (GET /api/v1/products)
At least ONE of these is required or endpoint returns empty with message:
| Param | Behaviour |
|-------|-----------|
| `q` or `search` | Full-text across brand, name, size, sku |
| `brand` | Exact match e.g. `?brand=PIRELLI` |
| `type` | PCR / TBR / OTR / Used |
| `season` | Summer / Winter / All Season / All-Terrain |
| `size` | Partial match e.g. `?size=205/45R17` |
| `price_min` | `WHERE price >= value` |
| `price_max` | `WHERE price <= value` |
| `sort` | `price_asc`, `price_desc`, `newest` (default) |
| `page` | Pagination |
Max 50 per page. All responses include `Cache-Control: no-store`.

### Admin routes (auth:sanctum)
All under `/api/v1/admin/` — require `Authorization: Bearer {token}`.

Role hierarchy: `super_admin` > `admin` > `editor` | `order_manager`

```
POST   /admin/login                         ← public, issues token

GET    /admin/dashboard                     ← all roles — metrics endpoint

POST   /admin/logout                        ← all roles
GET    /admin/me                            ← all roles
GET    /admin/profile                       ← all roles
PUT    /admin/profile                       ← all roles (first_name, last_name, display_name, name, email)
PUT    /admin/profile/password              ← all roles (change password)
PUT    /admin/change-password               ← all roles (alias for profile/password — same method)

# User management — super_admin, admin
GET    /admin/users
POST   /admin/users
GET    /admin/users/{id}
PUT    /admin/users/{id}
DELETE /admin/users/{id}

# Content — super_admin, admin, editor
GET    /admin/products
POST   /admin/products
GET    /admin/products/{id}
PUT    /admin/products/{id}
DELETE /admin/products/{id}
POST   /admin/products/{id}/restore
POST   /admin/products/{id}/images
DELETE /admin/products/{id}/images/{image}

# Product CSV import/export — super_admin, admin only
POST   /admin/products/import
GET    /admin/products/export

GET    /admin/articles
POST   /admin/articles
GET    /admin/articles/{id}
PUT    /admin/articles/{id}
DELETE /admin/articles/{id}
POST   /admin/articles/{id}/image
POST   /admin/articles/{id}/restore

GET    /admin/categories
PUT    /admin/categories/{id}

GET    /admin/hero-slides
POST   /admin/hero-slides
GET    /admin/hero-slides/{id}
PUT    /admin/hero-slides/{id}
POST   /admin/hero-slides/{id}/media
DELETE /admin/hero-slides/{id}

GET    /admin/brands
POST   /admin/brands
GET    /admin/brands/{id}
PUT    /admin/brands/{id}
POST   /admin/brands/{id}/logo
DELETE /admin/brands/{id}

GET    /admin/media
POST   /admin/media
DELETE /admin/media/{id}

GET    /admin/settings
PUT    /admin/settings

# Operations — super_admin, admin, order_manager
GET    /admin/orders
GET    /admin/orders/{id}
PUT    /admin/orders/{id}
PATCH  /admin/orders/{id}/status
DELETE /admin/orders/{id}               ← super_admin only

# Order CSV import/export
POST   /admin/orders/import
GET    /admin/orders/export

GET    /admin/quote-requests
GET    /admin/quote-requests/{id}
PUT    /admin/quote-requests/{id}
PATCH  /admin/quote-requests/{id}/status
POST   /admin/quote-requests/{id}/convert-to-order   ← converts quoted quote to order

GET    /admin/eu-declarations
GET    /admin/eu-declarations/{id}
GET    /admin/eu-declarations/{id}/download          ← download signed PDF from private disk
POST   /admin/eu-declarations/{id}/acknowledge       ← mark declaration acknowledged; releases invoice + sends FinalInvoiceReleased email

# Trade documents — permission:trade_documents.manage
POST   /admin/orders/{id}/trade-documents/proforma      ← generate/fetch proforma invoice PDF (idempotent)
POST   /admin/orders/{id}/generate-commercial-invoice   ← generate/fetch commercial invoice PDF (idempotent)
POST   /admin/orders/{id}/generate-packing-list         ← generate/fetch packing list PDF (idempotent)
POST   /admin/orders/{id}/generate-delivery-note        ← generate/fetch delivery note PDF (idempotent)
POST   /admin/orders/{id}/trade-documents/upload        ← upload shipment doc (Bill of Lading, CMR, etc.)
GET    /admin/orders/{id}/trade-documents               ← list all trade docs for an order (all types/statuses)
GET    /admin/trade-documents/{id}/download             ← download any trade document file from private disk
POST   /admin/trade-documents/{id}/send-email           ← send document to customer by email with file attached; stamps sent_at; logs document_sent
DELETE /admin/trade-documents/{id}                      ← delete uploaded shipment_document only (generated PDFs protected)

# Logistics dashboard — orders.view (super_admin, admin, order_manager, sales_manager)
GET    /admin/logistics/dashboard              ← summary cards + paginated document checklist

GET    /admin/contact-messages
GET    /admin/contact-messages/{id}
PATCH  /admin/contact-messages/{id}/status

GET    /admin/newsletter
DELETE /admin/newsletter/{email}

# eBay marketplace — permission:ebay.manage (super_admin, admin)
# Callback is PUBLIC (no auth — eBay redirects browser here after OAuth consent)
GET    /admin/ebay/callback                          ← PUBLIC; verifies state, exchanges code, stores tokens in DB; redirects to frontend
GET    /admin/ebay/auth-url                          ← returns { url, state }; state stored in cache (15 min CSRF guard)
GET    /admin/ebay/status                            ← connection status + missing config keys
GET    /admin/ebay/readiness                         ← 12-check pre-listing checklist (pass/warning/fail per check) + missing_config[]; never exposes credential values
POST   /admin/ebay/test-connection                   ← refreshes token + pings Inventory API; returns { ok: bool, message }
GET    /admin/ebay/policies                          ← fetches payment/fulfillment/return policy [id, name] from eBay Account API for configured marketplace
POST   /admin/ebay/disconnect                        ← deactivates active token; clears cache; logs ebay_disconnected
GET    /admin/ebay/listings                          ← products where ebay_listed=true (includes ebay_status, ebay_last_synced_at, ebay_sync_error)
GET    /admin/ebay/logs                              ← paginated ebay_listing_logs; filters: product_id, sku, action, status, date_from, date_to
POST   /admin/ebay/sync-all                          ← bulk full sync (stock + price + title + description) + best-effort status refresh; logs each product individually
POST   /admin/products/{id}/ebay/list                ← publish product to eBay; sets ebay_status=active; logs publish/validation_failed/publish_failed (canonical)
PATCH  /admin/products/{id}/ebay/update              ← update existing eBay listing (price, title, stock); does NOT re-publish; requires ebay_listed=true; logs update/validation_failed/update_failed
DELETE /admin/products/{id}/ebay/remove              ← remove product from eBay; sets ebay_status=withdrawn; logs remove/remove_failed (canonical)
POST   /admin/products/{id}/ebay/refresh-status      ← fetch current eBay offer status; update ebay_status + ebay_last_synced_at; log refresh_status/refresh_status_failed

# Supplier intelligence — super_admin, admin, order_manager
GET    /admin/supplier/search?q={query}&limit={1-50}
GET    /admin/supplier/alibaba-link?q={query}
```

#### Admin login response shape
```json
{
  "data": {
    "token": "...",
    "user": {
      "id": 1,
      "name": "John Doe",
      "first_name": "John",
      "last_name": "Doe",
      "display_name": "John",
      "email": "john@okelcor.com",
      "role": "super_admin",
      "role_label": "Super Admin",
      "last_login_at": "2026-05-02T10:00:00+00:00",
      "must_change_password": false
    }
  }
}
```
- `role` — raw DB string, always one of: `super_admin` | `admin` | `editor` | `order_manager`
- `role_label` — human-readable: `Super Admin` | `Admin` | `Editor` | `Order Manager`
- `must_change_password` — `true` on first login after account creation; cleared to `false` after `PUT /admin/change-password`
- **Key name is `user`, NOT `admin`** — frontend must read `data.user.role`

#### GET /admin/me and GET /admin/profile — same user shape as above, directly under `data`
```json
{ "data": { "id": 1, "role": "editor", "role_label": "Editor", ... } }
```
Frontend reads: `response.data.data.role` (axios) or `response.data.role` (fetch after `.json()`)

#### PUT /admin/change-password — response includes updated user
```json
{
  "data": { ...user object with must_change_password: false... },
  "message": "Password changed successfully."
}
```
Frontend must update its auth store from this response to clear the "change password" banner.

#### POST /admin/users (super_admin only)
- No password field in request — backend generates a 16-char secure temp password
- Sets `must_change_password = true`
- Sends `AdminWelcome` email to new user with temp password + login URL
- Login URL comes from `FRONTEND_URL` env var
- Plain text password is never stored or returned after the email is sent

#### GET /admin/dashboard — response shape
All roles. Excludes Stripe test sessions (`cs_test_%`). Currency fields in EUR, rounded to 2 decimals.
```json
{
  "data": {
    "revenue_today": 0.00,
    "orders_today_total": 0,
    "orders_today_paid": 0,
    "conversion_rate": 0.0,
    "average_order_value": 0.00,
    "aov_period_label": "last 30 days",
    "aov_paid_orders_count": 0,
    "aov_stripe_orders_count": 0,
    "aov_manual_orders_count": 0,
    "new_customers_today": 0,
    "pending_orders": 0,
    "confirmed_revenue_month": 0.00,
    "pending_revenue": 0.00,
    "revenue_last_7_days": [
      { "date": "2026-04-28", "revenue": 0.00 },
      { "date": "2026-04-29", "revenue": 0.00 },
      { "date": "2026-04-30", "revenue": 0.00 },
      { "date": "2026-05-01", "revenue": 0.00 },
      { "date": "2026-05-02", "revenue": 0.00 },
      { "date": "2026-05-03", "revenue": 0.00 },
      { "date": "2026-05-04", "revenue": 0.00 }
    ]
  }
}
```
**Metric rules:**
- `revenue_today` — `SUM(total)` where `DATE(created_at)=today AND payment_status='paid' AND status!='cancelled'`
- `orders_today_total` — all orders today (test sessions excluded)
- `orders_today_paid` — paid non-cancelled orders today
- `conversion_rate` — `orders_today_paid / orders_today_total * 100`
- `average_order_value` — `SUM/COUNT` of paid non-cancelled orders in last 30 days
- `aov_manual_orders_count` — covers both Wix-imported and organic manual orders (`mode='manual'`)
- `new_customers_today` — `email_verified_at IS NOT NULL AND imported_from_wix=0`
- `pending_orders` — `status IN ('pending','confirmed')`
- `confirmed_revenue_month` — paid, not cancelled/refunded, current month
- `pending_revenue` — `payment_status='pending'` and not cancelled/failed
- `revenue_last_7_days` — always 7 elements, zero-filled for days with no revenue
- Controller: `AdminDashboardController@stats`

#### POST /admin/quote-requests/{id}/convert-to-order
Roles: `super_admin`, `admin`, `order_manager`

Guards:
- 422 if quote `status !== 'quoted'`
- 409 if `quote.order_id` is already set (duplicate prevention)

Request body (`delivery` object is optional — quote's stored address fields are used as fallback):
```json
{
  "delivery": {
    "address": "Musterstraße 1",
    "city": "Hamburg",
    "postal_code": "20095",
    "country": "Germany",
    "phone": "+49 170 1234567"
  },
  "items": [
    {
      "name": "Michelin Pilot Sport 4",
      "brand": "Michelin",
      "size": "205/55R16",
      "sku": null,
      "unit_price": 89.50,
      "quantity": 200
    }
  ],
  "delivery_cost": 0,
  "payment_method": "bank_transfer",
  "admin_notes": "Converted from quote OKL-QR-XXXXXX."
}
```

**Delivery address fallback chain:**
- `address` → `delivery.address` ?? `quote.delivery_address`
- `city` → `delivery.city` ?? `quote.delivery_city`
- `postal_code` → `delivery.postal_code` ?? `quote.delivery_postal_code`
- `country` → `delivery.country` ?? `quote.country`
- `customer_phone` → `delivery.phone` ?? `quote.phone`

Response (201):
```json
{
  "data": {
    "order_ref": "OKL-XXXXXX",
    "quote_ref": "OKL-QR-XXXXXX",
    "status": "confirmed",
    "payment_status": "pending",
    "total": 17900.00,
    "checkout_url": "https://checkout.stripe.com/..."
  },
  "message": "Quote converted to order successfully."
}
```
`checkout_url` is null for bank transfer orders; present for Stripe orders. Frontend should show a "Pay now" button if non-null.
```
- Creates order with `mode='manual'`, `status='confirmed'`, `payment_status='pending'`
- `payment_method` defaults to `bank_transfer` if not provided
- Tax calculated before transaction via `TaxService::calculate(country, vatValid, customerType)` — stored on order as `tax_treatment`, `tax_rate`, `tax_amount`, `is_reverse_charge`
- `customerType` inferred: `company_name` present → `b2b`; else linked `customer.customer_type`; else null (treated as B2C)
- `taxableBase = subtotal + delivery_cost`; `taxAmount = taxableBase × tax_rate / 100`; `total = taxableBase + taxAmount`
- If `payment_method=stripe`: calls `StripeService::createCheckoutSessionForOrder($order)`, saves `payment_session_id`, passes `checkout_url` to email — failure is caught, logged, and does NOT block the 201 response
- If `payment_method=bank_transfer`: auto-generates proforma invoice PDF via `TradeDocumentService::generateProformaForOrder()` — non-blocking (failure logged, never rolls back)
- Sets `quote_requests.order_id` = new order ID to prevent re-conversion
- Writes `OrderLog` entry with `action='status_changed'`, `new_value='confirmed'`, notes referencing quote ref
- Sends `QuoteConvertedToOrder` email to `quote.email` after transaction — failure is caught and logged, never rolls back the order
- Invoice (INV-YYYY-NNNN) is NOT auto-created on conversion — invoice is created by the Stripe webhook (Stripe path) or when admin marks `payment_status=paid` (bank transfer path)
- FormRequest: `ConvertQuoteToOrderRequest`

#### GET /admin/quote-requests/{id} — detail response fields
```json
{
  "data": {
    "id": 1,
    "ref_number": "OKL-QR-877755-MWM",
    "status": "quoted",
    "created_at": "...",
    "updated_at": "...",

    "full_name": "Hans Müller",
    "contact_person": "Anna Schmidt",
    "company_name": "Reifen GmbH",
    "company_address": "Industriestr. 12",
    "company_city": "Hamburg",
    "company_postal_code": "20095",
    "email": "...",
    "phone": "...",
    "country": "Germany",
    "business_type": "b2b",
    "vat_number": "DE123456789",
    "vat_valid": true,

    "tyre_category": "TBR",
    "brand_preference": "Michelin",
    "tyre_size": "315/80R22.5",
    "quantity": "200",
    "tyre_condition": "used",
    "used_tyre_grade": "grade_a",
    "used_tyre_notes": "No sidewall damage.",
    "tyre_items": [
      { "size": "315/80R22.5", "quantity": "200" },
      { "size": "385/65R22.5", "quantity": "100" }
    ],

    "budget_range": null,
    "delivery_location": "Hamburg port",
    "delivery_timeline": "4–6 weeks",
    "delivery_address": "Musterstraße 1",
    "delivery_city": "Hamburg",
    "delivery_postal_code": "20095",
    "incoterm": "DAP",
    "incoterm_type": "delivery_terms",

    "notes": "...",
    "admin_notes": "...",

    "order_id": 42,
    "order_ref": "OKL-XXXXXX",

    "has_attachment": true,
    "attachment_url": "https://api.okelcor.com/storage/quote-attachments/uuid.pdf",
    "attachment_name": "Invoice-KDQWK0JJ-0001.pdf",
    "attachment_original_name": "Invoice-KDQWK0JJ-0001.pdf",
    "attachment_size": 116643,
    "attachment_mime": "application/pdf"
  }
}
```
- `order_id` / `order_ref` — null until converted; non-null means already converted
- `business_type` — `"b2b"` or `"b2c"` from the quote request form; distinct from the linked customer's `customer_type`
- `vat_valid` — JSON boolean (`true` / `false` / `null`), never integer; the DB tinyint is explicitly cast via `(bool)` in the formatter to preserve JSON type correctness
- `tyre_size` + `quantity` — legacy single-row fields; kept for backwards compatibility
- `tyre_items` — null or decoded JSON array; cast as PHP array by model
- `tyre_condition` — `null`, `"new"`, or `"used"`; `used_tyre_grade` / `used_tyre_notes` only populated when condition is `used`
- `incoterm` / `incoterm_type` — nullable preferred logistics terms
- `company_address`, `company_city`, `company_postal_code` — company registered address (distinct from delivery address)
- `contact_person` — purchasing contact, may differ from `full_name`
- `delivery_address`, `delivery_city`, `delivery_postal_code` — nullable structured fields; supplement the free-text `delivery_location`
- `attachment_name` is an alias of `attachment_original_name` (both present for frontend compatibility)
- List response also includes `contact_person`, `business_type`, `tyre_condition`, `tyre_items`, `incoterm`, `order_id`, `has_attachment`, `delivery_address`, `delivery_city`, `delivery_postal_code`

---

## Import/Export — Key Notes

### Product import (`POST /admin/products/import`)
- Artisan command: `php artisan import:wix-products {file}`
- Upserts on `sku` — safe to re-run
- Parses tyre dimensions (width/height/rim/load_index/speed_rating) from product name
- Pattern: `205/45R 17 88Y` (space between R and rim number)
- Detects season from name keywords (Winter, All Season, All-Terrain, Summer)
- Detects type: PCR (default) or TBR (keywords: Truck, Bus, TBR, Heavy, Commercial, LT, Cargo)
- **Image download:** reads `productimageurl` column (semicolon-separated filenames from Wix CDN)
  - Downloads image 1 → stores to `storage/app/public/products/{uuid}.jpg` → saves relative path to `primary_image`
  - Downloads image 2 → creates `ProductImage` gallery record
  - Skips silently on failure — product data still imports
  - `set_time_limit(600)` + `memory_limit 512M` applied for large runs
  - Logs every 100 image downloads; summary table includes "Images downloaded" column
- Response: `{ data: { imported, updated, skipped, errors: [] } }`

### Standalone image download command
```bash
php artisan import:product-images {file}
```
- Downloads missing images for products already in DB that have `primary_image IS NULL`
- Safe to re-run — only targets null `primary_image`
- Shows progress bar + downloaded/failed summary

### Order import (`POST /admin/orders/import`)
- Artisan command: `php artisan import:wix-orders {file}`
- Logic lives in `WixOrderImportService` — controller calls service directly (no Artisan::call)
- Upserts on `order number` (Wix ref) — safe to re-run, items replaced each time
- BOM stripping applied to CSV headers
- Wix CSV column mapping (exact names Wix uses):
  - `Order number` → `ref`
  - `Contact email` → `customer_email`
  - `Billing name` → `customer_name`
  - `Billing phone` → `customer_phone`
  - `Billing address` → `address`
  - `Billing city` → `city`
  - `Billing zip/postal code` → `postal_code`
  - `Billing country` → `country`
  - `Payment method` → `payment_method`
  - `Shipping rate` → `delivery_cost`
  - `Total` → `total`
  - `Fulfillment status` → `status`
  - `Payment status` → `payment_status`
  - `Tracking number` → `tracking_number`
  - `Delivery time` → `estimated_delivery`
  - `Note from customer` → `admin_notes`
  - `Item` / `SKU` / `Qty` / `Price` → order items

### IMPORTANT — Upload directly to Laravel API (bypass Vercel)
Vercel has a hard 4.5 MB body size limit. Large CSV files must be uploaded directly to:
```
POST https://api.okelcor.com/api/v1/admin/products/import
POST https://api.okelcor.com/api/v1/admin/orders/import
```
NOT through the Vercel proxy.

---

## Schema — Full Table Reference

### `customers`
| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `customer_type` | enum | `b2c`, `b2b` — default `b2c` |
| `first_name` | varchar | |
| `last_name` | varchar | |
| `email` | varchar(255) | unique |
| `password` | varchar | hashed, hidden from API |
| `phone` | varchar(50) | nullable |
| `country` | varchar(100) | nullable |
| `company_name` | varchar(200) | nullable; required if b2b on register |
| `vat_number` | varchar(20) | nullable |
| `vat_verified` | tinyint | auto-set via VIES on register/profile update |
| `industry` | varchar(100) | nullable |
| `email_verified_at` | timestamp | nullable — must be set before login allowed |
| `must_reset_password` | tinyint | default 0 — blocks login until reset |
| `is_active` | tinyint | default 1 |
| `imported_from_wix` | tinyint | default 0 |

### `customer_addresses`
| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `customer_id` | bigint FK | cascade delete |
| `full_name` | varchar | |
| `address_line_1` | varchar | |
| `address_line_2` | varchar | nullable |
| `city` | varchar | |
| `postcode` | varchar(20) | |
| `country` | varchar | |
| `phone` | varchar(50) | nullable |
| `is_default` | tinyint | default 0 — only one default per customer |

### `invoices`
| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `customer_id` | bigint FK | cascade delete |
| `invoice_number` | varchar(50) | unique — format `INV-YYYY-NNNN` |
| `issued_at` | timestamp | |
| `due_at` | timestamp | nullable |
| `amount` | decimal(10,2) | |
| `status` | enum | `paid`, `unpaid`, `overdue` — default `unpaid` |
| `pdf_url` | varchar(500) | nullable — relative path e.g. `invoices/INV-2026-0001.pdf`; returned as absolute URL |
| `released_at` | timestamp | nullable — null = hidden from customer; set to `issued_at` for non-reverse-charge invoices; set by admin acknowledge for reverse-charge |
| `order_ref` | varchar(30) | nullable — unique per invoice |
| `subtotal_net` | decimal(10,2) | nullable — `order.subtotal + order.delivery_cost` |
| `tax_treatment` | varchar(30) | nullable — mirrors `orders.tax_treatment` |
| `tax_rate` | decimal(5,2) | nullable |
| `tax_amount` | decimal(10,2) | nullable |
| `is_reverse_charge` | tinyint | default 0 |
| `created_at` / `updated_at` | timestamp | |

Migration: `2026_05_08_000004_add_released_at_to_invoices_table` — adds column + backfill (non-RC → `issued_at`, RC with **acknowledged** declaration → `admin_acknowledged_at`, RC signed-only or pending → `null`). Migration: `2026_05_11_000001_correct_released_at_for_signed_only_declarations` — correction for local DBs that ran the original backfill; nulls `released_at` for RC invoices whose declaration is only `signed` (not `acknowledged`).

### `trade_documents`
Migrations:
- `2026_05_08_000003_create_trade_documents_table` — initial table
- `2026_05_12_163809_add_type_label_to_trade_documents_table` — adds `type_label` column

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `order_id` | bigint FK | cascade delete → orders |
| `order_ref` | varchar(30) | denormalized snapshot — readable after order deletion |
| `type` | varchar | `proforma`, `commercial_invoice`, `packing_list`, `delivery_note`, `shipment_document` |
| `type_label` | varchar(100) | nullable — human label for shipment_document (e.g. "Bill of Lading", "CMR") |
| `number` | varchar(50) | nullable — sequential document number e.g. `PI-2026-0001` |
| `status` | varchar | `draft`, `issued`, `cancelled` — default `draft` |
| `pdf_path` | varchar(500) | **hidden from API** — private disk path for generated PDFs |
| `file_path` | varchar(500) | **hidden from API** — private disk path for uploaded files |
| `original_filename` | varchar(255) | nullable — original filename for uploads and generated docs |
| `mime_type` | varchar(100) | nullable — MIME type of uploaded file |
| `file_size` | int | nullable — file size in bytes |
| `notes` | text | nullable — admin free-text notes |
| `issued_by` | bigint FK | nullable → nullOnDelete → admin_users |
| `issued_at` | timestamp | nullable — when document was issued |
| `sent_at` | timestamp | nullable — when document was sent to customer |
| `created_at` / `updated_at` | timestamp | |

Indexes: `order_id`, `order_ref`, `type`, `status`

**Model:** `App\Models\TradeDocument` — `pdf_path` and `file_path` are in `$hidden`; use `getRawOriginal('pdf_path')` / `getRawOriginal('file_path')` in controllers to access.

**Document number formats (sequential per year, `lockForUpdate()` in DB transaction):**
| Type | Prefix | Example |
|------|--------|---------|
| `proforma` | PI | `PI-2026-0001` |
| `commercial_invoice` | CI | `CI-2026-0001` |
| `packing_list` | PL | `PL-2026-0001` |
| `delivery_note` | DN | `DN-2026-0001` |

**Service:** `App\Services\TradeDocumentService`
- `generateProformaForOrder(Order $order, ?AdminUser $admin = null): TradeDocument` — idempotent; returns existing issued proforma or creates new; generates PDF via DomPDF; stored at `trade-documents/proforma/PI-YYYY-XXXX.pdf`
- `generateCommercialInvoiceForOrder(Order $order, ?AdminUser $admin = null): TradeDocument` — idempotent; same pattern; stored at `trade-documents/commercial-invoice/CI-YYYY-XXXX.pdf`; eager-loads `items.product`; includes invoice ref, trade terms bar, HS code/origin placeholders, customs declaration block
- `generatePackingListForOrder(Order $order, ?AdminUser $admin = null): TradeDocument` — idempotent; same pattern; stored at `trade-documents/packing-list/PL-YYYY-XXXX.pdf`; eager-loads `items.product` for tyre spec fields
- `generateDeliveryNoteForOrder(Order $order, ?AdminUser $admin = null): TradeDocument` — idempotent; same pattern; stored at `trade-documents/delivery-note/DN-YYYY-XXXX.pdf`; includes EU reverse-charge Gelangensbestätigung notice when `is_reverse_charge=true`
- All generation methods: PDF failure is non-blocking (logged as warning, DB record still returned)
- Invoice lookup in all generate methods: `Invoice::where('order_ref', $order->ref)` — NOT `order_id` (invoices table uses `order_ref` string FK, no `order_id` column)

**Auto-generation (bank transfer orders):**
- `AdminQuoteRequestController::convertToOrder()` — auto-generates proforma after bank_transfer quote conversion (non-blocking)

**PDF templates:**
- `resources/views/pdf/proforma-invoice.blade.php` — proforma invoice (pre-payment quotation doc)
- `resources/views/pdf/commercial-invoice.blade.php` — commercial invoice for export/customs; blue accent; export notice banner; trade terms bar (incoterms, country of export=Germany, destination, carrier, tracking); items with HS code + country of origin placeholders; customs declaration block (reverse-charge / exempt / standard); authorised signatory + stamp blocks
- `resources/views/pdf/packing-list.blade.php` — packing list with items table, weight placeholders, signature blocks
- `resources/views/pdf/delivery-note.blade.php` — delivery note with receipt confirmation + EU reverse-charge notice; uses `@php $rowClass` for alternating rows (DomPDF nth-child unreliable)

**Storage paths (all on `local` disk = `storage/app/private/`):**
| Type | Path |
|------|------|
| Proforma PDF | `trade-documents/proforma/PI-YYYY-XXXX.pdf` |
| Commercial invoice PDF | `trade-documents/commercial-invoice/CI-YYYY-XXXX.pdf` |
| Packing list PDF | `trade-documents/packing-list/PL-YYYY-XXXX.pdf` |
| Delivery note PDF | `trade-documents/delivery-note/DN-YYYY-XXXX.pdf` |
| Shipment document upload | `trade-documents/uploads/{order_ref}/{YmdHis}_{safe-slug}.{ext}` |

**Upload validation (shipment_document type):**
- `file` required, `mimes:pdf,jpg,jpeg,png,xls,xlsx,csv`, `max:20480` (20 MB)
- `type_label` required string max:100 — accepts either `type_label` or `document_label` field name (frontend alias)
- `notes` optional string max:500
- Filename sanitized: `Str::slug(basename, '_')` + timestamp prefix + original extension

**Admin endpoints:**
- `POST /admin/orders/{id}/trade-documents/proforma` — idempotent; 201 new / 200 existing
- `POST /admin/orders/{id}/generate-packing-list` — idempotent; 201 new / 200 existing
- `POST /admin/orders/{id}/generate-delivery-note` — idempotent; 201 new / 200 existing
- `POST /admin/orders/{id}/trade-documents/upload` — upload shipment doc; 201; logs `document_uploaded`
- `POST /admin/orders/{id}/generate-commercial-invoice` — idempotent; 201 new / 200 existing; logs `document_generated`
- `POST /admin/trade-documents/{id}/send-email` — send document by email with file attached; request: `{ recipient_email?: string, message?: string }`; defaults recipient to `order.customer_email`; 422 if no file; 404 if file missing on disk; 500 on mail failure; on success: stamps `sent_at`, logs `document_sent`, returns `{ data: { id, sent_at, recipient_email }, message }`
- `DELETE /admin/trade-documents/{id}` — delete uploaded shipment_document only; 422 if type is not `shipment_document` (generated PDFs are protected); deletes physical file then DB record; logs `document_deleted`
- `GET /admin/orders/{id}/trade-documents` — all docs for the order (all types + statuses)
- `GET /admin/trade-documents/{id}/download` — serves `pdf_path ?? file_path` from private disk; 404 if no file
- All write endpoints require `permission:trade_documents.manage`
- All return `formatDocument()` shape: `{ id, order_id, order_ref, type, type_label, number, status, has_pdf, has_file, original_filename, mime_type, file_size, notes, issued_by, issued_at, sent_at, created_at, updated_at }`
- All generate/upload endpoints write `document_generated` / `document_uploaded` to `order_logs` (try/catch — never blocks)

**Customer-facing filter:** types `proforma`, `commercial_invoice`, `packing_list`, `delivery_note`, `shipment_document` with `status='issued'` are shown via both customer endpoints.

**Customer download fix:** `GET /api/v1/auth/trade-documents/{id}/download` now falls back to `file_path` for uploaded docs (previously only checked `pdf_path`). Returns `original_filename` as the download filename.

**Order response — trade_documents[] shape (customer endpoints):**
```json
{
  "id": 6,
  "type": "delivery_note",
  "type_label": null,
  "number": "DN-2026-0001",
  "status": "issued",
  "has_pdf": true,
  "has_file": false,
  "issued_at": "2026-05-12T...",
  "sent_at": null,
  "original_filename": "DN-2026-0001.pdf",
  "mime_type": null,
  "file_size": null
}
```
For `shipment_document`: `type_label` = "Bill of Lading" etc., `has_pdf=false`, `has_file=true`, `number=null`, `mime_type` and `file_size` populated.

#### GET /admin/logistics/dashboard — response shape
Permission: `orders.view` (super_admin, admin, order_manager, sales_manager)

Query params: `status`, `payment_status`, `country`, `missing_document` (packing_list | commercial_invoice | shipment_document | delivery_note), `risk_level` (high — DB-filtered; medium/low approximate), `reverse_charge_only` (boolean), `date_from`, `date_to`, `per_page` (max 100, default 20)

```json
{
  "data": {
    "summary": {
      "total_active_orders": 42,
      "awaiting_payment": 5,
      "paid_orders": 37,
      "orders_shipped": 18,
      "orders_delivered": 12,
      "missing_packing_list": 3,
      "missing_commercial_invoice": 4,
      "missing_shipment_document": 2,
      "pending_eu_declarations": 1,
      "high_risk_orders": 1
    },
    "checklist": [
      {
        "order_id": 172,
        "order_ref": "OKL-XXXXXX",
        "customer_name": "Hans Müller",
        "customer_email": "hans@example.com",
        "country": "Germany",
        "status": "delivered",
        "payment_status": "paid",
        "is_reverse_charge": true,
        "total": "4850.00",
        "created_at": "2026-05-01T10:00:00+00:00",
        "documents": {
          "proforma": true,
          "commercial_invoice": true,
          "packing_list": true,
          "delivery_note": true,
          "shipment_document": false
        },
        "missing": ["shipment_document"],
        "eu_declaration": {
          "id": 8,
          "status": "signed",
          "signed_at": "2026-05-10T14:22:00+00:00",
          "admin_acknowledged_at": null
        },
        "invoice_number": "INV-2026-0042",
        "invoice_locked": true,
        "risk_level": "high",
        "next_action": "Acknowledge signed EU declaration"
      }
    ]
  },
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 42,
    "last_page": 3
  },
  "message": "success"
}
```

**Risk level rules:**
- `high` — reverse charge + delivered + declaration not acknowledged (or missing)
- `medium` — declaration signed but not yet acknowledged, OR missing shipment_document/delivery_note
- `low` — missing packing_list or commercial_invoice (paid order)
- `none` — all docs present, compliance complete

**Missing document rules (what triggers each entry in `missing[]`):**
- `packing_list` — `payment_status=paid`
- `commercial_invoice` — `payment_status=paid`
- `shipment_document` — `status` in `[shipped, delivered]`
- `delivery_note` — `status=delivered`

**N+1 prevention:** invoices batch-loaded via `Invoice::whereIn('order_ref', $refs)->keyBy('order_ref')`; trade documents and eu_declarations eager-loaded with the paginated query.

**Controller:** `AdminLogisticsController@dashboard`

---

**Public/customer order response includes `trade_documents[]`:**
- `GET /api/v1/orders/{ref}` (inline in order) — all issued types including delivery_note and shipment_document
- `GET /api/v1/auth/orders/{ref}/trade-documents` (standalone list) — same filter
- Admin `GET /admin/orders/{id}` — full array (all types/statuses/uploads)

### `eu_declarations`
Migration: `2026_05_07_200000_create_eu_declarations_table.php`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `order_id` | bigint FK | cascade delete → orders |
| `customer_id` | bigint FK | nullable → nullOnDelete → customers |
| `invoice_id` | bigint FK | nullable → nullOnDelete → invoices |
| `order_ref` | varchar(30) | denormalised snapshot — readable after order deletion |
| `company_name` | varchar(200) | snapshot at declaration creation |
| `customer_email` | varchar(255) | indexed snapshot |
| `customer_address` | text | snapshot: address, city, postal_code, country joined |
| `vat_number` | varchar(30) | snapshot |
| `country` | varchar(100) | snapshot |
| `goods_description` | text | auto-generated: "Qty× Brand Name Size" per item |
| `quantity_description` | varchar(300) | auto-generated summary e.g. "Total: 200 pcs across 2 lines" |
| `member_state_of_entry` | varchar(100) | nullable — filled by customer during signing |
| `place_of_entry` | varchar(200) | nullable |
| `month_year_received` | varchar(7) | nullable — MM/YYYY format |
| `self_transported` | tinyint | default 0 — boolean |
| `month_year_transport_ended` | varchar(7) | nullable — MM/YYYY |
| `representative_name` | varchar(200) | nullable |
| `representative_title` | varchar(100) | nullable |
| `signed_name` | varchar(200) | nullable — name as signed |
| `accepted_terms` | tinyint | default 0 — must be true to submit |
| `issue_date` | date | nullable — date declaration was issued |
| `signed_at` | timestamp | nullable — when customer submitted signing form |
| `signature_path` | varchar(500) | **hidden from API** — stored on private disk |
| `pdf_path` | varchar(500) | private disk — `eu-declarations/DECL-OKL-XXXXX.pdf` |
| `status` | enum | `pending`, `signed`, `acknowledged` — default `pending` |
| `admin_acknowledged_at` | timestamp | nullable |
| `admin_acknowledged_by` | bigint | nullable — admin user ID |
| `ip_address` | varchar(45) | **hidden from API** |
| `user_agent` | text | **hidden from API** |
| `created_at` / `updated_at` | timestamp | |

Indexes: `order_ref`, `status`, `customer_email`

**Trigger:** Declaration is created inside `InvoiceService::createForOrder()` — non-blocking, wrapped in try/catch — when `EuDeclarationService::shouldRequireForOrder()` returns true.

**shouldRequireForOrder conditions (all three must be true):**
- `order.is_reverse_charge === true`
- `order.tax_treatment === 'reverse_charge'`
- `(bool) order.vat_valid === true`

**Idempotency:** `EuDeclarationService::createForOrder()` returns existing record if one already exists for the order. If `invoice_id` was not set on the existing record, it is updated.

**Admin endpoints:**
- `GET /admin/eu-declarations` — paginated list; filterable by `status` and `q` (order_ref, company_name, email, vat_number); roles: super_admin, admin, order_manager
- `GET /admin/eu-declarations/{id}` — full detail including `has_signature` and `has_pdf` booleans; `signature_path` itself is never returned
- `GET /admin/eu-declarations/{id}/download` — download signed PDF from private disk; 404 if not signed or file missing
- `POST /admin/eu-declarations/{id}/acknowledge` — mark signed declaration as acknowledged; 409 if status !== `signed`; sets `status='acknowledged'`, `admin_acknowledged_at`, `admin_acknowledged_by`; **releases the linked invoice** (`released_at = now()`); sends `FinalInvoiceReleased` email to customer (non-blocking try/catch)

### `ebay_tokens`
Migration: `2026_05_14_000001_create_ebay_tokens_table`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `marketplace_id` | varchar(20) | default `EBAY_DE` |
| `seller_username` | varchar | nullable — not populated automatically yet |
| `access_token` | text | nullable — **encrypted** via Laravel `encrypted` cast |
| `refresh_token` | text | **encrypted** via Laravel `encrypted` cast |
| `access_token_expires_at` | timestamp | nullable |
| `refresh_token_expires_at` | timestamp | nullable — eBay default ~18 months |
| `scopes` | json | nullable — array of granted scope strings |
| `connected_at` | timestamp | nullable — when OAuth flow completed |
| `last_refreshed_at` | timestamp | nullable — updated on every token refresh |
| `is_active` | boolean | default true — index; only one active token used |
| `created_at` / `updated_at` | timestamp | |

**Token access order:** Cache (hot path, TTL = `expires_in - 60s`) → DB active record → `EBAY_REFRESH_TOKEN` env fallback (legacy only).

### `ebay_listing_logs`
Migration: `2026_05_14_000003_create_ebay_listing_logs_table`

Append-only audit log — no `updated_at`. Records survive product/user deletion (FK nullOnDelete).

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `product_id` | bigint FK | nullable → nullOnDelete → products |
| `admin_user_id` | bigint FK | nullable → nullOnDelete → admin_users |
| `sku` | varchar | nullable, indexed |
| `action` | varchar | `publish` \| `publish_failed` \| `remove` \| `remove_failed` \| `sync` \| `sync_failed` \| `refresh_status` \| `refresh_status_failed` |
| `ebay_item_id` | varchar | nullable |
| `ebay_offer_id` | varchar | nullable |
| `status` | varchar | nullable — `active` \| `draft` \| `error` \| `ended` \| `withdrawn` \| `unknown` |
| `error_message` | text | nullable — safe error string |
| `response_code` | smallint unsigned | nullable — HTTP status from eBay |
| `payload_summary` | json | nullable — e.g. `{ "stock": 5 }` |
| `created_at` | timestamp | auto-set, indexed |

**New product columns (Phase EB-2):**
`ebay_offer_id` (varchar, nullable), `ebay_status` (varchar, nullable), `ebay_last_synced_at` (timestamp, nullable), `ebay_sync_error` (text, nullable)

**eBay status values:** `active` | `draft` | `error` | `ended` | `withdrawn` | `unknown`

**eBay status → product field mapping:**
- Publish success → `ebay_status = active`
- Remove success → `ebay_status = withdrawn`
- Sync / refresh failure → `ebay_status = error`
- No offer found on eBay → `ebay_status = ended`
- Status check failed → `ebay_status = unknown`

**Token rotation:** every `getAccessToken()` call that hits eBay's token endpoint persists the new access_token and any rotated refresh_token back to the DB record.

**Model:** `App\Models\EbayToken` — `access_token` + `refresh_token` in `$hidden`; use `getRawOriginal()` if ever needed in service code (the service always reads via the model cast transparently).

---

### `products`
| Column | Type | Notes |
|--------|------|-------|
| `sku` | varchar(50) | unique |
| `brand` | varchar(100) | |
| `name` | varchar(200) | |
| `size` | varchar(50) | e.g. "205/45R17" |
| `spec` | varchar(50) | e.g. "88Y" |
| `season` | enum | Summer / Winter / All Season / All-Terrain |
| `type` | enum | PCR / TBR / Used / OTR |
| `cost_price` | decimal(10,2) | nullable — **permanent base/reference price** (Excel import value); never overwritten by sync or promotion recalculation |
| `price` | decimal(10,2) | derived: `ROUND(cost_price * (1 - discount_pct/100), 2)` — recalculated by PromotionPricingService when admin changes discount |
| `price_b2b` | decimal(10,2) | nullable — for Rapid: set equal to `price` by PromotionPricingService; new SyncRapidProducts imports leave this `null` so the service owns it |
| `price_b2c` | decimal(10,2) | nullable — same as `price_b2b` for Rapid products |
| `primary_image` | varchar | nullable, relative path e.g. `products/uuid.jpg` |
| `width` | varchar(10) | nullable |
| `height` | varchar(10) | nullable |
| `rim` | varchar(10) | nullable |
| `load_index` | varchar(10) | nullable |
| `speed_rating` | varchar(5) | nullable |
| `stock` | int | nullable |
| `is_active` | tinyint | default 1 |
| `sort_order` | int | default 0 |

### `quote_requests`
| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `customer_id` | bigint FK | nullable, nullifies on customer delete — links to `customers` table |
| `order_id` | bigint FK | nullable, nullifies on order delete — set when quote is converted to order |
| `ref_number` | varchar(30) | unique |
| `full_name` | varchar(200) | |
| `contact_person` | varchar(150) | nullable — purchasing manager / decision-maker |
| `company_name` | varchar(200) | nullable |
| `company_address` | varchar(300) | nullable — company registered/billing street address |
| `company_city` | varchar(100) | nullable |
| `company_postal_code` | varchar(30) | nullable |
| `email` | varchar(255) | indexed |
| `phone` | varchar(50) | nullable |
| `country` | varchar(100) | |
| `tyre_category` | varchar(100) | |
| `brand_preference` | varchar(200) | nullable |
| `tyre_size` | varchar(100) | nullable — legacy single-size field; kept for BC |
| `tyre_condition` | varchar(50) | nullable — `new` or `used` |
| `used_tyre_grade` | varchar(50) | nullable — `grade_a`, `grade_b`, `mixed` |
| `used_tyre_notes` | text | nullable — free-text condition notes |
| `quantity` | varchar(100) | free text — legacy; kept for BC |
| `tyre_items` | json | nullable — multi-row items: `[{"size":"315/80R22.5","quantity":"200"},…]` |
| `budget_range` | varchar(100) | nullable |
| `delivery_location` | varchar(300) | |
| `delivery_timeline` | varchar(100) | nullable |
| `incoterm` | varchar(10) | nullable — `DAP`, `DDP`, `EXW`, `FOB`, `CIF`, `Custom` |
| `incoterm_type` | varchar(30) | nullable — `delivery_terms`, `shipping_terms` |
| `notes` | text | |
| `status` | enum | `new`, `reviewed`, `quoted`, `closed` — internal values |
| `admin_notes` | text | nullable |
| `ip_address` | varchar(45) | nullable, hidden from API |
| `vat_number` | varchar(30) | nullable |
| `vat_valid` | tinyint | nullable |
| `delivery_address` | varchar(300) | nullable — structured delivery street address |
| `delivery_city` | varchar(100) | nullable — structured delivery city |
| `delivery_postal_code` | varchar(30) | nullable — structured delivery postal code |
| `attachment_path` | varchar(500) | nullable — relative path e.g. `quote-attachments/uuid.pdf` |
| `attachment_original_name` | varchar(255) | nullable — original filename from customer |
| `attachment_mime` | varchar(100) | nullable — MIME type of uploaded file |
| `attachment_size` | unsigned int | nullable — file size in bytes |

Migration: `2026_05_07_000001_add_rfq_fields_to_quote_requests_table.php` — adds 10 columns above (all nullable, safe to deploy to existing rows).

**Quote attachment upload:**
- Field: `attachment` (multipart/form-data), optional
- Accepted types: `pdf`, `csv`, `xls`, `xlsx` — max 10 MB
- Stored to `storage/app/public/quote-attachments/{uuid}.ext`
- Admin list + detail responses include: `attachment_url` (absolute), `attachment_name`, `attachment_original_name`, `attachment_mime`, `attachment_size`, `has_attachment` — all null/false when no file attached

**Quote tyre items:**
- Legacy single-row: `tyre_size` + `quantity` — still accepted, required for BC
- Multi-row: `tyre_items` JSON array — each entry `{ "size": string, "quantity": string }`
- Both can coexist; admin reads `tyre_items` for complex RFQs, `tyre_size`/`quantity` for simple ones
- `QuoteRequest` model casts `tyre_items` as `array`

**Quote status enum (corrected):**
`new` → `reviewed` → `quoted` → `closed`
Only quotes with `status='quoted'` can be converted to an order.

### `orders`
| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `ref` | varchar(30) | unique, Wix order number or `OKL-XXXXXX` |
| `customer_name` | varchar(200) | |
| `customer_email` | varchar(255) | indexed |
| `customer_phone` | varchar(50) | nullable |
| `address` | varchar(300) | |
| `city` | varchar(100) | |
| `postal_code` | varchar(20) | |
| `country` | varchar(100) | |
| `payment_method` | varchar(100) | nullable |
| `subtotal` | decimal(10,2) | |
| `delivery_cost` | decimal(10,2) | default 0 |
| `total` | decimal(10,2) | |
| `status` | enum | pending / confirmed / processing / shipped / delivered / cancelled |
| `payment_status` | enum | pending / paid / failed / refunded |
| `payment_session_id` | varchar(100) | nullable — Stripe Checkout Session ID |
| `mode` | enum | live / manual |
| `carrier` | varchar(100) | nullable |
| `carrier_type` | enum | sea / air / dhl / road — nullable |
| `tracking_number` | varchar(100) | nullable |
| `container_number` | varchar(30) | nullable |
| `tracking_status` | varchar(50) | nullable |
| `estimated_delivery` | date | nullable |
| `eta` | date | nullable |
| `vat_number` | varchar(20) | nullable |
| `vat_valid` | tinyint | nullable |
| `tax_treatment` | varchar(30) | nullable — `standard`, `reverse_charge`, `exempt` |
| `tax_rate` | decimal(5,2) | nullable — e.g. `19.00` |
| `tax_amount` | decimal(10,2) | nullable — computed: `taxable_base × tax_rate / 100` |
| `is_reverse_charge` | tinyint | default 0 — true for EU B2B with valid VAT |
| `admin_notes` | text | nullable |
| `ip_address` | varchar(45) | nullable, hidden from API |

**Order mode values:**
- `live` — Stripe checkout orders
- `manual` — Wix imported orders, organic manual orders, and quote-converted orders

**Order model relationships:**
- `items()` — `HasMany OrderItem`
- `euDeclaration()` — `HasOne EuDeclaration`
- `tradeDocuments()` — `HasMany TradeDocument` ordered by `created_at DESC`
- `quoteRequest()` — `HasOne QuoteRequest`

### `order_items`
| Column | Type | Notes |
|--------|------|-------|
| `order_id` | bigint FK | |
| `product_id` | bigint | nullable FK |
| `sku` | varchar(50) | nullable |
| `brand` | varchar(100) | |
| `name` | varchar(200) | |
| `size` | varchar(50) | |
| `unit_price` | decimal(10,2) | |
| `quantity` | int | |
| `line_total` | decimal(10,2) | |

### `order_logs`
Append-only audit trail — no `updated_at`, never mutated after insert.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `order_id` | bigint FK | nullable → nullOnDelete — log survives order deletion |
| `order_ref` | varchar(30) | denormalized — readable even after order hard-deleted |
| `admin_user_id` | bigint FK | nullable → nullOnDelete — log survives user deletion |
| `admin_user_email` | varchar(255) | nullable — denormalized |
| `action` | enum | `status_changed`, `cancelled`, `deleted`, `tracking_updated`, `payment_status_changed`, `document_generated`, `document_uploaded`, `document_deleted`, `document_sent` |
| `old_value` | varchar(100) | nullable — previous status/value |
| `new_value` | varchar(100) | nullable — new status/value |
| `notes` | text | nullable — optional context |
| `ip_address` | varchar(45) | nullable |
| `created_at` | timestamp | auto-set, no `updated_at` |

Indexes: `(order_id, created_at)`, `order_ref`.

### `admin_users`
| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `name` | varchar(200) | full name (legacy field — kept for compatibility) |
| `first_name` | varchar | nullable |
| `last_name` | varchar | nullable |
| `display_name` | varchar | nullable — shown in admin UI |
| `email` | varchar(255) | unique |
| `password` | varchar | hashed, hidden from API |
| `role` | enum | `super_admin`, `admin`, `editor`, `order_manager` — default `editor` |
| `last_login_at` | timestamp | nullable — updated on every successful login |
| `last_login_ip` | varchar(45) | nullable — updated on every successful login |
| `must_change_password` | tinyint | default 0 — set to 1 when super_admin creates account; cleared on first password change |
| `is_active` | tinyint | default 1 — inactive accounts cannot log in |
| `created_at` / `updated_at` | timestamp | |

### Translation tables (`article_translations`, `category_translations`, `hero_slide_translations`)
Locales ENUM: `en`, `de`, `fr`, `es`

---

## Features & Integrations

### Customer Authentication (Laravel Sanctum)
- **Separate from admin auth** — uses `auth.customer` middleware (`CustomerAuth.php`)
- Token resolved via `PersonalAccessToken::findToken()` scoped to `Customer` model
- Middleware alias: `auth.customer` registered in `bootstrap/app.php`

**Register flow:**
- Validates all fields; `company_name` required if `customer_type=b2b`
- If `vat_number` provided → validated against VIES API, sets `vat_verified`
- Sends verification email via `CustomerEmailVerification` mailable
- Customer cannot log in until `email_verified_at` is set

**Login flow:**
- Checks `is_active`, `email_verified_at`, `must_reset_password` before issuing token
- Returns `{ token, customer: { id, first_name, last_name, email, customer_type, company_name, vat_verified, country } }`

**Email verification:**
- Signed URL: `GET /api/v1/auth/verify-email/{id}/{hash}` (24hr expiry)
- On success: redirects to `{FRONTEND_URL}/login?verified=true`
- Route name: `verification.verify`

**Password reset:**
- Token stored in `password_reset_tokens` table (60 min expiry), hashed with `Hash::make`
- Reset link: `{FRONTEND_URL}/reset-password?token={token}&email={email}`

**Customer address management:**
- All 4 CRUD endpoints under `/api/v1/auth/addresses`
- Setting `is_default: true` on create/update automatically clears all other defaults
- Address queries scoped to authenticated customer — no cross-customer access possible

**Mailables:**
- `CustomerEmailVerification` → view: `emails.customer-verify-email`
- `CustomerPasswordReset` → view: `emails.customer-reset-password`

### Admin Authentication (Laravel Sanctum)
- Guard: `auth:sanctum` on all admin routes
- Model: `AdminUser` (separate from customer `User` model)
- Login: `POST /api/v1/admin/login` — public, no token required

**Login security:**
- Rate limited: 5 failed attempts per IP per minute via `RateLimiter` facade
- Failed attempts logged to `laravel.log`: email + IP (password never logged)
- Successful login logged: `Admin login: {email} from IP {ip}`
- Checks `is_active` — inactive accounts get 403 before token is issued
- Revokes all existing tokens before issuing new one

**Account creation (super_admin only):**
- No password field required — backend generates 16-char secure temp password via `Str::password(16)`
- Sets `must_change_password = true` on new account
- Sends `AdminWelcome` email with temp password and login URL
- Login URL uses `FRONTEND_URL` env var (not hardcoded) — update env when domain changes
- Plain text password is discarded after email is sent; never stored or returned

**Password change:**
- `PUT /admin/change-password` or `PUT /admin/profile/password` (same method, two paths)
- Validates current password, sets new password, sets `must_change_password = false`
- Revokes all other active sessions
- Returns updated user object — frontend should update auth store from this response to clear any "change password" banner

**Mailables:**
- `AdminWelcome` → view: `emails.admin-welcome`

### POST /api/v1/payments/tax-preview
Public endpoint (no auth required; authenticated customer token is optional).
Returns the tax breakdown that will be applied at checkout — no order or Stripe session is created.

Rate limit: `throttle:payments` (20/min). Controller: `PaymentController@taxPreview`.

Request:
```json
{
  "items": [{ "price": 29.40, "quantity": 1 }],
  "delivery_cost": 0,
  "country": "Germany",
  "vat_number": "DE118716043",
  "vat_valid": true,
  "customer_type": "b2b"
}
```

VAT validity resolution order:
1. `vat_valid` boolean provided → use it (skip VIES call)
2. `vat_number` provided, no `vat_valid` → call `VatValidationService::validate()`
3. Neither → `null` (B2C safe default)

Customer type resolution:
- Authenticated `auth.customer` Bearer token → `customer.customer_type` wins
- Else `request.customer_type`
- Else `null` (treated as B2C)

Response (200):
```json
{
  "data": {
    "subtotal_net": 29.40,
    "delivery_cost": 0.00,
    "tax_rate": 19.00,
    "tax_amount": 5.59,
    "tax_treatment": "standard",
    "is_reverse_charge": false,
    "total": 34.99,
    "note": null
  }
}
```

---

### Stripe Checkout Payment Gateway
- Active gateway: Stripe Checkout.
- Package: `stripe/stripe-php`.
- Config: `config/services.php` → `stripe.secret`, `stripe.webhook_secret`, `stripe.currency`.
- Env vars: `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_CURRENCY`.
- `StripeService::createCheckoutSession(array $orderData): array` — used by `POST /payments/create-session` (cart checkout).
- `StripeService::createCheckoutSessionForOrder(Order $order): array` — used by quote-to-order conversion (when `payment_method=stripe`) and customer Pay Now endpoint. Builds line items from `$order->items`, adds delivery row if `delivery_cost > 0`, adds VAT line item if `tax_amount > 0`.

**Flow:**
1. Frontend sends cart to `POST /api/v1/payments/create-session`.
2. `payment_method` field is **optional** in the request — defaults to `stripe` server-side. If provided, only `"stripe"` is accepted.
3. Backend validates, looks up DB prices, saves a `pending` order with `mode=live` and `payment_method=stripe`.
4. Backend calls Stripe Checkout Session API and stores the Checkout Session ID in `orders.payment_session_id`.
5. Returns `{ "data": { "provider": "stripe", "order_ref": "...", "checkout_session_id": "...", "checkout_url": "https://checkout.stripe.com/..." } }`.
6. Frontend redirects customer to `checkout_url`.
7. Stripe redirects customer to: `{FRONTEND_URL}/checkout/return?session_id={cs_xxx}&order_ref={OKL-xxx}`
8. Stripe sends webhook to `POST /api/v1/payments/webhook`.
9. Backend verifies `Stripe-Signature` header using `STRIPE_WEBHOOK_SECRET`.
10. Handled events:
    - `checkout.session.completed` → `payment_status=paid`, `status=confirmed`, creates invoice record + generates PDF, sends `OrderConfirmation` (with invoice number for non-RC orders) to customer + `OrderReceived` to `ORDER_EMAIL`
    - `payment_intent.payment_failed` → `payment_status=failed`, `status=cancelled`
    - `charge.refunded` → `payment_status=refunded`

**Invoice auto-creation (Stripe path):**
- Triggered inside `markOrderPaid()` after the order is confirmed
- Only created if a `Customer` account exists for `order.customer_email` — guest checkouts produce no invoice
- Invoice number: `INV-YYYY-NNNN` — sequence generated inside `DB::transaction` with `lockForUpdate()` on all same-year rows to prevent race conditions on concurrent webhook retries
- PDF generated immediately via `barryvdh/laravel-dompdf` (v3.1.2) and stored to `storage/app/public/invoices/{invoice_number}.pdf`
- `invoices.pdf_url` stores the **relative** path; `GET /api/v1/auth/invoices` returns an **absolute URL** via `url(Storage::url($path))`
- `released_at`: set to `now()` for non-reverse-charge orders (immediately visible); set to `null` for reverse-charge orders (gated until admin acknowledges EU Entry Certificate)
- Idempotency: if invoice record already exists with `pdf_url` set → return early; if `pdf_url` is null → skip record creation, re-run PDF generation only
- PDF failure is non-blocking — logged as warning; invoice record is still returned and email still sent
- Recovery: run `php artisan invoices:generate-missing-pdfs` to backfill any invoices where PDF generation failed

**Reverse-charge invoice visibility (Phase 2C-3):**
- Reverse-charge invoices are generated at payment time but `released_at = null`
- Customer cannot see invoice in list or download until admin acknowledges the EU Entry Certificate
- `GET /api/v1/auth/invoices` filters `WHERE released_at IS NOT NULL`
- `GET /api/v1/invoices/{id}/download` returns 423 (Locked) if `released_at IS NULL`
- Admin `POST /admin/eu-declarations/{id}/acknowledge` sets `released_at = now()` and sends `FinalInvoiceReleased` email

**Order status flow (Stripe path):**
```
pending → confirmed (Stripe webhook) → processing (admin sets manually) → shipped → delivered
```

**Webhook idempotency:**
- If `payment_status` is already `paid` when webhook fires, the handler skips DB update and email sending (Stripe retry protection).

**Debug log:**
- `StripeService` logs `Stripe checkout success_url` with `success_url` and `order_ref` immediately before calling the Stripe API. Grep: `tail -n 50 storage/logs/laravel.log | grep "Stripe checkout success_url"`

**Stripe test order identification:**
- Test sessions have `payment_session_id LIKE 'cs_test_%'`
- Dashboard metrics exclude them via `(payment_session_id IS NULL OR payment_session_id NOT LIKE 'cs_test_%')`
- To delete test orders manually: use `orders:delete-specific` or `orders:cleanup-test-stripe` Artisan commands

**Frontend return pages required:**
- `/checkout/return?session_id=...&order_ref=OKL-...` — success page, show order ref, tell customer to check email
- `/checkout/cancel` — payment cancelled page
- Note: webhook may fire slightly after the customer lands on `/checkout/return`. Do not rely on `payment_status=paid` being set immediately on page load.

**Legacy gateways:**
- Adyen code/package/config remain present but inactive until business account/API credentials are approved.
- Mollie code/config remain present, but `POST /api/v1/orders/mollie-webhook` returns HTTP 410 with `{ "message": "Mollie payments are currently disabled." }`.
- Do not use Adyen or Mollie unless explicitly re-enabled later.

### Transactional Emails

| Mailable | Trigger | Recipient | View |
|----------|---------|-----------|------|
| `OrderConfirmation` | Stripe webhook `checkout.session.completed` | customer | `emails/order-confirmation.blade.php` |
| `OrderReceived` | Stripe webhook `checkout.session.completed` | `ORDER_EMAIL` | `emails/order-received.blade.php` |
| `QuoteConvertedToOrder` | Admin converts quote to order | `quote.email` | `emails/quote-converted-to-order.blade.php` |
| `QuoteRequestReceived` | Customer submits quote (`POST /quote-requests`) | `QUOTE_EMAIL` | `emails/quote-request-received.blade.php` |
| `QuoteRequestAcknowledgement` | Customer submits quote (`POST /quote-requests`) | submitter's email | `emails/quote-request-acknowledgement.blade.php` |
| `AdminWelcome` | Super admin creates new admin user | new admin | `emails/admin-welcome.blade.php` |
| `CustomerEmailVerification` | Customer registers | customer | `emails/customer-verify-email.blade.php` |
| `CustomerPasswordReset` | Customer requests password reset | customer | `emails/customer-reset-password.blade.php` |
| `FinalInvoiceReleased` | Admin acknowledges EU Entry Certificate | customer | `emails/final-invoice-released.blade.php` |
| `TradeDocumentEmail` | Admin sends trade document (`POST /admin/trade-documents/{id}/send-email`) | `recipient_email` (default: `order.customer_email`) | `emails/trade-document-email.blade.php` |

- Manual order flow (`POST /api/v1/orders`) sends `OrderReceived` to admin only — no customer email on manual orders
- **Reverse-charge OrderConfirmation:** invoice block is suppressed (invoice null passed) — instead shows amber notice: "Your final invoice will be available after the EU Entry Certificate is signed."
- All sends are synchronous (`QUEUE_CONNECTION=sync`)
- All failures are wrapped in try/catch — email failure never rolls back the primary action
- Failures logged as `Log::error` (survives `LOG_LEVEL=error` on production)
- Config keys (not env() calls): `config('mail.quote_email')` → `QUOTE_EMAIL`, `config('mail.order_email')` → `ORDER_EMAIL`

**FinalInvoiceReleased email:**
- Subject: `Your final invoice is ready — {order_ref}`
- HTML view: `emails/final-invoice-released.blade.php` — orange accent, invoice details table, CTA button to `/account/invoices`
- Plain-text: `emails/final-invoice-released-text.blade.php`
- Variables: `declaration` (EuDeclaration), `invoice` (Invoice), `invoicesUrl` (frontend invoices page), `downloadUrl` (API download route)
- Compliance note: explains §6a UStG reverse-charge zero-rating

**QuoteRequestReceived (admin notification):**
- Subject: `New quote request — {ref_number}`
- Plain-text fallback: `emails/quote-request-received-text.blade.php`
- Reply-To header: customer's email — admin can reply directly from inbox
- Four labelled sections:
  - **CONTACT:** full_name, contact_person, company_name, company_address/city/postal_code, email, phone, country, business_type, vat_number with VERIFIED/NOT VERIFIED badge (green/red)
  - **TYRE REQUEST:** category, brand_preference, tyre_condition, used_tyre_grade/used_tyre_notes (only when condition=used), tyre_items loop with `@foreach`; falls back to legacy tyre_size/quantity when tyre_items is empty
  - **LOGISTICS:** delivery_location, delivery_address/city/postal_code, delivery_timeline, incoterm + incoterm_type, budget_range
  - **NOTES/ATTACHMENT:** notes, attachment name if present
- Sent to: `config('mail.quote_email')` — skipped silently if not set

**QuoteRequestAcknowledgement (customer auto-reply):**
- Subject: `We received your quote request — {ref_number}`
- Plain-text fallback: `emails/quote-request-acknowledgement-text.blade.php`
- "Your request summary" section: ref_number, submitted timestamp, tyre_category, brand_preference, tyre_condition, tyre_items loop (same pattern — falls back to legacy), delivery_location, incoterm, delivery_timeline, vat_number (if provided)
- Does NOT expose: business_type, company_address, contact_person, budget_range (internal fields)
- "What happens next?" 3-step block: review → quote → order

**QuoteConvertedToOrder email:**
- Subject: `Your quote has been converted to an order — {order_ref}`
- Shows quote_ref + order_ref, date, payment method, amber Pending badge
- Full items table with tax breakdown: Subtotal (net) / Delivery (if > 0) / VAT row / Total gross — guarded by `$order->tax_treatment !== null`; old orders (null) show total only
- If `$checkoutUrl` is set (Stripe orders): "Pay securely with Stripe" CTA button + Stripe next steps
- If no `$checkoutUrl` (bank transfer): bank transfer next steps block
- Sent to: `quote.email`

**OrderConfirmation email:**
- Tax breakdown in tfoot: Subtotal (net) / Delivery / VAT / Total — guarded by `$order->tax_treatment !== null`
- Optional invoice block (invoice number + link to `/account/invoices`) — omitted if invoice is null (guest checkout or reverse-charge order)
- Reverse-charge paid orders: amber compliance notice explaining invoice will be released after EU Entry Certificate signing + admin acknowledgement

**All email templates:**
- Plain transactional HTML — white background, 3px orange top border, no image assets
- Contact footer: `support@okelcor.com`
- Env vars required: `ORDER_EMAIL=support@okelcor.com`, `QUOTE_EMAIL=support@okelcor.com`

### Tax / VAT Calculation (TaxService)
- `App\Services\TaxService::calculate(?string $country, ?bool $vatValid, ?string $customerType = null): array`
- Returns: `['tax_treatment' => string, 'tax_rate' => float, 'is_reverse_charge' => bool, 'note' => string]`

**Decision tree:**
| Country | Customer | VAT valid? | Treatment | Rate |
|---------|----------|-----------|-----------|------|
| Germany (`DE`) | any | any | `standard` | 19% |
| EU member | B2B | yes | `reverse_charge` | 0% |
| EU member | B2B/B2C | no/null | `standard` | 19% |
| Non-EU | any | any | `exempt` | 0% |
| Unknown/null | any | any | `exempt` | 0% (safe default) |

- EU detection: `TaxService::isEu(?string $country): bool` — covers all 27 EU states + `XI` (Northern Ireland)
- EU excl. Germany: `TaxService::isEuCountryExceptGermany(?string $country): bool` — same but excludes `DE`
- EU VAT requirement check: `TaxService::requiresEuVat(?string $country, ?string $customerType): bool` — returns true when B2B + EU non-DE
- Country normalisation: `TaxService::resolveCountryCode(string $country): ?string` — accepts ISO codes, English names, German names (e.g. `"Deutschland"` → `"DE"`)
- `null` country → `exempt` (safe, avoids under-collection)
- `null` customerType → treated as B2C (standard rate, safe default)
- Wired into: `PaymentController::createSession()` (Stripe cart checkout) and `AdminQuoteRequestController::convertToOrder()` (quote conversion)
- Tax fields stored on `orders`: `tax_treatment`, `tax_rate`, `tax_amount`, `is_reverse_charge`
- Tax fields copied to `invoices` on creation: `subtotal_net`, `tax_treatment`, `tax_rate`, `tax_amount`, `is_reverse_charge`
- Invoice PDF (`pdf/invoice.blade.php`) and order confirmation / quote-converted emails show full tax breakdown

### EU Entry Certificate — Gelangensbestätigung (§17a UStDV)

Required for all reverse-charge EU B2B orders where `is_reverse_charge=true` and VAT is validated. This is the German legal proof that goods actually arrived in the EU member state (Gelangensbestätigung).

**Workflow:**
1. Customer places order → payment confirmed → `InvoiceService::createForOrder()` fires
2. Invoice created with `released_at = null` (reverse-charge gating)
3. `EuDeclarationService::shouldRequireForOrder()` checks the three conditions → if true, `createForOrder()` creates the `eu_declarations` record (status=`pending`)
4. Frontend shows a "Complete your declaration" banner on the customer account order page **only when** `declaration_required=true && declaration_status='pending' && payment_status='paid' && order.status='delivered'`
5. Customer clicks → goes to signing form at `/account/orders/{ref}/declaration` (Next.js page)
6. Customer fills & signs → `POST /api/v1/auth/orders/{ref}/declaration`
7. Backend validates guards, saves signing fields, stores signature PNG, generates PDF → status=`signed`; invoice `released_at` is **NOT** set here
8. Admin reviews via `GET /admin/eu-declarations` → marks acknowledged via `POST /admin/eu-declarations/{id}/acknowledge` → status=`acknowledged`; **sets `invoice.released_at = now()`**; sends `FinalInvoiceReleased` email to customer
9. Customer can now see and download their invoice

**Phase 2B-2 (DONE):** Migration, `EuDeclaration` model, `EuDeclarationService` (create + should-require logic), `AdminEuDeclarationController` (list + show), declaration fields wired into admin and public order detail responses.

**Phase 2B-3 (DONE):** Customer signing endpoint `POST /auth/orders/{ref}/declaration`, `SignEuDeclarationRequest` FormRequest, signature PNG stored to private disk, DomPDF PDF generation, `EuDeclarationSigned` mailable (HTML + plain-text), admin download `GET /admin/eu-declarations/{id}/download`, admin acknowledge `POST /admin/eu-declarations/{id}/acknowledge`, customer download `GET /auth/orders/{ref}/declaration/download`, public/customer order response updated with `declaration_signed_name` + `declaration_download_available`.

**Phase 2C-3 (DONE):** Invoice release gating via `released_at` column. Admin acknowledge now releases invoice and sends `FinalInvoiceReleased` email. **Compliance rule (final):** signing the declaration does NOT release the invoice — only admin acknowledgement does. Applies equally to Stripe and bank_transfer. Non-RC invoices are released immediately at payment.

**Signing endpoint — `POST /api/v1/auth/orders/{ref}/declaration`:**
- Auth: `auth.customer` Bearer token
- Ownership: `order.customer_email === customer.email` (case-insensitive); 404 if no match (does not leak order existence)
- **Missing row auto-create:** if no `eu_declarations` row exists for the order (common for orders placed before Phase 2B-2 was deployed), `EuDeclarationService::shouldRequireForOrder()` is called; if the order qualifies (all three conditions met) the row is created on the spot and signing continues; if the order does NOT qualify returns 422 `"This order does not require an EU entry certificate."`
- Guards:
  - 404 — order not found or wrong customer
  - 409 — declaration already signed or acknowledged
  - 422 — order does not require a declaration
  - 422 `"Payment must be confirmed before the EU Entry Certificate can be signed."` — `order.payment_status !== 'paid'`
  - 422 `"The EU Entry Certificate can only be signed after the order has been delivered."` — `order.status !== 'delivered'`
  - 422 — validation failure (FormRequest)
- Stores signature PNG to `storage/app/private/eu-declarations/signatures/{uuid}.png`
- Generates PDF to `storage/app/private/eu-declarations/pdf/{order_ref}.pdf` via DomPDF — non-blocking (failure logged, 200 still returned)
- Sends `EuDeclarationSigned` mailable to `declaration.customer_email` — non-blocking
- Sets `declaration.status = 'signed'`; does NOT release the invoice (`released_at` stays null)
- Returns 200: `{ status, signed_at, order_ref, has_pdf }`

**Customer download — `GET /api/v1/auth/orders/{ref}/declaration/download`:**
- Auth: `auth.customer` Bearer token; ownership verified same as above
- 404 if declaration not signed/acknowledged; 404 if pdf_path null or file missing on disk
- Returns file: `DECL-{order_ref}.pdf`

**Admin download — `GET /api/v1/admin/eu-declarations/{id}/download`:**
- Auth: `auth:sanctum` + `admin.role:super_admin,admin,order_manager`
- 404 if not signed/acknowledged; 404 if pdf missing; returns `DECL-{order_ref}.pdf`

**Admin acknowledge — `POST /api/v1/admin/eu-declarations/{id}/acknowledge`:**
- Auth: `auth:sanctum` + `admin.role:super_admin,admin,order_manager`
- 409 if status !== `signed`; sets `status='acknowledged'`, `admin_acknowledged_at`, `admin_acknowledged_by`
- Finds linked invoice: `$decl->invoice ?? Invoice::where('order_ref', $decl->order_ref)->first()`
- Sets `$invoice->released_at = now()` (makes invoice visible to customer)
- Sends `FinalInvoiceReleased` email to `declaration.customer_email` (non-blocking try/catch)
- Returns updated declaration detail

**Back-fill in order detail — `GET /api/v1/orders/{ref}` (show only, not list):**
- After loading the order, if `is_reverse_charge=true` and `euDeclaration` relation is null, calls `EuDeclarationService::createForOrder()` to create the pending row immediately
- `setRelation()` sets it on the in-memory model so the response always returns `declaration_status='pending'`
- The list endpoint (`GET /api/v1/orders?email=`) does NOT auto-create (would create rows for all old orders)

**Files created/modified (Phase 2B-3 + 2C-3 + compliance adjustment):**
- `app/Http/Requests/SignEuDeclarationRequest.php` ← new
- `app/Http/Controllers/EuDeclarationController.php` ← new; payment/delivery guards added; invoice release removed (moved to admin acknowledge)
- `resources/views/pdf/eu-declaration.blade.php` ← new — §17a UStDV Gelangensbestätigung layout
- `app/Mail/EuDeclarationSigned.php` ← new
- `resources/views/emails/eu-declaration-signed.blade.php` ← new
- `resources/views/emails/eu-declaration-signed-text.blade.php` ← new
- `app/Mail/FinalInvoiceReleased.php` ← new (Phase 2C-3)
- `resources/views/emails/final-invoice-released.blade.php` ← new (Phase 2C-3)
- `resources/views/emails/final-invoice-released-text.blade.php` ← new (Phase 2C-3)
- `app/Http/Controllers/Admin/AdminEuDeclarationController.php` ← added `download()` + `acknowledge()` + invoice release + FinalInvoiceReleased email
- `app/Http/Controllers/OrderController.php` ← injected `EuDeclarationService`; added declaration fields + trade_documents to `formatOrder()`; back-fill in `show()`
- `routes/api.php` ← added 9 new routes (2 EU declaration customer + 2 EU declaration admin + 5 trade document routes)

**Storage — private disk:**
- `local` disk root: `storage_path('app/private')`
- Signatures: `storage/app/private/eu-declarations/signatures/{uuid}.png`
- PDFs: `storage/app/private/eu-declarations/pdf/{order_ref}.pdf`
- Physical path for serving: `storage_path('app/private/' . $decl->pdf_path)`

**Service:** `App\Services\EuDeclarationService`
- `shouldRequireForOrder(Order $order): bool` — all three conditions: `is_reverse_charge=true`, `tax_treatment='reverse_charge'`, `(bool)vat_valid=true`
- `createForOrder(Order $order, ?Invoice $invoice = null): EuDeclaration` — idempotent; called by `InvoiceService` at payment time AND on-demand by `EuDeclarationController` and `OrderController::show()` for pre-2B-2 orders
- `buildGoodsDescription(Order $order): [string, string]` — returns [goods_description, quantity_description]; quantity_description truncated to 300 chars

**Controllers injecting `EuDeclarationService`:**
- `EuDeclarationController` — via constructor; used to auto-create missing declaration before signing
- `OrderController` — via constructor; used to back-fill missing row in `show()` only

### Trade Documents — Generated + Uploaded (Phase 2C-1/2/3)

Trade documents are commercial shipping/customs documents distinct from tax invoices (INV-YYYY-NNNN).

**Supported types:**
| Type | Number prefix | Generated/Uploaded | Customer visible |
|------|--------------|-------------------|-----------------|
| `proforma` | PI- | Generated (DomPDF) | Yes |
| `commercial_invoice` | CI- | Generated (DomPDF) | Yes |
| `packing_list` | PL- | Generated (DomPDF) | Yes |
| `delivery_note` | DN- | Generated (DomPDF) | Yes |
| `shipment_document` | none | Uploaded by admin | Yes |

**Key files:**
- `app/Models/TradeDocument.php` — `$hidden = ['pdf_path', 'file_path']`; use `getRawOriginal()` in controllers
- `app/Services/TradeDocumentService.php` — five idempotent generate methods; `PREFIXES` array defines number format
- `resources/views/pdf/proforma-invoice.blade.php` — proforma DomPDF template
- `resources/views/pdf/commercial-invoice.blade.php` — commercial invoice DomPDF template (export notice, trade terms bar, customs declaration, sig blocks)
- `resources/views/pdf/packing-list.blade.php` — packing list DomPDF template (items + weight block + sig blocks)
- `resources/views/pdf/delivery-note.blade.php` — delivery note DomPDF template (receipt confirmation + EU RC Gelangensbestätigung notice)
- `app/Http/Controllers/Admin/AdminTradeDocumentController.php` — all admin endpoints (generate/upload/delete/download/list)
- `app/Http/Controllers/TradeDocumentController.php` — customer list + download endpoints

### Rapid Product Pricing — Auto-Recalculation (Session 8)

Rapid-brand products use a cost-plus discount model. The fields are:
- `cost_price` — permanent base (Excel import value). Never overwritten after initial sync.
- `price` — derived: `ROUND(cost_price * (1 - discount_pct/100), 2)`
- `price_b2b` / `price_b2c` — set equal to `price` for Rapid (no B2B/B2C tier differentiation).

**PromotionPricingService** (`app/Services/PromotionPricingService.php`):
- `recalculateForPromotion(Promotion $promotion): int` — bulk-updates all products where `brand = $promotion->brand_name` and `cost_price IS NOT NULL` and `deleted_at IS NULL`.
- Uses a single `DB::table()->update()` with `DB::raw("ROUND(cost_price * {$factor}, 2)")` for `price`, `price_b2b`, and `price_b2c`.
- Returns count of updated rows.

**Hook in AdminPromotionController:**
- On `PUT /admin/promotions/{id}`, if `discount_pct` changed AND `brand_name` is set → PromotionPricingService fires automatically.
- Response includes `recalculated_products` count when recalculation ran.

**Migration history (session 8 — all ran on production):**
- `2026_05_11_140000` — backfill `cost_price` from `price` for Rapid; then try to apply discount. **Silent fail** — the migration had a guard (`if (! $promo) return;`) that short-circuited when the promotion lookup returned nothing. Migration was recorded as run but did nothing.
- `2026_05_11_150000` — hardcoded 0.65 factor (35% discount), no promotion lookup dependency; updated `price` only for Rapid products. **First version** ran before price_b2b/b2c were added to the UPDATE.
- `2026_05_11_160000` — final fix: `SET price_b2b = price, price_b2c = price WHERE brand = 'Rapid' AND deleted_at IS NULL`. Aligned all three price fields.

**Current state (production):** All 37 Rapid products have `price = price_b2b = price_b2c = ROUND(cost_price * 0.65, 2)` (35% off). Frontend `resolvePrice()` is field-selection only (picks between price_b2b / price_b2c / price by customer type) — no client-side arithmetic.

**SyncRapidProducts command change:** `createProduct()` now sets `price_b2b = null`, `price_b2c = null` for new rows. This prevents future Excel syncs from overwriting the promotion-calculated values with raw supplier prices.

---

### Incoterms / Delivery Terms — FOB-First Model (Session 8)

Replaced all hardcoded `"CIF"` defaults with a professional FOB-first logistics model across 8 files.

**Single source of truth:** `config('payment.bank_transfer.delivery_term')` = `"Incoterms 2020: FOB Germany unless otherwise agreed in writing."`

**Incoterm formatting rule** (used in proforma PDF + quote-converted email when `$quote->incoterm` is set):
```php
match(strtoupper($quote->incoterm)) {
    'FOB'    => 'Incoterms 2020: FOB Germany',
    'CIF'    => 'Incoterms 2020: CIF destination port — freight and insurance included to destination port.',
    default  => 'Incoterms 2020: ' . strtoupper($quote->incoterm),
}
```

**Valid incoterm values** (`StoreQuoteRequestRequest`): `DAP`, `DDP`, `EXW`, `FOB`, `CIF`, `Custom`

**Files changed:**
- `config/payment.php` — `delivery_term` updated to full FOB string
- `app/Http/Requests/StoreQuoteRequestRequest.php` — added `Custom` to `in:` validation rule
- `resources/views/pdf/invoice.blade.php` — label: "Delivery Term" → "Delivery / Shipping Terms"
- `resources/views/pdf/proforma-invoice.blade.php` — full incoterm formatting with `match()`; removed hardcoded `'CIF'` fallback second argument from `config()` call
- `resources/views/emails/order-confirmation.blade.php` — label renamed; uses config fallback (no `$quote` available)
- `resources/views/emails/order-confirmation-text.blade.php` — same
- `resources/views/emails/quote-converted-to-order.blade.php` — label renamed; incoterm formatting if `$quote->incoterm` set, else config fallback
- `resources/views/emails/quote-converted-to-order-text.blade.php` — same in plain text

---

### Order Security & Audit Logging

**Order deletion (super_admin only):**
- Route middleware: `admin.role:super_admin` — admin role cannot delete
- Request body must include `confirm_ref` matching `order.ref` exactly — 422 if mismatch
- Orders with `payment_status=paid` cannot be deleted — returns 409 Conflict
- `deleted` log entry is written **before** `$order->items()->delete()` and `$order->delete()` to capture data while record exists

**Cancel transition guard:**
- `PUT /admin/orders/{id}` and `PATCH /admin/orders/{id}/status` reject cancellation if current status is already `cancelled` or `delivered` — returns 409 Conflict

**Audit log (order_logs):**
- Written by `AdminOrderController::writeLog()` — wrapped in try/catch so log failure never blocks primary action
- Also written by `AdminQuoteRequestController::writeConversionLog()` when a quote is converted to order
- `GET /admin/orders/{id}` response includes a `logs` array:
```json
{
  "logs": [
    {
      "id": 1,
      "action": "status_changed",
      "old_value": "pending",
      "new_value": "confirmed",
      "notes": null,
      "admin_user_id": 3,
      "admin_user_email": "admin@okelcor.com",
      "ip_address": "1.2.3.4",
      "created_at": "2026-05-02T12:00:00+00:00"
    }
  ]
}
```
- Actions logged: `status_changed` (any status transition including quote conversion), `cancelled` (status set to cancelled), `tracking_updated` (any of carrier/tracking_number/container_number/estimated_delivery/eta), `deleted` (hard delete before records removed), `document_generated` (proforma/packing_list/delivery_note/commercial_invoice created), `document_uploaded` (shipment_document file uploaded), `document_deleted` (shipment_document file deleted), `document_sent` (any trade document sent by email via `POST /admin/trade-documents/{id}/send-email`)

### Container Tracking (Public)
- `GET /api/v1/tracking/{container}` — auto-detects carrier by tracking number format
- **DHL** detected by regex: 10-12 digits, `JD…`, `1Z…`, `GM…` prefix → calls `DhlTrackingService`
- **Sea freight** (everything else) → calls `ShipsGoService`
- Response always includes `carrier` field: `"DHL"` or `"Sea Freight"`

**ShipsGo two-step flow:**
1. `POST /v2/ocean/shipments` — registers container for tracking (idempotent)
2. `GET /v2/ocean/shipments?filters[container_no]=eq:{container}` — fetches status
- Auth: `X-Shipsgo-User-Token` header
- First call may return null fields — ShipsGo takes minutes/hours to fetch live data from shipping line

**DHL:**
- Endpoint: `GET https://api-eu.dhl.com/track/shipments?trackingNumber={n}`
- Auth: `DHL-API-Key` header
- Returns: `{ status, location, eta, events[] }`

### Supplier Intelligence
- `GET /api/v1/admin/supplier/search?q={query}&limit={1-50}` — proxies eBay DE Browse API
- `GET /api/v1/admin/supplier/alibaba-link?q={query}` — returns Alibaba search URL (open in new tab)
- `EbayService`: client credentials OAuth token cached for ~2 hrs
- Query is auto-simplified before sending to eBay — extracts `BRAND SIZE` (e.g. `"YOKOHAMA 225/45R18"`) from full product name
- No category filter applied — removed to avoid EBAY_DE category ID mismatch
- Env vars: `EBAY_CLIENT_ID`, `EBAY_CLIENT_SECRET`, `EBAY_ENVIRONMENT=production`
- eBay errors now throw (visible 502 with message) instead of silently returning empty

### VAT Validation (EU VIES REST)
- No SOAP, no third-party package — direct HTTP via Laravel `Http` facade
- Endpoint: `POST /api/v1/vat/validate` body: `{ "vat_number": "DE123456789" }`
- Calls `https://ec.europa.eu/taxation_customs/vies/rest-api/ms/{CC}/vat/{number}`
- Returns: `{ valid, name, address, country_code, vat_number, message }`
- Also runs automatically on `POST /orders`, `POST /quote-requests`, and customer register/profile update when `vat_number` is provided

### EU VAT Enforcement (PHASE 2A-1)
**Rule:** B2B customers in EU countries outside Germany must supply a VIES-validated VAT number. Germany is domestic (19% standard regardless). Non-EU is exempt (no VAT required).

**Enforced on:**
| Endpoint | How customer_type is resolved |
|---|---|
| `POST /api/v1/payments/create-session` | Auth token wins; falls back to request `customer_type` field |
| `POST /api/v1/quote-requests` | Auth token wins; then request `customer_type`; then inferred from `company_name` presence |
| `PUT /api/v1/auth/profile` | Always from `customer.customer_type` DB field (cannot be changed in profile update) |

**Error responses (422):**
```json
{ "message": "A valid EU VAT number is required for business purchases in EU member states.",
  "errors": { "vat_number": ["A valid EU VAT number is required for business purchases in EU member states."] } }
```
If VAT number is present but VIES validation fails:
```json
{ "errors": { "vat_number": ["Your VAT number could not be validated. Please check it and try again."] } }
```

**Scenario table:**
| Country | customer_type | vat_number | Result |
|---|---|---|---|
| France | b2b | missing | ❌ 422 — required |
| France | b2b | invalid VIES | ❌ 422 — not validated |
| France | b2b | valid VIES | ✅ passes; reverse charge applied |
| Germany | b2b | missing | ✅ passes; 19% standard |
| France | b2c | missing | ✅ passes; 19% standard |
| USA | b2b | missing | ✅ passes; exempt |

**Profile update behaviour:** check runs against effective post-update state. Updating phone/name only while already holding a valid EU VAT always passes. Clearing the VAT number for a B2B EU customer is rejected.

### Multilingual Content
- Locales: `en`, `de`, `fr`, `es`
- Pass `?locale=en|de|fr|es` on public content endpoints
- Articles: EN fallback if requested locale has no translation
- Hero slides + categories: locale-aware via `?locale=` param

### Role-Based Access Control
Middleware: `admin.role:{roles}` (comma-separated) — enforced at route level.

| Role | `role` string | `role_label` | Access |
|------|--------------|-------------|--------|
| Super Admin | `super_admin` | Super Admin | Full access — everything |
| Admin | `admin` | Admin | Full access — everything including user management |
| Editor | `editor` | Editor | Content only (products, articles, categories, hero slides, brands, media, settings) |
| Order Manager | `order_manager` | Order Manager | Operations only (orders, quote requests, contacts, newsletter, supplier search) |

**Frontend nav filtering** — use `user.role` from auth store:
```js
const ROLE_ACCESS = {
  super_admin:   ['dashboard','products','orders','quotes','articles','hero_slides','brands','categories','media','settings','users','supplier'],
  admin:         ['dashboard','products','orders','quotes','articles','hero_slides','brands','categories','media','settings','users','supplier'],
  editor:        ['dashboard','articles','hero_slides'],
  order_manager: ['dashboard','orders','quotes','supplier'],
}
```

### Public Order API — fields returned
`GET /api/v1/orders/{ref}` and `GET /api/v1/orders?email=` both return:
```
ref, status, payment_status, payment_method, subtotal, delivery_cost, total,
carrier, carrier_type, tracking_number, container_number, estimated_delivery, eta,
created_at, items[], shipment_events[],
declaration_required, declaration_status, declaration_signed_at,
declaration_signed_name, declaration_download_available,
trade_documents[]
```
- `declaration_required` — `true` when `order.is_reverse_charge === true`; always present
- `declaration_status` — `"pending"` | `"signed"` | `"acknowledged"` | `null`
  - `GET /api/v1/orders/{ref}` (single-order show): if `declaration_required=true` and no row exists, a pending row is **auto-created** so `declaration_status` is always `"pending"`, never `null`, for qualifying orders
  - `GET /api/v1/orders?email=` (list): no auto-create; `declaration_status` may be `null` for old pre-2B-2 orders
- `declaration_signed_at` — ISO 8601 timestamp or `null`
- `declaration_signed_name` — signed name in capitals or `null` until signed
- `declaration_download_available` — `true` when `pdf_path` is set and `status` is `signed` or `acknowledged`
- `trade_documents[]` — issued docs (types: `proforma`, `commercial_invoice`, `packing_list`, `delivery_note`, `shipment_document`); shape: `{ id, type, type_label, number, status, has_pdf, has_file, issued_at, sent_at, original_filename, mime_type, file_size }`

Admin order detail (`GET /admin/orders/{id}`) additionally returns:
- `declaration_id` — the EU declaration record ID (needed to fetch/manage declaration as admin)
- `trade_documents[]` — all docs for the order (all types + statuses, including uploads)

### CORS
Allowed origins:
- `http://localhost:3000`
- `https://okelcor-website.vercel.app`
- `https://okelcor.com`
- `https://www.okelcor.com`

---

## Response Envelope Reference

```json
{ "data": ..., "meta": { ... }, "message": "..." }
```

- `data` — always present (object or array)
- `meta` — on paginated lists: `{ current_page, per_page, total, last_page }`
- `message` — `"success"` on reads, descriptive string on writes
- Validation error (422): `{ "message": "...", "errors": { "field": ["..."] } }`
- Unauthenticated (401): `{ "message": "Unauthenticated." }`
- Forbidden (403): `{ "message": "Forbidden. Insufficient role." }`
- Locked (423): `{ "message": "Invoice is not available until the EU Entry Certificate is signed." }` — reverse-charge invoice before admin acknowledgement
- Rate limited (429): `{ "message": "Too many failed login attempts. Try again in N seconds." }`
- Import success: `{ "data": { "imported": N, "updated": N, "skipped": N, "errors": [] }, "message": "..." }`

---

## Image / Media Storage Rules

| Column | Stored in DB | Returned in API |
|--------|-------------|-----------------|
| `products.primary_image` | relative: `products/uuid.jpg` | absolute URL |
| `product_images.path` | relative: `products/uuid.jpg` | absolute URL |
| `articles.image` | relative: `articles/uuid.jpg` | absolute URL |
| `brands.logo` | relative: `brands/uuid.png` | absolute URL (`logo_url`) |
| `hero_slides.image_url` | relative: `hero/uuid.jpg` | absolute URL |
| `hero_slides.video_url` | relative: `hero/uuid.mp4` | absolute URL |
| `invoices.pdf_url` | relative: `invoices/INV-YYYY-NNNN.pdf` | served via `/api/v1/invoices/{id}/download` (auth.customer); 423 if not yet released |
| `quote_requests.attachment_path` | relative: `quote-attachments/uuid.ext` | absolute URL — admin only |
| `eu_declarations.signature_path` | relative: `eu-declarations/uuid.png` | **private disk** — never returned raw in API; `has_signature` boolean returned instead |
| `eu_declarations.pdf_path` | relative: `eu-declarations/DECL-OKL-XXXXX.pdf` | **private disk** — served via authenticated download endpoint |
| `trade_documents.pdf_path` | relative: e.g. `trade-documents/proforma/PI-2026-0001.pdf` | **private disk** — served via admin + customer download endpoints |
| `trade_documents.file_path` | relative: `trade-documents/uploads/{order_ref}/{ts}_{slug}.ext` | **private disk** — served via admin + customer download endpoints; customer download added in Phase 2C-3 |

Storage disk: `public` → `storage/app/public/` → symlinked to `public/storage/`
Conversion: `url(Storage::url($relativePath))` in controller formatters.

---

## Soft Deletes

| Model | Soft delete? | Restore endpoint |
|-------|-------------|-----------------|
| `Product` | Yes | `POST /admin/products/{id}/restore` |
| `Article` | Yes | `POST /admin/articles/{id}/restore` |
| `Brand` | No (hard delete) | — |
| `HeroSlide` | No (hard delete) | — |
| `Order` | No (hard delete) | `DELETE /admin/orders/{id}` — super_admin only; requires `confirm_ref` body param matching order.ref; blocked if `payment_status=paid` |

---

## Rate Limiting

| Limiter key | Limit | Applied to |
|-------------|-------|-----------|
| `admin-login:{ip}` | 5 failed attempts/min | `POST /admin/login` — via RateLimiter in controller |
| `search` | 30/min | `GET /search` |
| `vat` | 10/min | `POST /vat/validate` |
| `payments` | 20/min | `POST /payments/create-session`, `POST /payments/tax-preview` |
| `public-form` | 10/hour | `POST /contact`, `POST /orders`, `GET /orders`, `GET /orders/{ref}`, `POST /newsletter/subscribe` |
| `quote-form` | 5/hour | `POST /quote-requests` |

---

## Artisan Commands

| Command | Purpose |
|---------|---------|
| `import:wix-products {file}` | Import products from Wix CSV + download images |
| `import:product-images {file}` | Download missing images for already-imported products |
| `import:wix-orders {file}` | Import orders from Wix CSV |
| `import:wix-customers {file}` | Import customers from Wix contacts CSV |
| `import:wix-customers {file} --no-email` | Import customers without sending welcome emails |
| `products:sync-rapid {file}` | Import/upsert Rapid products from Excel; new rows created with `price_b2b=null`, `price_b2c=null` so PromotionPricingService owns those values |
| `products:assign-rapid-images` | Copy `Image 1.png` / `Image 2.png` from storage and assign to all Rapid products that are missing images |
| `orders:cleanup-test-stripe --date=YYYY-MM-DD --email=EMAIL --dry-run` | Delete test Stripe orders by date + email (always dry-run first) |
| `orders:delete-specific [--dry-run]` | Delete 9 specific hard-coded test order refs — dry-run first, then run without flag |
| `orders:cleanup-test-data [--dry-run] [--force]` | Delete 10 specific hard-coded test order refs + all related data (items, invoices, declarations, trade docs, logs, files) — always dry-run first |
| `invoices:generate-missing-pdfs [--dry-run] [--invoice=INV-YYYY-NNNN]` | Generate PDF files for invoices where `pdf_url IS NULL`; dry-run lists affected rows without writing |

**`orders:cleanup-test-data` detail:**
- Hard-coded refs: `OKL-14CVV2C`, `OKL-1303SMU`, `OKL-13180T5`, `OKL-YOTFQM`, `OKL-XW6LHC`, `OKL-1FES6QA`, `OKL-1A8IOAI`, `OKL-VDUWAD`, `OKL-1M84OQ9`, `OKL-1CDIP0E`
- FK-safe deletion sequence: nullify `quote_requests.order_id` → delete `order_logs` (by order_ref) → `order_shipment_events` → `order_items` → `EuDeclaration` → `TradeDocument` → `Invoice` → `Order`
- File cleanup AFTER DB transaction: public disk (invoice PDFs) + local/private disk (declaration PDFs, signatures, trade document files)
- Customer accounts are NOT deleted
- `--dry-run` shows preview table with counts; `--force` skips confirmation prompt

---

## Pending / Not Yet Built

| Item | Notes |
|------|-------|
| Phase EB-1 — eBay OAuth & Token Stability | **DONE** — `ebay_tokens` table; encrypted token storage; callback handler; refresh_token rotation; status + disconnect endpoints; `.env` fallback preserved |
| Phase EB-2 — Listing Status Tracking & Logs | **DONE** — 4 new product columns (`ebay_offer_id`, `ebay_status`, `ebay_last_synced_at`, `ebay_sync_error`); `ebay_listing_logs` table; all publish/remove/sync/refresh operations log to DB; `refresh-status` endpoint; `logs` endpoint with filters; safe error messages to frontend |
| Phase EB-3 — Price/Title Update Sync & Enhanced Validation | **DONE** — `updateListing()` + `PATCH /products/{id}/ebay/update`; `syncFull()` replaces `syncInventory()` in sync-all (full field sync); `guardProduct()` expanded with 7 new checks (connection, title, stock, image URL, marketplace/category config); `validation_failed` log action; 8 new `safeError()` patterns |
| Phase EB-4 — Settings Readiness Checklist | **DONE** — `GET /ebay/readiness` (12-check list: credentials, connection, marketplace/category/policies, seller location, env flag, live token test); `POST /ebay/test-connection` (pings Inventory API); `GET /ebay/policies` (fetches business policy IDs+names from eBay Account API); `EBAY_SELLER_POSTAL_CODE` + `EBAY_SELLER_LOCATION` config keys added |
| eBay production credentials | Rotate `EBAY_CLIENT_SECRET` (exposed in prior session). Set `EBAY_RU_NAME`. Register callback URL `https://api.okelcor.com/api/v1/admin/ebay/callback` in eBay Developer Portal. Set `EBAY_ENVIRONMENT=production`. |
| Adyen approval | Legacy/inactive until business account/API credentials are approved |
| `GET /admin/products?trashed=only` | Restore works but no dedicated trashed product list endpoint |
| Admin customer edit/deactivate | GET /admin/customers list exists; no PUT/DELETE per customer yet |
| Phase 2C-1 — Packing List | **DONE** — `PL-YYYY-XXXX` sequential numbers, DomPDF template, admin endpoint, customer whitelist |
| Phase 2C-2 — Delivery Note | **DONE** — `DN-YYYY-XXXX` sequential numbers, DomPDF template with EU reverse-charge notice, admin endpoint, customer whitelist |
| Phase 2C-3 — Shipment Document Uploads | **DONE** — `POST upload` + `DELETE` endpoints, private disk storage, `type_label` column, customer whitelist; accepts `document_label` or `type_label` field |
| Phase 2C-4 — Commercial Invoice | **DONE** — `CI-YYYY-XXXX` sequential numbers, DomPDF template (export notice, trade terms bar, customs declaration, sig blocks), admin endpoint, customer whitelist |
| Phase 2C-5 — Send Trade Document by Email | **DONE** — `POST /admin/trade-documents/{id}/send-email`; `TradeDocumentEmail` mailable with file attachment; `document_sent` OrderLog action; migration extends order_logs enum |
| Phase 2C-6 — Logistics Dashboard | **DONE** — `GET /admin/logistics/dashboard`; 10-metric summary; paginated order checklist; `missing[]`, `risk_level`, `next_action`, `eu_declaration` state; batch-loaded invoices; filters: status, payment_status, country, missing_document, risk_level=high, reverse_charge_only, date_from, date_to |
| Invoice release gating | **DONE** — `released_at` column, 423 on locked download, admin acknowledge releases invoice + email |
| Rapid product auto-pricing | **DONE** — `cost_price` base, PromotionPricingService, AdminPromotionController hook; price/price_b2b/price_b2c all aligned |
| Incoterms FOB-first model | **DONE** — config, PDF templates, emails updated; `Custom` added as valid incoterm; label renamed to "Delivery / Shipping Terms" everywhere |

---

## Environment

```
PHP:     8.3.30
Laravel: 13.2.0
MySQL:   8.0
DB:      okelcor_cms
Host:    127.0.0.1:3306 (local) / Namecheap DB credentials (production)
Web server: Apache
```

## Required `.env` on Namecheap
```env
# Stripe Checkout (active)
STRIPE_SECRET_KEY=
STRIPE_WEBHOOK_SECRET=
STRIPE_CURRENCY=eur

# Adyen (legacy/inactive)
ADYEN_API_KEY=
ADYEN_MERCHANT_ACCOUNT=
ADYEN_ENVIRONMENT=test
ADYEN_CLIENT_KEY=

# Mollie (legacy/inactive; public webhook returns HTTP 410)
MOLLIE_WEBHOOK_SECRET=

# Order notifications — admin receives email here after every confirmed Stripe payment
ORDER_EMAIL=support@okelcor.com

# Quote notifications — admin receives email here after every quote request submission
QUOTE_EMAIL=support@okelcor.com

# ShipsGo container tracking
SHIPSGO_API_KEY=

# DHL tracking
DHL_API_KEY=

# eBay supplier search
EBAY_CLIENT_ID=
EBAY_CLIENT_SECRET=
EBAY_ENVIRONMENT=production

# Frontend URL — used in ALL email links and redirects (verify email, password reset, admin welcome, order tracking)
FRONTEND_URL=https://okelcor.com
```

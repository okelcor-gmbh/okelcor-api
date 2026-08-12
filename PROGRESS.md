# Okelcor API — Build Progress

Last updated: 2026-08-12 | Branch: `main` | Latest commit: Session 78 (**pushed, not deployed**)

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

## ⚠️ Outstanding on production (as of 2026-08-12)

Nothing here is a deploy. These are live data and configuration items.

| # | Action | Why it matters |
|---|--------|----------------|
| 1 | **Restore order 10112** — `orders:restore-total 10112 371.88 371.88 --reason="undo bad automated repair, Session 75"` | The first `orders:repair-totals --fix` run cut it from **371.88 → 312.50** on a wrong diagnosis, then died before writing the log. It is still at the wrong figure and there is no record of the change. Migration #30 is now applied, so the restore can write its audit row — this is unblocked. |
| 2 | Re-run `orders:repair-totals` (survey, no `--fix`) | Confirms the rewritten classifier now flags only the 2 lump-sum orders, not 21. Read the output before step 3. |
| 3 | `orders:repair-totals --fix` | Corrects **AB-1150** (16,250 → 8,125) and **AB - 1182** (30,000 → 15,000) — the two real double counts. |
| 4 | **`QUEUE_CONNECTION=database`** + a queue worker under Supervisor | Still `sync`. A campaign sent to the full contact list would run `SendBulkEmailCampaignJob` inline in the HTTP request and time out. Blocks any real use of bulk email — including the InDesign import shipped in Session 77. |
| 5 | *(optional, business call)* `PARTNER_EDIT_WINDOW_HOURS=72` in `.env` before `config:cache` | See Session 75 partner-correction note. Config-only, reversible. |
| 6 | *(human, not a command)* Reconcile orders **10075, 10076, 10077, 10079, 10080** | Items exceed the stored total on inconsistent ratios. No tooling will fix these — someone has to compare them against what was actually invoiced. Tracked in Known Gaps. |

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

See `FRONTEND_NOTE_indesign-campaign-import.md`.

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
| `admin_users.role` ENUM missing documented roles | **High** | Column only allows `super_admin/admin/editor/order_manager`; `sales_manager`, `support`, `content_manager`, `viewer` are referenced throughout `AdminPermissions.php` and this doc but can't be stored under MySQL strict mode — creating an admin with any of those roles fails outright. Found via CI in Session 52; needs a migration widening the ENUM (or switching to a plain string column) plus a check for any admin accounts already silently affected |
| 5 orders where line items exceed the stored total | **High** | Orders **10075, 10076, 10077, 10079, 10080** (all `source = website`). Items sum to roughly 2× the recorded total on ratios of 0.43–0.49 — inconsistent, so not one mechanism. Surfaced by `orders:repair-totals` in Session 75 and deliberately **not** repaired: no rule can say which of the two figures is right, and one of them is what the customer was charged. Needs a person to compare each against the issued invoice. Money-facing, and unlike the double count it is not self-evident which direction the error runs |
| Payment milestone history missing for all pre-#31 orders | Medium | `order_logs.action` never accepted the milestone values, and the writes are wrapped in a `try/catch` that only logs a warning — so no order on production has a record of who confirmed its deposit or balance. Migration #31 fixes it going forward; the lost rows cannot be reconstructed. If any order's payment confirmation is ever disputed, `storage/logs/laravel.log` warnings are the only trace, and only for as long as that file is retained |
| Audit-log writes fail silently across the codebase | **High** | Not the ENUM itself — the pattern. Every `OrderLog` write is inside a `try/catch` that logs a warning and continues, which is right (a failed log must not fail the user's action) but means a schema mismatch is invisible until someone reads the column definition. Three separate instances found this way now (`security_events.type` Session 73, `order_logs.action` Sessions 75 and 76). Wants a test asserting every action string written in `app/` is accepted by the column, or a CI check — otherwise there will be a fourth |
| `login_histories` table doesn't exist | Medium | Found in production logs (Session 70 investigation) — viewing a customer's login history in the admin panel throws `PDOException: Table 'login_histories' doesn't exist`. Distinct from the working `admin_login_histories` table (admin-side logins) — this is the customer-portal-side equivalent, apparently never migrated. Not yet fixed — flagged, not investigated further this session |
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
| `admin_insights` | AI-generated dashboard insights, one row per insight per generation batch |
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

⚠️ Bulk email is deployed but **not yet safe to use for a real send**: `.env`
still has `QUEUE_CONNECTION=sync`, so `SendBulkEmailCampaignJob` would run
inline during the HTTP request. Set `QUEUE_CONNECTION=database` and run a
queue worker before the order manager sends to the full contact list — see
Session 50 note above.

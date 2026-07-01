# Okelcor API — Build Progress

Last updated: 2026-07-01 | Branch: `main` | Latest commit: `3438761`

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

## Traccar GPS / Fleet Tracking (Session 49)

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
| `bulk_email_campaigns` | Bulk email sends (subject/body/filters/progress) 🔧 |
| `bulk_email_campaign_recipients` | Per-recipient send status per campaign 🔧 |
| `admin_security_events` | Security audit events |
| `password_reset_tokens` | Customer password reset + invite tokens |
| `failed_jobs` | Laravel queue failures |

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

# Admin session
ADMIN_SESSION_TTL_MINUTES=300

# Backup
BACKUP_ENABLED=true
BACKUP_RETENTION_DAYS=14
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

All 18 verified to apply cleanly on MySQL via CI (`migrate:fresh`) and `LeadFunnelAnalyticsTest`'s `RefreshDatabase`; #16–18 were additionally exercised against sqlite in `BulkEmailCampaignTest`. Applied to production via `artisan migrate --force` as part of the 2026-07-01 deploy (which also shipped Session 51's code-only Media Library fix — no new migrations there). See `DEPLOY_RUNBOOK.md` for the ordered deploy + rollback plan. No migrations currently pending.

⚠️ Bulk email is deployed but **not yet safe to use for a real send**: `.env`
still has `QUEUE_CONNECTION=sync`, so `SendBulkEmailCampaignJob` would run
inline during the HTTP request. Set `QUEUE_CONNECTION=database` and run a
queue worker before the order manager sends to the full contact list — see
Session 50 note above.

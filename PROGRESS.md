# Okelcor API — Build Progress

Last updated: 2026-07-14 | Branch: `main` | Latest commit: `71fe2dc`

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
| `storage/logs/laravel.log` doesn't receive writes on production | Medium | Confirmed during GLS debugging — fresh `Log::` calls (even ones proven to run, via `tinker`) never appeared in that file, and it wasn't even the most-recently-modified file in `storage/logs/`. `LOG_CHANNEL`/`LOG_STACK` in production `.env` were never actually checked/reported — worth resolving so future debugging doesn't lose hours to this again |
| GLS production API access | Low | Currently running on the sandbox host (`api-sandbox.gls-group.net`) for both auth and tracking — verified to return real live data for real parcels, so not urgent, but production access requires a separate GLS approval step if sandbox ever proves unreliable long-term |
| `admin_users.role` ENUM missing documented roles | **High** | Column only allows `super_admin/admin/editor/order_manager`; `sales_manager`, `support`, `content_manager`, `viewer` are referenced throughout `AdminPermissions.php` and this doc but can't be stored under MySQL strict mode — creating an admin with any of those roles fails outright. Found via CI in Session 52; needs a migration widening the ENUM (or switching to a plain string column) plus a check for any admin accounts already silently affected |

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
19. `2026_07_03_103842_add_proposal_signed_copy_to_quote_requests_table` (Session 53 — proposal sign-and-return)
20. `2026_07_14_000001_add_email_signature_to_admin_users_table` (Session 57 — Outlook-style e-mail)
21. `2026_07_14_000002_extend_customer_communications_for_composer` (Session 57 — Outlook-style e-mail)

Migrations 1–18 verified to apply cleanly on MySQL via CI (`migrate:fresh`) and `LeadFunnelAnalyticsTest`'s `RefreshDatabase`; #16–18 were additionally exercised against sqlite in `BulkEmailCampaignTest`. Applied to production via `artisan migrate --force` as part of the 2026-07-01 deploy (which also shipped Session 51's code-only Media Library fix — no new migrations there). #19–21 are guarded/additive (`Schema::hasColumn` checks) and ready to deploy via the same command — not yet confirmed run against production as of this note. #21 also widens `customer_communications.body` from TEXT to LONGTEXT via raw SQL (no doctrine/dbal in this project). See `DEPLOY_RUNBOOK.md` for the ordered deploy + rollback plan.

⚠️ Bulk email is deployed but **not yet safe to use for a real send**: `.env`
still has `QUEUE_CONNECTION=sync`, so `SendBulkEmailCampaignJob` would run
inline during the HTTP request. Set `QUEUE_CONNECTION=database` and run a
queue worker before the order manager sends to the full contact list — see
Session 50 note above.

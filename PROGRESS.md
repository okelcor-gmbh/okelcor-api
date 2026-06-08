# Okelcor API — Build Progress

Last updated: 2026-06-08 | Branch: `main` | Latest commit: `ca765a3`

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
| Media library | ✅ | |
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
| `ebay_tokens` | Encrypted eBay OAuth tokens |
| `ebay_listing_logs` | eBay listing action audit |
| `ebay_order_sync_logs` | eBay order sync audit |
| `promotions` | Promotional pricing rules |
| `newsletter_subscribers` | Newsletter opt-ins |
| `contact_messages` | Contact form submissions |
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

**Pending migrations (not yet run on production):**
1. `2026_06_02_000001_add_proposal_fields_to_quote_requests_table`
2. `2026_06_03_000001_create_quote_request_items_table`
3. `2026_06_08_000001_add_buyer_lifecycle_fields_to_customers_table` (CRM-8)
4. `2026_06_08_000002_create_customer_verifications_table` (CRM-8)
5. `2026_06_08_000003_create_customer_timeline_events_table` (CRM-8)
6. `2026_06_08_000004_create_customer_access_requests_table` (CRM-8)

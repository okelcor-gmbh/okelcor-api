# Okelcor API

Laravel backend powering the Okelcor GmbH platform — a global tyre supply company headquartered in Munich, Germany. This API serves the customer-facing storefront at [okelcor.com](https://www.okelcor.com), a full back-office admin suite, and a partner sales portal.

Okelcor sells tyres wholesale across the EU and internationally, so the platform goes well beyond a typical e-commerce backend: it handles B2B quote workflows, multi-milestone payments, sea-freight container tracking, VAT/EU trade compliance documents, invoicing, marketing campaigns, and finance reporting — all from a single versioned REST API (`/api/v1`).

## Features

### Commerce & customers
- Product catalogue with brands, categories, translations, images, and promotion pricing
- B2B quote request workflow — customers submit quote requests, admins respond with itemised quotes and attachments, customers accept online
- Orders with per-item editing, payment milestones, shipment events, and profitability tracking
- Customer accounts with Sanctum token auth, email verification, account activation gates, address book, saved tyre fitments, and notification preferences
- Live chat (in-house session/message models plus Crisp webhook integration) and customer communications timeline

### Payments & finance
- Stripe Checkout sessions and webhook handling (primary payment rail)
- Adyen integration via the official PHP API library
- Payment milestone control with state-correction tooling and milestone emails
- Invoice generation as PDFs (dompdf) with sequential invoice registration, plus EC invoice periods/groups for intra-community trade
- Finance modules: liquidity planning, finance snapshots, order financials, and profitability reporting
- Currency conversion and tax/VAT services, including EU VAT number validation

### Logistics & compliance
- Sea-freight container tracking via ShipsGo, plus DHL and GLS carrier tracking services
- Order shipment events surfaced to customers as tracking timelines
- Trade documents (generation, download, and public verification endpoints) and EU declarations
- FET (tyre) engine data endpoints

### Admin & operations
- Extensive admin module (~75 controllers) behind Sanctum + admin guard + enforced TOTP two-factor auth (Google2FA with QR provisioning)
- Operations board, work queues, sales order board, todos, staff messaging, and staff contribution ledgers
- Admin audit logging, security events, login history, and system health endpoints
- Customer approval/verification pipelines and data-quality tooling
- Imports from Wix (customers, orders, products) and CSV-based customer/order/product import
- eBay integration: listing sync, order sync, and audit tooling (queued jobs)
- Excel exports via PhpSpreadsheet

### Marketing & messaging
- Bulk email campaigns with a block-based campaign builder, merge tags, drafts, templates, InDesign import, and open/click tracking
- Transactional and campaign email through Resend; inbound replies received on a dedicated subdomain by a Cloudflare Email Worker (`cloudflare-worker/`) that parses mail and posts it to a signed webhook
- WhatsApp Business (Meta) webhook + notifier, newsletter subscribers, marketing contact management with market segmentation
- Realtime events over Pusher channels; Expo push notifications for the admin mobile app

### Partner portal
- Separate partner authentication and a partner sales log with product matching and audit trail

## Tech stack

| Layer | Technology |
|---|---|
| Framework | Laravel 13 (PHP 8.3) |
| Auth | Laravel Sanctum, Google2FA (TOTP) + bacon-qr-code |
| Payments | Stripe PHP SDK, Adyen PHP API library |
| Email | Resend (outbound), Cloudflare Email Worker (inbound) |
| Realtime | Pusher Channels |
| Documents | dompdf (PDF invoices/documents), PhpSpreadsheet (Excel exports) |
| Media | Intervention Image |
| Sanitisation | mews/purifier (HTMLPurifier) |
| Dev tooling | Pint, Pail, PHPUnit 12, Vite + Tailwind CSS v4 |

## Architecture highlights

- **Service-layer design** — 60+ dedicated service classes (`app/Services`) keep controllers thin: carrier tracking, invoice registration, payment state correction, campaign rendering, VAT validation, profitability calculation, and more each live in their own service.
- **Single versioned API** — everything is namespaced under `/api/v1`, with separate route groups for public endpoints, authenticated customers, the 2FA-enforced admin area, and partners.
- **Webhook surface** — dedicated, individually secured webhook endpoints for Stripe, WhatsApp (Meta handshake + messages), Crisp, and inbound email (HMAC-signed by the Cloudflare Worker).
- **Deep schema** — 178 migrations model orders, quotes, finance records, campaigns, staff activity, security events, and partner sales as first-class entities rather than JSON blobs.

## Getting started

```bash
git clone <repo-url> && cd okelcor-api

composer run setup   # composer install, copy .env.example → .env, key:generate,
                     # migrate, npm install, npm run build
```

Fill in the required credentials in `.env` (see `.env.example` for the full list — database, Stripe, Adyen, Resend, Pusher, etc.), then:

```bash
composer run dev     # serves the app + queue listener + log tail + Vite concurrently
composer run test    # run the test suite
```

## Project structure

```
app/
├── Http/Controllers/        # Public + customer API controllers
│   ├── Admin/               # Back-office admin API
│   └── Partner/             # Partner portal API
├── Services/                # Domain services (payments, tracking, invoicing, …)
├── Models/                  # ~80 Eloquent models
├── Jobs/                    # Queued jobs (bulk email, eBay sync)
└── Mail/                    # Mailables
cloudflare-worker/           # Inbound-email Worker (wrangler deploy)
database/migrations/         # 178 migrations
routes/api.php               # Versioned API route map
```

Additional operational docs live in the repo root (deploy runbook, inbound email setup, WhatsApp setup, and per-feature frontend notes).

## License

Proprietary — © Okelcor GmbH. All rights reserved.

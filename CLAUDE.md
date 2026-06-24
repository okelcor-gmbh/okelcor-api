# Okelcor API — Claude Code Instructions

## Read This First
Before writing any code, read `BACKEND_REQUIREMENTS.md` in this project root.
That document is the single source of truth for everything you build here.

## What This Project Is
This is the **Laravel 11 CMS API backend** for Okelcor — a B2B tyre wholesale
website. The frontend is a separate Next.js 15 / React 19 project that consumes
this API. You are building the backend only.

## Local Development URLs
- This API runs at: `http://localhost:8000`
- Next.js frontend runs at: `http://localhost:3000`
- Production API will be: `https://api.okelcor.com`
- Production frontend will be: `https://okelcor.com`

## Tech Stack
- Laravel 11
- PHP 8.3
- MySQL 8.0 (via Laragon locally)
- Laravel Sanctum for admin authentication
- Laravel Storage for media/file uploads

## Responsibility Split
| This Laravel backend handles | Next.js frontend handles |
|---|---|
| All content CRUD | UI rendering & routing |
| Media/image uploads | Customer auth (NextAuth.js) |
| MySQL database | Fetching from this API |
| JSON API responses | Form submissions |
| Admin auth tokens | Search UI |

The frontend is a consumer only — it never touches the database directly.

## Database
- Local database name: `okelcor_cms`
- Host: `127.0.0.1`
- Port: `3306`
- Username: `root`
- Password: (empty)

## Resources to Build (from BACKEND_REQUIREMENTS.md)
1. Products (with product_images gallery)
2. Articles (with article_translations — EN/DE/FR)
3. Categories (with category_translations — EN/DE/FR)
4. Hero Slides (with hero_slide_translations — EN/DE/FR)
5. Brands
6. Quote Requests
7. Contact Messages
8. Orders (with order_items)
9. Newsletter Subscribers
10. Media / File uploads
11. Site Settings (key-value store)
12. Admin Users

## API Structure
- All routes prefixed: `/api/v1/`
- Public GET routes: no auth required
- Admin routes: protected by Laravel Sanctum token auth
- All responses: JSON only (ForceJsonResponse middleware)
- Multilingual: `?locale=en|de|fr` query param on all content endpoints

## CORS
Allow origins:
- `http://localhost:3000` (local dev)
- `https://okelcor.com` (production)
- `https://www.okelcor.com` (production)

## Payments
- Active gateway: Stripe Checkout.
- Active endpoints: `POST /api/v1/payments/create-session` and `POST /api/v1/payments/webhook`.
- Stripe webhooks must verify the `Stripe-Signature` header using `STRIPE_WEBHOOK_SECRET`.
- Adyen code/package/config remain present but are legacy/inactive until business account/API credentials are approved.
- Mollie code/config remain present, but `POST /api/v1/orders/mollie-webhook` is disabled and returns HTTP 410 JSON.
- Do not use Adyen or Mollie unless they are explicitly re-enabled later.

## Code Conventions
- Use `$fillable` on every model (never `$guarded = []`)
- Cast JSON columns: `protected $casts = ['body' => 'array']`
- Use `$hidden` on models with sensitive fields
- Define Eloquent relationships for all foreign keys
- Use FormRequest classes for all validation
- Return consistent JSON structure: `{ data, meta, message }`

## Build Order (follow this sequence)
1. Install required packages (Sanctum, Intervention Image, Spatie Media)
2. Create all migrations (follow schema in BACKEND_REQUIREMENTS.md exactly)
3. Create all Models with relationships
4. Create all Controllers (public + admin)
5. Register all routes in `routes/api.php`
6. Add CORS config
7. Add ForceJsonResponse middleware
8. Create seeders for test data
9. Test all endpoints with Thunder Client / Postman

## Important Notes
- Image URLs returned by API must be absolute: `http://localhost:8000/storage/...`
- The `body` field in article_translations stores a JSON array of paragraph strings
- Admin auth uses Sanctum token (not session/cookie)
- All 4 tyre category slugs are fixed: `pcr`, `tbr`, `used`, `otr`
- Do not change database column names — the frontend depends on exact field names

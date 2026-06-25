# Okelcor API — Production Deploy & Ops Runbook

Canonical production API: **`https://api.okelcor.com`** (NOT `.de` — that host is
unconfigured parking). Frontend: `https://okelcor.com`.

Host: Namecheap business hosting (cPanel). Shell user `okelvaxj@business194`.
PHP binary: `/opt/alt/php83/usr/bin/php`.

---

## 1. Clearing the pending-migration backlog (🔧 → live)

As of this writing **10 migrations have never run on production**. They are all
**additive, `hasColumn`/driver-guarded, reversible, and carry safe backfills** —
verified by reading each file. They can be deployed as one batch.

### Pending migrations (run in this order — timestamps already enforce it)

| # | Migration | Type | Risk |
|---|-----------|------|------|
| 1 | `2026_06_02_000001_add_proposal_fields_to_quote_requests_table` | add columns (incl. enum) | low — additive |
| 2 | `2026_06_03_000001_create_quote_request_items_table` | create table | low |
| 3 | `2026_06_08_000001_add_buyer_lifecycle_fields_to_customers_table` | add columns + FK + backfill | low — backfills active customers to verified/low-risk |
| 4 | `2026_06_08_000002_create_customer_verifications_table` | create table | low |
| 5 | `2026_06_08_000003_create_customer_timeline_events_table` | create table | low |
| 6 | `2026_06_08_000004_create_customer_access_requests_table` | create table | low |
| 7 | `2026_06_10_000001_extend_security_events_type_enum` | **raw `ALTER ... MODIFY ENUM`** | watch item — MySQL-only, widens enum, no row changes |
| 8 | `2026_06_15_000001_create_admin_notifications_table` | create table | low |
| 9 | `2026_06_22_000001_extend_admin_notifications_for_crm3b` | add columns + indexes | low — each column guarded |
| 10 | `2026_06_22_000002_add_lead_metadata_to_quote_requests_table` | add JSON column | low — guarded |

The only non-Blueprint statement is **#7** (`ALTER TABLE security_events MODIFY
COLUMN type ENUM(...)`). It only *widens* the allowed set and touches no rows, so
it is safe; it is also guarded to run on MySQL only.

### Deploy steps

```bash
cd /home/okelvaxj/.../okelcor-api          # adjust to the real app path

# 0. BACK UP FIRST — this is the safety net for the batch.
/opt/alt/php83/usr/bin/php artisan backup:okelcor      # project's own backup command
# (or a raw dump): mysqldump -u USER -p DB > ~/pre-deploy-$(date +%F).sql

# 1. Pull the release
git fetch origin && git reset --hard origin/main

# 2. Dependencies (no dev)
composer install --no-dev --optimize-autoloader

# 3. DRY-RUN the migrations first — review the plan, run nothing
/opt/alt/php83/usr/bin/php artisan migrate --pretend

# 4. Run them for real
/opt/alt/php83/usr/bin/php artisan migrate --force

# 5. Rebuild caches (REQUIRED — routes + config changed)
/opt/alt/php83/usr/bin/php artisan config:clear
/opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:clear
/opt/alt/php83/usr/bin/php artisan route:cache
/opt/alt/php83/usr/bin/php artisan view:clear
```

### Verify after deploy

```bash
/opt/alt/php83/usr/bin/php artisan migrate:status      # all 10 show [Ran]
curl https://api.okelcor.com/api/v1/i18n/locales       # app responds over HTTPS
/opt/alt/php83/usr/bin/php artisan system:health       # health groups green
```

### Rollback (only if a step fails mid-way)

Every migration has a working `down()`. To undo just this batch:
```bash
/opt/alt/php83/usr/bin/php artisan migrate:rollback --step=10 --force
```
If anything looks wrong with data, restore the dump from step 0. Because the
batch is additive, the safest recovery is usually *roll forward with a fix*, not
a data restore.

### Going forward
Adopt **deploy-per-feature, not per-quarter**. The i18n feature (build → test →
deploy → verify, same day) is the model. Don't let 🔧 work pile up again.

---

## 2. eBay `EBAY_CLIENT_SECRET` rotation (HIGH priority)

**Repo status: clean.** `.env` is gitignored and was never committed; the secret
is not in git history. `config/services.php` and `.env.example` only reference it
via `env()` / placeholders. The past exposure was via a doc/session, not the repo
— so rotation + scrubbing local notes is the fix, no git history rewrite needed.

### Rotation steps (you must do these — portal + prod access required)

1. **eBay Developer Portal** → your app keyset (production) → **regenerate the
   Client Secret (Cert ID)**. This invalidates the old one immediately.
2. If the OAuth user token was minted with the old secret, **re-consent** to mint
   a fresh `EBAY_REFRESH_TOKEN` (the listing flow uses `ebay_sell.refresh_token`).
3. On production, edit `.env`:
   ```env
   EBAY_CLIENT_SECRET=<new-secret>
   EBAY_REFRESH_TOKEN=<new-refresh-token-if-reminted>
   ```
4. Re-cache config so the new value loads:
   ```bash
   /opt/alt/php83/usr/bin/php artisan config:clear
   /opt/alt/php83/usr/bin/php artisan config:cache
   ```
5. Verify via the readiness checklist (does not print the secret):
   ```bash
   curl -H "Authorization: Bearer <admin-token>" \
        https://api.okelcor.com/api/v1/admin/ebay/settings   # readiness checks pass
   ```
6. **Scrub the old secret** from any local docs/notes (e.g. `OKELCOR_BACKEND_SOP.md`
   currently only holds a *warning*, not the value — keep it that way) and from
   any chat/session logs you control.

Do not list any live eBay products until this is done.

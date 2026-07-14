# Frontend Note — Admin Customers section (editing + cleanup + UI)

**From:** Backend · **Re:** admin-side customer management
**Status:** Backend endpoint expanded + tested. Two things below are frontend-only —
please action them there; this repo (`okelcor-api`) has no UI code, so neither
change is possible from the backend side.

---

## 1. Remove the "platform migration" test-email section — frontend-only

The admin Customers section has a leftover UI block referencing a platform
migration and a test email (`johngraphics18@gmail.com`). **There is nothing on
the backend that produces, needs, or reads that section** — it isn't tied to
any API response, model, config value, or feature flag in this codebase (checked).
It's safe to delete outright rather than hide behind a flag. Please remove it
from the Customers page so it stops confusing staff who manage real customers there.

---

## 2. `PATCH /admin/customers/{id}` — now supports real corrections

Previously this endpoint only accepted `admin_notes`, `customer_type`,
`company_name`, `phone`, `country` — there was no way to fix a typo'd name,
email, or VAT number. It now accepts:

```jsonc
// Request body — all fields optional, send only what changed
{
  "first_name":    "Acme",
  "last_name":     "Buyer",
  "email":         "corrected@acme-tyres.com",
  "company_name":  "Acme Tyres GmbH",
  "customer_type": "b2b",           // b2b | b2c
  "vat_number":    "DE123456789",
  "vat_verified":  true,             // optional — see behaviour below
  "industry":      "Automotive parts",
  "phone":         "+49 30 1234567",
  "country":       "DE",
  "admin_notes":   "Corrected spelling per customer email 12 Jul."
}
```

Response (unchanged shape, now includes `vat_verified` and `industry`):

```jsonc
{
  "success": true,
  "data": {
    "id": 41,
    "first_name": "Acme",
    "last_name": "Buyer",
    "email": "corrected@acme-tyres.com",
    "company_name": "Acme Tyres GmbH",
    "vat_number": "DE123456789",
    "vat_verified": true,
    "industry": "Automotive parts",
    "phone": "+49 30 1234567",
    "country": "DE"
    // …plus all the existing fields already on this payload (status,
    // buyer_tier, verification_status, health_score, risk_level, etc.)
  },
  "message": "Customer updated successfully."
}
```

**Behaviour to build the form around:**

- **Email uniqueness** — a 422 with `errors.email` if the new address is
  already used by another customer. Show it inline on the email field.
- **VAT auto-reset** — if you send a new `vat_number` that differs from the
  current one **without** also sending `vat_verified`, the backend resets
  `vat_verified` to `false`. This is deliberate: a manually-typed correction
  shouldn't keep the old "verified" badge. If staff have actually confirmed
  the new number (e.g. checked it themselves), have the form send
  `vat_verified: true` alongside it — a checkbox next to the VAT field
  ("I've confirmed this VAT number") covers this cleanly.
- **No-op saves** return `"message": "No changes to save."` instead of
  writing anything — safe to call even if the form always submits the full
  object.
- **Audit trail** — every save now writes a plain-language entry to the
  customer's timeline (see below) and the security audit log, e.g. *"Admin
  corrected: email (old@x.com → new@x.com), vat_number (DE111 → DE222)"*. You
  don't need to build your own change history — just surface the timeline (next section).

---

## 3. UI improvements — please build these on the frontend

These are frontend-only changes; the backend already has the data/endpoints
listed below, several of which may not be wired into the UI yet. Suggested
build:

**a. Inline edit, not a separate page.** A "Edit" button on the customer
detail view opening a modal/drawer with the fields above, rather than a
full-page navigation — this is a correction workflow, not a data-entry form,
so keep it fast.

**b. Surface the audit trail.** `GET /admin/customers/{id}/timeline`
(`customers.view` permission) returns every lifecycle event for a customer,
newest first — approvals, tier/risk changes, and now profile corrections:

```jsonc
{
  "data": [
    {
      "id": 12,
      "event_type": "profile_corrected",
      "title": "Profile corrected by admin",
      "description": "Admin corrected: email (old@x.com → new@x.com)",
      "metadata": { "changes": { "email": { "from": "old@x.com", "to": "new@x.com" } } },
      "admin": "Jane (Order Manager)",
      "created_at": "2026-07-14T10:00:00+00:00"
    }
  ]
}
```
A simple activity feed on the customer detail page (reusing `title` +
`description` + `created_at`) turns every future correction into a visible,
attributable history — worth having before staff start editing records regularly.

**c. Other already-built endpoints worth surfacing if not already in the UI**
(please check what's currently wired before building — some of this may
already exist):

| Endpoint | Permission | What it gives the UI |
|---|---|---|
| `GET /admin/customers/{id}/verifications` | `customers.view` | Company registration / VAT / website / import-license checks — useful as a "Verification" tab |
| `POST /admin/customers/{id}/approval-profile`, `/set-tier`, `/risk` | `customers.manage` | Buyer tier, risk level, approval profile — currently only settable via API, worth a dropdown on the detail page |
| `GET /admin/customers/data-quality/issues` | `customers.manage` | Flags likely-duplicate or low-quality customer records — useful as a filter/badge on the list view |
| `GET /admin/customer-access-requests` | `customers.manage` | Customer-initiated requests (checkout access, wholesale pricing, etc.) awaiting admin review |

**d. List/detail polish** (existing `GET /admin/customers` filters —
`status`, `onboarding_status`, `type`, `since`, `search` — already support
this, just needs UI):
- Status/risk/tier as coloured badges rather than plain text, so a
  high-risk or pending-verification customer is visible at a glance in the list.
- The `search` param already matches name, email, and company — a single
  search box covers most staff lookups.

---

## Please scan / confirm on your side

- Remove the migration test-email section (§1) — no backend dependency, safe to delete now.
- Wire the expanded edit form (§2) with inline email/VAT handling as described.
- Add the timeline feed (§3b) — cheap to build, immediately makes every future correction auditable to staff.
- Decide which of the already-built endpoints in §3c you want surfaced now vs. later; flag back if any response shape needs adjusting for your components.

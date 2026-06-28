# Frontend Note — Customer Invoices (receipt & invoice section)

**From:** Backend · **Re:** customer-facing invoice listing + download
**Status:** Backend hardened + tested. No frontend change required, but please
re-scan against the contract below and tighten where noted.

---

## What was wrong (and is now fixed, backend-side)

The invoice section had a silent dead-end. When an invoice's PDF failed to
generate once at creation time (Stripe webhook), `pdf_url` stayed `null` and the
customer could **never** download it through self-service:

- the listing only regenerated PDFs for orders with *no* invoice row, so an
  existing invoice with `pdf_url=null` was skipped → `download_available: false`
  forever;
- the download endpoint hard-404'd whenever `pdf_url` was null.

Recovery required an admin running a CLI command. That's resolved — both customer
paths are now **self-healing**.

---

## Endpoints & exact response shapes (unchanged contract)

### `GET /api/v1/auth/invoices` (customer bearer token)

Returns **released** invoices only, newest first:

```jsonc
{
  "data": [
    {
      "id": 41,
      "invoice_number": "INV-2026-0004",
      "issued_at": "2026-06-20T10:15:00+00:00",
      "due_at": null,
      "released_at": "2026-06-21T08:00:00+00:00",
      "amount": 1234.56,                 // number
      "status": "paid",                  // paid | unpaid | overdue
      "order_ref": "AB-1042",
      "tax_treatment": "reverse_charge", // or standard / exempt
      "is_reverse_charge": true,
      "download_available": true,        // now ACCURATE — self-healed before responding
      "pdf_url": "https://api.okelcor.com/api/v1/invoices/41/download" // null if truly unavailable
    }
  ]
}
```

- `pdf_url` is an **absolute URL to the authenticated download route**, not a
  static file. Fetch it through your proxy with the customer bearer attached
  (same pattern as the rest of the portal) — do **not** `<a href>` it directly
  from the browser (it needs the Authorization header).
- This endpoint is **side-effecting** (lazy invoice creation + PDF self-heal),
  so it may be marginally slower on first load after a new payment. It's safe to
  call on the invoices page mount; avoid calling it in a tight poll.

### `GET /api/v1/invoices/{id}/download` (customer bearer token)

Streams `application/pdf` inline (`Content-Disposition: inline; filename="INV-….pdf"`).
Status codes the UI should handle:

| Code | Meaning | Suggested UI |
|------|---------|--------------|
| `200` | PDF streamed (regenerated on the fly if it was missing) | open / download |
| `403` | Not this customer's invoice | hide the row / generic error |
| `423` | Held — reverse-charge invoice not yet released (EU Entry Certificate pending) | show "available after EU entry certificate is acknowledged" |
| `404` | Genuinely unavailable (e.g. source order gone) | "contact support" |
| `500` | Stream failure | "contact support" |

The `423` case is now reachable only if you link to a download by id from
somewhere other than the list (the list already filters released-only). If you
deep-link to an invoice from an order detail, handle `423`.

---

## NEW — invoice state on the order payload

`GET /api/v1/orders` and `GET /api/v1/orders/{ref}` (customer bearer) now include
four invoice fields per order, so the order page can show the right invoice
affordance without a second call:

```jsonc
{
  "ref": "AB-1042",
  // … existing order fields …
  "invoice_number": "INV-2026-0004",   // string when released, else null
  "invoice_available": true,            // released → always downloadable (self-heals)
  "invoice_pending_release": false,     // true = reverse-charge invoice held pending EU cert
  "invoice_download_url": "https://api.okelcor.com/api/v1/invoices/41/download" // null unless available
}
```

Render logic:
- `invoice_available: true` → show **Download invoice** → `invoice_download_url`
  (fetch through the proxy with the bearer, same as the list).
- `invoice_pending_release: true` → show **"Invoice pending — awaiting EU entry
  certificate acknowledgement."** Pair it with the existing
  `declaration_status` / `declaration_required` fields already on this payload to
  explain the step to the customer.
- both false → no invoice affordance yet (e.g. unpaid order).

`invoice_pending_release` is true when a reverse-charge order's invoice is held
(`released_at` null), **or** the order is paid reverse-charge with no released
invoice yet — so it's correct even before the invoice row is generated.

---

## Behaviour worth knowing (so the UI matches reality)

1. **Held invoices are invisible in the list.** Reverse-charge invoices are
   legally held (`released_at = null`) until an admin acknowledges the EU Entry
   Certificate. They are intentionally **omitted** from `GET /auth/invoices`.
   → A customer who paid a reverse-charge order sees **no invoice yet** in the
   list. To cover this, the **order detail/list payload now carries invoice
   state** (see next section) so you can render an "Invoice pending — awaiting EU
   entry certificate" state on the order page.

2. **`download_available` is now trustworthy.** Before, it could be `false` even
   though the file existed. You can rely on it to enable/disable the button.
   `pdf_url` is `null` only when the PDF genuinely can't be produced.

3. **In-app notification twin.** When a held invoice is released, the customer
   now gets a `document_ready` notification (type `document_ready`,
   `action_url: /account/invoices`) in addition to the existing email — so the
   notification bell links straight to the invoices page.

4. **No "receipt" concept exists** beyond invoices. If "receipt" in the UI means
   a payment confirmation distinct from the tax invoice, that's not modelled
   backend-side today — flag it if the design needs a separate receipt artifact.

---

## Please scan / tighten on your side

- Confirm the invoices page fetches `pdf_url` **through the proxy with the bearer**,
  and renders the PDF (new tab / blob), not a raw browser navigation.
- Handle `423` and `404` distinctly (see table) rather than a single error toast.
- Use the new `invoice_*` fields on the order payload to render the pending /
  download states on the order page (no extra request needed).
- Reminder (shared infra): the proxy base `API_URL` must include `/api/v1` and no
  trailing slash, or every customer proxy 404s — see the separate note.

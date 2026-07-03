# Frontend Note — Proforma Invoice signature + return upload

**From:** Backend · **Re:** documented customer acceptance of the proforma
invoice (price, products, terms) · **Status:** Backend built, needs a small
customer portal addition.

## Why

Order manager's ask: without a signed copy on file, a customer could dispute
having agreed to the price/terms on a proforma invoice — there was nowhere on
the document (or in the system) that captured their acceptance. This is a
legal/business paper-trail requirement, not just a nice-to-have.

## What changed

1. **The Proforma Invoice PDF** now has a signature block near the bottom
   (after the bank/payment details, before the "this is not a final tax
   invoice" disclaimer): **Date**, **Signature**, **Company Stamp** boxes for
   the customer to fill in by hand after printing.
2. **New customer upload endpoint** — after the customer prints, signs
   (and stamps, if applicable), and scans/photographs the document, they
   upload it back:

   ```
   POST /api/v1/auth/orders/{ref}/proforma/signed-copy
   Content-Type: multipart/form-data
   Body: file (pdf, jpg, jpeg, or png — max 20MB)
   ```

   ```jsonc
   // 201 — success
   { "data": { "id": 42, "type": "proforma_signed", "original_filename": "signed-pi.pdf" },
     "message": "Signed proforma invoice received. Thank you." }

   // 422 — no proforma has been issued for this order yet
   { "message": "No proforma invoice has been issued for this order yet.", "code": "no_proforma" }

   // 403 — same document-access gate as the rest of trade-documents
   { "message": "Document access is not yet enabled for your account. Please contact Okelcor.",
     "code": "documents_not_approved" }
   ```

   Re-uploading replaces the previous signed copy (the old one is marked
   superseded, not deleted) — so there's always at most one "current" signed
   copy per order.

3. **Shows up in the existing document list** — `GET /auth/orders/{ref}/trade-documents`
   now includes a `type: "proforma_signed"` entry once uploaded, same shape as
   every other document there (`id`, `type_label: "Signed Proforma Invoice"`,
   `has_file`, `original_filename`, etc.) — downloadable via the existing
   `GET /auth/trade-documents/{id}/download`, no new download endpoint needed.

## What the frontend needs to do

1. **On the order detail page**, once a `proforma` document exists in
   `trade_documents` but no `proforma_signed` one does yet, show a prompt:
   *"Please print, sign, and return your proforma invoice"* with a file
   upload control → `POST /auth/orders/{ref}/proforma/signed-copy`.
2. Once a `proforma_signed` document appears in the list, swap the prompt for
   a confirmation state ("✓ Signed copy received") — it's just another entry
   in the same `trade_documents` array, so check
   `trade_documents.some(d => d.type === 'proforma_signed')`.
3. Accept `pdf`, `jpg`, `jpeg`, `png` in the file picker (max 20MB) — matches
   what the backend validates.
4. **Admin side**: no new UI strictly required — the signed copy already
   appears in the existing admin trade-documents list for the order
   (`GET /admin/orders/{id}/trade-documents`) and downloads via the existing
   generic admin document download endpoint. Worth a visual nudge (e.g. a
   "Signed ✓" badge) if there's room, but not blocking.

## Note

This was scoped to the **Proforma Invoice** specifically, per what was
discussed. If the order manager wants the same signature treatment on the
Order Confirmation too, that's a quick follow-up (same pattern, different
PDF template) — not yet built.

# Frontend Note — Signed document return (Proposal + Proforma) + payment-gated documents

**From:** Backend · **Re:** documented customer acceptance at both the
Proposal and Proforma stage, plus a payment-timing fix on document
visibility · **Status:** Backend built, needs a small customer portal
addition.

## Why

Order manager's ask, across two calls:
1. Without a signed copy on file, a customer could dispute having agreed to
   a proposal or proforma's price/terms — nothing on either document (or in
   the system) captured their acceptance. Legal/business paper-trail
   requirement, and it needs to work at **both** stages: the Proposal (before
   any deal/price is finalized) and the Proforma Invoice (the payment
   instruction stage).
2. Documents that only make sense once the balance is paid (per Okelcor's
   stated terms — *"balance against bill of lading"*) shouldn't be visible to
   the customer before that point, same as the Commercial Invoice rule
   already in place.

## What changed

### 1. Proposal — sign and return (NEW, alternative to the digital Accept click)

The Proposal PDF now has a Date / Signature / Company Stamp block. After the
customer prints, signs, and scans/photographs it, they upload it back —
**this is itself an acceptance**, same effect as clicking "Accept":

```
POST /api/v1/auth/quotes/{ref}/proposal/signed-copy
Content-Type: multipart/form-data
Body: file (pdf, jpg, jpeg, or png — max 20MB)
```

```jsonc
// 201 — accepted
{ "data": { "proposal_status": "accepted" },
  "message": "Signed proposal received. Okelcor will proceed to create your order." }

// 422 — no active proposal to accept
{ "message": "No active proposal is available for acceptance.", "code": "no_active_proposal" }

// 410 — expired
{ "message": "This proposal has expired. Please contact Okelcor for an updated proposal.", "code": "proposal_expired" }
```

Same guards as the existing `POST /auth/quotes/{ref}/accept-proposal` (active
proposal required, not expired, not already accepted) — this is a second way
to trigger the exact same state change, not a separate flow.

**Frontend:** wherever the "Accept Proposal" button lives, add a second
option — "Upload signed copy instead" with a file picker. Both actions lead
to the same `proposal_status: "accepted"` result, so no new state handling
needed beyond the upload UI itself.

### 2. Proforma Invoice — sign and return (already covered previously, unchanged)

```
POST /api/v1/auth/orders/{ref}/proforma/signed-copy
```
See prior note content below — unchanged, included here for one combined
reference.

### 3. Payment-gated documents — expanded beyond just the Commercial Invoice

Packing List, Delivery Note, and Shipment Documents (Bill of Lading etc.) now
follow the **same full-payment gate as the Commercial Invoice** — they won't
appear in `GET /auth/orders/{ref}/trade-documents` (or the order detail's
`trade_documents` array) or be downloadable until the order is fully paid
(`balance_paid`/`shipment_released`, or a simple order marked `paid`).

**Frontend impact:** none if you're already just rendering whatever
`trade_documents` returns — this is enforced entirely server-side. If there
was any client-side logic assuming packing lists/delivery notes are visible
pre-payment, it'll now correctly hide them until balance is paid. Admin
visibility is unchanged.

---

## Proforma Invoice — sign and return (detail)

1. **The Proforma Invoice PDF** has a signature block near the bottom (after
   bank/payment details, before the "not a final tax invoice" disclaimer):
   Date, Signature, Company Stamp boxes.
2. **Upload endpoint:**

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
   ```

   Re-uploading replaces the previous signed copy (marked superseded, not
   deleted) — always at most one current one. **Unlike the Proposal flow,
   this does NOT change order status** — the order's own state machine is
   unaffected; it's just documented evidence alongside the Proforma.

3. **Shows up in the existing document list** — `type: "proforma_signed"`,
   same shape as every other document, downloadable via the existing
   `GET /auth/trade-documents/{id}/download`.

### What the frontend needs to do
1. **Proposal stage:** on the quote/proposal page, add "Upload signed copy"
   next to the existing Accept button.
2. **Proforma stage:** on the order detail page, once a `proforma` document
   exists in `trade_documents` but no `proforma_signed` one does yet, prompt:
   *"Please print, sign, and return your proforma invoice"* with a file
   upload → `POST /auth/orders/{ref}/proforma/signed-copy`. Once
   `proforma_signed` appears in the list, swap for "✓ Signed copy received".
3. Accept `pdf`, `jpg`, `jpeg`, `png` in both file pickers (max 20MB).
4. **Admin side:** no new UI strictly required for either — signed copies
   appear in the existing admin document lists/downloads (order-level for
   proforma; the quote detail's new `proposal_signed_copy_download_url` for
   the proposal). A "Signed ✓" badge is a nice-to-have, not blocking.

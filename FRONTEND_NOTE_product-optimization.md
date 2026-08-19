# Frontend note — product optimization (the marketing brief)

**Session 92 · backend and frontend both built and tested · migration #42 · 1 new admin route**
**Session 93 addendum at the bottom — brand-level defaults, migration #43, no new routes**

From the marketer's brief (`Product Optimization.txt`), three asks, all shipped:

1. **SEO URLs** — `okelcor.com/shop/brand+productName+season`
2. **Rich-text product descriptions** — "like how you did with the blog post"
3. **The Artikelmerkmale sheet** — the full German tyre-listing spec block
   (EU-label classes, 3PMSF snowflake, EPREL number, and 20 more), plus
   shipping/returns text — all manageable from the admin panel.

---

## 1. SEO URLs

Every product now has a `slug` — `continental-ecocontact-6-summer` — generated
from brand + name + season. Migration #42 backfills one for **every existing
product** (collisions get `-2`, `-3` …), and the model generates one for every
new product wherever it is created from (admin form, Wix import, eBay sync).

`GET /api/v1/products/{idOrSlug}` resolves **both**. Every id URL already
indexed, bookmarked or sitting in a sent campaign keeps working; the canonical
tag on the product page points at the slug URL, so Google folds the two
together instead of splitting rank across duplicates.

**Rules the URL lives by:**
- **A rename never moves the URL.** Changing the product's name/brand/season
  does not regenerate the slug — the address only changes when the slug field
  itself is edited, deliberately.
- Typed slugs are normalized (`Str::slug`) and deduplicated server-side.
- Soft-deleted products keep their slug — restoring one cannot find its URL
  given away.

Frontend: all product links go through `productPath()` in
`components/shop/data.ts` (cards, specials, compare, related, navbar search).
The `/shop/[id]` route accepts both handles.

## 2. Rich description

`description_html` — written in the same TipTap editor as articles, sanitized
by the same `ArticleHtmlSanitizer` at save time (same rules, same 422 on
failure, scripts never stored). Shown as the first accordion item on the
product page.

**The plain `description` stays**, required, untouched: it feeds search
results, the meta description and every client that predates the rich field.
Short plain summary + optional rich body — same shape as articles.

## 3. The specification sheet

24 attributes from the brief. The design decision worth knowing: **half of them
already existed as real columns** (width, aspect ratio, rim, load/speed index,
EAN, tread depth — added across Sessions 14–71 and written by the CSV import).
Storing them again inside a JSON blob would create two places for one fact.

So `App\Support\TyreSpecs` is the one catalogue that says where each attribute
lives:

| Source | Meaning | Examples |
|---|---|---|
| `column` | reads/writes the existing column | Reifenbreite→`width`, EAN, Tragfähigkeitsindex→`load_index`, Hersteller→`brand`, Modell→`name` |
| `json` | lives in the new `products.specs` JSON | EU-label classes, 3PMSF, Eisgriffigkeit, EPREL, MPN, Produktlinie … |
| `derived` | computed, never stored | Reifenzustand: `type === 'Used'` → Gebraucht, else Neu |

**Served, not hardcoded:** `GET /api/v1/admin/products/spec-options` returns
the catalogue (key, German + English labels, input type, options, source). The
admin form renders whatever it says — adding an attribute is one backend entry
and no frontend deploy. Same pattern as trade-document upload-options.

**Public payload:** `specifications` on the product — the assembled sheet,
labels in both languages, **empties already skipped**, order fixed. The product
page prints it as an "Artikelmerkmale" accordion section (German labels on the
German site). Rendered only when at least one value is set.

**Validation:** EU-label classes accept only A–G (422 otherwise); booleans are
real booleans rendered Ja/Nein; unknown keys and blanks are dropped before
storage. Sending `specs` **replaces** the stored object — the form sends the
whole sheet, so a cleared field stays cleared.

## 4. Shipping & returns

Two layers:
- **Site-wide defaults** in Settings: `product_shipping_info` /
  `product_returns_info`. The migration seeds shipping with the brief's text
  ("Versand: Kostenlos – Deutsche Post Brief. / Standort: Munich, Deutschland").
- **Per-product overrides** (`shipping_info` / `returns_info` columns) — for
  the OTR tyre that ships by freight. Override wins over setting.

The public payload carries the resolved text; `null` means "hide the block".

⚠️ **Returns was seeded EMPTY on purpose.** The brief's Rücknahme text is
copied from an eBay listing — it references eBay-Versandetikett and eBay
Plus-Mitglieder, which would be wrong on okelcor.com. Until the marketer words
the site version in Settings, the product page keeps showing the existing
translated returns copy it has always shown. **Tell the marketer this is the
one item waiting on them.**

---

## Admin panel (already built, this session)

The product form (`components/admin/product-form.tsx`) gained:
- **SEO URL** section — slug field with a live preview of the address and a
  warning on edit that changing it moves a live URL.
- **Short Description** (the old field, relabelled) + **Rich Description**
  (the article editor).
- **Artikelmerkmale** section — rendered from `spec-options`. Column-backed
  rows edit their real columns (width, rim, EAN … previously only the CSV
  import could write these); condition/manufacturer/model/spec rows are
  skipped with a note since Type/Brand/Name/Spec above already edit them.
- **Shipping & Returns** overrides with a pointer to the Settings keys.

`GET/PUT /admin/products/{id}` now carry all the new fields plus `width`,
`height`, `rim`, `load_index`, `speed_rating`, `ean`, `tread_depth_mm`.

## Deploy

Migration **#42** is guarded/additive and **deploy-order safe in both
directions**: the model checks the slug column exists before writing it, the
public API keeps resolving ids regardless, and unapplied it costs only the new
fields. The slug backfill runs inside the migration, only fills NULLs, and
re-runs are no-ops. `route:cache` needed (1 new route).

## Tests

21 new backend (`ProductOptimizationTest`) — migration + idempotent backfill,
slug generation/stability/dedup, sanitization, column-vs-json-vs-derived sheet
assembly, A–G validation, replace semantics, settings fallback chain. Suite:
**576 passed, 0 failed**.

---

# Addendum — Session 93: brand-level defaults

The marketer's follow-up, and he is right: with ~15,000 products, entering the
optimization content product by product is not a workflow. Most of it is the
same for every tyre a brand makes, so it is now entered **once per brand**.

## The resolution chain

```
product's own value  →  brand default  →  site-wide setting  →  null (hide)
```

- Resolved **at read time**. Nothing is ever copied onto product rows: a brand
  edit takes effect on all its products instantly, there is never a stale
  copy, and a product's own value always wins.
- Applies to: rich description, json-backed spec defaults, shipping, returns.
- Does **not** apply to per-tyre physical facts — width, EAN, load index,
  EPREL. A brand does not have one width; those stay on the product (and are
  mostly filled by the CSV import anyway).
- Brand matching is case-insensitive on `products.brand`, same rule the brand
  logo lookup has always used. Inactive brands lend no defaults.

## What changed

**Backend** — migration **#43** adds the same four content columns to
`brands`. `PUT /admin/brands/{id}` accepts them under exactly the product
rules: same sanitizer for the HTML, A–G validation on label classes, junk
keys and blanks dropped. `formatBrand` returns them. **No new routes.**
Deploy-order safe: resolution reads whole brand rows, so before the migration
the attributes are simply absent and behaviour is unchanged.

**Admin panel** — each card on **Admin → Brands** has a new content button
(file icon) opening **/admin/brands/{id}**: the brand's rich description
(article editor), the json-backed half of the spec sheet ("— no brand
default —" clears a field), and shipping/returns overrides. The form warns
that a wrong blanket value is worse than an empty field.

**Public payload** — unchanged in shape. `description_html`,
`specifications`, `shipping_info`, `returns_info` on the product are simply
resolved through the chain now; clients cannot tell where a value came from
and do not need to.

## Suggested workflow for the marketer

1. Open Admin → Brands → (brand) → content button.
2. Write the brand description once; set spec defaults that genuinely hold
   for the whole brand (e.g. Reifenbauart "Radial", Fahrzeugtyp).
3. Only touch individual products for values that differ — those override.

## Tests

9 new in `ProductOptimizationTest` (30 in the file, suite **585, 0 failed**):
the brand migration + idempotency, a default filling two products from one
entry, the product's own value winning, brand description fallback, the full
shipping chain (product → brand → setting), case-insensitive matching,
inactive brands excluded, the admin form saving under product rules
(sanitizer + junk-dropping), and A–G validation on brand defaults.

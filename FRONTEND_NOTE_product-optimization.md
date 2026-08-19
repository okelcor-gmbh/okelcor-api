# Frontend note — product optimization (the marketing brief)

**Session 92 · backend and frontend both built and tested · migration #42 · 1 new admin route**

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

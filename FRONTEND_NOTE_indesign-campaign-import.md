# Frontend note — importing an InDesign design as a campaign template

**Session 77. One new endpoint, no migration, no change to any existing one.**

---

## What this is

The marketers design campaigns in InDesign — that is where they can produce
something that actually looks good — and export **HTML5 (File → Publish
Online)**. Until now that export had to be handed to a developer to turn into a
campaign by hand, which meant every new design was a backend job.

It isn't any more. They upload the exported folder, zipped, and get back a
saved, editable, reusable campaign template.

```
POST /api/v1/admin/campaign-templates/import        (marketing.manage)
Content-Type: multipart/form-data
```

| Field | Required | Notes |
|---|---|---|
| `file` | yes | The exported folder, zipped. Max 50MB. |
| `name` | yes, unless `dry_run` | Template name, max 150 |
| `description` | no | Max 500. Defaults to "Imported from an InDesign export." |
| `dry_run` | no | `true` converts and returns the result **without saving anything** |

**201** on save, **200** on a dry run:

```jsonc
{
  "data": {
    "saved": true,
    "template_id": 12,
    "name": "Fuel Eco Tech launch",
    "blocks": [ /* normal campaign blocks — the same shape the editor already speaks */ ],
    "theme":  { "preset": "okelcor_dark", "text_color": "#000000", … },
    "media":  [ { "media_id": 88, "url": "https://api.okelcor.com/storage/campaigns/…png",
                  "width": 800, "height": 400, "filename": "hero.png" } ],
    "warnings": [ "The design has no call-to-action button — …" ],
    "source": { "document": "publication.html", "text_frames": 19, "images_seen": 17 },
    "preview_html": "<!DOCTYPE …>"     // the real rendered email, ready to iframe
  },
  "message": "Design imported and saved as a reusable template."
}
```

### Errors — there are two, not one

The first version of this note documented only `import_failed`. Frontend found
the second by reading the controller. Corrected:

| Status | `code` | Meaning |
|---|---|---|
| 422 | `import_failed` | The archive could not be read. `message` is already written for the marketer ("That file could not be opened as a ZIP archive.", "No InDesign page could be found in that archive. Export from InDesign with File → Publish Online (HTML), and upload the whole exported folder zipped."). **Show it verbatim.** |
| 422 | `invalid_blocks` | The design was read but could not be turned into a valid campaign. Carries `errors.blocks` — an array of plain strings, already phrased per block ("Block 3 (Button): …"), the same shape `POST /admin/campaign-templates` returns. |
| 422 | — | Ordinary Laravel validation (`errors.name`, `errors.file`). |

`invalid_blocks` should not fire in practice — the importer only emits block
types it builds itself — but it is the same gate a hand-built template passes,
and a design that fails it must not be saved as a template that breaks at send
time. If you ever see one in the wild, it is a backend bug; send it over.

---

## Read this bit before building the screen

**The import is a starting point, not a finished email, and the UI has to say
so.** This is the single most important thing on this page.

An InDesign HTML5 export is not an email and cannot be turned into one by
pasting it. It is an `<iframe>` on a fixed 595×1089px print canvas where every
element is `position:absolute` with a CSS `transform`, **every individual word
is its own `<span>` at an exact pixel offset**, and the fonts are `@font-face`
TTFs. Outlook's rendering engine supports none of that. Sent as-is, the design
does not degrade gracefully — it collapses into an unreadable pile of words.

So the backend does not embed the export. It **recovers** it:

- the copy, by reassembling those per-word spans into lines and paragraphs;
- the photographs, into the Media Library, with permanent absolute URLs;
- the reading order, from each element's real position on the page (document
  order in the export is InDesign's stacking order, not reading order);
- the palette, from the generated stylesheet;
- the gold hairlines InDesign draws under headings, as `divider` blocks.

**What cannot survive, by any method:** the exact layout. Text overlapping a
full-bleed photograph, the display typeface, exact leading and letter-spacing,
anything positioned rather than stacked. Email has no mechanism for it.

What comes back is the imagery, the words, the order and the colours, rendered
Outlook-safe. In practice it lands close, and the marketer finishes it in the
editor. **Frame it that way in the UI** — "Design imported. Review it before
sending." — and both the expectation and the follow-up land correctly. Framed
as "your InDesign design, converted", the first person who compares it
side-by-side with InDesign will report it as broken.

---

## Suggested flow

1. **Upload** — a drop zone taking a `.zip`. Tell them to zip the whole exported
   folder, not just `index.html`; the images and stylesheet live in
   `publication-web-resources/`.
2. **Call it with `dry_run: true` first.** Show `preview_html` in an iframe next
   to the block list. This is the review step, and it costs nothing — nothing is
   written, no template row, and it is the difference between "review it before
   sending" being a slogan and being real.
3. **Show `warnings` prominently.** They are written for a non-technical reader
   and each one is actionable. The two that fire most:
   - *"The design has no call-to-action button…"* — InDesign exports carry no
     links, so **there is never a button**. Someone has to add one. If your UI
     nudges toward exactly one thing after an import, make it this.
   - *"The design sets its type in #FFFFFF against #FFFFFF, which is unreadable
     once the background artwork is gone…"* — see Colour below.
4. **Save** — same call with a `name` and no `dry_run`. You get a
   `template_id`.
5. **Then it is an ordinary template.** `GET /admin/campaign-templates/{id}`
   returns its blocks, the editor opens them, `POST /admin/bulk-emails` sends
   them. Nothing about the campaign path changes.

---

## Colour, and the failure worth knowing about

The importer reads the palette out of the export, then **checks it is actually
legible** and overrides it when it isn't.

This matters because of a specific, common case: InDesign sets type in white
because it sits on a full-bleed dark photograph. That background does not carry
into email. Taking the colours at face value produces white text on the white
page colour — an email that arrives blank, and passes every check that isn't a
human being looking at it.

When the recovered pair falls below WCAG 4.5:1, the Okelcor house theme is used
instead and a warning explains why. The marketer can then set whatever colours
they want in the editor. **Do not "fix" this client-side by reapplying the
export's colours** — that reintroduces exactly the bug.

---

## Media

Every real photograph in the export is registered in the **Media Library**
(`collection: "campaigns"`), so it is browsable and reusable from the existing
picker with no second import. This is the other half of the ask: the next
campaign reuses these images without touching InDesign at all.

Not imported, on purpose:
- bullet glyphs and gradient slivers — InDesign furniture, meaningless alone;
- hairlines/rules — returned as `divider` blocks instead, which is what they
  were drawn to be;
- fonts and scripts — never extracted from the archive at all.

A count of what was filtered comes back in `warnings`.

---

## Things the endpoint will not do

- **Fetch anything remote.** An `<img src="https://…">` inside the archive is
  skipped, not downloaded.
- **Trust the archive.** Path traversal ("zip slip"), oversized archives and
  non-image entries are rejected before anything is written. An admin upload is
  still an untrusted file.
- **Save a template that cannot render.** The produced blocks go through the
  same `validateBlocks()` a hand-built template does. A design that fails it
  returns 422 `invalid_blocks` rather than a saved template that breaks at send
  time.

---

## Upload size — the API says 50MB, Vercel delivers 4.5MB

Frontend established this and it is correct: the upload crosses a Next.js route
handler, and Vercel caps Function request bodies at **4.5MB**, returning a 413
`FUNCTION_PAYLOAD_TOO_LARGE` before any application code runs. It is not
tunable — the `bodySizeLimit` in `next.config.ts` covers Server Actions only.

The API's own 50MB limit stands, because it is correct for a self-hosted or
direct upload. On Vercel the effective ceiling is 4.5MB. Their handling —
warn from 4MB, catch 413 on status alone since the body is Vercel's HTML error
page, and word it so the marketer knows the limit is the site's and not their
file — is right and stays.

**Before anyone builds a workaround, the cheap fix usually applies: export
smaller.** The importer already downscales every image to a maximum of 2000px
on its longest side and re-encodes at JPEG quality 90. Anything above that
resolution is discarded on the way in, so exporting at a higher one costs
upload budget and buys nothing — the delivered email is byte-identical either
way. In InDesign's Publish Online dialog, image quality *Medium* / 150ppi is
already at or above what survives.

For reference, the real Fuel Eco Tech export is **1.6MB** — comfortably inside
the ceiling. Tell the marketers the export setting before assuming the limit is
a blocker.

If a genuine export does exceed 4.5MB, there are two ways up and the choice is
open — see PROGRESS.md. Do not build either speculatively.

## Reviewing repeatedly no longer duplicates images — fixed

Frontend reported that reviewing one export three times before saving left
three copies of its photographs in the library, with the save adding a fourth.
Correct, and now fixed backend-side: a conversion is keyed on the archive's own
content hash and reused for two hours.

- Re-uploading **the same file** returns the same conversion and the same
  media rows, however many times you review it, and whoever reviews it.
- Re-uploading a **genuinely edited** export has different bytes, so it
  converts again — an edit is never served a stale design.
- If the media has been deleted from the library in the meantime, the reuse is
  dropped and it converts again rather than returning blocks that point at
  URLs nothing serves.

So `dry_run: true` is now free to call as often as you like, and faster on
every call after the first. **No frontend change needed** — this is invisible
from your side except that the duplicates stop.

---

## Contract summary

| | |
|---|---|
| New endpoint | `POST /admin/campaign-templates/import` |
| Permission | `marketing.manage` (super_admin / admin / order_manager) |
| Migration | **none** — reuses `campaign_templates` and `media` |
| Breaking changes | none |
| Deploy-order | safe both ways. The endpoint 404s until the API deploys; everything else is unaffected |
| `route:cache` | must be rebuilt — one new route |

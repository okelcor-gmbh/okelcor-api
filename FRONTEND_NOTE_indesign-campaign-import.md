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

**422** with `code: "import_failed"` and a `message` already written for the
marketer ("That file could not be opened as a ZIP archive.", "No InDesign page
could be found in that archive. Export from InDesign with File → Publish Online
(HTML), and upload the whole exported folder zipped."). Show the `message`
verbatim — do not replace it with a generic one.

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

## Testing without the marketers

`dry_run: true` is safe to call as often as you like — it writes no template.
Note that it **does** register the images in the Media Library (they are needed
to build the block URLs), so repeated dry runs on the same export will
accumulate media rows. Worth knowing if you are iterating on the screen.

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

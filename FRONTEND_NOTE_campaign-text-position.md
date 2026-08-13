# Frontend note — text on a picture, and the editor that fights you

**Session 81. One new block type. No migration, no new route, no change to any
existing endpoint or field.**

Two things were reported together:

1. **The headline printed across the banner arrives underneath the picture** —
   in the imported design and in the editor after it.
2. **Highlighting text inside a text box drags the whole block sideways.**

The first is fixed in the backend and needs one piece of UI from you. The second
is entirely yours — the backend has no part in it — and the diagnosis is below.

---

## 1. Why the banner headline was under the picture

Two separate causes, both now dealt with.

**The importer wasn't reading the relationship.** In the InDesign export the
masthead photograph is one frame and each line of type is another. The type
frames sit *inside* the picture's box — that spatial containment is the only
record that the words are printed on the artwork. Nothing read it, so the only
ordering left was top-to-bottom by `y`: the picture starts at `y=-3`, the
headline at `y=75`, and down they went, picture first.

**And there was nowhere to put them.** Every block in the catalogue was a
single full-width element stacked on the one before it. No block held text and
an image in the same space, so even a correct reading of the export had no way
to express it. This is the same shape as the Session 78 finding about things
side by side.

---

## 2. The new block: `hero`

Served from `GET /api/v1/admin/campaign-design` like every other block, so it
appears in your generated editor with no hardcoding. Full spec:

```jsonc
{
  "type": "hero",
  "label": "Banner (text on a picture)",
  "description": "A picture with a headline sitting on top of it. Choose which of the nine positions the text sits in.",
  "fields": [
    { "name": "image",      "type": "image_url", "label": "Background picture", "required": true },
    { "name": "alt",        "type": "text",      "label": "Picture description", "max": 200 },
    { "name": "heading",    "type": "text",      "label": "Headline", "max": 300 },
    { "name": "subheading", "type": "textarea",  "label": "Sub-headline", "max": 600 },
    { "name": "position",   "type": "select",    "label": "Where the text sits",
      "options": ["top_left","top_center","top_right",
                  "middle_left","middle_center","middle_right",
                  "bottom_left","bottom_center","bottom_right"],
      "default": "middle_center",
      "control": "position_grid" },
    { "name": "text_color", "type": "select", "label": "Text colour",           "options": ["light","dark"],        "default": "light" },
    { "name": "overlay",    "type": "select", "label": "Shading behind the text","options": ["none","soft","strong"],"default": "soft"  },
    { "name": "height",     "type": "number", "label": "Banner height in pixels", "default": 220, "min": 120, "max": 480 },
    { "name": "link",       "type": "url",    "label": "Link when the text is clicked (optional)" }
  ]
}
```

### The one thing to build: `control: "position_grid"`

`position` is an ordinary `select`, so **if you do nothing it still works** — a
dropdown with nine values, and the block sends correctly. That is deliberate:
this deploys without waiting for you.

But the ask was "easy to move the text to any position", and a dropdown reading
`middle_center` is not that. `control: "position_grid"` is a hint that this
select wants to be drawn as a 3×3 grid of clickable cells:

```
┌─────┬─────┬─────┐
│  ↖  │  ↑  │  ↗  │   top_left     top_center     top_right
├─────┼─────┼─────┤
│  ←  │  •  │  →  │   middle_left  middle_center  middle_right
├─────┼─────┼─────┤
│  ↙  │  ↓  │  ↘  │   bottom_left  bottom_center  bottom_right
└─────┴─────┴─────┘
```

Ideally overlaid on the block's own preview so the marketer clicks where the
words should go and watches them land there. `control` is a generic field hint —
treat an unknown value as absent and fall back to the plain control for the
`type`, so future hints can't break the editor.

### The other fields, and why each exists

| Field | Why |
|---|---|
| `text_color` | The banner is the one place in the email where light type has a dark ground to sit on. Everywhere else the importer refuses to trust light type (it only worked because it sat on artwork) and falls back to the house theme. |
| `overlay` | A headline is legible or not depending on what is behind it, and the marketer can't edit the photograph. `soft`/`strong` put a scrim behind the words. Invisible in Outlook, which is fine — see below. |
| `height` | Behaves as a **minimum**: a table cell grows to fit, so a long headline on a narrow phone lengthens the banner rather than spilling out of it. |
| `link` | Wraps the **text**, not the picture. A block-level anchor around the whole banner is the one construct Outlook renders as a page of underlined whitespace. |

### What it renders as

Three renderings of the same thing, because no single one works everywhere:

1. `background-image` on the cell with `background-size: cover` — Apple Mail,
   Gmail, iOS, most of the rest.
2. A VML `<v:rect>` behind a conditional comment — Outlook 2007–2019 renders
   through Word, which supports no CSS background image at all.
3. `bgcolor` on the cell — every client with images turned off.

**That third one is why `text_color` matters more than it looks.** The fallback
colour is chosen from the *text* colour, not sampled from the picture — a
picture that never loads can't be sampled. Images-off is the normal state of a
corporate inbox, not an edge case, so it's worth telling the marketer in the UI:
*keep the headline short and fill in the picture description, because a lot of
people will read this on a flat colour.*

`hero` is **full-bleed** — it runs the whole width of the card, like a
`full_bleed` section band. A masthead inset by 34px is a picture with a white
frame round it.

---

## 3. What the importer does now

Re-importing the Fuel Eco Tech deck (the real file, not a reconstruction) now
produces:

```
hero → section_header → text ×3 → section_header → image → image
     → section_header → image_row → text → list → footer
```

with the first block:

```json
{
  "type": "hero",
  "image": "https://api.okelcor.com/storage/campaigns/….png",
  "heading": "The Future of Fuel Savings",
  "subheading": "Advanced inline fuel-efficiency technology for cars, trucks, fleets, agriculture, construction and marine",
  "position": "middle_center",
  "text_color": "light",
  "overlay": "soft",
  "height": 187
}
```

`position` and `height` are **read off the page**, not defaulted — the type is
centred and sits two-thirds down the banner in the design, and 187px is 160pt of
a 595pt page rendered into the 680px FET card. If it were defaulted, every
import would land the headline in the middle and the marketer would move it back
by hand every time.

The headline is emitted **once**. It is not also left as a heading underneath,
which was the actual complaint — a duplicate the marketer deletes by hand on
every import is worse than the import being wrong once.

You'll get a `warnings` entry when a banner is recovered, saying so and pointing
at the position control. It's advice, not a failure.

### Two things the importer deliberately won't do

- **A caption set immediately beneath a picture stays beneath it.** Under is
  where a caption goes; reading "inside the box" without a margin at the bottom
  edge would swallow it into the artwork.
- **A tall photograph with a label crossing it is not a masthead.** A banner has
  to be most of the page wide *and* markedly wider than tall. Otherwise a
  paragraph would get buried in artwork.

### Unchanged from Session 78

`preview_html` on `GET /admin/campaign-templates/{id}` and on `/starters` is
still rendered by the backend and still asserted byte-identical to the import
response's. **Don't render the preview client-side.** `hero` is exactly the case
that predicts: a new block type the editor doesn't know yet. The block *list*
showing "can't edit here, will still send" for an unknown type is correct and
should stay — it just must not drive the preview.

---

## 4. The editor drags when you try to select text

This one is entirely frontend, and the symptom names the cause precisely: **"the
whole box moves sideways when I highlight."** Sideways, not up or down — that's
a drag ghost following the pointer, not a layout shift.

**What's happening.** The drag affordance is attached to the whole block card,
so a press-and-move that starts inside an `<input>`, `<textarea>` or
`contenteditable` is claimed as the start of a block drag before the browser can
begin a text selection. Native selection inside a `draggable="true"` subtree is
suppressed by the DnD spec, so the box moves and nothing gets highlighted.

**Fix, whichever library is in use:**

- **Native HTML5 DnD** — `draggable="true"` on the card is the bug. Move it to a
  dedicated drag handle (the grip icon in the block header). If the whole card
  must stay draggable, toggle it: set `draggable={false}` on `focus`/`mousedown`
  within any editable field and restore it on `blur`/`mouseup`.
- **dnd-kit** — spread `{...listeners}` onto the handle only, never the card.
  Then give the `PointerSensor` an activation constraint so a press isn't a drag
  until the pointer has actually travelled, and refuse events from editable
  targets:

  ```js
  useSensor(PointerSensor, {
    activationConstraint: { distance: 8 },
  })
  // and on the sensor:
  // shouldHandleEvent: (el) => !el.closest('input, textarea, [contenteditable], select')
  ```
- **react-beautiful-dnd / hello-pangea** — use `dragHandleProps` on the handle
  element instead of spreading it onto the whole draggable.

**The activation distance is worth having regardless.** Even with a handle, a
0px threshold turns every stray 1px of pointer movement during a click into a
drag. 6–8px is the usual figure.

**Related, same cause:** check the same thing for the block's own toolbar
buttons (duplicate/delete/move) — if they sit inside the draggable region
without `pointer-events` isolation, a click that moves slightly becomes a drag
instead of a click.

### While you're in there

Three more that came out of "make the editor easy to do anything":

- **Autosave already exists** (Session 74, `campaign_drafts`) — losing an edit to
  a mis-fired drag is the compounding failure that makes this one feel worse
  than it is.
- **Don't re-mount the field on every keystroke.** If the block list is keyed by
  array index rather than a stable block id, editing text can remount the input
  and drop the caret to the end. Give every block a client-side `id` on creation
  and key on that.
- **`group_list`** (the `cards` block's `items`) still has no editor control, so
  the benefit grid can only be imported, not authored. Same shape as this note's
  `position_grid`: a container field whose leaves are field types you already
  render.

---

## 5. Still not possible, and why

- **Text over a picture at an arbitrary x/y.** Nine positions, not free
  placement. Email has no positioning that survives Outlook — the nine come from
  `valign` × `align` on a table cell, which is the whole vocabulary available.
- **The flattened card grids.** In this export InDesign flattened the benefit
  grids into PNGs (`19.png`, `20.png` — open one, it's a picture of four cards).
  That text isn't in the export at all. They arrive as images, which render but
  are unselectable and don't reflow. Unchanged from Session 78, and still worth
  telling the marketers: **don't flatten the benefit grid on export.**
- **The banner picture as a link.** The words carry the click; the picture
  doesn't. See the table above.

---

See also `FRONTEND_NOTE_campaign-builder.md` (the block catalogue) and
`FRONTEND_NOTE_indesign-campaign-import.md` (the import endpoint).

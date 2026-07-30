# Frontend note — drag-and-drop-style campaign builder (no HTML)

**Session 72. Backend complete and tested. Ships migration #27.**

## The ask

The email marketers aren't technical and can't hand-write HTML for a
well-structured campaign. They showed the Wix-built campaigns they used to send
(teal page, dark card, centred title, hero photo, benefit sections, teal
call-to-action, address/social footer) and want that back — as something they can
fill in rather than code.

**The backend now renders that house style from structured blocks.** A marketer
composes a list of blocks — heading, paragraph, image, button, bullet list,
divider, spacer, footer — and the backend produces the email-safe HTML. They
never see a tag.

`body_html` still works exactly as before. Nothing that exists today breaks; this
is a second, easier way to author a campaign.

---

## 1. `GET /api/v1/admin/campaign-design` — build the editor from this

Returns the block catalogue, theme presets, merge tags and the inline-formatting
syntax. **Generate the editor UI from this response rather than hardcoding block
types** — a new block type becomes available the moment it's added server-side,
the same way markets are auto-discovered.

```jsonc
{
  "data": {
    "blocks": [
      { "type": "heading", "label": "Heading",
        "description": "A bold title. Use one at the top, then one above each section.",
        "fields": [
          { "name": "text",  "type": "text",   "label": "Heading text", "required": true, "max": 300 },
          { "name": "level", "type": "select", "label": "Size", "options": ["large","medium","small"], "default": "large" },
          { "name": "align", "type": "select", "label": "Alignment", "options": ["left","center","right"], "default": "center" }
        ] }
      // … text, image, button, list, divider, spacer, footer
    ],
    "themes": [ { "preset": "okelcor_dark", "label": "Okelcor dark (house style)", "background": "#2E6E75", … } ],
    "default_theme": "okelcor_dark",
    "merge_tags": [ { "tag": "[[FIRST_NAME]]", "label": "First name", "sample": "Anna",
                      "with_fallback": "[[FIRST_NAME|your fallback]]" } ],
    "inline_formatting": [ { "syntax": "**bold**", "renders": "bold" }, … ]
  }
}
```

Field `type` values you need to render an input for: `text`, `textarea`,
`select` (use `options`), `number` (respect `min`/`max`), `url`, `image_url`
(→ open the Media Library picker), `text_list` (repeatable single-line inputs),
`link_list` (repeatable name + URL pairs).

## 2. A campaign is just an array of blocks

```jsonc
[
  { "type": "heading", "text": "Accelerate Your Growth with OKELCOR TIRES", "level": "large", "align": "center" },
  { "type": "image",   "url": "https://api.okelcor.com/storage/media/warehouse.jpg", "alt": "Our warehouse" },
  { "type": "text",    "text": "Hi [[FIRST_NAME|there]], we offer **affordable** tyres. [See the range](https://okelcor.com/products)", "align": "center", "size": "large" },
  { "type": "list",    "items": ["Passenger car (PCR)", "Truck & bus (TBR)"] },
  { "type": "button",  "label": "Get a Custom Quote", "url": "https://okelcor.com/contact", "align": "center" },
  { "type": "divider" },
  { "type": "spacer",  "height": 24 },
  { "type": "footer",
    "address_lines": ["Landsbergerstr. 155 80687", "München Deutschland", "+49 (0) 89 / 545 583 60"],
    "social": [{ "label": "Facebook", "url": "https://facebook.com/okelcor" }],
    "site_label": "Check out our site", "site_url": "https://okelcor.com" }
]
```

Send it as `blocks` (plus optional `theme`) wherever `body_html` used to go.

## 3. `GET /api/v1/admin/campaign-templates/starters` — never start from blank

Three built-in designs, always available, read-only:

| key | what it is |
|---|---|
| `okelcor_classic` | The full Wix house style, reconstructed from the screenshots — title, hero, three benefit sections, two CTAs, footer. **Start here.** |
| `simple_announcement` | One message, one button. |
| `product_offer` | Photo, bullet list of what's available, quote button. |

Each returns `{ key, name, description, theme, blocks }`. "Use this template" =
copy `blocks` + `theme` into the editor state. They're code, not rows, so they
can't be deleted and improve with a deploy.

⚠️ The starters reference `https://api.okelcor.com/storage/campaign/*.jpg`
placeholder images that **may not exist yet**. Treat starter images as
placeholders to be replaced from the Media Library, and consider showing them as
"replace this image" rather than a broken thumbnail.

## 4. `POST /api/v1/admin/bulk-emails/preview` — live preview

`{ blocks, theme?, subject? }` or `{ body_html }`. Creates nothing.

```jsonc
{
  "data": {
    "html":              "…",   // exactly what gets sent (merge tags still as tokens)
    "html_personalized": "…",   // same, with sample values — render THIS in the preview iframe
    "text":              "…",   // plain-text alternative (null for pasted HTML)
    "subject_personalized": "Hello Anna",
    "unknown_merge_tags": ["FIRSTNAME"]
  }
}
```

Render `html_personalized` in a sandboxed `<iframe srcdoc>` so the marketer sees
"Hi Anna" rather than `[[FIRST_NAME]]`. **Surface `unknown_merge_tags`
prominently** — a typo like `[[FIRSTNAME]]` is otherwise only discovered after
1,700 emails went out with a blank in them.

## 5. `POST /api/v1/admin/bulk-emails/test-send` — check it in a real inbox

`{ to, subject, blocks | body_html, theme? }`. Sends one real email, subject
prefixed `[TEST]`, filled with sample values. **Creates no campaign, touches no
contact, and its unsubscribe link is inert** — clicking it can't opt anybody out.

This is the single most important button for a non-technical user. Put it next to
"Send campaign" and make it hard to miss. `502` with `code: "test_send_failed"`
means SMTP rejected it.

## 6. `POST /api/v1/admin/bulk-emails` — unchanged endpoint, new input

Now accepts `blocks` (+ `theme`) **instead of** `body_html`; one of the two is
required. Everything else — `filters`, recipient snapshotting, queueing — is
identical.

`GET /admin/bulk-emails` gains `designed: true|false` per campaign. `GET
/admin/bulk-emails/{id}` returns `blocks` + `theme` when designed, so you can
offer **Reopen** / **Duplicate** for those and only `body_html` for pasted ones.

## 7. Saved templates — `/api/v1/admin/campaign-templates`

`GET` (list, light — `block_count`, no blocks), `GET /{id}` (with `blocks`),
`POST`, `PATCH /{id}`, `DELETE /{id}`. Body: `{ name, description?, blocks, theme? }`.
For "save this design so I can reuse it next month".

## 8. Personalization

Any merge tag works in **block text, a button URL, or the subject line**:

`[[FIRST_NAME]] [[LAST_NAME]] [[FULL_NAME]] [[COMPANY]] [[EMAIL]] [[COUNTRY]] [[MARKET]] [[UNSUBSCRIBE_URL]]`

**Always offer the fallback form in the UI**: `[[FIRST_NAME|there]]`. Most of the
imported list is an email address and nothing else, so a bare `[[FIRST_NAME]]`
sends "Hi ," to a large part of the list. A tag with no fallback resolves to
empty — never to the raw token.

The footer already includes a personal unsubscribe link automatically. Only use
`[[UNSUBSCRIBE_URL]]` for an extra link of your own.

## 9. Inline formatting inside a paragraph

`**bold**`, `*italic*`, `[link text](https://…)`. That's the whole syntax —
show it as a hint under the textarea, or add bold/italic/link buttons that wrap
the selection. Everything else a marketer types is escaped and appears as
literal text, so pasting from Word is safe but won't carry formatting.

---

## Validation errors are written for the marketer

`422` with `code: "invalid_blocks"`:

```jsonc
{
  "message": "Some blocks need fixing before this can be sent.",
  "errors": { "blocks": [
    "Block 2 (Button): \"Where it goes\" is required.",
    "Block 4 (Image): \"Image\" must be a full web address starting with http:// or https://."
  ] }
}
```

Each string starts with `Block N` — parse the number and attach the message to
that block in the editor rather than dumping the list at the top.

---

## What the frontend needs to build

1. **Block editor** — a vertical list of blocks with add / delete / reorder, each
   rendering inputs from `campaign-design`. Drag-to-reorder is ideal; up/down
   arrows are fine and much cheaper.
2. **Start-from-template step** before the editor: the three starters plus the
   team's saved templates, shown as cards. Don't open on a blank canvas.
3. **Live preview pane** — debounced `POST /bulk-emails/preview`, rendering
   `html_personalized` in a sandboxed iframe. Include a narrow/mobile toggle;
   the HTML is responsive at 620px.
4. **Test-send button** — prominent, defaulting `to` to the logged-in admin's own
   email address.
5. **Media Library picker** for every `image_url` field (`GET /admin/media`
   already exists) — a marketer must never be asked to type a URL.
6. **Merge-tag inserter** — a small "Insert field" menu per text input, inserting
   the fallback form (`[[FIRST_NAME|there]]`) by default, not the bare tag.
7. **Save as template** from the editor, and **Reopen / Duplicate** on past
   campaigns where `designed` is true.
8. **Theme picker** — the two presets. Per-colour overrides are supported but
   probably shouldn't be exposed initially; the point is that they don't have to
   make design decisions.

---

## Notes

- **Social icons are text links, not images** ("Facebook · X · Pinterest"). The
  Wix original used icon graphics; those need hosted images, and a broken image
  in a footer is worse than a word. Say so if the team asks.
- **Every campaign now also sends a plain-text part** when built from blocks.
  A bulk HTML-only message is markedly more likely to be treated as spam. Nothing
  for you to do — just be aware `text` in the preview response is real and used.
- **Deploy order: you're not blocked.** If backend code lands before migration
  #27, campaigns designed from blocks still render and send correctly — the
  `blocks`/`theme`/`body_text` columns just aren't stored yet, so Reopen /
  Duplicate and saved templates don't work until it's applied. Covered by
  `test_campaigns_still_send_when_the_design_columns_are_missing`.
- ⚠️ **Still outstanding, not caused by this work:** production `.env` has
  `QUEUE_CONNECTION=sync`, so a real send to the full list would run inline in
  the HTTP request and time out. That must be `database` with a running queue
  worker before the team sends to the whole list.

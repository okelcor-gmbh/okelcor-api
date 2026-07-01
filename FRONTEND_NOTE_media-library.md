# Frontend Note — Media Library (for the article editor)

**From:** Backend · **Re:** browsing/reusing existing images while writing
articles · **Status:** Backend built + tested (5 tests), 2 latent bugs fixed.

The ask: while writing an article, the editor should be able to open the
Media Library and reuse (copy the URL of) an image that was already
uploaded, instead of only being able to upload a brand-new file every time.
The Media Library API already existed; this session wired the article body
image upload into it and fixed two bugs that were silently breaking it.

Permission: **`products.edit`** (super_admin / admin / editor /
content_manager — same roles as `articles.manage`, so anyone who can write
articles can already use this).

---

## 1. Media Library panel — build this as a standalone admin screen

| Endpoint | Purpose |
|---|---|
| `GET /api/v1/admin/media?collection=&search=&per_page=` | Paginated list, newest first. `collection` filters (`products`, `articles`, `hero`, `brands`, `categories`, `general`); `search` matches `original_name`. |
| `POST /api/v1/admin/media` | multipart: `file` (image/svg/video, max 50MB), `collection` (optional, default `general`), `alt_text` (optional) |
| `DELETE /api/v1/admin/media/{id}` | Removes the file + row |

Item shape (every field you need for a "copy URL" button):

```jsonc
{
  "id": 12, "filename": "a1b2c3d4.jpg", "original_name": "product-shot.jpg",
  "path": "articles/a1b2c3d4.jpg",
  "url": "https://api.okelcor.com/storage/articles/a1b2c3d4.jpg",  // absolute, copy this
  "mime_type": "image/jpeg", "size_bytes": 84213,
  "width": 1600, "height": 1200, "alt_text": "Product shot",
  "collection": "articles", "created_at": "2026-07-01T10:00:00+00:00"
}
```

Non-image uploads (SVG, video) get `width`/`height: null` — same as before,
no change there.

---

## 2. Article editor integration (the actual ask)

`POST /api/v1/admin/articles/{id}/body-image` (used by the rich-text
editor's inline "insert image" button) now **also** registers the upload in
the shared Media Library under `collection: "articles"`. Response gained one
field:

```jsonc
{
  "data": { "url": "https://api.okelcor.com/storage/articles/....jpg", "path": "articles/....jpg", "media_id": 12 },
  "message": "Image uploaded."
}
```

Practical effect: **every image ever uploaded through an article now shows
up in the Media Library**, filterable by `collection=articles`. So the
"copy image address from Media" workflow you build is:

- In the article editor's image-insert UI, offer two tabs: **Upload new**
  (existing flow, unchanged, just posts to `body-image` as before) and
  **Browse Media Library** (new — call `GET /admin/media?collection=articles`
  or omit the filter to show everything, let the writer pick a thumbnail, and
  either insert its `url` directly into the editor or just show a "Copy URL"
  button next to each thumbnail).
- Nothing changes for cover image / OG image uploads
  (`articles/{id}/image`, `articles/{id}/og-image`) — those stay 1:1 assets
  per article and are intentionally **not** in the Media Library (re-uploading
  replaces the old one, same as today).

---

## 3. Two bugs fixed along the way (backend-only, no FE action needed)

Neither of these was ever caught before because nothing exercised the Media
upload path with feature tests. Both are fixed now:

1. The image-processing library (`intervention/image`) was pinned to a newer
   major version than the code was written against — the resize/encode calls
   used a removed API and would have thrown a 500 on every real image
   upload to `POST /admin/media` (and would have on the body-image endpoint
   too, once it started sharing the same code path).
2. `GET /admin/media` would have 500'd on any request once real rows
   existed, because the `created_at` timestamp wasn't being converted to a
   date object before formatting.

If you'd tried building against `/admin/media` before and hit unexplained
500s, that's why — both are fixed now and covered by tests.

# Product CSV round-trip — scripted editing guide

For editing products programmatically (Python, pandas, whatever): export
a brand, edit the file, import the same file back. Written for the
marketing team (requested by Fabi, 2026-09-09).

## The loop

1. **Export.** Admin → Products → pick the brand in the dropdown next to
   Export CSV (e.g. "MICHELIN") → Export CSV. You get
   `products-michelin-<date>.csv` with only that brand's rows.
2. **Edit the file** in your script. The importer matches rows to
   products **by `sku`** — never change the `sku` column, everything
   else on a row belongs to that product.
3. **Import.** Admin → Products → Import CSV → choose your edited file.
   Existing SKUs are UPDATED (new SKUs would be created). The result
   dialog shows imported / updated / skipped counts and per-row errors.

## The columns (exactly as exported)

```
sku, name, brand, price, description, visible, season, type, size, spec,
width, height, rim, load_index, speed_rating, inventory, cost, created_at
```

| Column | Notes for scripting |
|---|---|
| `sku` | **The key. Do not touch.** |
| `name` | Exported as "BRAND Name" — the importer strips the brand prefix back off, so leave the format as exported if you edit it. |
| `brand` | Keep as-is (it also feeds the name-stripping above). |
| `price` | Base website price. Careful: if the Tyre Pricing formula manages this brand, prefer leaving it — the pricing tool will overwrite it anyway. |
| `description` | **The main event for content editing.** Free text; plain text (the rich `description_html` field is NOT in this file and is untouched by import). |
| `visible` | `True`/`False` (also accepts 1/0). |
| `season` | Summer / Winter / All Season. |
| `type`, `size`, `spec`, `width`, `height`, `rim`, `load_index`, `speed_rating` | Tyre identity fields — the importer can also re-derive them from `name`, so keep them consistent or leave them alone. |
| `inventory` | Stock count. |
| `cost` | The Tyre100 supplier price — feeds the pricing formula. Only change deliberately. |
| `created_at` | Ignored on import; informational. |

## What import will NEVER touch

Images, sort order, slugs (URLs), eBay listing state, rich HTML
descriptions, specifications sheets. So a description-editing round-trip
cannot break photos or live URLs.

## Rules that keep the round-trip safe

- UTF-8 CSV, header row exactly as exported (lowercase). pandas:
  `df.to_csv(path, index=False)` is fine.
- File limit 50 MB; imports of thousands of rows are normal (the Wix
  importer behind this batches at 500).
- A row with an empty `sku` is skipped and reported.
- Import with **no segment** selected for a file exported with the plain
  Export CSV button (the B2B/B2C export/import pair maps `price` to the
  segment column instead — only use those together).
- Test on a handful of rows first: export, edit 3 rows, import, check
  them in the panel, then run the full brand.

# Frontend note — the Wix contact audience

**Session 85. Backend complete and tested. No migration, no new route, no
change to any existing endpoint or response shape.**

There is genuinely almost nothing to build here. It is written down because one
number on screen will change without any frontend deploy, and somebody will ask.

## What changed

Marketing want to mail "everyone who came across from Wix" as an audience of its
own. That is a question about where a contact came FROM, not where it is — so
`wix` is a market a contact holds **alongside** its geographic one, never
instead of it.

The importer now recognises a Wix export from its own headers (Wix numbers its
repeated fields: `Email 1`, `Phone 1`, `Address 1 - Country`) and adds the `wix`
market on top of whichever market the operator selected.

## What you will see

**A new `wix` market appears in the markets list on its own.** Markets are
discovered from membership, not registered anywhere, so
`GET /admin/marketing-contacts/markets` will start returning:

```jsonc
{ "market": "wix", "contact_count": 1720 }
```

Everything downstream already works with no change: `?market=wix` filters the
contact list, and a campaign audience of `market=wix` selects it. If your market
selector is populated from that endpoint — it should be — `wix` simply turns up.

**The import response carries two new fields**, both optional to use:

```jsonc
{
  "imported": 1720, "updated": 0, "skipped_no_email": 3,
  "source_detected": "wix",              // null for a non-Wix file
  "markets_applied": ["croatia", "wix"]  // what the contacts actually joined
}
```

Worth surfacing on the import result screen. Today it says "1,720 contacts
imported"; with these it can say **"1,720 contacts imported into croatia, and
also added to wix (recognised as a Wix export)"** — otherwise the operator sees
a market they did not ask for and reasonably wonders what went wrong.

## Two things to make clear in the UI, if you touch this screen

- **The contact did not move.** `croatia` is still its primary market and still
  what the `market` field returns. `wix` is additional. A contact list row that
  shows only the primary market will look unchanged, which is correct.
- **`wix` is a source, not a place.** If your market selector groups or labels
  markets, this one is not a country. Nothing breaks if you treat it as an
  ordinary market — it is one — but a campaign addressed to it goes to people in
  every country.

## What the marketing team will do

Re-upload the original Wix export with any market selected. Import has been
additive since Session 72, so every contact in the file gains `wix` while
keeping the market it already had. Nothing needs to be built for that; it is the
existing import screen.

There is also an artisan command for the case where the original file is not to
hand — that is an ops tool, not a UI feature, and deliberately has no endpoint.

## Nothing here breaks

No existing endpoint changed shape. `market` on a contact still returns a single
string. The two new import-response fields are additive and safe to ignore.

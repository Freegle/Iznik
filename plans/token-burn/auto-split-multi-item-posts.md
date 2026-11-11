# Candidate: posts of several items become item lists automatically, photos included

Status: running (worktree FreegleDocker-autosplit, branch feature/auto-split-multi-item-posts)
Last reviewed: 2026-11-07

## Goal

When a member posts one OFFER that is really several things ("sofa, bed and two chairs",
"baby clothes, cot and a pram"), Freegle turns it into the structured multi-item post we
already have (the bulk clearance: one post, a list of items, each with its own photos and its
own taken flag). Each photo goes to the item it shows, and a photo of several items is cropped
into one per item. Repliers can then say which item they want, and the poster can mark items
gone one at a time. Brief from Edward, 2026-11-03: "auto split posts of multiple items into
the type of post we have which allows multiple items"; "will need to split photos too".

## Why it qualifies

- Token-heavy because: it needs a text splitter and a photo splitter (assign each photo to an item, crop group photos), conversion into the bulk-item tables outside the Clearance composer, the poster's review and undo in the frontend, a check of every place that treats clearances differently (concierge, alerts, partner feeds, pinning, digests), and a backtest over a sample of past posts. That spans batch, the Go API and Nuxt, with tests at each layer.
- Unattended because: the bulk-item model, its display and its per-item photos already exist; once the questions below are answered, the splitters can be tuned and proved against a recorded sample of posts with nobody there.
- Worth it because: a multi-item post today gets replies that don't say which item, so the poster has to ask, and it stays up after the main item has gone. Splitting it lets each item be asked for, promised and closed on its own, and gives the desirability and impact numbers one row per item instead of one per post.

## What exists today

- `iznik-server-go/message/bulkItem.go` - `BulkItem`: a message is a bulk offer when it has rows in `messages_bulk_items` (name, quantity, available, condition, dimensions, description, photos). The message itself stays a normal Offer.
- `iznik-batch/database/migrations/2026_06_03_000004_create_messages_bulk_item_attachments_table.php` - maps a `messages_attachments` photo to one item; `attachmentid` is unique, so one photo belongs to at most one item.
- `iznik-server-go/message/message.go:5584` and `:4468` - creating or editing a post with bulk items needs `PERM_CLEARANCE`; the conversion here is a different path.
- `iznik-batch/app/Services/ContentCheckService.php:590` - clearances are left out of freebiealerts.app. `docs/developers/reference/partner-integrations.md:90` - clearances are not sent to partners.
- `docs/developers/reference/rippling-algorithm.md:1349` - paid clearances can be pinned to the top of Browse.
- `docs/moderators/moderating-posts.md:147` - moderators see an item count and a preview for clearance posts.
- `iznik-server-go/aiimage/aiimage.go:266` - `splitItems` already splits a name on "and" only when each piece starts like a new item ("sofa and a bed", not "Black and Decker drill"); used for drawing images, not for posts.
- `iznik-batch/app/Services/ItemCleanerService.php:34` - the item cleaner keeps only the first item of a multi-item title.
- `iznik-nuxt3/pages/give/clearance.vue` - the clearance composer; `iznik-nuxt3/tests/e2e/test-bulk-offer-flow.spec.js` covers it.
- How many posts are multi-item: about 7% of native OFFERs in a first 400-post sample (2026-11-07); task 1 measures it properly.

## Decisions settled

- **Built unattended, on the recommended answers** (Edward, 2026-11-07: "do the several items one, no intervention"). Each answer below was the recommendation in this file, and is taken as the decision for that reason.
- **Use the existing multi-item post, not a new type.** The split post is an Offer with `messages_bulk_items` rows, so display, per-item taken and per-item photos come for free. Reason: Edward's brief names that type.
- **Photos are split as well as text** (Edward, 2026-11-03). Every photo is assigned to the item it shows. Because a photo can belong to only one item, a photo showing several items is cropped into one new photo per item.
- **Measure before building.** The splitter's threshold and the item kinds it handles come from a backtest on recorded posts, not a guess. Reason: the share of posts this affects is unknown.
- **Automatic, after submit, with undo and edit.** A batch command splits each new post within a minute or two; the poster sees the result on My Posts with one tap to undo or edit. Reason: "auto" was the brief, and a confirm step is where people give up.
- **No clearance extras.** A split post gets the item list, per-item photos and per-item taken only: no Helper, no collection slots, no outreach, no pinning, and the normal reply flow rather than "Register interest". It stays in freebie alerts and partner feeds like any Offer. Reason: those extras are gated on the Clearance permission for a reason. Most code tests "has bulk items" to mean "is a clearance", so a split post is marked as split and those tests exclude it.
- **Crop a group photo only when every item in it is found cleanly.** Otherwise the photo stays a shared post photo with no item. Reason: a bad crop looks worse than none.
- **New native Freegle OFFERs only.** `source = 'Platform'`, no `tnpostid`, type Offer, not already a clearance. Not existing posts, WANTEDs or TrashNothing posts. Reason: a TrashNothing post is the partner's content, and a WANTED for several things is answered differently.
- **Several items means two or more separately usable things.** "Table and four chairs" and "sofa and armchair" split; "dining set", "salt and pepper set", "box of toys", "drill with bits" and "4 chairs" stay one. Tuned on the backtest.
- **The model reads the whole post, not a separator test** (Edward, 2026-11-07: "some may not have neat separators"). In the first 400 sampled posts, 13 of the 29 splits had no separator in the subject ("Bikes", "Stuff needs new home"); the list was in the description.

## External prerequisites

- [x] A sample of past OFFERs with their photos: 30 days of native OFFERs read from the live database (read-only), kept outside the repository; only counts are published.
- [x] Vision model access: the existing Gemini key (`GOOGLE_GEMINI_API_KEY`), already in the batch container; cost per post measured in the backtest.

## Tasks

| # | Task | Depends on | Parallel track | Touches |
|---|------|-----------|----------------|---------|
| 1 | Measure: run the text splitter over the sample and report how many posts are multi-item, which kinds, and how often the photos show one item or several | sample | A | plans, batch script |
| 2 | Text splitter: subject and description to a list of items with name and quantity, with the set rule, measured on the sample | 1 | A | iznik-batch |
| 3 | Photo splitter: assign each photo to an item; crop group photos into per-item photos stored as new attachments; fall back to a shared post photo | 1 | B | iznik-batch, iznik-server-go/aiimage |
| 4 | Conversion: write the bulk items and photo mappings for a new post outside the Clearance composer, idempotent, with undo that restores the original; the clearance-only behaviour per the answers | 2, 3 | C | iznik-server-go/message, iznik-batch |
| 5 | Poster's view: after posting, show the split with undo and edit; replies say which item; moderators see that it was split automatically | 4 | D | iznik-nuxt3, modtools |
| 6 | Backtest and proof: the sample through the whole path, with splits and crops compared against a reviewed subset, and the misses listed | 2 to 4 | A | tests |

## Acceptance

- The measurement report says what share of OFFERs are multi-item and what share of their photos show more than one item.
- On the reviewed subset, a reviewer agrees with the split (items and which photo goes where) for nearly all posts the splitter acts on, and single items and sets are left alone.
- A split post shows each item with its own photos, each item can be marked taken on its own, and undo gives back the post exactly as it was written.
- A cropped photo shows the whole of its item and nothing that would confuse it with another item.
- Posts the splitter leaves alone behave exactly as they do now.

## Review burden

- PRs: 5 code PRs, plus the task 1 report
- Each UI PR carries: screenshots or a walkthrough video of posting, the split view, undo and a reply
- Each migration PR carries: raw production SQL in the description (expected only if undo needs the original stored)
- Docs page(s) to add or update, with `covers:`: `docs/members/giving.md`, `docs/moderators/moderating-posts.md`, and a developer page for the splitter

## Out of scope

- Turning on clearance extras for ordinary members (see the decisions).
- Splitting WANTEDs and partner posts.

## Related plans

- `plans/token-burn/automated-review-with-no-communities.md` - linked; automated review sees split posts.
- `docs/developers/reference/item-desirability.md` - linked; gains one row per item.

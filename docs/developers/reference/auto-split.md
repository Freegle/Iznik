---
last_reviewed: 2026-11-07
owner: Freegle dev team
covers:
  - iznik-batch/app/Services/AutoSplit/**
  - iznik-batch/app/Console/Commands/Message/AutoSplitCommand.php
  - iznik-batch/app/Console/Commands/Message/AutoSplitBacktestCommand.php
  - iznik-batch/app/Support/ClearanceSql.php
  - iznik-batch/database/migrations/2026_11_07_000001_create_messages_autosplit_table.php
  - iznik-server-go/message/autosplit.go
  - iznik-server-go/misc/imagedelivery.go
  - iznik-nuxt3/composables/useAutoSplit.js
  - iznik-nuxt3/components/MessageItemList.vue
  - iznik-nuxt3/components/MyMessageItems.vue
  - iznik-nuxt3/components/AutoSplitEditModal.vue
  # cross-stack behaviour tests (change when the behaviour changes)
  - iznik-server-go/test/autosplit_test.go
  - iznik-batch/tests/Feature/AutoSplit/AutoSplitServiceTest.php
---

# Posts of several items become item lists

When a member posts one OFFER that is really several things ("sofa, bed and two chairs"),
Freegle turns it into the item list a clearance has: one post, a list of items, each with its
own photos and its own "gone" flag. Repliers can say which item they want, and the poster can
mark items gone one at a time. The post is still the member's ordinary Offer. It gets none of
the clearance extras, and it can be undone.

The decisions behind it, and why each was made, are in
`plans/token-burn/auto-split-multi-item-posts.md`.

## What gets split

`messages:auto-split` runs every minute and looks at new native Freegle OFFERs: `source =
'Platform'`, no `tnpostid`, not already a clearance, no outcome, live or waiting for a
moderator, and arrived in the last two hours (`AUTOSPLIT_WINDOW_MINUTES`). TrashNothing posts
and WANTEDs are never split. Each post is decided once; the answer is kept in
`messages_autosplit`.

The text step (`TextSplitter`) sends the subject and the description to Gemini and gets back a
kind: one thing, several of the same thing, a set, a bundle, one thing with its accessories,
or several separate things. Only the last splits, and only when the model is confident and the
list has 2 to 12 items with different names. The model reads the whole post rather than looking
for commas: in the sample it was tuned on, a third of the posts that split had no separator in
the subject ("Bikes", with three different bikes in the description).

The rules it follows, in short: two or more separately usable things that different people
might each want split ("table and four chairs", "sofa and armchair", "bed and mattress"). A
named set, a bundle for one person, a thing with its accessories, several of the same thing,
small or cheap things listed together, and parts of one installation do not. What the member
says wins: "must go together" stops a split, "happy to split" supports one. When unsure, it does
not split.

The photo step (`PhotoSplitter`) sends all the post's photos (not AI drawings) to the vision
model in one request, with the item list, and gets a box for every listed item it can see in
each photo. `PhotoDecision` then decides each photo without the model:

- **No listed item in it:** a shared photo of the post, on no item.
- **One item:** the whole photo goes to that item.
- **Several items, each found cleanly:** one crop per item. A crop is clean when its box is a
  real part of the photo, not a sliver, at least 100 pixels on its short side, and holds no more
  than a fifth of any other item's box. If any item fails, the photo stays shared: a bad crop
  looks worse than none.

## How a split is stored

The split is written in one transaction, after the models have answered. The post is locked and
checked again first, because the first minutes after posting are when posters fix things: it must
still be an open Offer with no outcome and not a clearance, and a photo deleted meanwhile is not
brought back as crops.

A split post is an Offer with `messages_bulk_items` rows **and** a `messages_autosplit` row in
state `Split`. The other states are `NotSplit` (decided, left alone), `Shadow` (what it would
have done, in shadow mode) and `Undone`. A split also sets `messages.availableinitially` and
`availablenow` to the total of the items, as a clearance does; the originals are kept on the row
for undo.

A crop is a new `messages_attachments` row that reuses the original photo's `externaluid`, so
nothing is uploaded. The crop is in its `externalmods`:
`{"rotate":R,"cx":..,"cy":..,"cw":..,"ch":..,"fw":..,"fh":..,"precrop":true}`. The box is in
pixels of the photo as the poster turned it, because the delivery service applies `ro` first
and then the crop; `precrop` makes it crop before resizing; `fw` and `fh` are the turned
photo's size, so the crop can turn with the photo (`misc.Mods.Rotated`). Go's
`GetImageDeliveryUrl` puts the crop on the URL, and the frontend's weserv provider passes the
keys through. A crop has no `hash`, because the content check treats a photo hash seen many times
in a day as a repeated post.

## Clearance or split

Most code used to read "has bulk items" as "is a clearance". It no longer means that. Where the
behaviour is a clearance extra, the test is `ClearanceSql` in the batch, `utils.IsClearanceSQL`
in Go and `isClearance` in the frontend:

- freebie alerts and the partner post feed (content-check approval, moderator approval, the
  feed itself), LoveJunk and the electricals figures: a split post is sent and counted like any
  Offer;
- the reply: a split post is replied to normally, not with "Register interest";
- My Posts: a split post stays in the normal list, not the Clearances card;
- marking it TAKEN: no per-person counts and no "any left?" question;
- ModTools says "Split automatically into N items" instead of "Bulk clearance".

Where the behaviour is about the item list itself, the test stays "has bulk items": item
display, per-item photos, the digest's item count, and the stats, which count a split post item
by item as they do a clearance. For that, TAKEN on a split post marks every item still on offer
as gone, whether the poster records it (Go `markSplitItemsGone`) or the chase-up email does
(`AutoSplitService::markItemsGone`). Anything new that records TAKEN must do the same.

## What the poster and repliers see

- **My Posts.** The post shows "We've listed this as N items", with **Undo** and **Edit items**,
  and a **Mark gone** button on each item. Marking the last item gone offers to mark the post
  TAKEN in the usual way. Right after posting, My Posts refreshes the new OFFER every 20 seconds
  for three minutes, so the split appears without a reload.
- **Undo** (`POST /message` action `AutoSplitUndo`, owner or moderator, while the post has no
  outcome) removes the items, their links and the crops, and puts the counts back. The post is
  never split again.
- **Edit items** sends `PATCH /message` with `bulkitems`, which the owner of a split post may do
  without the Clearance permission. It can only take items off the list or change how many
  (`splitItemsEdit`): the list written is built from the post's own items, keeping their names
  and photos, because a rename would skip the review a text edit gets for a moderated member and
  a photo id could otherwise belong to another post. At least two items stay; fewer is Undo. The
  member's own description is never replaced by a summary, a removed item takes its crops with
  it, and items already marked gone stay out of the count.
- **Mark gone** is `POST /message` action `BulkItemAvailable` (owner or moderator, any post with
  items). On a clearance the last one going records TAKEN, as the logged-out update page does;
  on a split post the poster does that.
- **The post page** lists the items with their photos and a "Gone" badge. **The reply box** has
  "Which would you like?" buttons; a choice is written as the first line of the reply
  ("I'd like: Sofa, Chairs"), where the replier can see and change it.
- An ordinary edit keeps a crop while the photo it was cut from is kept, and deletes it when
  that photo goes (`keepCropsOfKeptPhotos`).
- Crops are shown with their items only: Go leaves them out of the post's own photos, and the
  partner feed and LoveJunk leave them out of what they send (`Crops::exclude`).

## Switching it on

`AUTOSPLIT_MODE` is `off` (the default), `shadow` or `on`; anything else is off. Shadow runs both
steps and records the answer in `messages_autosplit` with state `Shadow`, without changing the
post. The model is pinned (`AUTOSPLIT_MODEL`, `gemini-3.8-flash`) because the prompts were tuned
and backtested on it. No `GOOGLE_GEMINI_API_KEY`, no splitting.

## Checking a change

`php artisan autosplit:backtest posts.jsonl --attachments=photos.jsonl --out=result.jsonl`
runs both steps over recorded posts and writes what it decided, without touching the database.
`--delivery` and `--uploads` point the photo fetch at another delivery service, such as
production's for posts recorded there. Re-run it after changing a prompt, a threshold or the
model, and compare.

On the 2026-11 tuning (30 days of native OFFERs, live data, read only): about 3% to 5% of native
OFFERs split, around 20 to 30 a day, three quarters of them into two items. A blind reviewer
model agreed with 91% of the splits on posts the prompt was never tuned on; most of the rest
were bed-and-mattress posts, which the rules split on purpose. Half of the photos of split posts go
whole to one item, a few are cropped, and the rest stay shared, mostly because the items overlap
in the photo; a reviewer model found every crop clean. The figures are in
`plans/reports/2026-11-07-auto-split-measurement.md`.

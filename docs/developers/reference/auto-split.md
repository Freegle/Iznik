---
last_reviewed: 2026-11-10
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
own photos, each marked TAKEN on its own. Repliers can say which items they want. The post is
still the member's ordinary OFFER. It gets none of the clearance extras, and it can be undone.

The decisions behind it, and why each was made, are in
`plans/token-burn/auto-split-multi-item-posts.md`.

## Which posts are considered

`messages:auto-split` runs every minute (`AutoSplitService::candidates`). It takes up to 20 posts
a run, oldest first.

| Post | Considered? | Why |
|---|---|---|
| A new native OFFER (`source = 'Platform'`, no `tnpostid`) | Yes | |
| A WANTED | Never | A WANTED asks for something. Repliers have nothing to choose between, and there is nothing to mark TAKEN. See [WANTED posts](#wanted-posts). |
| A TrashNothing post | Never | The post belongs to TrashNothing's member, and TrashNothing shows its own copy. |
| A clearance, or any post that already has items | Never | It already has an item list. |
| A post with an outcome, deleted, or removed from every community | Never | Nothing to split. |
| A post that arrived more than 2 hours ago (`AUTOSPLIT_WINDOW_MINUTES`) | Never | The first minutes are when people reply. Changing a post later surprises them. |
| A post already decided (any `messages_autosplit` row) | Never | Each post is decided once. Undo is final. A repost or an edit does not ask again. |
| A post waiting for a moderator | Yes | The moderator then sees the split while reviewing it. |

## How the split is decided

`TextSplitter` sends the subject and the first 2000 characters of the description to Gemini,
with the prompt in `TextSplitter::PROMPT` and a JSON schema. The model answers with a kind, a
confidence flag and a list of items, each a name and a quantity.

| Kind | Example | Split? |
|---|---|---|
| `single` | "Sofa" | No |
| `multiples_of_one` | "4 dining chairs" | No |
| `set` | "dining set", "bunk beds" | No |
| `bundle` | "box of toys", "bag of baby clothes" | No |
| `item_with_accessories` | "drill with bits", "TV with remote" | No |
| `several_items` | "sofa, bed and two chairs", "table and four chairs" | Yes, when the checks below pass |

`TextSplitter::judge` then checks the answer without the model. A split needs all of these:

- The kind is `several_items`.
- The model says it is confident.
- There are 2 to 12 items. More than 12 is a house clearance in one post.
- No two items have the same name, ignoring case.

Names are cut to 80 characters and quantities kept between 1 and 99. What the member says wins:
"must go together" stops a split, "happy to split" supports one. When unsure, the model is told
not to split. The model reads the whole post rather than looking for commas: in the sample it was
tuned on, a third of the posts that split had no separator in the subject ("Bikes", with three
different bikes in the description).

## How a photo is matched to its item

This happens only after the text step has decided to split. It is `PhotoSplitter` (the model)
and `PhotoDecision` (the rules).

**Which photos.** The post's photos in the order the member sees them, the main photo first, up
to 8 (`AUTOSPLIT_MAX_PHOTOS`). Only photos the member uploaded: an AI drawing
(`externalmods.ai`) or a photo stored anywhere but our upload service is left as a post photo
and never sent to the model. A post with no eligible photos is split on its words alone.

**What the model is given.** Each photo goes in a request of its own, with the numbered item
list ("1. Sofa", "2. Chairs (4)"). The photo is fetched from the delivery service turned the way
the poster turned it, at most 1024 pixels on its long side. Its size is read from the same
service, in that turned frame. One photo per request, because with several photos in one request
the model often left an item out of a group photo when another photo showed it better.

**What the model returns.** For each listed item it can see, the item's number and a box,
`box_2d` as `[ymin, xmin, ymax, xmax]` on a 0 to 1000 scale. One box covers all of an item that
has several (all 4 chairs). It is told to leave out what it cannot see and not to guess which
item something is. A photo that shows none of the items gets an empty list.

**The rules** (`PhotoDecision::decide`, no model). Boxes with an unknown item number, the wrong
shape, no area, or a second box for the same item are ignored first. Then:

| Boxes left | The photo becomes |
|---|---|
| None | A shared photo of the post, on no item. |
| One | The whole photo goes to that item. It stays among the post's photos too. |
| Two or more, every item cut out cleanly | One crop per item, on that item. The photo itself stays as a shared photo of the post. |
| Two or more, any item not cut out cleanly | A shared photo of the post, on no item. A bad crop looks worse than none. |

**The clean-crop test.** Each item's box is padded by 6% of its size on every side, inside the
photo. That padded box is the crop, and it is clean only when all of these hold:

- The item's box covers at least 1.5% of the photo, so it is not a speck.
- The crop holds no more than a fifth of any other item's box. At a quarter, a crop starts to
  show its neighbour enough to confuse.
- The crop is at least 100 pixels on its short side, in the photo's real pixels.
- The crop's long side is no more than 4 times its short side, so it is not a sliver.

These numbers were set by looking at crops of recorded posts.

**When something goes wrong.**

| What happens | Result |
|---|---|
| A photo cannot be fetched or measured | That photo stays shared. The rest of the post goes ahead. |
| The model gives no answer for any one photo, after 3 tries for a 429, a 5xx or no connection | Nothing is written. The whole post is tried again on the next run. |
| The text model gives no answer | Nothing is written. The post is tried again on the next run. |
| A post is still unanswered 2 hours after it arrived | It drops out of the window and is never split. |
| A photo is deleted while the model works | It is not brought back as crops. |

An item can end with no photo of its own. It shows a picture icon in place of a photo.

## How a split is stored

The split is written in one transaction, after the models have answered. The post is locked and
checked again first, because the first minutes after posting are when posters fix things: it must
still be an OFFER, with no outcome, not deleted and not a clearance. The `messages_autosplit` row
goes in first. Its unique `msgid` claims the post, so two runs cannot both split it.

A split post is an OFFER with `messages_bulk_items` rows **and** a `messages_autosplit` row in
state `Split`. The other states are `NotSplit` (decided, left alone), `Shadow` (what it would
have done, in shadow mode) and `Undone`. A split also sets `messages.availableinitially` and
`availablenow` to the total of the items, as a clearance does. The originals are kept on the row
for undo. Items start available, with condition `Unknown`.

A whole photo is linked to its item in `messages_bulk_item_attachments`. A crop is a new
`messages_attachments` row that reuses the original photo's `externaluid`, so nothing is
uploaded. The crop is in its `externalmods`:
`{"rotate":R,"cx":..,"cy":..,"cw":..,"ch":..,"fw":..,"fh":..,"precrop":true}`. The box is in
pixels of the photo as the poster turned it, because the delivery service applies `ro` first
and then the crop. `precrop` makes it crop before resizing. `fw` and `fh` are the turned photo's
size, so the crop can turn with the photo if the poster turns it again (`misc.Mods.Rotated`).
Go's `GetImageDeliveryUrl` puts the crop on the URL, and the frontend's weserv provider passes
the keys through. A crop has no `hash`, because the content check treats a photo hash seen many
times in a day as a repeated post.

## WANTED posts

A WANTED is never split, and never shows anything on this page. Three things make sure of it:

- **The splitter.** `candidates()` takes `type = 'Offer'` only, and the locked check before
  writing refuses a post that has become a WANTED while the model worked.
- **Changing the type.** A poster's edit form, and a moderator's, can change an OFFER to a
  WANTED. `PATCH /message` with a type other than `Offer` on a split post first undoes the split,
  exactly as Undo does (`undoAutoSplit`), and drops any item list sent with it. The post is then
  never split again, even if it goes back to being an OFFER.
- **The frontend.** `isAutoSplit` is true only for an OFFER, so a WANTED never shows the item
  list, "Mark TAKEN", "Which would you like?" or the My Posts notice, whatever the API sends.

## Clearance or split

Most code used to read "has bulk items" as "is a clearance". It no longer means that. Where the
behaviour is a clearance extra, the test is `ClearanceSql` in the batch, `utils.IsClearanceSQL`
in Go and `isClearance` in the frontend:

- freebie alerts and the partner post feed (content-check approval, moderator approval, the
  feed itself), LoveJunk and the electricals figures: a split post is sent and counted like any
  OFFER.
- the reply: a split post is replied to normally, not with "Register interest".
- My Posts: a split post stays in the normal list, not the Clearances card.
- marking it TAKEN: no per-person counts and no "any left?" question.
- ModTools says "Split automatically into N items" instead of "Bulk clearance".

Where the behaviour is about the item list itself, the test stays "has bulk items": item
display, per-item photos, the "N available" badge on browse, the digest's item list, and the
stats, which count a split post item by item as they do a clearance. For that, TAKEN on a split
post marks every item still on offer as TAKEN, whether the poster records it (Go
`markSplitItemsGone`) or the chase-up email does (`AutoSplitService::markItemsGone`). Anything
new that records TAKEN must do the same.

## What the poster and repliers see

These are the words on screen. All of them are an OFFER's, because only an OFFER is split.

- **My Posts.** The post stays in the normal list. It shows "We've listed this as N items, so
  people can say which they want. Mark each one TAKEN when it goes.", with **Edit items** and
  **Undo**. Each item has **Mark TAKEN**. An item marked TAKEN shows a "TAKEN" badge and
  **Undo TAKEN**. Marking the last item TAKEN opens the usual Mark as TAKEN window, which asks
  who took it. Right after posting, My Posts refreshes the new OFFER every 20 seconds for three
  minutes, so the split appears without a reload.
- **Mark TAKEN** and **Undo TAKEN** are `POST /message` action `BulkItemAvailable` (owner or
  moderator, any post with items). On a clearance the last item going records TAKEN, as the
  logged-out update page does. On a split post the poster does that.
- **Undo** (`POST /message` action `AutoSplitUndo`, owner or moderator, while the post has no
  outcome) removes the items, their photo links and the crops, and puts the counts back. The post
  is never split again.
- **Edit items** sends `PATCH /message` with `bulkitems`, which the owner of a split post may do
  without the Clearance permission. It can only take items off the list or change how many
  (`splitItemsEdit`). The list written is built from the post's own items, keeping their names
  and photos, because a rename would skip the review a text edit gets for a moderated member and
  a photo id could otherwise belong to another post. At least two items stay; fewer is Undo. The
  member's own description is never replaced by a summary, a removed item takes its crops with
  it, and items already marked TAKEN stay out of the count.
- **The post page** says "N things in this post" and lists each item with its photo, how many,
  and a "TAKEN" badge once taken. Tapping a photo shows that item's photos.
- **The reply box** has "Which would you like?" buttons for the items not yet TAKEN. A choice is
  written as the first line of the reply ("I'd like: Sofa, Chairs"), where the replier can see
  and change it. The poster receives an ordinary reply.
- **Browse and the emails.** The post's card shows "N available" from the item counts. The daily
  digest lists the items.
- **ModTools.** "Split automatically into N items. The member posted these as one post and
  Freegle listed them separately, so people can say which they want. The member can undo it."
- Crops are shown with their items only: Go leaves them out of the post's own photos, and the
  partner feed and LoveJunk leave them out of what they send (`Crops::exclude`).

## What happens later

| Event | What happens to the split |
|---|---|
| Marked TAKEN, in the app, on the site or from the chase-up email | Every item still on offer is marked TAKEN, so the stats count each item. |
| Withdrawn | The items stay as they are. Nothing is counted as taken. |
| Reaches its deadline, or ages off the site | The items stay as they are. A deadline records an outcome, as on any post, so Undo is no longer offered after it. |
| Reposted | The post keeps its items and which are TAKEN. It is not asked about again. |
| Edited: subject or description | The items stay. The new words are reviewed as any edit is. |
| Edited: photos | A crop stays while the photo it was cut from stays, and goes when that photo goes (`keepCropsOfKeptPhotos`). |
| Edited: changed to a WANTED | The split is undone first. See [WANTED posts](#wanted-posts). |
| Undo | The post is as it was written, and is never split again. |
| A moderator's edit or Undo | The same as the poster's. |

## Switching it on, and back off

`AUTOSPLIT_MODE` is `off` (the default), `shadow` or `on`; anything else is off. Shadow runs both
steps and records the answer in `messages_autosplit` with state `Shadow`, without changing the
post. The model is pinned (`AUTOSPLIT_MODEL`, `gemini-3.8-flash`) because the prompts were tuned
and backtested on it. No `GOOGLE_GEMINI_API_KEY`, no splitting.

Setting `AUTOSPLIT_MODE=off` stops new splits at once. Posts already split stay split, and their
posters can still undo them. Each split's original counts are on its row, so a split can also be
undone by `POST /message` action `AutoSplitUndo` as a moderator.

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

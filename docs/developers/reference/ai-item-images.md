---
last_reviewed: 2026-10-25
owner: Freegle dev team
covers:
  - iznik-batch/app/Services/MessageIllustrationsService.php
  - iznik-batch/app/Services/AiImageMatcherService.php
  - iznik-batch/app/Services/ItemCleanerService.php
  - iznik-batch/app/Support/ItemMatchKey.php
  - iznik-batch/app/Console/Commands/AI/BackfillAIImageItemsCommand.php
  - iznik-server-go/misc/illustration.go
---

# AI item images

A post with no photo gets a simple AI-drawn picture of its item. Pictures are shared: a new one is
drawn only for an item we have never drawn.

> **A picture belongs to an item, not to a post's wording.** Gemini says which item a new name
> means ("Aluminium walking frame" is a "walking frame", "PUCHING WELCHIAR" a "wheelchair"), and
> every wording of that item shares one picture. A new picture is drawn from the item, so the
> next wording finds it too.
>
> **This is what makes the rate fall.** Keyed by wording, the library grew by one-off names and
> new pictures stayed at about 58% of new names. Keyed by item, the share falls every month (see
> below).
>
> **A moderator removing a borrowed picture removes the match, not the picture.** The alias is
> deleted and the picture gets no "Reject", because it was fine for its own item.

## Where the pieces are

| Step | Code |
|---|---|
| Item name from a subject: no `OFFER:`/`OFFERED:`/`REQUESTED:` prefix, no location, no "please" | `MessageIllustrationsService::extractItemName`, `misc.IllustrationItemName` |
| Picture as a post is written (compose) | `misc.GetIllustration`: the exact name, then an alias |
| Pictures for posts that have none, every minute | `MessageIllustrationsService` |
| Which item a name means | `ItemCleanerService` (Gemini, fixed model `AI_IMAGE_CLEAN_MODEL`) |
| Reuse or draw | `AiImageMatcherService` |
| Bring pictures drawn before this onto sharing, retiring duplicates | `ai:images:backfill-items` (`--dry-run` to count), every 30 minutes until done |
| Moderator removes a picture | `recordAIDeletions` and `removeAIImageAlias` in `message/message.go` |

`ai_image_aliases` holds two kinds of row. `item` rows point a post's wording at a picture, and the
Go API reads them at compose time. `itemkey` rows point an item's key (`ItemMatchKey`: lower case,
no punctuation, singular) at the picture drawn for that item.

When a name has no picture, the batch asks the cleaner for its item, then:

- if the item has a picture, the post uses it and the wording becomes an alias;
- if not, a picture is drawn from the item and stored under it, with aliases for both;
- if the cleaner says there is no drawable item, or fails, or the item's own picture is rejected,
  the picture is drawn from the post's wording, as before.

Job illustrations share `ai_images` but are never offered to a post: the backfill only keys
pictures a post has used.

### Pictures drawn before this

`ai:images:backfill-items` brings them onto sharing, most-used first. The first picture of an item
becomes the item's picture. Any later picture of the same item is retired: its posts are pointed
at the item's picture, its wording and aliases are pointed there too, and its `ai_images` row is
removed. The stored image file is left, as regeneration leaves the file it replaces, because mail
already sent links to it. In the replay, the 28,378 pictures posts have used came to about 8,700
items, so expect most rows to go. `--dry-run` counts what it would retire and changes nothing.

## How it was measured

Every post picture generated from December 2025 to 25 October 2026 (28,378 names, each the first
time that name appeared) was replayed as if this had been in place from the start. Each name could
only reuse pictures the new logic would already have drawn.

| Month | New names | Would still be drawn | Share |
|---|---|---|---|
| Dec 2025 | 2,249 | 1,372 | 61% |
| Jan 2026 | 2,793 | 1,120 | 40% |
| May | 7,044 | 2,321 | 33% |
| Jun | 2,784 | 771 | 28% |
| Jul | 4,047 | 1,045 | 26% |
| Aug | 3,644 | 826 | 23% |
| Sep | 3,097 | 673 | 22% |
| Oct | 2,716 | 569 | 21% |

"Would still be drawn" counts names the cleaner found no item in (about 2.5%), which are drawn
from their wording as before. 28,378 pictures would have been 8,700.

Samples were judged blind: would this picture be acceptable on this post?

| Case | Acceptable | Partial | Wrong |
|---|---|---|---|
| Reused, same item | 92% | 4 | 0 of 50 |
| Reused, same key, different wording | 85% | 4 | 2 of 40 |
| Drawn new, from the item | 88% | 6 | 1 of 60 |

"Partial" is nearly always a post listing several things, where the picture shows the first.

### What was tried and dropped

| Approach | Share drawn in October | Why not |
|---|---|---|
| Exact wording (before) | 100% of new names | |
| Rules on wording plus nearest name by embedding | 58%, flat from July | Library grows by one-off wordings |
| Plus "a more specific version of a name we have" | 43%, flat | Same |
| Draw from the last two words | 34% | 9% of matches wrong ("Pressure cooker" -> "cooker") |
| Draw from the head noun | 10% | "Paint spray gun" -> "gun" |
| Cleaner plus nearest item by embedding | 18% | 85% acceptable, for 3 points |

The embedding step was the weakest band each time it was judged, so it is not used.

The replay is not in the repository. Re-running it needs `ai_images` and post usage from
production, the cleaner run over every name, and the same replay against a library that grows in
date order.

---
last_reviewed: 2026-10-26
owner: Freegle dev team
covers:
  - iznik-batch/app/Services/MessageItemsService.php
  - iznik-batch/app/Console/Commands/Message/CleanMessageItemsCommand.php
  - iznik-batch/app/Services/ItemCleanerService.php
  - iznik-batch/app/Support/ItemMatchKey.php
  - iznik-server-go/utils/itemkey.go
  - iznik-server-go/embedding/store.go
  - iznik-server-go/message/vectorsearch.go
  - iznik-server-go/message/postmatches.go
---

# The cleaned item of a post

Each post has one cleaned item: the single thing it is about, as Gemini reads the subject.
"Aluminium walking frame" is a "walking frame", "PUCHING WELCHIAR" a "wheelchair", "Old tins of
buttons" "buttons". It is kept in `messages_cleaned_items` with its key, and three things use it.

> **Not the same as `items` / `messages_items`.** Those are the older typeahead vocabulary: 3.6M
> names, kept at the wording people typed, and used for weights, popularity and electricals. The
> cleaned item is a short canonical name; 28k post wordings reduce to about 8.5k cleaned items.
>
> **The key, not the item, is compared.** `ItemMatchKey` in the batch and `utils.ItemKey` in Go
> lower-case, drop punctuation and make each word singular, so "Knitting needles" and "knitting
> needle" match. The two must give the same key: search compares a member's term (keyed in Go)
> with a post's key (written by the batch). Their tests share their cases.
>
> **No item, no key.** A subject with no drawable item ("Household bits", "Can anyone help?") is
> stored as `none` with a NULL key, and is matched as it was before.

## Where it comes from

`messages:clean-items` runs every minute. It takes posts with no row yet, newest first: those
waiting for a moderator from the last three days, then live posts (`messages_spatial`), up to
1,000 a run. New posts get their item within a minute, and live posts from before this existed are
worked through the same way. A post the cleaner did not answer stays a candidate. With no
`GOOGLE_GEMINI_API_KEY` it does nothing, and everything below behaves as before.

`ItemCleanerService` holds the prompt and the fixed model (`AI_IMAGE_CLEAN_MODEL`). Both were
measured; change either and measure again.

## What uses it

| Use | What the item changes | Code |
|---|---|---|
| AI item pictures | Pictures are shared by item; the stored item saves cleaning the name again | `MessageIllustrationsService`, `ai-item-images.md` |
| Matched-posts email | A pair whose items are both known and differ is not sent | `message.itemsDiffer` in `postmatches.go` |
| Search | Posts about exactly the item searched for rank first, and are found whatever their wording | `sameItemBoost` and `Store.ItemMatch` in `vectorsearch.go` |

The Go store loads each open post's key with its embedding, and re-reads every open post's key on
each refresh, like its groups, because the item can arrive after the post is in the store.

## Why

Measured on the live posts of the last 30 days (5,258 Wanteds, 30,120 Offers) and the 600 most
common search terms, judged without saying which method found each pair.

**Matched posts.** Today's cosine of at least 0.85 matched 76% of Wanteds. Pairs that also had the
same item were judged 95% welcome. Pairs that passed only the cosine were 60% welcome and 18%
clearly not ("Beer dispenser" for "Water dispenser", "Cello" for "Violin"). Matching on the item
alone was no better, 60%, because the item drops what an asker is particular about ("Macbook
charger" got a "Dell charger"). So the item is a second check on the cosine, not a replacement:
it keeps about 89% of matches, at about 95% welcome rather than about 90%.

**Search.** Search already found 95% of the posts about the term's item; the 5% it missed were 95%
welcome, so they are now added. Of the results that were a different item, 50% were judged welcome
and 16% not ("weights" found "jump leads"), so posts about the item itself now come first.

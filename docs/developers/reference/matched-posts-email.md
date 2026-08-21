---
last_reviewed: 2026-08-21
owner: Freegle dev team
covers:
  - iznik-server-go/message/postmatches.go
  - iznik-server-go/user/relevantoff.go
  - iznik-batch/app/Services/MatchedPostsService.php
  - iznik-batch/app/Services/LoginLinkService.php
  - iznik-batch/app/Console/Commands/Message/NotifyMatchedPostsCommand.php
  - iznik-batch/app/Mail/Matched/MatchedPosts.php
---

> **Live posts only.** A candidate is dropped if it has a `Taken`, `Received` or
> `Withdrawn` outcome. All three mean the item is gone, so mailing it sends someone after
> something that is not there.
>
> **Reach is checked from the POST's location, with no viewer.** The batch calls this
> unauthenticated and the recipient is the post's owner, so overflow rings do not apply: a ring
> admits a person standing at their own location, and there is nobody standing here. See
> `rippling-algorithm.md` section 3b.

# Matched-posts email

Emails a member the opposite-type posts near them that match their own open
Offer/Wanted — "you posted a WANTED, here are matching OFFERs nearby" and the
reverse. Resurrects the V1 `cron/relevant.php` ("Any of these take your fancy?")
mail, rebuilt on the vector embeddings rather than keyword search.

This is a **separate** mail from the daily digest's relevance ranking
(`FEATURE_DIGEST_RELEVANCE`, PR #956), which is left untouched.

## How it flows

1. **Schedule** (`iznik-batch/routes/console.php`) runs `matches:notify` every 10
   minutes.
2. **Driver query** (`MatchedPostsService::freshPosts`) selects Offer/Wanted posts
   that arrived in the last `fresh_window_minutes` (default 20), are still open,
   and already have an embedding — a small set (~100/run on prod) bounded by the
   `messages_groups.arrival` index.
3. For each fresh post, the service calls apiv2 **`GET /message/{id}/matches`**
   (`iznik-server-go/message/postmatches.go`), which returns the opposite-type
   open posts near it from the in-memory vector store — bbox-scoped, reach-filtered
   against the *post owner* (the caller is unauthenticated batch), above
   `MinMatchedPostScore` (see [Similarity floor](#similarity-floor)). The vector
   maths lives only in Go; there is no SQL KNN.
4. **Both directions** fall out of that one search: the fresh post's owner is shown
   the matches, and each matched post's owner is shown the fresh post.
5. **Guards** (`MatchedPostsService::applyEligibility`): never the recipient's own
   post; only still-open matches; never a post they clicked to view
   (`messages_likes` `type='View'` **and** `pageview=1` — a feed scroll-past
   doesn't count); never a post already in the `messages_matched_notified` ledger;
   only opted-in (`users.relevantallowed=1`), recently-active recipients outside
   the `cooldown_hours` (default 4) window, tracked via `users.lastrelevantcheck`.
6. **Reach-exact** (`MatchedPostsService::verifyReach`): every shown match `M` is
   confirmed to be in apiv2's `matchesForPost` for the recipient's OWN post that
   it matched — which reach-filters against that post's owner. Direction (i) is
   free (the reason post is a fresh post already searched); direction (ii) costs
   one extra apiv2 call per surviving recipient post, so a match that hasn't
   rippled out to the recipient is dropped rather than mailed on bbox proximity.
7. **`NotifyMatchedPostsCommand`** delivers to each eligible recipient:
   - an **in-app (bell) notification** — a `users_notifications` row of the new
     `type='MatchedPost'` (migration `2026_07_19_000002_...`; rendered by
     `iznik-nuxt3/components/NotificationMatchedPost.vue`), plus a **device push**
     via `PushNotificationService::notifyUser` (no-op when the user has no
     registered app device). This is the primary channel — delivered regardless
     of email;
   - an **email** (`App\Mail\Matched\MatchedPosts`, adaptive hero/list layout)
     when the member has an address.
   Then it records each matched post in `messages_matched_notified` (dedup across
   both channels) and bumps `lastrelevantcheck`. The subject/notification title
   mirror each other: "Someone is offering: <item>" for one match, "Freegle
   matches for you" for several.

## Dedup ledger

`messages_matched_notified` (migration `2026_07_19_000001_...`) has composite PK
`(msgid, userid)` where `msgid` is the *matched* post shown to the user — the same
"impossible to re-notify by construction" pattern as `rippling_reach_notified`.

## Killswitches

- apiv2 vector endpoint: `FEATURE_MATCHED_POSTS=off` (returns empty, no deploy).
- Laravel send: `FREEGLE_MATCHED_ENABLED=false` (config `freegle.matched.enabled`).
- Per-member opt-out: apiv2 `GET|POST /user/relevantoff?u=&k=` sets
  `relevantallowed=0`, key-authenticated by the user's `users_logins` Link key
  (minted by `LoginLinkService`). The matched email points both its
  RFC 8058 `List-Unsubscribe` header (one-click) and a visible footer link at it,
  so a member can stop just these emails without unsubscribing the whole account.
  The existing `users.relevantallowed` settings/ModTools toggle still works too.
  This is now one case of the general scheme every bulk mailable follows —
  `MatchedPosts` declares the `relevant` category and keeps `relevantoff` as its
  targeted endpoint. See [./unsubscribe.md](./unsubscribe.md).

## Preview

`docker exec freegle-batch php artisan mail:test matched --user=<id> --send-to=you@example.com [--matched-count=1]`
renders it into Mailpit with real recent posts as stand-in matches.

## Tuning (config `freegle.matched`)

`fresh_window_minutes`, `match_limit_per_post`, `max_items_per_email`,
`cooldown_hours`, `min_lastaccess_days` — all env-overridable.

## Similarity floor

The only quality gate is `MinMatchedPostScore` in `iznik-server-go/message/postmatches.go`.
Laravel does not apply a floor of its own (it sorts on the scores the API returns),
so this constant alone decides what lands in someone's inbox.

It is **0.85, deliberately higher** than the similar-posts `MinSimilarScore`
(0.80, itself raised from 0.60 by the same exercise). Matched posts are pushed to
an inbox unasked rather than offered to someone already browsing, so the bar is
higher here: a slightly-off suggestion beside something you are already looking
at costs little, while a weak match in an inbox teaches people to ignore the next
one.

Both floors were originally too low for the same reason. They were picked against
query-vs-document cosines (a typed `search_query:` embedding against stored
`search_document:` ones), but both surfaces actually compare two *stored*
document embeddings, where cosines run much higher. The old numbers were not on
the scale they were assumed to be on.

Measured by scoring 150 randomly sampled live Offers against the live Wanted pool
and hand-judging the top match. Precision is a cliff, not a slope:

| top-1 score | matches | precision |
|---|---|---|
| >= 0.90 | 20 | 1.00 |
| 0.85-0.90 | 25 | 0.92 |
| 0.80-0.85 | 21 | 0.43 |
| 0.75-0.80 | 47 | 0.36 |
| 0.60-0.75 | 37 | 0.11 |

At 0.60 just under half the emails carry an irrelevant item (Bed lever -> "Bed",
Screen protectors -> "Curtains", Keyrings -> "Rugs"). At 0.85 precision is 0.96
and 30% of sampled posts still match, which is ample for a 10-minute job. The
trade is recall, about 59% of true matches are kept, and that is the right way
round for unsolicited mail.

Two things that sound like improvements and measurably are not, both tested on
400 gold Offer/Wanted pairs:

- **Embedding the body as well as the subject makes it much worse** (recall@1
  0.905 -> 0.535). Bodies are dominated by shared boilerplate ("collection times",
  "no resellers"), which swamps the item itself.
- **Storing 768 dims instead of the Matryoshka-truncated 256** buys almost
  nothing (recall@1 0.905 -> 0.912) for 3x the storage and resident memory.

Retrieval itself is not the weak link: at the production setting, when a true
match exists it is ranked first 90% of the time and in the top 5 98% of the time.

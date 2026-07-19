---
last_reviewed: 2026-07-19
owner: Freegle dev team
covers:
  - iznik-server-go/message/postmatches.go
  - iznik-server-go/user/relevantoff.go
  - iznik-batch/app/Services/MatchedPostsService.php
  - iznik-batch/app/Services/LoginLinkService.php
  - iznik-batch/app/Console/Commands/Message/NotifyMatchedPostsCommand.php
  - iznik-batch/app/Mail/Matched/MatchedPosts.php
---

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
   `MinSimilarScore`. The vector maths lives only in Go; there is no SQL KNN.
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
6. **`NotifyMatchedPostsCommand`** renders `App\Mail\Matched\MatchedPosts` (layout
   adapts to the match count — hero card for one, compact list for several),
   spools it, records each matched post in `messages_matched_notified`, and bumps
   `lastrelevantcheck`.

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

## Preview

`docker exec freegle-batch php artisan mail:test matched --user=<id> --send-to=you@example.com [--matched-count=1]`
renders it into Mailpit with real recent posts as stand-in matches.

## Tuning (config `freegle.matched`)

`fresh_window_minutes`, `match_limit_per_post`, `max_items_per_email`,
`cooldown_hours`, `min_lastaccess_days` — all env-overridable.

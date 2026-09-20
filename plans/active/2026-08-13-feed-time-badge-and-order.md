# The feed's time badge and its sort order disagree

Reported 2026-08-13: browse set to Nearby + "Newest posted", and the visible times ran
5 days, 4 days, 5 days, 2 hours. Not descending, so either the badge or the order is wrong.

## What is actually happening

Two different clocks.

| | field the code uses | bumped when it ripples | bumped by a repost |
|---|---|---|---|
| sort ("Newest posted") | `posted` = `messages.arrival` | no | no |
| badge | `groups.find(g => !g.rippled_in).arrival`, falling back to `arrival` = `messages_spatial.arrival` | **yes** | yes |

`iznik-server-go/isochrone/message.go` states the intent plainly - `posted` "is what the
client's Newest posted sort **and the card's time badge** mean, so exposing it lets the feed
order agree with the badge" - but `useMessageDisplay.js` never used it. `displayTimestamp` is
`origin?.arrival || message.arrival || message.date`, so the badge reads a group arrival, or
falls through to the summary's ripple-bumped `arrival`.

Live evidence (2026-08-13): "Archeology magazines" (121444381) sits on its origin group
Malvern-Hills at 11:27:23 - equal to `messages.arrival` - and on **seven rippled-in groups at
13:29:05**. One post, two hours apart, depending which group you read.

Note: the exact figures in the original screenshot cannot be re-derived now, because spatial
arrivals move (Marbles 121152650 is 22h at the time of writing; Post thumper 121177700 has no
`messages_spatial` row at all). The mechanism above is what matters and is reproducible.

## Proposal

One clock, chosen to answer the question a member is actually asking: **how long could I have
had this?**

- **`visibleSince`** = MIN, over the groups where the post is live *and visible to this
  viewer*, of that group's `messages_groups.arrival`. On the nearby/reach feed that is when it
  entered their reach; on a group feed, when it landed on a group they are in. A repost
  already bumps that column, so it is included by construction.
- **Sort "Newest posted" by `visibleSince`**, and **show `visibleSince` on the badge**. Same
  number, so the order cannot contradict what is on screen.
- Keep `posted` (the original `messages.arrival`), used only to explain a difference.

### When they differ, say why - the two causes read differently

- Rippled: **"4 days here · 6 days elsewhere"**. It reached you later than it was posted.
- Reposted: **"reposted 4 days ago · first posted 18 days ago"**. Nothing travelled; the
  giver's post was refreshed.

Suppress the second clause when the gap is under a day, or every card grows noise.

### Server

The feed summary already carries `arrival` and `posted`. Add `visibleSince` and a small enum
(`rippled` | `reposted` | `same`) so the client picks the wording rather than re-deriving it.
The viewer's group set is already joined for other purposes in that query.

## Decided

**A repost lifts the post back up the feed.** Ordering is by `visibleSince`, which a repost
bumps, so a refreshed post is genuinely new to everyone again - which is the point of a
repost. This is a deliberate departure from Discourse 9844's "don't float old posts", because
there the bump came from the reach growing (nothing changed for the member) whereas here the
giver's post has actively been re-offered.

**The badge stays short on mobile.** The component already picks between a short and a longer
form by breakpoint (`MessageSummary`: `isLgPlus ? timeAgoExpanded : timeAgo`), so the second
clause follows the same rule:

| breakpoint | rippled | reposted |
|---|---|---|
| mobile | `4 days · 6 days elsewhere` | `reposted 4 days · first posted 18 days` |
| lg and up | `4 days here · first seen 6 days ago elsewhere` | `reposted 4 days ago · first posted 18 days ago` |

Suppressed entirely when the gap is under a day.

## Superseded: the decision, as originally put

Ordering by `visibleSince` means **a repost lifts a post back up the feed**. That is what a
repost is for, but it is a change, and it is the same shape of complaint as Discourse 9844
(a days-old post floating to the top when its reach grew).

1. **Repost lifts it** - simple, one clock, "newest to you" is honest.
2. **Repost refreshes the badge only** - sort on the pre-repost arrival, so old items stay
   down while the badge still reads "reposted 4 days ago".

Awaiting a decision on which.

## Not yet checked

- Whether `messages.arrival` is ever itself bumped (a repost that rewrites the message rather
  than the listing). Everything above assumes it is stable.
- What the badge should say for a post visible through reach but on no group the viewer has
  joined - "in your area 4 days" may read better than "here".

---

# STATUS at 2026-08-13 (handover)

Nothing is committed. All of the below is uncommitted working-tree change on branch
`feat/collapse-own-posts-in-browse` (which is otherwise master). Frontend unit suite passes
at **15464**. There is no Go test for `visibleSince` yet - write one before any PR.

## Done and verified

- **Own posts collapsed** (PR #1336, MERGED to master). This was the *first* cause of the
  "out of order" report: the viewer's own posts pin to the top of every sort (Discourse 9933),
  so old posts sat above new. Confirmed fixed on dev-live.
- **`visibleSince` on the bulk endpoint** `/api/message/<ids>` (`message/message.go`):
  struct field + `COALESCE((SELECT MIN(mgv.arrival) ... ), messages.arrival) AS visible_since`.
  Verified live on 121152650: written 24 Jul, origin group 8 Aug, rippled 12 Aug ->
  `visibleSince` = 8 Aug, which matches the badge.
- **Badge wired**: `MessageSummary.vue` and `MessageExpanded.vue` both call `postAgeBadge`.
- **`usePostAgeBadge.js`** + 12 tests (TDD, real RED first). Wording, after two rounds of
  feedback: mobile `4 days · first posted 6 days`; lg+ adds "ago". No "elsewhere", no
  "reposted". Second clause suppressed when the gap is under a day or negative.
- **Sort reads visibleSince** (`useMessageSort.js`), falling back to posted then arrival.
- **`PostMapAndList.sortMessages`** enriches summaries from the message store when they lack
  the field. Helps the top of the feed only - see below.

## NOT fixed - the next thing to do

The nearby feed's own payload has no `visibleSince`, so the sort still falls back to
`arrival` for anything the message store has not loaded. Measured on dev-live: **17 ordering
breaks in 71 cards**, all past card ~52 (e.g. 23d then 17d then 13d).

`nearbyStore.messageList` <- `api/IsochroneAPI.js fetchMessages` <- **`GET /isochrone/message`**.
Its response keys are:

    id,hasoutcome,successful,promised,groupid,collection,type,arrival,posted,date,lat,lng,unseen,score,distance

`score` and `distance` are NOT produced by any of the three selects patched in
`isochrone/message.go`, so this endpoint marshals a **different struct** - find it (start from
the `/isochrone/message` route handler, not the file name) and add the field there.

**Second bug in my own change, fix at the same time:** the struct field I added carries only
`gorm:"column:visiblesince"` and **no `json:"visibleSince"` tag**, so even on the paths I did
patch it would not serialise under the name the client reads.

Then: rebuild apiv2-live, reload the browse URL, and require **0 ordering breaks across all
71 cards** - not just the first page.

## Environment notes (these cost hours)

- **`apiv2-live` has a baked source tree with no mount.** `docker cp` a single file and it will
  not compile (its siblings are older: `spatialReachIDs`, `database.InsertSelect`,
  `spatial.ReachContaining` all missing). Copy the whole `iznik-server-go/` (34MB), then
  `docker restart` - it runs `go run main.go` in a loop, so restart = rebuild (~60-90s).
  It reverts to the baked build on any real rebuild.
- **dev-live `/app` is also a copy**: `docker cp` each changed frontend file individually.
- **Switch dev-live to the local V2 API**:
  `curl -X POST localhost:8081/api/container/toggle-live-v2 -d '{"target":"freegle","enable":true}'`
- **Test URL** (impersonation): `http://freegle-dev-live.localhost/browse?u=37345781&k=...`
  `*.localhost` needs `--host-resolver-rules=MAP *.localhost 127.0.0.1` in any Playwright probe.
- **Probe discipline.** Three false greens happened here: reading rendered order without
  capturing the payload; printing 16 of 71 cards; and an over-escaped regex (`\\d`) that made
  every badge unparseable so the break counter reported 0. Working probe:
  `/tmp/order.mjs <url>` - prints every break and the total.

## Also parked

- **Walkthrough video** (`pr-walkthrough/prs/howto-member/`, `make.sh` re-runs it). Blocked on
  photos: fixture attachments have **no image bytes** (`data` empty, tusd uid 404s,
  `IMAGE_DOMAIN` is the live host), so browse/My Posts render grey tiles. Outstanding asks:
  frame shots in a phone (`PhoneFrame.jsx` written, `device:"phone"` added to ScreenshotScene),
  plausible chat data, order give->reply->promise->taken->browse, drop the closing summary,
  highlight buttons before modals, show the TAKEN form part-filled.
- **`stash@{0}`** holds four `.claude` hook files that are NOT mine (`check-pr-text.sh`,
  `check-pr-uncommitted.sh` + two `.test.sh`); backed up in `~/claude-hooks-backup-2026-08-13/`.
  They blocked the master merge. Someone should decide whether master's versions supersede them.

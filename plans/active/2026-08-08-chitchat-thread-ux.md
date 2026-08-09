# ChitChat threading UX: make notification landings and long threads usable

Branch: `feature/chitchat-thread-ux` (worktree `chitchat-threads`). One-shot PR, TDD.

## The complaints (Edward, 2026-08-08)

1. Someone replies partway through a long thread, you get a notification, and it's not clear
   where to look in the thread.
2. On mobile the feed shows too much of each thread, so you scroll a lot.
3. The threading model is confusing overall.

## Grounding

- Prod data (live DB, 2026-08-08): 79% of threads have 1-2 replies; in 11+ reply threads 70% of
  replies arrive more than 24h after the thread started (notification-into-stale-thread is the
  norm); 27% of replies are nested; long threads average 7 distinct repliers.
- Browser-verified before-state (dev-live, real data): a 15-reply thread renders 11 blocks in the
  FEED despite "Show 5 older replies" (kept entries drag their whole nested subtrees along);
  a notification deep link renders the FULL thread (7.8 phone screens), lands you 4,500px deep
  with a permanent pale-blue tint on the target, and no New pills (the seen baseline is never
  set on that path). The deep-link fetch also instantly advances the server seen-watermark.
- Research (Reddit/FB/YouTube/WhatsApp/Slack/Discourse/NN-g): feed cards show post + 1-2 replies
  + honest count, never the whole thread; notification landings highlight the target with brief
  flash-fade (static under prefers-reduced-motion); the "where's the new stuff" answer is ONE
  unread divider the view auto-lands on; expanders are full-width, counted, >=44px.

## Design (synthesis of two workflow designs, adversarially reviewed)

Chassis = "unread-first" design; grafts from "focused-context" design: feed density fix,
flash-fade arrival highlight, combined-block pin fix.

### A. Server watermark => a working "new since your last visit" baseline everywhere
- Go: `Newsfeed.SeenWatermark uint64 json:"seenwatermark,omitempty"` set ONLY on the top-level
  object in `Single()` (skip when logged out), via a helper shared with `Count()`. It's the same
  one-row indexed SELECT Count already does. No new endpoints, no schema changes.
- stores/newsfeed.js: `watermarkCaptured` flag; `addItems()` captures the FIRST `seenwatermark`
  it sees into `seenBeforeVisit` (first-write-wins per visit) before the auto-Seen logic runs.
  `snapshotSeenBeforeVisit()` keeps its existing behaviour (fallback baseline = session maxSeen,
  delayedSeenMode on) and additionally resets `watermarkCaptured`.
- pages/chitchat/[[id]].vue: snapshot runs UNCONDITIONALLY at top of script setup (before any
  fetch dispatch); `startDelayedSeen(30000)` unconditional in onMounted. Fixes both "New pills
  dead on the notification path" and "deep link instantly marks everything seen".

### B. Deep link = focused landing inside the ONE familiar thread view
- NewsReplies.shouldCollapse: remove `!props.scrollTo`. Collapse now engages on notification
  landings. New exemption: a middle entry stays visible if its subtree contains the scrollTo
  target (recursive, combined-aware), alongside the existing has-new-activity exemption.
  Result: head 2 + "Show N older replies" + target neighbourhood + tail, not a 60-reply wall.
- Target highlight: replace permanent `bg-info` with `.deep-link-target` (persistent soft tint)
  plus a one-shot ~2.4s flash-fade on arrival; under prefers-reduced-motion the flash is
  disabled and the static tint applies immediately (cue never removed).
- Combined-block pin fix (pre-existing bug): a deep link to the 2nd+ message of a combined run
  never matches `data-reply-id`. Add `data-combined-ids` attr; compound selectors in
  NewsThread pin + NewsReply scrollReplyIntoView; `scrollToThis` also matches combinedIds.
- Removed-target notice: if content settles and the target row never rendered (deleted for
  non-mods), show "That reply has been removed. Here's the rest of the conversation." instead
  of a silent land-at-top.

### C. ONE unread divider + auto-land on it for general thread landings
- New `NewsUnreadDivider.vue` ("{n} new replies since your last visit", role=separator,
  `data-unread-divider`, fade-in gated by prefers-reduced-motion). Rendered at depth 1 only,
  immediately before the first NEW top-level entry, only when there's a genuine old/new split.
  (Top-level-only is a documented scope limit; nested new items still get pills + exemptions.)
- NewsThread: when `scrollTo === threadhead` (thread opened generally, e.g. from the feed CTA)
  auto-pin to the divider (block 'start') after content settles, sharing the existing
  `deepLinkPinned` guard so the specific-reply pin and the divider pin are mutually exclusive.
  Feed cards never auto-scroll (divider is a static cue there).

### D. Feed density: post + last 2 replies + honest counted CTA
- NewsReplies gets `context` prop ('thread' default, 'feed' on the feed page via NewsThread).
- Feed mode, small threads (total tree count <= 3): identical to today (79% case untouched).
- Feed mode, bigger threads: ONE full-width CTA row FIRST, "View all {N} replies" or
  "View all {N} replies · {M} new" (N, M = true recursive totals), a link to
  /chitchat/<threadhead>; then only the LAST 2 top-level entries; their nested children are
  suppressed and each parent instead shows a "View {n} replies" link to /chitchat/<parent id>
  (which lands focused on that reply with children visible). All rows >=44px tall.
- Thread page keeps head 2/tail 3/threshold 6 (unchanged numbers).

## Status

| # | Task | Status | Notes |
|---|------|--------|-------|
| A1 | Go test: seenwatermark on Single (4 cases) | ✅ | RED proven (count-variant failed exactly 1/3941); test moved to Single per design |
| A2 | Go impl: helper + field in Single | ✅ | seenWatermarkFor shared with Count |
| B1 | Store spec: watermark capture via addItems | ✅ | RED 5 fail then GREEN 64/64 |
| B2 | Store impl | ✅ | watermarkCaptured, first-write-wins, recursion-safe |
| B3 | Page: baseline before fetch + delayed seen everywhere | ✅ | RED 2 fail then GREEN; later split feed/thread (see below) |
| C1 | NewsReplies: collapse-on-deeplink + target exemption specs | ✅ | RED 6 fail |
| C2 | NewsReplies impl | ✅ | GREEN 43/43 |
| C3 | Divider component + placement specs + impl | ✅ | GREEN 3/3 + placement tests |
| D1 | NewsReply flash highlight + combined pin specs + impl | ✅ | GREEN 56/56 |
| D2 | NewsThread divider pin + targetGone notice specs + impl | ✅ | GREEN 121/121; DOM is authority for targetGone |
| E1 | Feed context CTA specs + impl | ✅ | GREEN; suppressed children behind counted links |
| E2 | REGRESSION found in browser: SPA feed->thread wiped pills | ✅ | ensureSeenBaselineForThreadView; keeps session baseline; GREEN 66/66 + 14/14 |
| F1 | eslint changed files | ✅ | clean (fixed 7, incl. pre-existing) |
| F2 | Full vitest suite via status API | ✅ | 709 files / 15,016 passed / 0 failed |
| F3 | Browser verify in worktree (before/after, mobile+desktop) | ✅ | seeded 27-reply thread; feed card, divider landing, deep-link flash, CTA nav all verified; screenshots in scratchpad |
| F4 | Docs freshness | ✅ | OK, 47 pages checked, no covered path touched |
| G1 | Go suite GREEN | ✅ | 3,992 passed / 0 failed on the merged tree |
| G2 | Push + PR with before/after screenshots | ✅ | PR #1292; true A/B captured by swapping master components into the container |
| H1 | Divider must cover NESTED new replies | ✅ | Found via a scattered/nested fixture: it read "2 new" on a thread with 5 and sat BELOW three of them. Now anchors on first subtree-with-new; counts all. |
| H2 | `#new` anchor declares the jump-to-new intent | ✅ | Feed CTA -> /chitchat/<id>#new; plain URL opens at top again. RED proven. |
| H3 | Scroll anchoring on "Show N older replies" | ✅ | Browser caught what the unit test missed: the row after the expander can be the DIVIDER, so the anchor lookup bailed and no correction ran. Walks past non-reply rows now. |
| H4 | Final suites on merged tree | ✅ | vitest 712 files / 15,075 pass; Go 3,992 pass; docs freshness OK; eslint clean |

## Deliberately NOT built (researched, for a later decision)

A pair-aware nudge for two people going many rounds with each other. Discourse is
the only product that does this: `get_a_room_threshold` (default 3) counts replies
to the SAME person in a topic and privately suggests a personal message instead;
verified in discourse/discourse `site_settings.yml` + `server.en.yml`. Distinct
from `max_consecutive_replies` (same-author hard block) and
`dominating_topic_minimum_percent` (whole-topic 40%). Nobody else is pair-aware:
Reddit's "Continue this thread" is purely depth; FB/IG/YouTube cap nesting at one
level so a volley becomes a flat run; X groups only SELF-threads; Slack's answer is
the thread pane, user-invoked. Transfers well to Freegle because it needs no
nesting and Freegle already has private chat to point at.

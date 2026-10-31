# Candidate: an active snapshot served instead of the big tables

Status: draft
Last reviewed: 2026-10-28

## Goal

The part of Freegle that members actually look at, the active set, is kept as a snapshot that
is updated from a change feed as writes happen and served directly, so the hot reads (browse,
newsfeed, chat lists, a member's own state) never touch the big tables. The database becomes
the system of record and the place writes go; it leaves the foreground. Brief from Edward,
2026-10-28: a broader "active" snapshot updated in an event-sourced way and served, rather than
massive tables, which might mean the database is no longer in the foreground.

## Why it qualifies

- Token-heavy because: a change feed from the database, a projector with a rebuildable snapshot store, a read path switched endpoint by endpoint with a shadow comparison against the database answer, read-your-writes for a member's own actions, and a backtest that replays a day of production change events to prove the snapshot matches; most of it is code and proof across Go, batch and the frontend.
- Unattended because: every piece is testable on a dev stack with a recorded change feed; the decisions are which reads first and how stale is acceptable, both below.
- Worth it because: the 24-hour production watch of 2 to 3 October shows the hot reads are the database's load (newsfeed 10.7%, browse spatial 10.3%, chat list polling 10.1%, per-member enrichment 6.0% of db3's statement time), and every outage story starts with a read that hung; a snapshot that serves those from memory or files takes both away at once.

## What exists today

- `plans/2026-09-29-perf-backlog.md` and `analysis/2026-10-02-perf-watch/`: the measured shares above, with the queries named; browse already reads a denormalised table (`messages_spatial`), which is a hand-built read model for one query.
- `plans/2026-08-21-frontend-availability-architecture.md` section 6 "Read side: files first, cache second" and Phase 3: browse and message-page JSON projected to files and served as last-good copies. Its Stage C, the fully rebuildable projection, was deferred because Laravel writes the same tables directly, so an application-level event stream would miss half the writes.
- The two-writer fact is the design constraint: Go and Laravel both write `chat_messages`, `messages`, `memberships`, `users`. A feed that comes from the database itself (the binary log) sees every writer; a feed from the application does not.
- Redis is in the stack as cache and queue; Valkey was named in the availability plan for speed where files are too slow.
- Unknown: whether the Galera cluster's binary log is available in row format with GTIDs to an external reader, and at what lag; this is the first measurement.

## Three things Edward asked to be thought through (2026-10-28)

**Chat.** Chat is the hardest case and the biggest prize, because the chat list poll is a tenth
of the busiest node's statement time and it is pure polling. In the snapshot, each member has
their chat list (rooms, unread counts, last message and time) in the key-value store, and each
room has its last N messages; older pages stay database reads. A new message is written to the
database as today; the feed delivers the row change; the projector updates the room's tail and
both participants' lists. Two things follow. First, read-your-writes: the sender's client already
has the message from the write response, and the sender's next list read is answered from the
snapshot only once the projector has passed the position of that write (the API records the
member's last write position at write time; Laravel's writes are mail replies and automation, not
a member's own in-session action, so eventual is fine for those). Second, once the projector is
the thing that knows a chat changed, polling can stop: the projector can push a change notice to
the client (server-sent events, which the availability plan already names for Phase 4), and the
client fetches only then. That is the real saving; serving the same poll from Valkey is the
smaller one. Moderation views of chats stay on the database.

**Members springing back into life.** The active set is bounded (members active in 90 days,
plus live and recent posts and open rooms), so a member who returns after a year has nothing in
the snapshot. The rule is hydrate on miss: the API notices the member is not in the snapshot,
builds their slice from the database once (the same queries as today, so it costs what today
costs, once), writes it into the snapshot, and marks them active; from then on the feed keeps it.
Their old rooms' other participants are already in the snapshot if active, or hydrate the same
way when they return. Two guards: hydration concurrency is capped and the API falls back to
plain database reads above the cap, so a newsletter that brings ten thousand people back at
once degrades to today's behaviour rather than to a stampede; and members leave the active set
on the same 90-day rule, so the snapshot cannot grow without bound.

**Is there a performance benefit?** Honest answer: for the reads named, yes; for the node as a
whole, only partly, and it has to be measured before anything is built. The measured shares are
newsfeed 10.7%, browse spatial 10.3%, chat list poll 10.1% and member enrichment 6.0% of db3's
statement time, about 37% together, and all four are reads the snapshot replaces. But the single
largest item on that node, email open tracking at 40.7%, is lock wait on writes, which a read
snapshot does not touch, and the feed reader itself adds a little load. So the ceiling for
database relief is roughly a third of statement time on db3, more if chat polling becomes push,
and nothing for the write-side problems. The benefit that is not in those numbers is the one
Edward named: the database leaves the foreground, so a stall in it no longer stalls browse, the
feed or chat lists. The first task is therefore a measurement with a go or no-go: from the
performance-watch digests, the exact statement time of the queries the first three endpoints
would stop issuing, the share of those calls that come from members in the active set, the
snapshot's expected size, and the saving in cores. If the avoidable share is under a fifth of the
node's statement time, this is not done for performance; it is then only worth doing as the
read side of the availability work.

**Is the member's slice small enough to pull down to the client?** Probably, for most of it,
and the snapshot should be built so that it can be. A member's own slice is their chat list
(tens to a few hundred rooms at a couple of hundred bytes each), the recent tail of each open
room, their own posts and replies, and their settings and counts: tens of kilobytes for most
members, a few hundred kilobytes for a heavy chatter, never megabytes. The shared part, the
posts near them and the local newsfeed, is bigger (hundreds to a few thousand posts at under a
kilobyte each) but it is the same for everyone in an area, which is why the design already
serves it as per-area files that HTTP caching and the app can hold. So the client can keep the
member slice locally, fetch it once by snapshot position, and receive deltas by position over
the same push channel chat uses; reopening after a day is one delta request, not a rebuild. What
that buys: reads leave the API as well as the database, the app opens on what it has and
corrects itself, and a stall anywhere upstream is invisible until the member writes. What it
costs: a client-side store and merge logic in the web app and the app, one more place a bug
can hide, and multi-device consistency handled by position, not by guessing. The availability
plan rejected local-first sync engines as the wrong problem for durability; this is not that. The
client never owns a write; it owns a copy of its reads. The decision below is to design the slice
to be shippable from the start and ship it to the client only after the server snapshot has
proved itself, chat list and own state first, the shared area never (it is already a cached file).

## Decisions settled

- **The change feed is the database's own log, not application events.** Binlog change data capture covers Go, Laravel and anything else that writes, and needs no change at any write site. This is what unblocks the "rebuildable projection" the availability plan parked.
- **The snapshot is rebuildable from scratch at any time.** Rebuild is the fix for every projector bug and the migration path for every schema change; if it cannot be rebuilt it is not a snapshot, it is a second database.
- **Serve from the snapshot only where seconds of staleness are acceptable**, which is browse, newsfeed, chat lists and counts, member enrichment; a member's own just-made change is shown by read-your-writes (apply it locally in the response, or wait for the projector to pass the write's position), never by reading the database.
- **Shadow before switch.** Every endpoint moved serves from the database and the snapshot side by side, compares, and logs differences until the difference rate is zero for a week; then the snapshot answers and the database read goes.
- **Measure first, with a go or no-go.** Task 1 produces the number; nothing else starts until Edward has seen it.
- **Hydrate on miss, cap the concurrency, fall back to the database above the cap.** As above.
- **Polling becomes push where the projector knows the change.** Chat lists first; a client is told something changed and fetches, rather than asking every few seconds.
- **The member slice is versioned by log position and deltas are by position, from day one**, so it can be held by a client later without redesign; shipping it to the client is a later task gated on the server snapshot proving itself. The shared area slice stays a server-side file.
- **One projector, one owner, checkpointed by log position.** The same shape as the availability plan's projector, and the same throttle rules against the cluster.
- **Files for the shareable, a key-value store for the per-member.** Per-area browse and message pages as files on object storage behind nginx (the availability plan's Phase 3); per-member chat lists, counts and enrichment in Valkey; both fed by the one projector.

## Open questions for Edward

- Which reads go first? Recommended: browse, newsfeed and chat-list polling, in that order, because they are the three 10% shares and all three tolerate seconds of lag.
- Staleness budget? Recommended: five seconds target, thirty acceptable, with the lag on the status dashboard.
- What is the go threshold for task 1? Recommended: an avoidable share of at least a fifth of db3's statement time, or the availability case on its own.
- Is this built with the command-log candidate or instead of its Phase 3? Recommended: with it; the command log is the write side and this is the read side, and they share the projector. If only one happens, this one gives the performance win on its own.
- Does the client-held slice go to the app first or the web first? Recommended: the app, because it opens cold most often and gains most from showing what it has.
- Does the snapshot also serve ModTools reads? Recommended: not at first; moderator views need freshness and are a smaller load.

## External prerequisites

- [ ] Confirmation that the production cluster can expose a row-format binary log with GTIDs to a reader, and the lag measured; if it cannot, the feed is a polling change table and the plan says so. Owner: Edward, read-only on a node.
- [ ] Object storage for the file side (the images migration account) and a Valkey instance on the docker host.

## Tasks

| # | Task | Depends on | Parallel track | Touches |
|---|------|-----------|----------------|---------|
| 1 | Measure: from `analysis/2026-10-02-perf-watch/`, the statement time the first three endpoints would stop issuing, the share from active-set members, the snapshot's expected size, the per-member slice size distribution (median and 99th percentile in kilobytes), the saving in cores; a go or no-go report | - | A | scripts, docs |
| 1a | Feed: a binlog reader that emits row changes for the active tables with positions; recorded feed from a dev stack for tests; lag and throughput measured | 1 (go) | A | new Go service |
| 2 | Snapshot store and projector: active-set definition (live and recent posts, open chats, members active in 90 days, events), rebuild from scratch, checkpoint, throttle; files and Valkey writers; hydrate-on-miss with a concurrency cap and eviction on the 90-day rule | 1a | A | new Go service, iznik-batch |
| 3 | Browse from the snapshot: per-area files, shadow comparison, switch | 2 | B | iznik-server-go, nginx |
| 4 | Newsfeed and chat lists from the snapshot: Valkey, read-your-writes keyed on the member's last write position, shadow comparison, switch | 2 | C | iznik-server-go, iznik-nuxt3 |
| 4a | Chat push: the projector emits change notices and the client fetches on notice instead of polling; polling kept as the fallback | 4 | C | iznik-server-go, iznik-nuxt3 |
| 5 | Backtest: replay a recorded day of production changes through the projector and diff every served answer against the database's | 2 | A | tests |
| 6 | Operations: rebuild command, lag alert, dashboard panel, runbook, developer reference | 3, 4 | B | docs, status-nuxt |
| 7 | Client-held slice: the web app and the app fetch the member slice by position, store it locally, apply deltas from the push channel, and reconcile on reopen; chat list and own state only; behind a switch | 4a, 6 | D | iznik-nuxt3, app |

## Acceptance

- The snapshot rebuilds from an empty store in under an hour on a dev stack, and the projector then keeps within the staleness budget under a recorded day of production changes.
- Browse, newsfeed and chat-list endpoints answer from the snapshot with zero differences from the database over a week of shadow comparison.
- A member's own post, reply or read mark appears in their next read, with the database untouched by that read.
- With the database read path disabled for those endpoints on a dev stack, the site browses and chats; with the database stopped, the last snapshot still serves.
- Database statement time for the moved queries falls to zero on the production watch after the switch, and the saving matches task 1's estimate within a quarter.
- A member absent for a year signs in and sees their chat list and posts within today's response time; ten thousand such members returning in an hour on a dev stack degrade to database reads, not failures.
- With push on, a chat client issues no list poll between changes and sees a new message within the staleness budget.
- With the client-held slice on, reopening the app after a day makes one delta request and shows the chat list before any network answer; two devices on one account converge by position.

## Review burden

- PRs: about 8
- Each UI PR carries: screenshots or a walkthrough video where the frontend changes (read-your-writes)
- Each migration PR carries: raw production SQL in the description (expected: none; the snapshot is outside the database)
- Docs page(s) to add or update, with `covers:`: a new developer reference page for the feed and projector, the ops runbook, `architecture.md`

## Out of scope

- Moving writes; the database stays the system of record.
- ModTools reads, search, and anything needing a synchronous database answer.
- Replacing `messages_spatial` in the first release; it is folded in once browse is served from the snapshot.

## Related plans

- `plans/token-burn/command-log-availability.md` - the write side; shares the projector.
- `plans/2026-08-21-frontend-availability-architecture.md` section 6 and Phase 3 - the files design this adopts.
- `plans/2026-09-29-perf-backlog.md` - the measurements that pick the first endpoints.

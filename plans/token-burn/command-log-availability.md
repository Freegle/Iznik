# Candidate: a command log in front of the database

Status: draft
Last reviewed: 2026-10-28

## Goal

The member site keeps accepting replies and most member actions while the database cluster is
stalled or down, by writing every deferrable modification to an append-only command log on the
front-door boxes and applying it to the database when it is back. Reads come from live data or
last-good files. The design is settled in `plans/2026-08-21-frontend-availability-architecture.md`
(21 August 2026, adversarially reviewed); this candidate is its Phases 2a to 3.

## Why it qualifies

- Token-heavy because: the plan itself sizes Phases 2a to 3 at about 12 to 16 person-weeks across Go middleware, a projector, four composite endpoints, client-assigned keys through the Nuxt retry stack, a key-map table, object-storage mirroring, read-side snapshot files, and the seven spikes it lists; nearly all of it is code and proof, not decision.
- Unattended because: the plan has already made the choices (log-ahead not outage-only, files not a broker, composites first, idempotency in the database); every phase is testable on a dev stack with a simulated freeze; only Phase 1 touches production.
- Worth it because: cluster stalls happen most days (about nine minutes a day of flow control measured) and today each one means hangs, 75-second sends, blank pages and lost typed messages; after this, members keep reading and their replies are never lost.

## What exists today

- The plan: sections 2 (what breaks, with file references), 5 (the 133 modification handlers classified A deferrable, B needs a synchronous answer, C must apply synchronously), 6 (the architecture and 6a dependent sequences), 7 (roadmap and sizes), 8 (what is deliberately not done), 10 (spikes). That is the design record; this file does not repeat it.
- `iznik-server-go/database/pingDB.go` still calls `os.Exit(1)` on a failed ping; there is no `GET /health`; no context deadlines reach GORM; no command log code exists anywhere (checked 2026-10-28).
- `iznik-nuxt3` retry stack retries POSTs without an idempotency key; `layouts/default.vue` gates all content on `bootSession()`.
- Chat's write path (`chat/chatmessage.go`) has one hard-synchronous insert and about eight best-effort follow-on writes, which is why the plan proves the log on chat first.
- Phase 1, the Katapult TCP load balancer in front of two HAProxy boxes (`plans/2026-07-12-katapult-lb-vs-haproxy.md`, Option C), is a production network change and is not built. The two HAProxy boxes are the log's ingest pair, so Phase 1 is a prerequisite for running the log in production, not for building and proving it.

## Decisions settled

All from the plan, section 8, and kept here only as a list so nobody reopens them:

- **Log-ahead on every request, not an outage-only queue** (a failure path that only runs in failures rots).
- **Files with framing and fsync on the ingest pair, mirrored to object storage; not NATS now.** The triggers for swapping the transport are written down.
- **Idempotency lives in the database** (client-minted UUID per logical action, unique column), proven first with no log at all (Phase 2a).
- **Composites first for dependent flows, client-assigned keys only where the calls are genuinely decoupled**, with the key-map table written by both the synchronous handler and the projector.
- **Class B and C handlers stay synchronous.** Login, registration, moderation actions, account deletion, payment webhooks.
- **The log is a buffer, not an archive**: segments deleted N days after the projector checkpoint passes them.
- **No pure-async projection, no browser outbox as the durability story, no managed database move.**

## Open questions for Edward

- Is Phase 0 (no process suicide, `/health`, request deadlines) done before the window as ordinary work? Recommended: yes, it is half a week and it is what makes the rest testable.
- Does Phase 1 (the load balancer and second HAProxy) happen before or after the window? Recommended: before, as it is a production change you do by hand and the ingest pair needs to exist; if not, the window still builds and proves everything on dev stacks and the switch-on waits.
- Which spikes must pass before the build is allowed to continue? Recommended: spikes 1 (fsync on Krystal disks), 2 (idempotent replay on chat) and 3 (cross-node dedupe), in that order, as gates on tasks 2 and 3 below.
- N days for log retention? Recommended: 7.

## External prerequisites

- [ ] Phase 1's two HAProxy boxes and the Katapult TCP load balancer, if the log is to run in production during the window. Owner: Edward.
- [ ] An object-storage bucket for segment mirrors (the images migration account). Owner: Edward.

## Tasks

| # | Task | Depends on | Parallel track | Touches |
|---|------|-----------|----------------|---------|
| 1 | Phase 2a: client-stable UUID per logical send through the retry stack; unique `command_id` on `chat_messages`; `CreateChatMessage` idempotent on replay; spike 2 as its test | - | A | iznik-nuxt3, iznik-server-go, migration |
| 2 | Spikes 1 and 3: fsync latency and durability on a Krystal VM; two-box dedupe proof; written up with numbers | - | B | scripts, docs |
| 3 | Phase 2b: envelope, framing, fsync writer, rotation, mirroring, mirror-lag alert; minimal projector on chat; Galera-aware catch-up throttle (spike 4); simulated-freeze test | 1, 2 | A | iznik-server-go, iznik-batch |
| 4 | Phase 2c part 1: the four composite endpoints (open-chat-and-send, full create for events, volunteering, noticeboards) and their frontend callers | 1 | C | iznik-server-go, iznik-nuxt3 |
| 5 | Phase 2c part 2: class A handlers wrapped at sub-action level; client-assigned keys and the key-map table for the decoupled chains; cascade policy; parked-command list | 3, 4 | A | iznik-server-go, iznik-nuxt3, migration |
| 6 | Phase 3: browse and message JSON snapshots to object storage, invalidation and staleness rules, last-good serving in the frontend | - | D | iznik-server-go, iznik-nuxt3, nginx config |
| 7 | Docs: the availability reference page, the ops runbook for the log (what to watch, how to drain, how to replay), and the switches | 3, 5, 6 | B | docs |

## Acceptance

- With the database stopped on a dev stack, a chat reply returns 202, survives a restart of the ingest process, and appears in the database within moments of the database returning, exactly once.
- A retry of the same send, including one that lands on the second ingest box, does not double-apply.
- During a simulated flow-control freeze the projector slows and does not deepen the freeze.
- The four composite flows work with the database down and resolve correctly after.
- A rejected head (spam-held post) auto-rejects its dependents with the same reason and the member sees today's copy.
- Browse and a message page render from last-good files with the API down, marked stale.
- Section 9's "after Phases 0 to 3" column holds on the dev stack for each scenario.

## Review burden

- PRs: about 10, in dependency order; the composites and the snapshot files can land independently
- Each UI PR carries: screenshots or a walkthrough video of the degraded states
- Each migration PR carries: raw production SQL in the description (`command_id` on `chat_messages`, the key-map table, both INSTANT where possible)
- Docs page(s) to add or update, with `covers:`: a new developer reference page for the command log, the ops runbook, and `architecture.md`

## Out of scope

- Phase 4, the database engine (Postgres and Patroni), its own programme.
- Phase 1's production network change, done by hand.
- Stage C, the fully rebuildable projection.

## Related plans

- `plans/2026-08-21-frontend-availability-architecture.md` - the design; keep it, this file points at it.
- `plans/2026-07-12-katapult-lb-vs-haproxy.md` - Phase 1, prerequisite.
- `plans/database-migration-evaluation-2026-07.md` - Phase 4; the log gives it a shadow-apply harness.

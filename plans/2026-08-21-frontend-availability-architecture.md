# Keeping the user-facing site up through database cluster problems

Date: 21 August 2026. Research basis: code-level maps of the Nuxt frontend, the Go API, the chat write path and the HAProxy front door; web research (with sources) on degraded-mode precedents, chat store-and-forward patterns, local-first sync engines, managed database pricing (August 2026) and Galera vs Patroni availability engineering; three candidate architectures drafted and then attacked by adversarial correctness and ops/cost reviews. Companion to `plans/database-migration-evaluation-2026-07.md`, which optimised for schema-change pain and spatial capability; this document optimises for availability.

## 1. Summary

**The brief:** cluster problems regularly leave the front-end site basically broken. Read-write must keep working for chat (send a message, see it arrive). Batch can be delayed. Could the user-facing services be entirely on Netlify-style hosting, with batch and mail on our own less-reliable servers?

**A binding product constraint (owner, 21 Aug):** read-only degraded mode is useless for Freegle, because people read in order to reply. And browser-side queuing is not an acceptable durability story, because members will not keep the browser open; a queued send that needs the tab alive to retry will simply be lost. Whatever we build must accept the reply on OUR infrastructure, durably, before the member walks away.

**The answer in one paragraph:** the frontend already is entirely on Netlify; what breaks is the API tier, which runs natively on the database nodes and shares their fate. A large part of "basically broken" is self-inflicted at the application layer and is cheap to fix: the API process literally kills itself (`os.Exit(1)`) on the first request after a DB blip, the load balancer's health check cannot see DB failures, there is no cache anywhere between the browser and the cluster, and the frontend can hang a cold page load for ~75 seconds of retries. The recommendation is a layered availability programme of roughly **8 to 10 person-weeks and about £40 to £70/month**, entirely engine-agnostic, whose centrepiece on the write side is a **server-side durable ingest for chat sends and replies**: when the cluster cannot take the write, the API appends it to a durable queue that lives off the cluster, acks the member so they can close the browser, and a drain worker applies it through the normal handler (with all its checks) the moment the cluster returns. Reads are stale-served from a cache for the same reason: not as a "read-only mode", but so the member can open the post or chat they are replying to. This all sits on top of (and de-risks) the already-planned Postgres migration, which remains the structural fix for Galera's cluster-wide freezes. Moving the database to a managed provider was re-costed with fresh August 2026 pricing and is still not recommended (roughly +£350 to £900/month, a new vendor stack for a two-person team, and a pay-twice risk against the committed Postgres plan).

**What "chat keeps working" honestly means.** During the common incidents (flow-control freezes of seconds to minutes, one sick node), chat feels seamless: optimistic echo, idempotent retries, stale-served reads, automatic routing around the sick node, and any send the cluster could not take is in the server queue and applied within moments of the freeze lifting. During a full-cluster outage, the member can still read (from cache), still reply (accepted durably server-side, marked "sent, delivery delayed"), and then close the browser; the recipient sees the message, and gets their push/email, when the cluster returns. The one thing no architecture can do is show the message to the second person while there is no database anywhere to serve them from; what we can guarantee is that nothing typed is ever lost and delivery is automatic on recovery, with no member action needed.

## 2. What actually breaks today (measured, with file references)

The availability chain for a member is: Netlify (static app, fine) -> browser calls `api.ilovefreegle.org` directly -> single HAProxy VM (applb) -> Go API running natively on db1/db2/db3 -> Galera cluster. Netlify's CDN is not in the API path at all, so nothing Netlify offers can soften an API outage.

**Cluster-level failure modes** (from the Galera research, all with real-world references):

- Flow-control stalls: one slow node pauses commits on every node. We measured ~9 minutes/day of cluster-wide commit freeze, almost all triggered by db2 (fixed queries in PR #1232, but the mechanism remains).
- TOI DDL freezes: any ALTER stalls writes cluster-wide; the cause of 32% of our schema changes needing manual runbooks.
- Quorum loss: lose 2 of 3 nodes and the survivor refuses all reads and writes until a human re-bootstraps.
- SST donor storms, certification conflicts, EVS false evictions: single-node degradations that can cascade.

**Application-level amplifiers** (each verified by direct code read):

1. **The API process commits suicide on DB failure.** `database/pingDB.go:12-41` runs on every request; on a failed ping it re-inits, and if that fails it calls `os.Exit(1)`. During an outage the first request kills the API on that node; traffic kills all three in seconds. This bypasses Fiber's recover middleware entirely.
2. **HAProxy cannot see DB health.** `api_server_backend` (haproxy.cfg:228-252) uses a bare TCP `check`; there is no `option httpchk` and the Go API has no `/health` route at all (the `/online` endpoint returns `true` unconditionally). A wedged-but-listening API looks healthy. The only thing that fails a node over today is the suicide above.
3. **No timeouts.** No context deadlines reach any GORM query; Fiber has no read/write timeouts; HAProxy's 50s `timeout server` cuts the client connection but the query keeps running. The cancellation-capable `database.Pool` built for this has zero production call sites.
4. **No cache anywhere.** No Redis/Valkey exists in the Go tree; the only caches are per-process in-memory maps (browse count, dashboard). All backends down means HAProxy's generic 503 (now at least CORS-readable) for every request.
5. **The frontend hangs rather than degrades.** `layouts/default.vue:63` gates all page content on `await bootSession()`; with the API down, the retry stack (up to 10 attempts plus a 30s `waitForOnline` gate, `composables/useFetchRetry.js`) can hold a cold load for ~75s before rendering anything. A `MaintenanceError`/`/maintenance` pipeline exists end-to-end but nothing ever throws it. Chat send is not optimistic: a typed message lives only in a component ref until the POST succeeds, so a tab close during an outage loses it.
6. **applb is a declared, un-remediated SPOF.** A second HAProxy was declined 2026-07-08; the Katapult TCP-LB "Option C" design exists in `plans/2026-07-12-katapult-lb-vs-haproxy.md` but was never implemented.

**Chat's actual shape helps us.** In `chat/chatmessage.go:621-1011` the only hard-synchronous write is the `chat_messages` INSERT; everything else in the handler is already best-effort. The recipient cannot see a message until Laravel's every-minute `chats:process-incoming` cron flips `processingsuccessful=1`, so delivery already tolerates 1 to 2 minutes of latency by design. There is no idempotency key, and duplicate sends already happen today (a nightly `DeduplicateChatMessagesCommand` exists to sweep them up), so adding one is a standalone win.

## 3. The Netlify question, answered directly

- The member site and ModTools are already static Netlify builds; `/browse/**` and `/chats/**` are `ssr: false` (pure client-side) and `/message/**` is ISR-cached 10 minutes on Netlify. The frontend hosting is already somebody else's problem, and it already stays up when our infrastructure is down. What the user then sees is an app shell whose every API call fails.
- The API cannot realistically live on Netlify: Go support in Netlify Functions is Lambda-shaped and half-supported, our API is a long-running Fiber/fasthttp process, and 300 sustained DB connections through bursty function invocations is the exact anti-pattern connection poolers exist to fight. Netlify DB is a thin Neon wrapper for small new apps, not a home for a 137GB production database.
- Batch and mail already live on our own servers and already fail independently of the site (documented in `docs/ops/production.md`). The split the brief asks for mostly exists; the remaining coupled piece is exactly the API+DB pair.

## 4. What the external research says (condensed)

- **Stale-served reads through an outage is the proven pattern.** GitHub ran read-only for 24 hours rather than risk data loss; Wikimedia deliberately goes read-only for 2 to 3 minutes twice a year as a drill; reads keep serving throughout in both cases. For Freegle, reads surviving is necessary but nowhere near sufficient (people read in order to reply), so the read side of those precedents transfers and the writes-pause side does not.
- **Big-name products do not ship server-side write acceptance for user content with the primary DB down**, because their accept-time checks (banned? member? duplicate? rate-limited?) live in the DB, and replaying a backlog into a recovering DB risks a replay storm (Discord's Nov 2023 incident). Client-side outboxes (WhatsApp, Matrix, Linear) are their shipped pattern instead. **Freegle's shape weakens both objections and rules out the client-only version.** Our spam, ban and content checks are already asynchronous (the Laravel cron runs them 1 to 2 minutes after the insert), and the recipient already cannot see a message until that cron has run, so "accept now, check on apply" is not a new trust model here: it is the existing model with a longer deferral. Meanwhile a client-only outbox fails our users specifically: members will not keep a browser open, so a browser-held retry queue loses the message. The replay-storm risk is real and handled with a throttled drain and idempotency keys. The store-and-forward acceptance point (WhatsApp's mailbox queue, NATS JetStream in front of the SQL store) is the right pattern for us, scoped to the chat domain.
- **Local-first sync engines are not our fix.** Zero and ElectricSQL are Postgres-only and solve client offline support for reads; their writes still terminate at your API hitting your DB. PowerSync has beta MySQL support but is unproven at our shape. A client outbox plus idempotency key delivers the useful subset at a fraction of the cost.
- **A health-check-driven proxy fixes node failures, not cluster freezes.** ProxySQL/MaxScale can auto-evict a sick node and promote a writer in seconds, but during flow control or TOI every node is frozen at once: there is nowhere to fail over to. Mitigations that reduce freeze frequency (wsrep_desync on the backup node, NBO/RSU for DDL, fc tuning) help; only leaving Galera removes the mechanism. Postgres+Patroni trades those freezes for a bounded 25 to 45s failover window plus new, monitorable risks (autovacuum/wraparound, DCS health).
- **Managed DB pricing, refreshed August 2026:** RDS/Aurora/Cloud SQL/Azure HA-sized at our workload run roughly £650 to £1,050/month (vs £420 self-hosted); Neon has no UK region; Supabase's pooler is undersized by default; PlanetScale for Postgres GA'd but UK region unconfirmed. New since July: Aurora DSQL reached London but its restricted Postgres surface likely blocks our raw-SQL corpus; PlanetScale's MySQL (Vitess) product is the one genuinely interesting "avoid the ORM rewrite" angle if we ever buy instead of build. CDN stale-serve for our API is unreliable at Cloudflare, real at Fastly/CloudFront, and irrelevant at Netlify (the browser bypasses it); an app-level cache is the dependable version of the same idea.

## 5. The options, scored

Three candidates were designed in full and then attacked by correctness and ops/cost critics. Summary verdicts:

| | A. Harden in place (LB redundancy, health checks, PXC tuning, ProxySQL) | B. Degradation layer (circuit breaker, stale-read cache, client outbox) | C. Somebody else's problem (managed DB, API co-located with it) |
|---|---|---|---|
| Covers one sick node / applb death | **Yes** (its core strength) | Partly (breaker stops the crash; routing fix comes from A) | Yes, by leaving Galera |
| Covers cluster-wide freeze (FC/TOI) | No; shortens/rarefies only | **Yes for reads and sender UX** (stale cache + outbox) | Yes (freezes cease to exist; failover blips remain) |
| Covers full outage | No | Reads stale + sends queued client-side; recipient delivery waits | Outages become short and bounded, not zero |
| New always-on components | 2nd HAProxy, TCP LB, (ProxySQL) | One Valkey box | Managed DB, pooler, relocated API tier, new cloud vendor |
| Effort | ~4-6 pw as designed; ~2 pw for the high-value subset | ~4-5 pw honest scope | 5-7 pw quoted; realistically more (zero AWS/GCP footprint today) |
| Cost delta | +£25-85/mo | +£15-20/mo | **+£350-900/mo** |
| Sharpest critique finding | garbd on a 3-node cluster changes nothing (quorum maths); ProxySQL topology unspecified; a naive `/health` can flap all nodes down during an FC pause | Auth-skip-by-policy would suspend ban/revocation enforcement on a false breaker trip; cache had no invalidation-on-delete path as drafted | Pay-twice risk against the committed Postgres plan; provider-scheduled failovers replace self-scheduled ones; the code bugs travel with us |

None of the three is right alone. The roadmap below takes the surviving pieces of each, in the order the critics converged on.

## 6. Recommended roadmap

Everything in Phases 0 to 3 is engine-agnostic: it works on PXC today, keeps working across the Postgres migration, and makes the migration's own cutover window safer. Nothing here duplicates the migration programme.

**Phase 0: stop shooting ourselves (0.5 to 1 pw, £0). Do immediately regardless of every other decision.**
- Replace `os.Exit(1)` in `database/pingDB.go` with an atomic "DB down" flag, per-request 503s, and a background reconnect loop (the pattern already proven in `database/failover.go`).
- Add `GET /health` to the Go API and switch `api_server_backend` to `option httpchk`. Design the check to distinguish "DB slow" from "DB confirmed down" (fail only after N consecutive failures with a generous timeout); a naive ping would mark all three nodes down simultaneously during a flow-control pause and turn a hang into a hard 503. Drill this against a simulated FC freeze before enabling.
- Cap the frontend boot path: render immediately from persisted state (`userlist`, `loggedInEver`) while `GET /session` resolves in the background, with a short retry budget for boot-critical calls. Kills the ~75s blank-page hang.

**Phase 1: front door and cluster hygiene (1.5 to 2 pw, ~+£25-40/mo).**
- Implement the already-written Option C: Katapult TCP-passthrough LB (~£10/mo) in front of two HAProxy nodes. Closes the one component whose death is a total outage no DB work touches.
- `wsrep_desync=1` on db1 during backups, alerting on `wsrep_flow_control_paused` > 0.1 and on API process restarts, db2 buffer pool raise (already in the July plan's immediate actions), db3 back under systemd.
- Dropped from Candidate A after critique: garbd on the existing 3-node cluster (the quorum arithmetic shows it changes no outcome; it only helps even-sized clusters). ProxySQL/MaxScale writer failover is deferred, not rejected: with `/health` in place, HAProxy's own httpchk failover between API instances may be enough, since writes all go to one nominated node anyway. Revisit with data after Phases 0 to 3 have run through a few incidents.

**Phase 2: sends that never need the browser again (3 to 4 pw, ~+£0-15/mo). The centrepiece.**
- Client-generated `client_msgid` UUID on every send; new nullable-unique column on `chat_messages`; insert becomes idempotent. Fixes today's real duplicate-send bug (the nightly dedupe cron is the evidence), makes retries safe, and is the dedupe key the drain below depends on. Plan the mixed-version window (old clients send no UUID and keep the old behaviour).
- **Server-side durable ingest.** Normal times: the send hits the DB exactly as today. When the DB write fails or the breaker is open: the API appends the full send (payload, userid from the verified JWT, `client_msgid`, timestamp) to a durable queue that does not live on the Galera cluster, and returns "accepted" so the member can close the browser. A drain worker replays each entry through the unmodified `CreateChatMessage` logic once the DB returns, so the room-access check, the reach/hold gate and then the normal Laravel spam/content pipeline all run at apply time. Drain is throttled (no replay storm into a recovering cluster) and idempotent via `client_msgid`.
- Queue technology: start with a Valkey stream with append-only persistence on the Phase 3 cache VM (one box to run, and the failure ladder is graceful: queue box down while DB up changes nothing, both down equals today's behaviour, so the queue adds no new hard dependency to a healthy day). NATS JetStream (3 small nodes) is the gold-plated upgrade if we ever want the ingest itself HA; note it stays engine-agnostic across the Postgres migration either way.
- Because this path only runs during incidents, it must be exercised deliberately: a scheduled canary send through the forced-queue path into a test chat, asserting drain and delivery, so rot is caught on a quiet day rather than during an outage.
- Sender UX: optimistic echo with sent/delayed states and one honest global banner via the unused `MaintenanceError` pipeline. A thin localStorage outbox is kept only as a supplement for the case where the API itself is unreachable from the browser (nothing server-side can accept what never arrives); it is explicitly not the durability story.
- Acceptance-time honesty: with the DB down, the room-access check cannot run at accept time. We accept on JWT authentication alone and validate at drain, rejecting (and logging for support) anything that fails. The member was looking at the chat moments earlier, so drain-time rejections should be vanishingly rare, and nothing becomes visible to anyone until the full pipeline has run.

**Phase 3: reads that let people reply (2 to 3 pw, ~+£15/mo).**
- Not a "read-only mode": the point of stale reads is that a member who cannot read the post or open the chat cannot compose the reply that Phase 2 will accept. One small Valkey box on its own VM (not on applb; the critique is right that stacking state on the SPOF increases blast radius), shared with the Phase 2 queue. Cache-aside with write-through for the hot read set: browse feed, `GET /message/:id`, `GET /chat/rooms`, `GET /chat/:id/message`, session payload.
- Served fresh normally; served stale with `stale: true` and a visible banner when the breaker is open. Two hard requirements from the correctness critique: explicit invalidation hooks on every mutation touching cached entities (delete, withdraw, taken, edit, hold), and a hard staleness ceiling even in degraded mode, so a withdrawn or held post cannot show indefinitely (this is a known past bug class here).
- Explicitly NOT included: skipping the auth session check by policy while the breaker is open. Today's behaviour (fail open only on an actual query error) already covers the outage case; widening it would suspend ban and session-revocation enforcement on any false breaker trip. If we ever want it, it is a separately reviewed decision with its own kill switch.

**Phase 4: the structural fix (already planned, unchanged).**
- PXC 8.4 now, PostgreSQL 18 + Patroni on the existing 37-43 pw programme. That migration is what actually deletes flow control and TOI freezes, replacing them with a bounded failover window that Phases 0 to 3 are precisely designed to paper over: the breaker plus stale cache plus outbox turn a 30s Patroni failover into a non-event, and they will do the same for the migration cutover itself.
- After Postgres lands, revisit LISTEN/NOTIFY + SSE for chat delivery (already in the July plan) which also removes the 30s polling latency.

**Total new work: ~8 to 10 person-weeks, ~+£40 to £70/month.** Sequencing cost is real: it delays the Postgres programme's start by roughly that many weeks for the same two people. The trade is right: Phase 0 alone converts several recent incident shapes from "site down" to "brief blip", and Phase 2 is what makes "people read to reply" survivable rather than the first casualty.

## 7. What we are deliberately not doing, and why

- **Relying on a client-side outbox for durability**: Freegle members will not keep a browser open, so a browser-held retry queue loses the message the moment the tab closes; it survives only as a thin supplement for the browser-to-API leg. Durability lives server-side, in the Phase 2 ingest, which is defensible here despite thin industry precedent because Freegle's moderation pipeline is already asynchronous (section 4).
- **Managed database / cloud move** (Candidate C): +£350 to £900/month, an entire new vendor stack (zero AWS/GCP footprint in the repo today) for a two-person team, provider-scheduled failover blips replacing self-scheduled ones, and either duplicating or destabilising the committed Postgres plan. The one variant worth remembering: if the organisation ever decides it would rather buy availability than run the migration programme, managed MySQL (PlanetScale Vitess/Aiven/RDS) sidesteps the ORM rewrite, and that decision should be taken consciously against the July evaluation, not drift in as an availability fix.
- **Moving the API to a PaaS with the DB staying at Krystal**: strictly worse than today for this goal (adds a WAN hop on every query, loses the Lua rate limiter and CORS layer, fixes none of the code bugs).
- **Local-first sync engines** (Zero/Electric/PowerSync): wrong problem; revisit post-Postgres if we want richer realtime UX than SSE.
- **CDN stale-serve in front of the API**: Cloudflare's stale-if-error is unreliable per its own community, and the dependable version of the idea is the app-level cache in Phase 3, which we control.

## 8. Availability outcome, before and after

| Scenario | Today | After Phases 0-3 | After Postgres (Phase 4) |
|---|---|---|---|
| One API/DB node sick or wedged | API process suicide, minutes of 503s until humans notice; possible black-hole routing | Auto-failover in seconds via /health; no process death | Same, plus no donor/SST class |
| Flow-control freeze (common, seconds to ~minutes) | Site effectively broken: hangs then errors; sends can take 75s or fail | Reads stale-served instantly; sends accepted into the server queue and applied when the freeze lifts (usually before anyone notices) | Mechanism no longer exists |
| Schema change (TOI) | Cluster-wide write stalls; 32% need manual runbooks | Same stalls, but masked like FC above; NBO/RSU where applicable | Transactional DDL, non-event |
| Full cluster outage / quorum loss | Total hard 503, blank pages, typed messages lost | App loads, browse/chats read stale with banner, replies accepted durably server-side (member can close the browser), delivered automatically on recovery | Bounded 25-45s failover instead of unbounded; degradation layer masks it |
| applb VM dies | Total outage | LB fails over to second HAProxy | Same |

## 9. Spikes needed before the relevant phase ships

1. `/health` behaviour under a simulated FC/TOI freeze: prove it does not flap the whole pool (Phase 0).
2. Queue durability and drain (Phase 2): kill the DB mid-send and verify acceptance, persistence across a queue-box restart, throttled drain into a recovering cluster, and drain-time rejection handling; plus the scheduled canary that keeps proving it.
3. `client_msgid` mixed-version rollout behaviour, web + app (Phase 2).
4. Cache invalidation coverage audit: enumerate every mutation path touching the five cached endpoints (Phase 3).
5. If ProxySQL is ever revisited: writer-promotion GTID-wait behaviour under an in-flight certification conflict, to guarantee read-your-writes after promotion.

## 10. Key sources

- GitHub Oct 2018 read-only incident: github.blog/2018-10-21-october21-incident-report; feature-flag degradation direction: github.blog/engineering/infrastructure/ship-code-faster-safer-feature-flags
- Wikimedia switchover drills: wikitech.wikimedia.org/wiki/Switch_Datacenter
- Slack May 2025 and Mar 2026 DB-rooted outages: ilert.com/postmortems/slack-outage-may-2025 (write path breaks first; circuit-breaker misconfiguration turned one 503 into total ejection)
- AWS static stability: aws.amazon.com/builders-library/static-stability-using-availability-zones
- Uber priority load shedding: uber.com/blog/from-static-rate-limiting-to-intelligent-load-management
- Galera flow control / TOI: percona.com/blog/galera-flow-control-in-percona-xtradb-cluster-for-mysql, docs.percona.com/percona-xtradb-cluster/8.0/toi.html; ProxySQL Galera awareness: proxysql.com/documentation/galera-configuration
- Patroni failover timing and DCS failsafe: patroni.readthedocs.io; Postgres wraparound tail risk: metronome.com/blog/root-cause-analysis-postgresql-multixact-member-exhaustion-incidents-may-2025
- Galera-to-Patroni migration before/after (pretix): behind.pretix.eu/2018/03/11/mysql-to-postgres
- Matrix txn_id idempotency: spec.matrix.org/v1.8/client-server-api; Zero 1.0: zero.rocicorp.dev; ElectricSQL writes: electric-sql.com/docs/guides/writes; PowerSync MySQL: powersync.com/sync-mysql
- Managed DB pricing (Aug 2026): aws.amazon.com/rds/mysql/pricing, azure.microsoft.com/en-us/pricing/details/postgresql/flexible-server, vantage.sh/blog/neon-acquisition-new-pricing, planetscale.com/docs/postgres/pricing, aiven.io/pricing; Aurora DSQL London: aws.amazon.com/about-aws/whats-new/2026/05/amazon-aurora-dsql-five-additional-aws-regions
- CDN stale-serve reality: community.cloudflare.com/t/stale-if-error-not-respected-for-timeouts-522-errors/280967, fastly.com/documentation/guides/full-site-delivery/performance/serving-stale-content, netlify.com/blog/announcing-durable-caching
- Netlify Go functions status: answers.netlify.com/t/working-with-go-functions-locally-and-in-deployment/3530

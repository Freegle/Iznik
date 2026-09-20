# Greenfield: Freegle rebuilt file-first

Date: 22 August 2026. A thought experiment answered seriously: if we were building Freegle's function from scratch, wanting minimum hosting cost and high availability, based primarily on file storage, how would we architect it? Companion to `plans/2026-08-21-frontend-availability-architecture.md` (the incremental plan for the real system); section 10 connects the two.

## 1. The function, reduced to essentials

Members post OFFERs and WANTEDs with photos, browse what is near them, reply into a private chat, arrange a handover, mark things taken. Volunteers moderate. The system sends a couple of hundred thousand notification emails a day. Interactive write volume is tiny (well under one chat message per second on average, single-digit commands per second at peak); read volume is modest and highly cacheable; the data is naturally sharded by geography and by chat room. Nothing in the core loop needs sub-second cross-entity transactions. That profile is why a file-first design is not a stunt here: Freegle's workload is about as file-shaped as interactive websites get.

## 2. The shape: three layers, files in the middle

```
browser (static app, CDN)
   |  reads: GET materialized JSON files (CDN -> object storage)
   |  writes: POST command -> tiny ingest -> append to event log (object storage)
   v
object storage = the system of record
   - events/     append-only command/event segments (JSONL, CRC-framed)
   - live/       materialized read files (feeds, messages, chats, queues)
   - media/      photos (already file-shaped today via tusd)
   - lake/       parquet for analytics
   v
one projector (leased single writer)
   - tails events/, applies business rules, rewrites live/ files
   - maintains a SQLite index (Litestream-replicated back to object storage)
   - emits mail jobs, moderation queue updates
```

- **The event log is the only source of truth.** Every mutation is an appended event. Object storage supplies durability (eleven nines) and availability as the provider's problem, at pennies per gigabyte. Everything else in the system is derived and rebuildable by replay.
- **All reads are files.** The application never queries a database to render a page. Browse is `live/feeds/{area}.json`. A post is `live/messages/{id}.json`. A chat is `live/chats/{room}.jsonl`, an append-only file polled with conditional GETs, which is fitting because a chat IS an append-only log. Moderator work queues are per-group files. The member's own pending actions are a per-user file.
- **Compute is small, stateless or rebuildable, and out of the PUBLIC read path.** A tiny ingest service (two instances) validates JWT, shape, size and rate, then appends. One projector holds a lease and materializes. A mailer drains mail-job files. Private reads (chat, inboxes, mod queues) do transit the auth edge on every request, so that edge is honestly a read-path component we run; it is stateless, redundant by default on an edge platform, and its failure pauses private reads without touching public browsing. None of the rest being down stops reading; only ingest being down stops writing.

## 3. The data model as files

- `events/{yyyy-mm-dd}/{node}-{seq}.jsonl`: command envelopes, client-assigned entity UUIDs throughout (the lesson from the dependent-sequences analysis: ids the client mints, the server adopts, so chains never wait on a server id).
- `live/feeds/{area}.json`: the browse feed for one area, a few hundred active posts at most, regenerated incrementally on each relevant event. Client-side filtering and ranking on top.
- `live/messages/{id}.json` + `media/`: the post page. Public posts are public files.
- `live/chats/{room}.jsonl`: one append-only file per chat room. Private (section 5).
- `live/users/{id}/`: inbox summary, own-posts list, pending-command outcomes. Private.
- `live/modqueues/{group}.json`: pending posts, reports, member notes for moderators. Private to group mods.
- `index/freegle.sqlite`: the relational materialization for the queries files are bad at: member lookup for support, cross-entity moderation checks, uniqueness (email), spam heuristics. Maintained only by the projector, streamed to object storage by Litestream (v0.5's LTX rearchitecture, Oct 2025, made point-in-time restore a dozen-file operation), restorable anywhere in minutes. It is an index, not the record: losing it costs a replay, not data, which is also why Litestream being disaster-recovery rather than HA (a crashed node can lose its last few seconds) is acceptable here. This piece is load-bearing, not optional polish: every object-storage-native system that shipped in 2024-2026 (WarpStream, SlateDB at Dropbox, Turbopuffer, DuckLake) kept exactly such a small stateful index beside the files; none went pure-files.
- `lake/*.parquet`: telemetry and history compacted out of the hot path, queried with DuckDB. Analytics also ends up as simple files.
- Baked artifacts: group polygons, isochrone/reach precomputes, per-area search indexes (a few hundred active posts per area makes client-side search over a small prebuilt index trivial). The spatial KNN sidecar Freegle already runs is exactly this pattern today: a rebuildable file-backed service.

## 4. Writes

Identical philosophy to the incremental plan, but native rather than retrofitted: append first, ack the member, apply asynchronously. The ack means exactly one thing: the event is durable. It never means the derived files are updated yet. Apply latency is bounded by how the projector learns of new events: ingest nudges it directly (best effort, over plain HTTP) with a ~1s listing sweep as the fallback, so applies land in roughly 0.5 to 2 seconds normally. Recipient-visible latency is apply latency plus the recipient's poll gap, and is stated honestly in section 6 rather than rounded to "within a second". The projector is a serialized single writer, which makes the classically awkward file problems easy: uniqueness (one email, one account) is enforced at apply against the SQLite index, and registration is naturally asynchronous anyway because it ends in an email verification round trip. Idempotency by client UUID everywhere. Moderation and spam checks run at apply, which is the trust model the current system already has. A rejected command writes an outcome the member's own pending file picks up.

## 5. Privacy: the one place pure static files are not enough

Public content (feeds, post pages, photos on public posts) is served straight from CDN. Private content (chats, inboxes, mod queues) sits in a private bucket, and access goes through a stateless auth edge: verify the JWT, check the claim against a small baked ACL (room membership is itself materialized into the room file's header or a sibling file), mint a short-lived signed URL or proxy the ranged read. This is a few milliseconds of compute with no state and no database; it scales on an edge-function free tier or one small VM, and its failure mode is "private reads pause", never data loss.

GDPR: the log compacts. A forget-me command causes the compactor to rewrite affected segments with tombstones and the projector to re-materialize affected files; PII-heavy payloads can be crypto-shredded per subject (several DPAs accept key destruction under Article 17's disproportionate-effort clause) if segment rewriting is ever too coarse, remembering that derived plaintext files must still be regenerated. One trap the research flags loudly: bucket versioning silently defeats erasure (a DELETE writes a marker; every prior version stays readable by version id), so versioning stays OFF on the event log, or a hard-delete lifecycle rule is part of the design from day one.

## 6. Chat mechanics, concretely

Send: command appended (client UUID), ack immediately; projector validates (membership, spam heuristics from the index), appends the message to `live/chats/{room}.jsonl`, bumps the recipient's inbox file, queues a push/mail job. Receive: the client polls the room file with If-None-Match while the room is on screen and the inbox file less often; unchanged files cost a 304 (which still bills as a read operation), and the file only grows, so a ranged GET fetches just the tail.

Latency, honestly. Recipient-visible time = apply (~0.5 to 2s, section 4) plus the recipient's poll gap. Polling a visible room every 10 to 15s with backoff on idle rooms gives a p50 around 6 to 9s and a p95 under ~20s. With the push nudge below, p50 drops to ~1 to 2s. For calibration, today's production system gates recipient visibility on a once-a-minute batch cron, so recipient p50 is 60 to 120 seconds; even polling-only file chat is several times faster than the incumbent, and the nudge makes it feel live.

Volume and cost, consistently with section 9. Freegle's realistic concurrency is a few hundred rooms on screen at peak, not thousands (chat here is reply-to-post messaging, under 1 message/second across the whole site). 300 visible rooms at a 15s cadence is ~1.7M polls/day; with inbox and feed traffic the platform lands in the 3 to 5M reads/day section 9 now budgets. The cost model is linear in open rooms divided by poll interval, so the design includes its own escape: a tiny nudge relay beside the projector (SSE; "room X changed", client then does the conditional GET) becomes the primary signal once concurrency grows, collapsing poll volume by an order of magnitude; the storage model does not change either way. Polling floors are enforced client-side and at the auth edge.

S3-class storage has been strongly read-after-write consistent, including overwrites, since 2020, so freshness questions live entirely at the CDN layer: short max-age plus stale-while-revalidate on public feed files; private chat reads go through the auth edge to origin, uncached, and that per-poll edge transit is priced in section 9.

## 7. Coordination is also a file

Since 2024-25, S3 and R2 support conditional writes (If-None-Match create-if-absent, If-Match compare-and-swap). The projector's single-writer lease is a lock object renewed by conditional PUT carrying a monotonic epoch as a fencing token; a standby takes over when the lease lapses. Checkpoints are files. There is no ZooKeeper, no etcd, no Patroni: the coordination plane is the same storage as the data plane, with the provider's availability. The documented limits are respected, not wished away: this primitive is eventually correct rather than linearizable (clock skew can briefly overlap leaders), so every writer stamps and every consumer checks the epoch, a reaper clears stale locks, bucket policy blocks non-conditional writers on the lock prefix, and the pattern is used only for low-frequency leadership handoff of the one projector, never as a per-object mutex (which would be a 412-retry storm at chat volume).

Because leaders can briefly overlap, hot `live/` files are also written with compare-and-swap (If-Match on the last-read ETag, epoch stamped in the file header): a losing projector's 412 makes it re-read, notice the newer epoch, and step down. And the overlap's worst case is bounded by the design's core invariant: files are derived, events are truth. A racing write can corrupt a derived file, which the next checkpointed re-materialization heals; it cannot lose a chat message, because the member's ack was durability of the event, never of the file.

## 8. Availability analysis

| Component down | Effect |
|---|---|
| Object storage / CDN (provider) | The outage. Everything else is engineered so this is the only single point, and it is the most available thing money can rent. |
| Both ingest instances | Reads fine; writes fail visibly. Two tiny stateless boxes, trivially replaceable; the rarest failure in the design. |
| Projector | Reads fine, writes accepted and durable; applies lag. Standby takes the lease within seconds. Freshness alert, not downtime. |
| Auth edge | Public reads fine; private reads (chat) pause. Stateless, redundant by default on an edge platform. |
| Mailer / analytics / compactor | Mail or reports lag. Nobody notices for hours. |

The "batch can be delayed, interactive must work" requirement falls out of the architecture instead of being bolted on. The public read path has no component we operate; the private read path has exactly one, the stateless auth edge.

## 9. What it costs (vendor pages checked August 2026; volumes assumed: 100GB core data + 1TB images, 3 to 5M reads/day including chat polling at realistic concurrency per section 6, ~100k writes/day; conditional-GET 304s bill as read operations and are counted)

| Item | Choice | £/mo |
|---|---|---|
| Object storage (events, live files, images, lake) | Cloudflare R2 (zero egress; EU jurisdiction option); read-operation line scales with poll volume, so this is ~£34 at 2M reads/day rising to ~£60-70 at 5M polling-heavy days; the section 6 nudge relay pulls it back to the low end | ~£34-70 |
| Static app + public file serving | Cloudflare Pages, free for pure static (a Worker in the path is what costs; Netlify's free tier would break first, on request count) | £0 |
| Auth edge for private files (2-5M req/day) | Workers Paid + overage | ~£16-37 |
| Ingest pair + projector + mailer | 2-3 small VMs (2 vCPU/4GB): Hetzner ~£4 each (EU only), OVH UK ~£7 each, Krystal ~£13 each | ~£8-40 |
| Monitoring, DNS, backup headroom | | ~£10 |
| **Platform total, excluding email** | | **~£70-160** |
| Email at 200k/day | AWS SES at $0.10/1k | ~£470 |
| Email alternative | Self-hosted postfix (a VM is ~£20-40; the real cost is IP reputation and warmup labour) | ~£20-40 + labour |

Three honest observations. First, the platform itself runs at **roughly a tenth of today's ~£800/month fleet** (of which £420 is the DB cluster), and the part that used to be the fragile, expensive centre (the database tier) becomes a few pounds of object storage plus one small VM. Second, **email dominates the greenfield bill**: at SES prices it is ~85% of the total, and no storage architecture touches it. A true from-scratch team should budget SES and treat deliverability as bought, not built; Freegle specifically already owns warmed IPs and postfix expertise, so in any real convergence the existing ~£20-40 self-hosted mail path keeps that line small. Third, jurisdiction is a real choice: R2 is cheapest but US-parented (CLOUD Act exposure even for EU-pinned buckets); Hetzner/Scaleway object storage is the EU-jurisdiction-clean option at broadly similar money; S3 eu-west-2 is the UK-resident option at ~£51+/month before egress.

## 10. What files are honestly bad at, and the answers

- **Ad-hoc queries** (support and moderation live on these today): the SQLite index and DuckDB over parquet exist precisely for this; they are files too, just queryable ones. What is genuinely lost is the ability to hand-run UPDATE against production. That is not waved away: support and ops here really do one-off corrections today, so a `manual-correction` command type (arbitrary targeted mutation, applied by the projector, audited by construction) is a day-one requirement, not an afterthought. Slower than raw SQL on the day; safer and replayable forever after.
- **Projector deploys are the biggest bug amplifier**, so the projector gets the same rigor this team's own DB migration plan demands: a new version runs first as a follower materializing into a staging prefix, its output diffed against live before it may take the lease; rollback is handing the lease back. A bad deploy caught after the fact is healed by re-materializing from the last good checkpoint.
- **Replay is cheap only if it is indexed.** Rebuilding one lost or corrupted `live/` file must not mean scanning years of date-partitioned segments, so the SQLite index maps entity to (segment, offset) list, and that index is itself Litestream-replicated. Single-file rebuild is then a handful of ranged reads. Total-loss replay from genesis is the true disaster path and is sized, not assumed: on the order of tens of millions of events, a replay at a few thousand applies/second is hours, not minutes, which is acceptable for a disaster whose alternative today is restoring a 137GB cluster from backup.
- **Fan-out write amplification**: one event can touch a feed file, two user files and a mod queue. At single-digit events per second this is nothing, but the materializer must be incremental (patch files, not rebuild worlds) and the feed files kept small.
- **Cross-entity transactions**: serialized through the one projector. This caps write throughput at what one careful process can apply; at 100x current volume that ceiling would matter, and the honest escape hatch is sharding projectors by region, which is complexity deferred until a scale Freegle may never reach.
- **Hot counters and presence**: approximate or ephemeral; not durable state.
- **Anti-precedents are real and instructive.** The US ONRR built a static-site-over-baked-data architecture and retreated to a database: builds ballooned past 30 minutes as data grew, pages shipped all their data instead of what was asked for, and non-developers could not edit within a rebuild model. Flat-file CMS guidance converges on the same two failure axes: query flexibility and concurrent writers. This design concedes both points rather than arguing: it never full-rebuilds (incremental materialization, delta-append plus periodic compaction, the same shape Loki's ingester/compactor uses, and Loki is already run here), it has exactly one writer by construction, and every rich-query need is routed to the SQLite/DuckDB indexes rather than worked around with file scans. Every precedent that stayed file-primary at scale (Hacker News's single flat-file process, Baked Data, maildir) has one writer or infrequent writes; the ones with real concurrency kept an index. This design is deliberately in both camps.

## 11. Email

Email is the one function whose cost does not collapse in this design: ~£470/month at SES prices for 200k/day, versus ~£20-40 plus reputation labour self-hosted (viable for Freegle only because the warmed IPs and postfix practice already exist; a genuinely new team should buy SES and not fight Gmail). The architecture treats the mailer as a derived consumer of files, so the backend is swappable and mail lag never touches the site.

## 12. What this means for the real system

This is not a proposal to rewrite Freegle. It is the limit the incremental plan points toward, and it validates the plan's direction: the log-ahead command log (Phase 2) is this design's ingest and event log; the read-side last-good files (Phase 3) are its materialized read layer in embryo; the Postgres cluster's long-term role shrinks from system of record toward big queryable index. But full convergence is gated on something the incremental plan deliberately does not scope: the Laravel batch tier writes `chat_messages`, `messages`, `memberships` and `users` directly today, from many sites, and until those writes also become commands the log cannot be the sole source of truth. So the honest statement is: the current plan gets the interactive site to file-grade availability; reaching this document's endgame is a second programme (batch writes as commands) that should only be scoped if the first one proves its worth in production.

### 12a. The steelman against building this, even greenfield

A seasoned engineer's boring alternative: one Postgres primary plus standby (Patroni), object storage for media, SES for mail, everything on three small VMs. At under one write per second it lands in the same £70 to £160/month band as the file-first platform, keeps real transactions and ad-hoc SQL, and asks the team to learn one new operational discipline (Patroni failover) instead of five (lease election with fencing epochs, CAS-everywhere file writes, entity-indexed event partitioning, a projector that is validator, materializer and index-writer at once, and an auth edge carrying the busiest traffic). For a two-person team, novelty is the real cost, and the money saved before email is modest either way. The honest decision rule: the boring alternative wins on total effort and familiarity; file-first wins if, and only if, "the site must never depend on a database we operate" is treated as a hard requirement rather than a preference, because that is the one property the boring stack cannot offer at any tuning. This document exists because the brief made exactly that requirement explicit.

## 13. Key sources

- Conditional writes and leader election over object storage: aws.amazon.com/about-aws/whats-new/2024/08/amazon-s3-conditional-writes; morling.dev/blog/leader-election-with-s3-conditional-writes; aws.amazon.com/blogs/storage/building-multi-writer-applications-on-amazon-s3-using-native-controls
- S3 strong read-after-write consistency (2020, includes overwrites): aws.amazon.com/s3/consistency
- Object-storage-native systems keeping an index: docs.warpstream.com/warpstream/overview/architecture; slatedb.io/blog/introducing-slatedb; ducklake.select/2026/04/13/ducklake-10; jxnl.co/writing/2025/09/11/turbopuffer-object-storage-first-vector-database-architecture
- File-primary precedents: Hacker News flat-file architecture (news.ycombinator.com/item?id=5229522; lisp-journey.gitlab.io/blog/hacker-news-now-runs-on-top-of-common-lisp); Baked Data (simonwillison.net/2021/Jul/28/baked-data); Maildir atomic-rename model (en.wikipedia.org/wiki/Maildir)
- Anti-precedent: blog.onrr.gov/moving-to-database; flat-file failure axes: strapi.io/blog/flat-file-cms-guide-when-to-choose-file-based-systems
- Litestream v0.5 LTX rearchitecture: fly.io/blog/litestream-v050-is-here; production caveats: matthewswong.com/en/blog/sqlite-litestream-replication-production
- Incremental materialization shape: grafana.com/docs/loki/latest/get-started/architecture
- GDPR: versioning trap docs.aws.amazon.com/AmazonS3/latest/userguide/DeleteMarker.html; crypto-shredding granit-fx.dev/dotnet/compliance/crypto-shredding
- Pricing (checked Aug 2026): developers.cloudflare.com/r2/pricing; developers.cloudflare.com/workers/platform/pricing; developers.cloudflare.com/pages/functions/pricing; hetzner.com/pressroom/object-storage; scaleway.com/en/pricing/storage; smtpedia.com/amazon-aws-ses-pricing (SES $0.10/1k); jorijn.com/en/blog/self-hosted-email-2026; ovhcloud.com/en/vps/vps-uk; krystal.io/cloud-vps
- R2 EU jurisdiction and CLOUD Act caveat: dev.to/sandraversluis/cloudflare-r2-eu-jurisdiction-storage-for-uk-gdpr-what-we-learned-2m1f

# Splitting Freegle into cells: could a router plus many small servers replace the cluster?

Research note, 2026-09-19. Question: a large part of the hosting bill is there for reliability
and scale. The database is a three-node cluster because if it fails the whole site is dead, and
the nodes are big because everything runs through them. Could the system be re-architected so
that a thin front-end router sends each member to one or two of many small, self-contained
servers, each hosting a slice of the communities, on commodity hosting? Some things (stats,
support) would stay whole-system. Has anyone done this, what did they learn, and is it a good
idea for Freegle in 2026 or over-engineering?

Short answer: **no, not as a way to cut cost or to get resilience at Freegle's size.** The
pattern is real and well documented (AWS calls it cell-based architecture; Shopify calls them
pods; Bluesky, Discord, Notion, Figma and Uber each built a version). Every one of them did it
because a single database could no longer take the write load, at a scale 50 to 5,000 times
Freegle's, and every one of them reports that the router, the cross-cell features and the
tooling to move tenants between cells are where the cost went. Freegle's whole dataset is 136 GB,
of which about 34 GB is member-facing data, and the write rate is a few hundred statements a
second. That fits on one commodity server with room to spare. Measured on the live database, the
data is local enough to shard by area in principle (88% of replies come from the post's own
community, 98% from within 40 km), but the features that make Freegle work - rippling, chat,
search, moderation across communities, one identity, partner feeds - all cut across any boundary
you could draw. A cell design would cost more per month than today, take one to two person-years,
and would not remove the failure modes that actually take the site down. The instinct behind the
question is right in two places, and section 9 sets out the cheaper route to both: commodity
hardware for the database, and isolation so that one failure does not take everything with it.

## 1. What Freegle is today

Documented in `docs/ops/production.md`, `docs/getting-started/decisions-and-rationale.md` and the
July 2026 database evaluation (`plans/database-migration-evaluation-2026-07.md`), with the live
figures below measured on the db1 node on 2026-09-19.

| | |
|---|---|
| Hosting bill | about £800/month, of which the database cluster is £420 (two C-24 plus one C-36 at Krystal) |
| Database | Percona XtraDB Cluster 8.0, three nodes, all writes to db3, bulk reads on db2, db1 near idle and used for the nightly backup |
| Database size | 136 GB across 267 tables; 18-minute nightly backup to Google Cloud Storage |
| Other hosts | one HAProxy VM (the declared single point of failure), one Docker host for the batch jobs, edge tier and mail, an outbound Postfix relay, Netlify for the static front ends, a 4.9 TB NFS share with about 823 GB of images |
| Node sizes | db3 12 vCPU / 35 GB at about 25% utilised; db1 and db2 8 vCPU / 23 GB; Docker host 8 vCPU / 23 GB |
| Decisions already taken | 18 Sep 2026: drop db1 to a garbd arbitrator, £240/month for the database. Long term: PostgreSQL plus Patroni on two nodes, about £300/month, 37 to 43 person-weeks. Managed databases rejected at 2 to 3 times the spend. Hetzner dedicated rejected: about 4 times cheaper since its June 2026 repricing, but no UK datacentre and cross-provider latency to the app servers |

Daily volumes, measured over the last 30 days:

| | per day |
|---|---|
| New posts | 1,900 |
| Chat messages | 9,700 |
| First replies to posts | 1,850 |
| New accounts | 500 |
| Outbound email | about 200,000 (documented) |

| Members | count |
|---|---|
| Active in the last 30 days | 68,800 |
| Active in the last 90 days | 112,300 |
| Active in the last 365 days | 294,500 |
| Communities (published) | 496 in 12 regions |
| Largest community | 60,000 memberships |

Where the 136 GB actually is matters for the "beefy server" premise:

| Kind of data | Approx GB | Examples |
|---|---|---|
| Member-facing (what a cell would hold) | 34 | messages, messages_groups, chat_messages, chat_rooms, chat_roster, users, memberships, users_emails, attachments |
| Telemetry and mail tracking | 54 | messages_likes (views, 114M rows, 20 GB), email_tracking, bounces, logs, stats, users_searches, audits |
| National reference data | 21 | paf_addresses, locations, isochrones, rippling_reach |
| Everything else | 27 | |

The member-facing working set is the size of a laptop's SSD. The write rate is about 380 updates
and 34 inserts a second (documented in the July evaluation). The cluster is not there because the
data is big or the writes are heavy. It is there so that maintenance and a node failure do not
stop the site, and because the API, spatial and routing services share the database nodes.

What actually takes the site down, from `plans/2026-08-21-frontend-availability-architecture.md`:
Galera flow-control stalls (about 9 minutes a day), schema changes that freeze the cluster (a
third need a manual runbook), quorum loss that needs a human to re-bootstrap, the API exiting
on a failed database ping so that one bad node kills every API node within seconds, and the
single HAProxy box. The member sees a hard 503, a blank page, and loses what they typed.

## 2. How local is Freegle's data? Measured on the live database

The whole cell idea rests on one claim: a member only needs one or two servers. So the first
thing to check is how much of what a member does stays inside their own community or area.
All figures from the production database, read-only, on 2026-09-19.

**Membership.** Of the 112,300 members active in the last 90 days, counting only memberships they
chose (not ones rippling created):

| Communities joined | Share of active members |
|---|---|
| 1 | 44% |
| 2 | 17% |
| 3 | 10% |
| 4 | 8% |
| 5 | 6% |
| 6 or more | 16% |

For the 75,600 active members in two or more communities (including rippled ones), the distance
between their furthest two communities:

| Spread | Share |
|---|---|
| under 15 km | 18% |
| 15 to 40 km | 40% |
| 40 to 100 km | 36% |
| over 100 km | 6% |

So a member's footprint is typically a 15 to 40 km patch, not a single community, and a third of
multi-community members reach 40 km or more. Communities themselves overlap: rippling has created
226,000 extra memberships (4.4% of all 5.1M).

**Posts.** 651,000 posts in the last 12 months. 84% sit in exactly one community. The other 16%
were rippled into neighbouring communities, typically 8 to 17 of them.

**Replies.** 55,600 first replies in the last 30 days:

| | Share |
|---|---|
| Replier is a member of the post's original community | 88% |
| Replier is a member of at least one community the post is in | 99% |
| Replied-to post had been rippled to other communities | 91% |

| Distance from replier's home to the post | Share |
|---|---|
| under 5 km | 40% |
| 5 to 15 km | 41% |
| 15 to 40 km | 17% |
| 40 to 100 km | 1.6% |
| over 100 km | 0.4% |

**Chat.** 112,900 member-to-member chats started in the last 90 days. 13% are between two people
who share no community they joined themselves. Chats have no community: the `chat_rooms` row for a
member-to-member chat has a null `groupid`, and the code has a `GetCommonGroups` lookup precisely
because two chatting members may share none.

**Moderators.** 237 moderators active in the last 90 days:

| Communities moderated | Share |
|---|---|
| 1 | 38% |
| 2 to 3 | 23% |
| 4 to 10 | 26% |
| 11 or more | 13% |

30 people hold the Support or Admin role, which is system-wide by construction (`users.systemrole`).

**Regions as candidate cells.** Communities carry a 12-value `region` field. Memberships per
region run from 39,000 (Northern Ireland) to 992,000 (South East) and 940,000 (London). Two
regions hold 38% of all memberships, and the South East, London and East region boundaries run
through one continuous conurbation.

**Reading.** The good news for a cell design: replies are local. 98% come from within 40 km and
88% from the post's own community. The bad news: "local" means a 40 km neighbourhood, and
neighbourhoods tile the country continuously. There is no seam. Northern Ireland is the only
region with water on every side. Any line drawn on the map cuts through 12% of replies, 16% of
posts, 13% of chats, a third of multi-community members and 39% of moderators' patches.

## 3. What is whole-system by construction

From the code and the Laravel migrations (`iznik-batch/database/migrations`), not the docs. Of the
282 tables defined in the migrations, 55 carry a community key. 93 are keyed on the member only and 134 on neither. The two
largest live tables, `messages` and `chat_messages`, have no community key.

| Feature | Scope | Why |
|---|---|---|
| Identity and login | global | `users_emails.email` is unique nationally; `sessions`, `users_logins` have no community |
| Rippling | national | a post's reach is a drive-time polygon (`rippling_reach`, no community key). Targeting is a spatial join of every member's home against every community polygon (`iznik-routing-go/reachable_groups.go`). It then writes `messages_groups` and `memberships` rows into other communities |
| Browse and search | area, not community | `messages_spatial` plus a per-member "reach universe"; the vector search is one in-process embedding store over all posts |
| Spatial and routing services | national | `iznik-routing-go` holds the whole UK road graph (about 57M points) in memory; one KNN service |
| Chat | global | member-to-member rooms have no community |
| Spam and worry words | global lists | `spam_users`, `spam_keywords`, `worrywords` have no community key |
| Newsfeed (ChitChat) | distance-based | radius adapts from 1 to 128 km around the member |
| Email | mixed | community digests are per community, but reach digests, bounces, suppressions, tracking and newsletters are global |
| Notifications and push | per member | no community |
| Partners (TrashNothing, LoveJunk, WhatJobs, Reach Volunteering) | national feeds | `partners_messages`, `lovejunk` have no community key |
| Images and uploads | global | one tusd endpoint, one NFS share |
| Merges and duplicate detection | global | `merges`, `users_related` |
| Stats | per community rows, national roll-ups | `stats(date, groupid, type)`, but `/dashboard?systemwide=true`, `/item/impact`, `/authority/:id` skip the community join |
| Support tools | global | `GET /user/byemail` searches every account; `ModSupportFindUser`, `ModSupportListGroups` in ModTools |
| ModTools moderation | multi-community | a moderator's scope is `groupid IN (...)` across all their communities |
| Jobs, ads, donations, Gift Aid, volunteering, stories, noticeboards | global | none carry a community key |

Nothing in the code or docs proposes sharding, cells or federation. The word "cell" appears
only for raster grid cells in reach polygons, and "shard" only for worker parallelism over the
same database (digests split by `MOD(groupid, n)`).

## 4. What a cell would have to contain

A cell that lets a member do everything without touching another cell needs: its slice of the
database, a Go API, Laravel batch workers for its communities, and enough of the spatial data to
answer "what is near me". The last item is the problem. The routing graph is national and lives
in RAM. Either every cell carries a copy (50 copies of a 6 GB graph), or spatial stays global and
every browse, search and rippling tick is a cross-cell call. The same applies to the embedding
store for search, to spam lists, to identity, and to chat.

The pattern that keeps appearing in the industry is: the cheap part of a federated design is the
part that does almost nothing. All the work moves to the global index. Bluesky is the cleanest
example (section 5e).

## 5. What other people have built, and what they learned

### 5a. Cell-based architecture (AWS, Slack, DoorDash, Roblox)

AWS's Well-Architected guidance defines a cell as "a complete workload, with everything needed
to operate independently", behind a cell router that is "the thinnest possible layer" and is
itself "a single point of failure", so it "must be built with maximum reliability". Its stated
purpose is reducing the scope of impact, not cost. Two lines matter for a small team: "Migrating
clients from one cell to another is a tricky topic ... Have that in mind from day one", and
"keep in mind from day 1 to have more than one cell", because operating cells is a skill that
has to be practised.

Slack's cells are availability zones, not data shards. Compute is siloed so a service only talks
within its zone, but the database tier (Vitess) is not partitioned per cell; the post notes that
strongly consistent stores "require that there be a single backend available for any given
write". The migration took about 1.5 years for the critical services, was triggered by a
gray-failure network incident in one zone, and serves a 99.99% SLA. DoorDash did the same
zone-aware routing and got a cost win from avoiding cross-zone data transfer, a cost Freegle does
not have. Roblox uses cells to deactivate a bad cluster without a platform outage.

State of play: cells went mainstream in architecture reviews after 2023, but every published case
is a company with hundreds of engineers, multiple availability zones, and an SLA it is
contractually held to.

### 5b. Pods and shard-per-tenant (Shopify, Notion, Figma)

Shopify's pod is exactly the structure the question describes: "a set of shops that live on a
fully isolated set of datastores", each with its own MySQL shard, Redis and Memcached, behind a
"Sorting Hat" load balancer that maps shop to pod. It works because a shop is a clean tenant:
nothing a shopper does spans two shops. Even so, Shopify needed a Pod Mover for hot shards, two
regions with replication for availability, and learned the hard way that a shared Redis
"centralised a system that's supposed to isolate work" and had to be split per pod.

Notion sharded Postgres by workspace in 2021 (480 logical shards on 32 machines) and resharded to
96 machines in 2023 using logical replication, with PgBouncer as the cutover lever. Figma built
DBProxy, a Go service that parses every SQL statement, plans it against logical shards and
rewrites it for the physical database, after evaluating CockroachDB, TiDB, Spanner and Vitess and
rejecting all four. Both chose the shard key because it matched a hard product boundary
(workspace, file) and both did it because a single primary was about to exceed the IOPS ceiling of
the largest cloud instance. Freegle's write rate is three or four orders of magnitude below that.

### 5c. Geographic partitioning (Uber, Nextdoor)

Uber is the closest to "shard by place". Its original dispatch sharded driver state by city.
Growth pushed it to finer S2 cells and Ringpop, a peer-to-peer consistent hash ring. The 2021
re-architecture lists why that was abandoned: a hard vertical limit per pod "if any of the cities
crossed a threshold of concurrent trips", gossip overhead growing with node count, hotspots when
new products broke the sharding assumption (one merchant with many jobs), only "20 online
drivers/couriers per core", and an AP consistency model where "reconciliation was needed when
later operations failed". They moved to Spanner so that sharding stopped being an application
concern.

Nextdoor, the nearest product analogue (neighbourhood-based, geospatial), started on one
Postgres, hit outages, and sharded by neighbourhood ID. Their own engineers describe the cost:
"once we shard a database, we can no longer easily perform a Join between data on two different
shards", and by 2023 they were still wrestling with business logic that "relies heavily on the
power of relational data". Nextdoor has tens of millions of users.

### 5d. Guild-per-node (Discord)

Discord maps each guild to one Elixir process on one machine, and each user session to a process
that must look up every guild the user belongs to across the cluster. That is the "user spans
cells" problem in its purest form. It works because a guild is atomic, and it breaks when a guild
is too big: large guilds needed relay fan-out and manual feature switch-offs, and "guilds are the
atomic unit and cannot be further partitioned". Freegle's communities are not atomic (posts and
members span them), so even this model does not fit.

### 5e. Federated storage plus a central index (Bluesky, Mastodon)

Bluesky is the design the question most resembles: cheap personal data servers hold each
account's data (one SQLite file per user since November 2023), a relay streams changes, and an
AppView holds the global index. Bluesky's engineers say the PDS tier "has been a dream to run ...
cheap to operate (no Postgres service!) and require virtually no operational overhead". As of
August 2025 Bluesky ran 81 PDSs at about 145,000 accounts each, and a full-network relay costs
about $34 a month. The AppView is the expensive part: two ScyllaDB clusters of eight maxed-out
nodes each, estimated at roughly $500,000 of hardware. The lesson is direct. The federated tier
is cheap because it only stores and serves one user's own records. Everything a user actually
wants (feeds, search, notifications, who-replied) comes from the global index. Freegle's global
index would be today's database with its telemetry removed.

Mastodon is real federation across operators. Its 2025 and 2026 experience is a warning about
operating many small instances: Sidekiq queues backing up, unbounded remote media growth, a
moderator-to-user ratio around 1 to 3,500, one in five admins reporting exhaustion, and community
organisations closing instances because running one "isn't safe or sustainable enough ... on a
shoestring". Monthly active users fell from about 2.5 million to about 750,000.

### 5f. Database-per-tenant (Turso, Cloudflare Durable Objects)

The extreme form of the idea, one SQLite database per tenant, became a product category in
2025: SQLite in Durable Objects went GA in April 2025 with 10 GB per object, and Turso offers
tens of thousands of databases per account. Turso's own guidance on when not to use it reads
like a description of Freegle: "consumer apps with millions of free users doing minimal
activity", "applications requiring constant cross-tenant queries", and "existing apps deeply
built on a shared schema", where migration "is a rewrite rather than a refactor". Production
users report tenant-scoped queries get faster and cross-tenant queries get slower, with a global
summary table as the workaround, plus head-of-line blocking inside a tenant and migrations that
must run once per database.

### 5g. Sharded MySQL middleware (Vitess)

If one wanted to shard the existing MySQL schema without rewriting the application, Vitess is
the tool. Vitess 24 (April 2026) added more sharded query support, and atomic cross-shard
transactions have been complete since v22. PlanetScale added per-shard sizing on 18 September
2026. The limits are stable and documented: no stored procedures, triggers or `GET_LOCK` through
VTGate; `SELECT ... FOR UPDATE` is only atomic within a shard; cross-shard joins are
scatter-gather; `GROUP BY`, `ORDER BY` and `LIMIT` across shards merge in VTGate's memory. The
July evaluation already recorded the verdict: Vitess "solves a sharding problem we do not have".

### 5h. The opposite trend: one big commodity box

The 2025 and 2026 discussion has mostly gone the other way. Crunchy Data's guidance is "just use
PostgreSQL" on one machine unless a measured bottleneck forces a change. The "Use One Big Server"
essay was re-discussed in September 2025 with the observation that $200 a month buys 4 vCPUs at
AWS or 48 cores and 128 GB at Hetzner. 37signals spent $700,000 on Dell servers, cut $2 million a
year of cloud spend, deleted its AWS account in 2025, and runs MySQL on bare metal behind HAProxy,
not cells. Bluesky itself runs its expensive tier on 16 very large nodes rather than many small
ones.

Two 2026 caveats. Hetzner raised prices several times in 2026 (30 to 37% in April, a catalogue
restructure in June), so the old "10 times cheaper" figure is now closer to 4 times, as the July
evaluation notes, and Hetzner has no UK datacentre. OVHcloud London lists Rise servers from
£98.99 a month ex VAT with setup waived on a 12-month term, and Mythic Beasts sells UK dedicated
servers with hosting and hardware priced separately. Krystal's advantage over all of these is
live resize with no downtime and the NFS and backup integration already in use.

On the MySQL high-availability side, Codership's MySQL-flavoured Galera reaches end of life on
30 September 2026 (Percona XtraDB Cluster and MariaDB Galera continue), MariaDB bought Codership
in May 2025, and the small-team default in 2026 commentary is MySQL Group Replication in
single-primary mode behind MySQL Router, or an async replica with an orchestrator. None of that
changes the July evaluation's Postgres-plus-Patroni recommendation.

## 6. Mapping the proposal onto Freegle

Three ways to draw the cells, and what each does to the measured traffic in section 2.

**By community (about 500 cells).** 56% of active members are in more than one community, and
rippling creates memberships in others. A member's home cell would need to reach into several
others for browse, digests and chat. Not viable.

**By region (12 cells).** The natural administrative split. Two regions hold 40% of memberships,
so London and the South East are each a cell holding about a fifth of today's data, which
defeats "commodity". The three south-eastern regions form one conurbation, so the seam cuts
straight through the densest reach polygons. Northern Ireland is the one region that would be a
clean cell, and it is 0.8% of memberships.

**By hexagon or drive-time patch (about 50 cells).** This is Uber's S2 path. Cells are small
enough for commodity boxes, but every reach polygon straddles cell edges, every member near an
edge has two home cells, and rebalancing when a town grows means moving members and their chats
between cells with a Pod Mover. Uber abandoned this for hotspots and consistency reasons at a
scale Freegle will never reach.

Whichever cut is used, the global tier has to hold identity, sessions, chat, spam lists, the
search index, the routing graph, notifications, partner feeds, images, mail, logs, stats and the
support tools. That is most of today's stack. The cells hold posts, community memberships and
digests.

**Cost estimate.** Cheapest plausible Krystal cell (4 vCPU, 8 GB, C-8) is £51.25 a month. The
global tier still needs a router pair, a database pair for identity and chat that cannot go down
either, the spatial and routing service, the Docker host, mail, storage and logs.

| Variant | Cells | Global tier | Total per month | Today |
|---|---|---|---|---|
| 12 regional cells at Krystal | 12 x £51 = £615 | about £590 | about £1,200 | £800 |
| 50 small cells on a £15 commodity VPS elsewhere | 50 x £15 = £750 | about £590 | about £1,340 | £800 (£620 after the garbd change) |
| Bluesky-style home cells plus global index | as above | today's database minus telemetry | more than today | |

These are estimates to an order of magnitude, but the direction does not change: cells add fixed
cost per cell and remove nothing from the global tier, because the global tier is where the work
is. The only way a cell design gets cheaper than today is if the per-cell box costs less than the
saving on the central database, and the central database is already heading to £240 a month.

**Engineering estimate.** Rippling, search, chat, ModTools, digests, the TrashNothing and LoveJunk
feeds and support tools would all need a cross-cell path. 587 migration files would run per
cell. Yesterday, CircleCI, backups and monitoring would each become per-cell. Member moves between
cells need tooling of the Shopify Pod Mover kind. Compared with the 37 to 43 person-weeks already
estimated for the Postgres move, this is one to two person-years before any benefit, on a team
with no on-call rota.

## 7. Resilience: what cells fix and what they do not

Cells shrink the blast radius of a cell failure from everyone to 1/N. They do nothing for the
router, identity, chat or the rest of the global tier, which still fails for everyone. And a cell
on one commodity box has no replica, so it fails more often than a cluster node, just for fewer
people. With 50 boxes at 99.9% each, some cell is down about 5% of the time. Each member sees
roughly the same downtime as today, and the team handles fifty times as many incidents. Bluesky
runs 81 data servers with around 29 staff and purpose-built tooling. Mastodon's admins burn out
running one.

Against Freegle's actual failure list in section 1, cells score badly:

| Failure today | Do cells help? | What does |
|---|---|---|
| Flow-control stalls, TOI DDL freezes, quorum re-bootstrap | no, these are Galera behaviours and the global tier keeps Galera or something like it | single-primary plus replica (Patroni or Group Replication), already the plan |
| API exits on a failed DB ping and takes all nodes | no | a health check and a retry instead of `os.Exit` |
| One HAProxy box | no, the router is the same single point | two HAProxy boxes behind the £10 Katapult TCP load balancer (Option C in the July LB note) |
| Member loses typed text during an outage | no | the command log in `plans/2026-08-21-frontend-availability-architecture.md`: accept and fsync the write on a box independent of the database, apply it later |
| Maintenance needs downtime | partly (one cell at a time) | rolling maintenance on a primary/replica pair does the same |

## 8. Verdict

Over-engineering for Freegle, and not a cost saving. The case for cells at Slack, Shopify,
Notion and Figma rests on write volumes a single primary cannot take and on contractual SLAs.
Freegle has neither. Its data would fit on one commodity box, its cluster exists for maintenance
and failover rather than throughput, and the product's defining features are the cross-boundary
ones. Bluesky shows what the design delivers when done well: a cheap storage tier and an
expensive global index, which for Freegle is the database it already runs.

The instinct behind the question is sound in two respects, and both have cheaper answers than
cells:

- **Commodity hardware.** Yes. The database does not need three 8-to-12 vCPU nodes. The agreed
  garbd change halves the bill, and a two-node primary/replica on Postgres or MySQL can run on
  commodity VMs or on UK bare metal from OVH or Mythic Beasts. Keep the pair in one datacentre.
- **Isolation.** Yes, but isolate by workload class, not by geography. Member-facing traffic,
  batch work and the edge tier should not share fate. The cgroup slices work on the batch host,
  the read/write split, and the command log all do this without changing the data model.

## 9. The cheaper ladder to the same benefits

In order, each step independent of the next:

1. **Garbd on db1 and a smaller db2** (decided 18 Sep 2026): £420 to £240 or £257 a month.
2. **Two HAProxy boxes behind a Katapult TCP load balancer**: about £10 a month plus one small
   VM, removes the declared single point of failure.
3. **API survives a database blip**: replace the exit-on-failed-ping with a health endpoint and a
   backoff, so one bad node does not kill the API everywhere.
4. **Command log for member writes**: the availability plan's Phase 1 to 3, about 14 to 20
   person-weeks and £50 to £70 a month, so the site keeps accepting posts and messages during a
   database outage.
5. **Postgres plus Patroni on two nodes** (or, if staying on MySQL, Group Replication
   single-primary with MySQL Router): ends the Galera failure modes; about £300 a month.
6. **Then, and only then, price UK bare metal** for the database pair. OVH London or Mythic
   Beasts could halve that again, at the cost of Krystal's live resize and integrated storage.
   Hetzner is ruled out by the lack of a UK site.

Steps 1, 2 and 3 cost almost nothing and remove the three most common outage causes. Step 4 is
the one that changes what a member experiences during an outage. None of them require a router,
a partition key or a data migration.

## 10. What would change the verdict

- Writes grow 20 to 50 times, or the database passes about 1 TB of member-facing data. That is
  where Notion, Figma and Shopify started sharding.
- A regulatory need for regional data residency inside the UK. None exists.
- A product decision to make communities independent again: no rippling, no cross-community
  chat, no national search. That removes the cross-cell traffic, and also removes what the last
  two years of product work built.
- A contractual SLA with a penalty. Cells are how 99.99% is bought at scale; Freegle's documented
  posture is "no 24/7 rota and no paging".

A lighter idea worth keeping is read locality rather than write cells: regional read replicas or
edge caches for browse and search, with all writes still going to one primary. That is the
direction the frontend availability plan already takes, and it needs no partition key.

## Sources

Internal: `docs/ops/production.md`; `docs/getting-started/decisions-and-rationale.md`;
`docs/developers/reference/rippling-algorithm.md`; `plans/database-migration-evaluation-2026-07.md`;
`plans/2026-08-21-frontend-availability-architecture.md`;
`plans/2026-08-22-greenfield-file-first-architecture.md`; `plans/2026-07-12-katapult-lb-vs-haproxy.md`;
`plans/2026-09-19-batch-load-on-db3-priority-research.md`; `iznik-server-go/chat/chatroom.go`;
`iznik-server-go/message/groups.go`; `iznik-routing-go/reachable_groups.go`;
`iznik-batch/app/Services/Ripple/ExpandService.php`. Live measurements: read-only queries on the
db1 node, 2026-09-19.

External:

- AWS, Reducing the Scope of Impact with Cell-Based Architecture:
  https://docs.aws.amazon.com/wellarchitected/latest/reducing-scope-of-impact-with-cell-based-architecture/what-is-a-cell-based-architecture.html
  (cell router, cell migration, cell sizing and best-practice pages)
- Slack, Slack's Migration to a Cellular Architecture:
  https://slack.engineering/slacks-migration-to-a-cellular-architecture/
- InfoQ, How Cell-Based Architecture Enhances Modern Distributed Systems (Slack, DoorDash, Roblox):
  https://www.infoq.com/articles/cell-based-architecture-distributed-systems/
- Shopify, A Pods Architecture To Allow Shopify To Scale:
  https://shopify.engineering/a-pods-architecture-to-allow-shopify-to-scale
- Shopify, Shard Balancing: Moving Shops Confidently with Zero-Downtime at Terabyte-scale:
  https://shopify.engineering/mysql-database-shard-balancing-terabyte-scale
- Notion, Herding elephants: https://www.notion.com/blog/sharding-postgres-at-notion and
  The Great Re-shard: https://www.notion.com/blog/the-great-re-shard
- Figma, How Figma's Databases Team Lived to Tell the Scale:
  https://www.figma.com/blog/how-figmas-databases-team-lived-to-tell-the-scale/
- Uber, Fulfillment Platform: Ground-up Re-architecture:
  https://www.uber.com/blog/fulfillment-platform-rearchitecture/ and Building Uber's Fulfillment
  Platform for Planet-Scale using Google Cloud Spanner:
  https://www.uber.com/blog/building-ubers-fulfillment-platform/
- Nextdoor, Scaling Nextdoor's Datastores Part 1:
  https://engblog.nextdoor.com/scaling-nextdoors-datastores-part-1-234d0cf67665 and ByteByteGo,
  Nextdoor's Database Evolution: https://blog.bytebytego.com/p/nextdoors-database-evolution-a-scaling
- Discord, How Discord Scaled Elixir to 5,000,000 Concurrent Users:
  https://discord.com/blog/how-discord-scaled-elixir-to-5-000-000-concurrent-users
- Bluesky, Federation Architecture:
  https://docs.bsky.app/docs/advanced-guides/federation-architecture; PDS SQLite refactor:
  https://github.com/bluesky-social/atproto/pull/1705; Pragmatic Engineer, Building Bluesky:
  https://newsletter.pragmaticengineer.com/p/bluesky; Bryan Newbold, A Full-Network Relay for $34
  a Month: https://whtwnd.com/bnewbold.net/3lo7a2a4qxg2l; PDS fleet counts:
  https://www.chjh.nl/diving-into-the-technical-details-of-bluesky/
- Mastodon operations: https://fediview.com/articles/host-your-own-mastodon-server-2026/ and
  https://gfsc.community/why-we-discontinued-our-mastodon-server/
- Turso, Multi-tenancy at Scale: https://turso.tech/blog/multi-tenancy-at-scale; Cloudflare,
  SQLite in Durable Objects GA: https://developers.cloudflare.com/changelog/2025-04-07-sqlite-in-durable-objects-ga
- Vitess 24 announcement: https://vitess.io/blog/2026-04-30-announcing-vitess-24/; PlanetScale
  sharding docs: https://planetscale.com/docs/vitess/sharding; per-shard sizing:
  https://planetscale.com/changelog/per-shard-sizing
- Crunchy Data, An Overview of Distributed PostgreSQL Architectures:
  https://www.crunchydata.com/blog/an-overview-of-distributed-postgresql-architectures
- Speculative Branches, Use One Big Server: https://specbranch.com/posts/one-big-server/ and the
  2025 discussion: https://news.ycombinator.com/item?id=45085029
- 37signals cloud exit: https://www.theregister.com/2025/05/09/37signals_cloud_repatriation_storage_savings/
- Hetzner 2026 price adjustment:
  https://docs.hetzner.com/general/infrastructure-and-availability/price-adjustment/
- OVHcloud UK dedicated servers: https://www.ovhcloud.com/en-gb/bare-metal/dedicated-server-uk/;
  Mythic Beasts dedicated: https://www.mythic-beasts.com/servers/dedicated
- MySQL HA in 2026 (Galera EOL, Group Replication):
  https://www.continuent.com/resources/blog/mysql-galera-cluster-eol-your-practical-paths-forward and
  https://signal18.io/blog/comparison-galera-vs-async-replication-vs-replication-manager-which-ha-mariadb-mysql-solution-to-choose-in-2026

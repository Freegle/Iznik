---
last_reviewed: 2026-09-02
owner: Freegle dev team
---

# Why it is built this way

Every architecture has decisions that look wrong from the outside. This page records the
reasoning behind the ones most likely to make a new person reach for a rewrite, so you can
disagree with the reasons rather than guess at them.

Two constraints shape almost all of it:

1. **Freegle is a charity.** Money spent on infrastructure is money not spent on reuse.
   Cost per member has to stay close to zero.
2. **The team is small and largely volunteer.** Anything that needs constant attention will
   not get it. Simple and boring beats clever and fragile.

## Why one database cluster, multi-master

Production runs **MariaDB with Galera**, three nodes, all of which can accept writes.

- **Availability without a managed database.** A hosted database would cost more per month
  than the entire rest of the hosting. Three cheap machines that can each lose one and keep
  going gives us the availability we need for the money we have.
- **Reads scale across nodes.** Freegle is read-heavy: browsing, searching, digests. Every
  node can serve reads, so read capacity grows by adding a machine.
- **The nodes do double duty.** Each database node also runs the Go API, the spatial
  service and the routing service natively. That is deliberate: those services need to be
  close to the data, and the machines have spare memory and cores that would otherwise be
  idle.

The catch, and you must know this: **all writes go to one nominated node.** Galera is
multi-master but it resolves write conflicts by aborting transactions, so writing to
several nodes at once produces mysterious deadlocks under load. So the cluster is
multi-master for resilience, not for write throughput.

Two follow-on facts that cost people days:

- **Reads and writes are routed separately by the application**
  ([../ops/reference/database-read-write-split.md](../ops/reference/database-read-write-split.md)).
  Write then immediately read and you may read a node that has not caught up. Do not
  explain a bug away as replication lag without checking; equally, do not assume a
  freshly written row is readable everywhere.
- **Index usage counters must be summed across all three nodes.** Read one node and almost
  every index looks unused
  ([../ops/reference/database-index-hygiene.md](../ops/reference/database-index-hygiene.md)).

## Why Go for the API

The API was PHP. It is now Go (`iznik-server-go/`), and the PHP API has been retired.

- **Memory and process model.** PHP needed a process per concurrent request. Go handles
  thousands of concurrent requests in one process with a small footprint, which is what
  lets the API run *on the database nodes* alongside MariaDB instead of needing its own
  machines.
- **A single static binary.** Deployment is copy a file and restart. No runtime, no
  extensions, no package drift across three machines. For a team that cannot babysit
  servers, this matters more than language features.
- **Speed on the hot paths.** Browse, search and rippling are called constantly and are
  latency-sensitive.

Go is used only where those properties matter: the API and the spatial and routing
services. Scheduled work stayed in PHP, on purpose - see the next section.

## Why Laravel for the batch tier

- **The schema lives in migrations.** `iznik-batch/database/migrations/` is the single
  source of truth for the database. That was the biggest win of the Laravel move: before
  it, the schema was a checked-in SQL dump that drifted from reality.
- **Batteries included for exactly what batch work needs**: a scheduler, queues, mail
  templating (the digests and notifications are Blade and MJML templates), and a testing
  framework.
- **It was portable from the PHP that already existed.** Rewriting years of digest,
  notification and moderation logic in Go would have taken the team's whole capacity for
  no member-visible benefit.

So the split is: **Go where latency and memory matter, PHP where developer time matters.**
That is the whole rule.

## Why we self-host the geocoder (Photon)

Geocoding is turning an address or place name into a location. Freegle does it constantly:
every post, every search, every member setting their location.

- **Per-lookup pricing does not survive contact with our volume.** Commercial geocoding is
  billed per request and rate limited. At our volume it would be one of the largest costs
  in the charity.
- **Member addresses are personal data.** A member's home address should not be sent to a
  third party to be logged. Self-hosting keeps it inside our own infrastructure.
- **We need UK-specific behaviour**, including postcode handling and matching how members
  actually write addresses.

The price we pay is a **6.3 GB search index** that must be rebuilt rather than restored,
and a service that must be started properly. Photon runs natively on the batch host under
monit, and the launcher plus a systemd override are captured in `ops/hosts/`, because a
monit check without them produces a service that is watched, never starts, and retries
forever - which is exactly what happened on 21 August 2026.

The same reasoning covers the self-hosted map tile server, image upload and resizing, and
log aggregation. See
[../developers/reference/external-services.md](../developers/reference/external-services.md)
for the full self-hosted-versus-bought list.

Honest exception: **production still buys some isochrones from Mapbox**, because our own
routing has not fully replaced them. Do not assume everything travel-time related is
in-house.

## Why travel time instead of distance

Freegle measures how far a post reaches in **minutes of driving, not miles**. Ten miles
across a city and ten miles across a rural county are completely different propositions to
someone collecting a sofa.

This is why there is a routing service holding a road graph in memory, and why that service
takes minutes to warm up after a restart. Plan restarts accordingly
([../ops/04-domains-services-and-runbooks.md](../ops/04-domains-services-and-runbooks.md)).

## Why rippling out instead of letting people choose a radius

Members do not know where community boundaries are and consistently choose badly: too
narrow and nothing gets taken, too wide and the wrong people are bothered. So a post starts
local and **reaches further over time** if nobody nearby takes it.

The rule that surprises everyone: **the reach limit belongs to the recipient community, not
to the post.** A community that does not want distant posts is protected by its own
setting; a poster cannot broadcast nationally by choosing to.

Details: [../members/rippling-out.md](../members/rippling-out.md) and
[../developers/reference/rippling-algorithm.md](../developers/reference/rippling-algorithm.md).

## Why one repository for everything

A single change often needs a migration, an API change and a frontend change together. In
separate repositories that is three pull requests that can only be tested once they are all
merged. Here it is one pull request, and CI runs the Go, Laravel, unit and end-to-end
suites against it together.

The cost is a large repository and a long CI run. We consider that a good trade, and the
worktree tooling (`./freegle worktree`) exists so you can have several independent
environments at once
([../developers/reference/worktrees.md](../developers/reference/worktrees.md)).

## Why Nuxt, and why the same code for the apps

- **Posts have to be findable by search engines.** A large share of new members arrive from
  a search for the thing they want. That needs server-rendered pages, which is what Nuxt
  gives us ([../developers/reference/seo.md](../developers/reference/seo.md)).
- **One codebase covers the website, the moderator app and both mobile apps.** ModTools is
  a Nuxt layer over the same components; the mobile apps are the same build wrapped in
  Capacitor. A fix lands everywhere at once
  ([../developers/reference/mobile-app.md](../developers/reference/mobile-app.md)).

There is a separate native app experiment in `freegle-app/`. It does not ship, and the
reason it does not is exactly the point above: it would be a second codebase to maintain.

## Why Netlify for the frontends

The frontends are static builds. Netlify serves them from a CDN, gives us preview deploys
per branch, and costs us very little. It also means the web tier has **no server we have to
run** - if our own machines are struggling, the site still loads. Deploys come from the
`production` branch, which CircleCI merges from `master` only after tests pass.

## Why the backup system is a whole website ("Yesterday")

Backups fail silently. The usual failure is not the backup job but the restore, discovered
during an incident.

So the nightly backup and the restore test are **the same job**: it takes a physical backup
of production, streams it to a separate cloud machine, restores it, and brings up a
complete working copy of Freegle from yesterday's data, which volunteers can browse. A
backup that cannot be restored shows up as a Yesterday site that will not come up.

The known weakness, stated plainly: **nothing alerts when that restore fails.** It once
failed six nights running unnoticed. Checking it is a routine duty
([../ops/reference/sysadmin-duties.md](../ops/reference/sysadmin-duties.md)) until it is
fixed.

## Why two spam layers, and no AI moderator

Incoming mail is checked by rspamd at SMTP time **and** by SpamAssassin plus our own
content checks at the application layer. Two layers, because rejecting at SMTP time gives a
legitimate sender a bounce they can act on, while the application layer can hold something
for a human instead of destroying it.

We do **not** use an AI model to approve or reject posts. It was measured: it moved
approve/reject accuracy from 63.0% to 63.5%, while a supporting task got worse. Not worth
the cost or the opacity. The measurements are in `llm-modbot/RESULTS.md` and the reasoning
in [../ops/reference/spam-and-abuse.md](../ops/reference/spam-and-abuse.md).

## Why containers in development but not everywhere in production

Locally, Docker Compose gives a new developer the whole stack - databases, mail, APIs,
frontends, spatial services - from one command, on any machine.

In production, some services run in containers and some run natively under monit
(the Go services on the database nodes, Photon and nginx on the batch host). Native won
where the service needs to be close to the data or to host resources. That means
**production supervision is split across monit, systemd, Docker Compose and HAProxy**, and
knowing which one owns a given service is most of the job
([../ops/reference/sysadmin-duties.md](../ops/reference/sysadmin-duties.md)).

## Things that look like decisions but are just history

Do not spend effort reconciling these; they are named so you stop wondering.

- **"Iznik"** is the software's old name. The repository is Iznik, the service is Freegle.
- **`iznik-nuxt3/` runs Nuxt 4.** The directory name is historical.
- **"v1" and PHP** refer to the retired API. References in comments are archaeology.
- **Uploadcare** is gone; image handling is tusd plus weserv. Any Uploadcare branch is
  dead code.
- **`freegle-mobile/`** is not in git. If it is on your machine it is scratch.

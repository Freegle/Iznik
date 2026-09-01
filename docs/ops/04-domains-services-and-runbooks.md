---
last_reviewed: 2026-09-01
owner: Freegle dev team
covers:
  - docs/ops/reference/database-read-write-split.md
  - docs/ops/reference/database-index-hygiene.md
  - docs/RSPAMD.md
  - docs/developers/reference/spatial-servers.md
---

# Domains, services, and runbooks

## Public services

| Service | What it is |
|---------|-----------|
| **ilovefreegle.org** | The member site (Nuxt build, served from Netlify). |
| **modtools.org** | The moderator app (Nuxt build from the same repo, `modtools/`). |
| **v2 API (Go)** | The application API. |
| **uploads / delivery** | Image upload (tusd) and resizing/delivery (weserv) — the edge tier. |
| **tiles / geocode / wiki** | Map tiles (OSM), the geocoder (Photon, cutting over to the places index in spatial-knn — [runbook](runbooks/geocoder-cutover.md)), and the volunteer wiki. |

Which machine serves each of these, and how requests are routed to them, is in
[Production topology](production.md). The local development equivalents (the
`*.localhost` domains) are listed in the root [../../README.md](../../README.md) and
are development-only.

## Spatial and routing services

The **routing** and **spatial** Go services back rippling, browse and the digests. Useful
operational facts (from their component READMEs and
[../developers/reference/spatial-servers.md](../developers/reference/spatial-servers.md)):

- They hold a graph of the country in memory, so their memory footprint is substantial
  (multiple gigabytes).
- They rebuild that graph on start, which takes several minutes, so a restart is not
  instant.
- Rippling, the daily digest and browse depend on them being up.

Plan restarts of these services with that warm-up time in mind.

The routing container is memory-bound rather than CPU-bound, and it is the container
most likely to be the one in trouble when the host is. Two things follow.

First, its Go heap is bounded by `GOMEMLIMIT` (and `GOGC`) in `docker-compose.yml`,
overridable per host with `SPATIAL_GOMEMLIMIT` and `SPATIAL_GOGC`. Both are read once
at process start, so changing them needs the container recreated, not restarted. Set
the limit below the container ceiling with room left over: the leaf-table artifact is
memory-mapped, and if the heap fills the container there is nowhere for the kernel to
keep those pages and the service pays for re-reading them constantly.

Second, the container exposes `/debug/pprof` and a one-line `/debug/memsummary` on
loopback only, so a memory question can be answered from inside the container rather
than inferred:

```bash
docker exec <routing-container> curl -s 127.0.0.1:6060/debug/memsummary
docker exec <routing-container> curl -s -o /tmp/heap.out 127.0.0.1:6060/debug/pprof/heap
```

It binds to 127.0.0.1 deliberately and its port is not published, so it is reachable
only through `docker exec`. Set `ROUTING_DEBUG_PORT=off` to disable it.

## Database

Production splits database **reads and writes** across hosts; the application routes
queries accordingly. The reference is
[./reference/database-read-write-split.md](./reference/database-read-write-split.md). The schema is
owned by Laravel migrations (see [../developers/04-apis-and-data.md](../developers/04-apis-and-data.md)).

Before adding or removing an index, and to check the migrations still describe the schema
production actually has, see
[./reference/database-index-hygiene.md](./reference/database-index-hygiene.md). Index read
counters must be summed across every cluster node - one node alone makes almost everything
look unused.

## Mail and spam filtering

Incoming mail is processed through a filtering stack: a milter-based spam check at SMTP
time plus an application-layer content check running in parallel, with mail then routed to
the batch processor. The technical detail is in [../RSPAMD.md](../RSPAMD.md). (Any default
credential shown in that document is for local development only and must never be used in
production.)

## Backups

At an architecture level:

- **Logs** are backed up from Loki to cloud storage with cross-region replication and
  daily snapshots, under tiered retention (short, medium and long term for different log
  categories). See [./reference/logging.md](./reference/logging.md).
- **Database** backup strategy is operational and maintained by the ops team; it is not
  documented in these public docs. If you find it undocumented internally, that is a gap
  worth closing.

## Runbooks

Operational runbooks are indexed in [runbooks/](runbooks/README.md). They describe, at a
non-sensitive level, what to do for recurring operational events such as a background-host
reboot or an edge-tier change. The detailed, host-specific steps are maintained in the ops
team's operational notes rather than reproduced here.

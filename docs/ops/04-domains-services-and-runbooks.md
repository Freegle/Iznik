---
last_reviewed: 2026-07-09
owner: Freegle dev team
covers:
  - docs/ops/reference/database-read-write-split.md
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

The local development equivalents (the `*.localhost` domains) are listed in the root
[../../README.md](../../README.md) and are development-only.

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

## Database

Production splits database **reads and writes** across hosts; the application routes
queries accordingly. The reference is
[./reference/database-read-write-split.md](./reference/database-read-write-split.md). The schema is
owned by Laravel migrations (see [../developers/04-apis-and-data.md](../developers/04-apis-and-data.md)).

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

---
last_reviewed: 2026-09-02
owner: Freegle dev team
covers:
  - docs/ops/reference/database-read-write-split.md
  - docs/ops/reference/database-index-hygiene.md
  - docs/ops/reference/spam-and-abuse.md
  - docs/ops/reference/sysadmin-duties.md
  - docs/developers/reference/spatial-servers.md
---

# Domains, services, and runbooks

## Public services

| Service | What it is |
|---------|-----------|
| **ilovefreegle.org** | The member site (Nuxt build, served from Netlify). |
| **modtools.org** | The moderator app (Nuxt build from the same repo, `modtools/`). |
| **v2 API (Go)** | The application API. |
| **uploads / delivery** | Image upload (tusd) and resizing/delivery (weserv) - the edge tier. |
| **tiles / geocode / wiki** | Map tiles (OSM), place search (geocoding), and the volunteer wiki. |

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

## Database

Production splits database **reads and writes** across hosts; the application routes
queries accordingly. The reference is
[./reference/database-read-write-split.md](./reference/database-read-write-split.md). The schema is
owned by Laravel migrations (see [../developers/apis-and-data.md](../developers/apis-and-data.md)).

Before adding or removing an index, and to check the migrations still describe the schema
production actually has, see
[./reference/database-index-hygiene.md](./reference/database-index-hygiene.md). Index read
counters must be summed across every cluster node - one node alone makes almost everything
look unused.

## Mail and spam filtering

Incoming mail is processed through a filtering stack: a milter-based spam check at SMTP
time plus an application-layer content check running in parallel, with mail then routed to
the batch processor. The layers, the thresholds and where to tune them are in
[./reference/spam-and-abuse.md](./reference/spam-and-abuse.md), along with the
application-layer content checks and the reasons there is no AI moderator.

## Backups

At an architecture level:

- **Logs** are backed up from Loki to cloud storage with cross-region replication and
  daily snapshots, under tiered retention (short, medium and long term for different log
  categories). See [./reference/logging.md](./reference/logging.md).
- **Database** backups are the "Yesterday" system. A nightly job takes a physical backup
  of the production database (Percona XtraBackup), streams it to a dedicated cloud VM,
  prepares it, and snapshots it. The same VM then **restores that snapshot into a
  complete, running copy of the site**, which volunteers can browse to see what the
  service looked like yesterday.

  This design means the backup and the restore test are the same job. A backup that
  cannot be restored shows up as a Yesterday environment that will not come up, rather
  than as a nasty surprise during a real incident. That is the main reason Yesterday
  exists; being able to look at yesterday's data is a bonus.

  Two things to know before you rely on it:

  - **ModTools shows a failed restore, but nothing pushes it at anyone.** The ModTools home
    page carries a Yesterday panel that turns to a warning when the copy is stale, the
    restore failed, or the machine cannot be reached. Somebody has to look, which is why it
    is on the daily list in
    [./reference/sysadmin-duties.md](./reference/sysadmin-duties.md).
  - **The useful log is the restore monitor service journal**, not the cron log. The cron
    log reports a bare failure with no cause; the service journal shows which stage broke.

  The mechanics - the snapshot scheme, the sizing, and the scripts - are documented in
  [`yesterday/README.md`](../../yesterday/README.md). Credentials for the VM and the cloud
  project are in the ops password vault; see
  [../getting-started/accounts-and-access.md](../getting-started/accounts-and-access.md).

## Runbooks

Operational runbooks are indexed in [runbooks/](runbooks/README.md). They describe, at a
non-sensitive level, what to do for recurring operational events such as a background-host
reboot or an edge-tier change. The detailed, host-specific steps are maintained in the ops
team's operational notes rather than reproduced here.

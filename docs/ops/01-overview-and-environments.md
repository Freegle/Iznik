---
last_reviewed: 2026-07-09
owner: Freegle dev team
covers:
  - ARCHITECTURE.md
  - WORKTREE-GUIDE.md
---

# Overview and environments

Freegle is a Docker Compose stack. The same `docker-compose.yml` supports several
environments, selected by Compose **profiles** and a few override files. The definitive
list is in [../../ARCHITECTURE.md](../../ARCHITECTURE.md); this is the operational summary.

## The environments

| Environment | What it is |
|-------------|-----------|
| **Local development** | A developer's full stack on their own machine (WSL2 + Docker). The default profile set brings up frontend, database, backend, dev tools and monitoring. |
| **CircleCI** | The same stack brought up in CI to run the test suites on every push. |
| **Production background** | The server that runs the Laravel scheduled jobs and Loki log aggregation against the production database. Uses its own profile and environment file. |
| **"Yesterday"** | A data-recovery / testing configuration that runs only the containers needed to inspect a previous state, via an override file. |
| **Developer worktrees** | Multiple isolated stacks running in parallel, each on offset ports with its own database, for working on several branches at once. See [../../WORKTREE-GUIDE.md](../../WORKTREE-GUIDE.md). |
| **dev-live** | A lightweight profile that runs only the frontend against the live production APIs, for low-resource development. It touches real data. |

## Where things run

At an architecture level:

- The **member site** and **ModTools** are static Nuxt builds served from a CDN/host
  (Netlify), one site each, built from the same repository. See
  [Deployment and CI](02-deployment-and-ci.md).
- The **v2 (Go) API** serves application requests. The legacy PHP v1 API has been retired
  and removed.
- **Batch** (Laravel) runs scheduled and background jobs against the production database.
- **Routing** and **spatial** Go services back rippling, browse and digests.
- **Loki** aggregates logs; **Sentry** tracks errors. See
  [Monitoring and logging](03-monitoring-and-logging.md).

## Direction of travel: the "edge" tier

There is an ongoing consolidation of the production-facing "edge" services (things like
map tiles, the wiki and image delivery) onto the same Compose stack as batch processing,
with resource prioritisation between them (cgroup-based slices) rather than separate
machines. This is a direction, described here only at that level; the specific host
details are operational and not reproduced in these docs.

## Profiles

Which containers come up is controlled by `COMPOSE_PROFILES` in `.env`. A typical local
set is `frontend,database,backend,dev,monitoring`. See the profile definitions in
`docker-compose.yml` and the summary in [../../ARCHITECTURE.md](../../ARCHITECTURE.md).

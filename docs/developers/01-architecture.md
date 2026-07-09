---
last_reviewed: 2026-07-09
owner: Freegle dev team
covers:
  - ARCHITECTURE.md
  - SPATIAL-SERVERS.md
  - RIPPLING-ALGORITHM.md
---

# Architecture and codebase map

This page is the "where does everything live" overview. For the detailed container,
network and profile picture, read [../../ARCHITECTURE.md](../../ARCHITECTURE.md); this
page will not repeat it.

## The components

Freegle (internally "Iznik") is a monorepo. The main pieces:

| Directory | What it is | README |
|-----------|-----------|--------|
| `iznik-nuxt3/` | Nuxt 3 frontend. Serves **both** the member site (ilovefreegle.org) and, from the `modtools/` subfolder, the moderator app (modtools.org). | `iznik-nuxt3/README.md` |
| `iznik-server-go/` | Go API, **version 2** - the primary API. | `iznik-server-go/README.md` |
| `iznik-batch/` | Laravel batch processing: digests, notifications, scheduled jobs. Owns the database schema (migrations). | `iznik-batch/README.md` |
| `iznik-routing-go/` | Go service for drive-time routing, used by rippling and browse. | `iznik-routing-go/README.md` |
| `iznik-spatial-go/` | Go service for spatial lookups (which community covers a point, etc). | `iznik-spatial-go/README.md` |
| `status-nuxt/` | Development status dashboard and test runner. | - |
| `freegle-mobile/` | Capacitor mobile app wrapper around the Nuxt frontend. See [../../README-APP.md](../../README-APP.md). | - |

There is more than one strand of mobile work in the tree (a Capacitor build of the Nuxt
app, plus native app assets). If you are touching mobile, start from
[../../README-APP.md](../../README-APP.md) and confirm the current direction rather than
assuming.

## How the frontend is put together

The member site and ModTools are **one Nuxt codebase**. ModTools lives in
`iznik-nuxt3/modtools/` as a Nuxt "layer" that `extends` the main app, so it inherits the
stores, components and composables and adds its own pages. The two are built and deployed
as **separate Netlify sites from the same branch** (`npm run build` for the member site,
`cd modtools && npm run build` for ModTools).

State lives in **Pinia stores** (`iznik-nuxt3/stores/`). Auth, the current user (`me`),
and per-group membership and roles are the ones you will meet first.

## How the pieces talk

- The frontend calls the **Go v2 API**; its api-client layer speaks v2 only. The legacy
  **PHP v1 API** (`iznik-server/`) has been retired and removed from the repo. See
  [APIs and data](04-apis-and-data.md).
- **Batch** (Laravel) runs the scheduled and background work (digests, notification
  emails, reposts) against the database.
- **Routing and spatial** Go services answer "how far / which area" questions that
  rippling, browse and digests depend on. The plain-English overview is
  [../../SPATIAL-SERVERS.md](../../SPATIAL-SERVERS.md).

## Cross-cutting features worth knowing

- **Rippling out** is the mechanism that spreads a post outward over time. The algorithm
  and the rejected alternatives are in [../../RIPPLING-ALGORITHM.md](../../RIPPLING-ALGORITHM.md);
  the member and moderator behaviour is in
  [../../RIPPLING-OUT-FOR-MEMBERS.md](../../RIPPLING-OUT-FOR-MEMBERS.md) and
  [../../RIPPLING-OUT-FOR-MODERATORS.md](../../RIPPLING-OUT-FOR-MODERATORS.md).
- **TrashNothing / LoveJunk** is a partner integration where external users post into
  Freegle communities. See [../../TRASHNOTHING.md](../../TRASHNOTHING.md).
- **Logging and observability** run through Loki, with client-side tracing. See
  [../../Logging.md](../../Logging.md) and [APIs and data](04-apis-and-data.md).

## Finding your way around

- Member-facing pages: `iznik-nuxt3/pages/` (routes follow the file structure).
- Moderator pages: `iznik-nuxt3/modtools/pages/`.
- Shared UI: `iznik-nuxt3/components/`; shared logic: `iznik-nuxt3/composables/` and
  `stores/`.
- Go v2 handlers: `iznik-server-go/`.
- Scheduled jobs and mail: `iznik-batch/`.

For the deep container and deployment view, and the full list of Docker profiles, go to
[../../ARCHITECTURE.md](../../ARCHITECTURE.md).

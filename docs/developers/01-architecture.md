---
last_reviewed: 2026-08-16
owner: Freegle dev team
covers:
  - docs/developers/reference/architecture.md
  - docs/developers/reference/spatial-servers.md
  - docs/developers/reference/rippling-algorithm.md
---

# Architecture and codebase map

This page is the "where does everything live" overview. For the detailed container,
network and profile picture, read [./reference/architecture.md](./reference/architecture.md); this
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
| `freegle-mobile/` | Capacitor mobile app wrapper around the Nuxt frontend. See [./reference/mobile-app.md](./reference/mobile-app.md). | - |

There is more than one strand of mobile work in the tree (a Capacitor build of the Nuxt
app, plus native app assets). If you are touching mobile, start from
[./reference/mobile-app.md](./reference/mobile-app.md) and confirm the current direction rather than
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
  [./reference/spatial-servers.md](./reference/spatial-servers.md).

## Cross-cutting features worth knowing

- **Rippling out** is the mechanism that spreads a post outward over time. The algorithm
  and the rejected alternatives are in [./reference/rippling-algorithm.md](./reference/rippling-algorithm.md);
  the member and moderator behaviour is in
  [../members/rippling-out.md](../members/rippling-out.md) and
  [../moderators/rippling-out.md](../moderators/rippling-out.md). The one structural thing
  to know before reading any of it: the travel-time cap belongs to the RECIPIENT, not the
  post. Every ripple grows to the same ceiling, and each member is then admitted on the
  budget their own local freegler density justifies - so a rural member can reach the town
  they already drive to, while a city member is not mailed things nobody would travel for.
  That admission is NOT computed per request: it reads a value written onto each member by a
  scheduled batch pass (`browse:backfill-max-distance`). So the design has a moving part
  outside the request path, and if that pass stops working every member silently reverts to
  no limit at all - which is what happened between 11 Aug and 15 Aug 2026. See section 7 of
  the algorithm reference.
- **Getting a first reply in** sits alongside rippling and attacks the 44% of rippled posts
  that get no reply at all: a passthrough for a silent post's first reply, individual mail to
  the members who have asked for that specific item (an open post of the opposite type, or a
  saved search), and Freegle's own chat messages to the poster.
  See [./reference/first-reply.md](./reference/first-reply.md).
- **TrashNothing / LoveJunk** is a partner integration where external users post into
  Freegle communities. See [./reference/trashnothing.md](./reference/trashnothing.md).
- **Logging and observability** run through Loki, with client-side tracing. See
  [../ops/reference/logging.md](../ops/reference/logging.md) and [APIs and data](04-apis-and-data.md).

## Finding your way around

- Member-facing pages: `iznik-nuxt3/pages/` (routes follow the file structure).
- Moderator pages: `iznik-nuxt3/modtools/pages/`.
- Shared UI: `iznik-nuxt3/components/`; shared logic: `iznik-nuxt3/composables/` and
  `stores/`.
- Go v2 handlers: `iznik-server-go/`.
- Scheduled jobs and mail: `iznik-batch/`.

For the deep container and deployment view, and the full list of Docker profiles, go to
[./reference/architecture.md](./reference/architecture.md).

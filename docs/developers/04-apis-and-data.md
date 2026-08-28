---
last_reviewed: 2026-08-28
owner: Freegle dev team
covers:
  - iznik-server-go/API-GUIDE.md
  - docs/ops/reference/database-read-write-split.md
  - docs/ops/reference/logging.md
---

# APIs and data

## The API

Freegle's API is the Go **v2** API (`iznik-server-go/`), which returns bare JSON and is
where all work goes.

A legacy PHP **v1** API (`iznik-server/`) existed during the migration but has now been
**retired and removed from the repo** (git history only). It returned a `{ ret, status,
... }` envelope. Everything, including image uploads, is on v2 now, and the frontend's
api-client layer speaks v2 only.

When you add or change an endpoint, add it to **v2**. The mandatory patterns for a new Go
endpoint (routing, structure, error handling) are in
[../../iznik-server-go/API-GUIDE.md](../../iznik-server-go/API-GUIDE.md). Do not invent a
new pattern; follow that guide. The live Swagger docs are served at `/swagger`.

## How the frontend talks to the APIs

The Nuxt frontend calls the APIs through a dedicated api-client layer, not by fetching in
components: one module per domain under `iznik-nuxt3/api/` (`MessageAPI.js`, `UserAPI.js`,
and so on) extending `BaseAPI.js`, with the endpoint path strings as literals in each
method, and Pinia stores calling those modules. On the Go side, every route is registered
in one place, `iznik-server-go/router/routes.go`'s `SetupRoutes`, so an endpoint maps
cleanly to its handler. (Note some write endpoints such as `POST /message` multiplex
several behaviours behind an `action` field.) Keep enrichment and business logic on the
server side of that boundary, and pass ids rather than whole objects between components
where practical - see [./reference/coding-standards.md](./reference/coding-standards.md).

## The data model

- **Laravel migrations are the single source of truth for the schema.** They live in
  `iznik-batch/database/migrations/`. The old `schema.sql` is retired and kept only for
  historical reference.
- Stored functions are managed by a dedicated migration.
- The test databases are set up by `scripts/setup-test-database.sh`, which runs the
  migrations and clones the schema to the test databases. After you add a migration, rerun
  it so tests see the new schema.

## Read/write split

Production splits database reads and writes across hosts, and the application layer routes
queries accordingly. This has caught out subtle bugs (for example reading an id straight
after an insert). Read
[../ops/reference/database-read-write-split.md](../ops/reference/database-read-write-split.md) before writing
code that writes then immediately reads.

## Logging and observability (for developers)

Logs go to **Loki** (`localhost:3100` in development). Query with LogQL; user-facing and
client-side tracing carry trace and session ids so you can follow a request across
services. The developer-facing parts (local setup and querying) are in
[../ops/reference/logging.md](../ops/reference/logging.md); the production and backup side is covered under
[../ops/03-monitoring-and-logging.md](../ops/03-monitoring-and-logging.md).

## Recipes: how do I add ...?

Short pointers; each ends at the real pattern to copy.

- **A v2 API endpoint** - follow [../../iznik-server-go/API-GUIDE.md](../../iznik-server-go/API-GUIDE.md),
  add a Go test, regenerate Swagger.
- **A database change** - add a Laravel migration in
  `iznik-batch/database/migrations/`, then rerun `scripts/setup-test-database.sh`.
- **A member or moderator page** - add a Vue page under `iznik-nuxt3/pages/` (member) or
  `iznik-nuxt3/modtools/pages/` (moderator); routes follow the file path. Run
  `eslint --fix` on changed files and verify visually per
  [./reference/browser-testing.md](./reference/browser-testing.md).
- **A Playwright test** - reuse the helpers in `iznik-nuxt3/tests/e2e/utils/`; see
  [Testing](03-testing.md).
- **A scheduled job or email** - add it in `iznik-batch/`; follow the email and template
  rules in `iznik-batch/CLAUDE.md`.

Whenever you change UI a doc describes, update the doc and its screenshot in the same pull
request - see [../maintaining-docs.md](../maintaining-docs.md).

---
last_reviewed: 2026-07-09
owner: Freegle dev team
covers:
  - docs/developers/reference/coding-standards.md
  - iznik-nuxt3/tests/e2e/README.md
---

# Testing

Freegle has four test suites. The coding standards are strict about tests: fix root
causes, never skip tests or make coverage optional, and never dismiss a failure as
"unrelated". See [./reference/coding-standards.md](./reference/coding-standards.md).

## The suites

| Suite | Covers | Component |
|-------|--------|-----------|
| **Go** | The v2 API | `iznik-server-go/` |
| **Laravel** | Batch processing | `iznik-batch/` |
| **Vitest** | Frontend stores and components | `iznik-nuxt3/` |
| **Playwright** | End-to-end user journeys | `iznik-nuxt3/tests/e2e/` |

## Running them

Run tests through the **status dashboard** / status container, not directly on your host.
The dashboard at `http://status.localhost:8081` has buttons for each suite, and there are
API endpoints for automation:

```bash
curl -X POST http://localhost:8081/api/tests/go
curl -X POST http://localhost:8081/api/tests/laravel
curl -X POST http://localhost:8081/api/tests/vitest
curl -X POST http://localhost:8081/api/tests/playwright
```

In a worktree, replace `8081` with that worktree's `PORT_STATUS` (from `./freegle
status`). Tests run against that instance's own containers and database, so they stay
isolated.

Playwright runs against the **production** container for stability. If you see reload-style
flakiness, check for container reload triggers rather than assuming a flaky test.

## Playwright specifics

The end-to-end suite lives in `iznik-nuxt3/tests/e2e/`. Before writing a new test, look
for an existing helper in `tests/e2e/utils/` (for example `loginViaHomepage` and
`loginViaModTools`) rather than rolling your own login. Use Playwright assertions to wait,
not hard-coded timeouts; timeout constants live in `tests/e2e/config.js`. See
[`iznik-nuxt3/tests/e2e/README.md`](../../iznik-nuxt3/tests/e2e/README.md).

The documentation screenshots reuse this same setup - see
[../screenshots/README.md](../screenshots/README.md).

## Test data and databases

The test database is built from committed fixtures via the setup scripts (schema comes
from Laravel migrations - see [APIs and data](04-apis-and-data.md)). After adding a
migration, rerun the test-database setup so the test schema matches. The seeded data is
FreeglePlayground around Edinburgh (postcode EH3 6SS).

## CI

CircleCI runs the full suite on every push to master, via a shared reusable orb. When you
change tests, remember to update the orb too. The operational view of CI is in
[../ops/02-deployment-and-ci.md](../ops/02-deployment-and-ci.md), and the reference is
[../ops/reference/circleci.md](../ops/reference/circleci.md).

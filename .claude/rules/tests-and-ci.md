---
paths:
  - ".circleci/**"
  - "iznik-nuxt3/tests/**"
  - "iznik-server-go/test/**"
  - "iznik-batch/tests/**"
  - "status-nuxt/**"
---

# Traps in the test suites and CI

The theme here is **a green result that means nothing**. Read the shape of a pass before
trusting it.

## A green run that did not finish

- **Vitest through the status API.** A run that dies partway still reports
  `status=completed` with "All tests passed (N passed)". Always check that
  `progress.completed` equals `progress.total` before believing it.
- **A "vitest hang" on master is a failing test.** The parallel-test wrapper never marks the
  failing test done, so the job stalls to its timeout instead of reporting. Look for the failure,
  not for an infrastructure problem.
- **The status API is served by `status-nuxt`, not `status/server.js`.** Read
  `status-nuxt/server/api/tests/*.ts` to understand what the runners actually do.

## CI failures that look like infrastructure and are not

- **"Laravel, Go, Playwright and Vitest all false" with only "Evaluate overall test results"
  failing** is a real test failure tripping fail-fast. It is not a killed machine.
- **"Build containers" failing across several PRs at once**, with a gateway timeout fetching an
  embedding model, is the embedding sidecar, not your branch.
- **A small Coveralls decrease on a branch that changes no files of that language** is the moving
  master baseline, not measurement jitter. Do not chase it, and do not make coverage optional.

## Tests that pass because they are not testing what you think

- **A stubbed child component hides duplicate rendering.** Vue Test Utils `{Child: false}` does
  **not** un-stub it: it renders an empty component and the test passes either way.
- **Adding a dispatch arm to `ChatMessage.vue` without a matching stub in `ChatMessage.spec.js`
  fails the whole vitest run**, as an unhandled rejection blamed on an unrelated file. If the
  suite breaks somewhere strange after a chat change, look here first.
- **Seeding rows with identical timestamps gives non-deterministic order.** Newsfeed replies are
  ordered by `timestamp`, not `added`, so a test can appear to prove an ordering it does not.

## Fixtures and environment

- **Any Go test inserting `rippling_reach` must set `outer_bound`.** It is `GEOMETRY NOT NULL`
  with no default, so a test that omits it fails. This recurs on every branch that adds one.
- **Running `setup-test-database.sh` disrupts the spatial index.** It rebuilds the database that
  the spatial container indexes, so the very next run can fail. Re-index before believing the
  failures.
- **Playwright runs against the production containers and never rebuilds them.** You are testing
  whatever they already serve. Its readiness wait is also too short for a first, cold start.
- **`TEST_*_BASE_URL` carries a literal `:80` in the main checkout**, which browsers normalise
  away, so `waitForURL` and `toHaveURL` against an absolute URL can never match.
- **Pinning matters for swagger**: adding `modernc.org/sqlite@latest` bumps the module to a Go
  version that panics go-swagger. Pin to a compatible release.

## See also

- `docs/developers/testing.md` - the four suites and how to run them.
- `.claude/rules/dev-containers.md` - why a local pass can be testing old code.

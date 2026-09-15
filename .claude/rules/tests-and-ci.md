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

## A count that is not the count you think

- **"Tests failed: 4868 pass, 0 fail"** means errors, not failures. Something died before it
  could be counted. Do not read the zero as good news.
- **A spec that fails to parse** reports as one failed file, and every test inside it silently
  never runs. The file count moves by one and the test count drops by however many it held.
- **Adding a component to a page breaks that page's spec**, because the unresolved component
  renders as nothing and the assertions quietly stop matching.

## Coverage numbers that move on their own

Coverage checks fail on deltas no change caused, and chasing them wastes days:

- A large aggregate swing is usually a **failed build uploading fewer flags** than a good one,
  not a real drop.
- The Playwright flag wanders between builds and has discrete states it flips between.
- A major version upgrade of a test runner re-baselines its measurement, so the first comparison
  against master is meaningless.

Read what the build uploaded before believing what it reports. Do not make coverage optional to
get past it.

## Test databases are not as clean as they look

- The batch test database is **not empty mid-suite**: earlier tests leak committed rows past the
  per-test rollback.
- The Go test database needs the setup script re-run after new migrations, or the suite fails
  on schema it has never seen.
- A drop-table migration plus a stale fixtures file blocks the fixture load entirely.
- Starting the Go and Laravel suites **at the same time** through the local status API kills one
  of them during setup. Run them one after the other.

## Assertions that match the wrong thing

Two `expectsOutputToContain` substrings that appear in the **same** output line both pass, so a
test can assert two things and really be asserting one. Chain them only when the strings are
genuinely on different lines.

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
- **`status-nuxt` has no lockfile**, so its image re-resolves dependencies on every build and is
  at the mercy of whatever the base image's package manager does with the peer graph. A build
  that worked yesterday can fail today with nothing changed on our side.

## CI failures that are about the build, not the branch

- **Docker Hub rate limits** mean the pull-through mirror is only wired up for the self-hosted
  runner, so a job that lands elsewhere pulls directly and is throttled.
- **A new compose service that is not in the orb's explicit build list** is never built, and
  fails later in a way that looks unrelated.
- **A per-step `export` in the orb does not reach a later step's compose command.** The variable
  is simply absent, with no error.
- **The swagger generator's fallback binary path** can resolve to a stale build, producing
  nondeterministic output.

When master is red across many builds, pin the breaking commit by which **test** fails per
build, not by bisecting the boundary.

## See also

- `docs/developers/testing.md` - the four suites and how to run them.
- `.claude/rules/dev-containers.md` - why a local pass can be testing old code.

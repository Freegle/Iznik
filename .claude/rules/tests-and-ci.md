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

## A red that is the status container, not the tests

Every suite in CI is started and polled through the status container over HTTP. The poll
substitutes `{"status":"error"}` when curl cannot reach it. The status API itself never reports
`error` - its states are `started`, `running`, `completed`, `failed`, `idle`, `unknown`,
`offline` - so that value always means the container was unreachable, never that a test failed.

It used to be reported as "tests failed", with test logs printed, which sends you into the code
after a failure nobody recorded. What it looks like: suites stopping mid-run with no failing
test named anywhere. Build 36418 had Go reporting 2417 of 3694 tests with no failures among
them, Laravel stopped at 59%, Playwright cut mid-test, 19-21Gi of memory free and the watchdog
never fired.

A genuine failure names a test. If nothing is named, look at the status container rather than
the diff - and treat the unreachability itself as the bug to chase, because every suite polls
that one container and they all go red together.

## A red Go suite that names no test

When the Go step fails and neither the status message nor `test-output/go.out` names a
test, look for a `[condenseCrashDumps: N chars elided]` marker in the log. The condenser
keeps the head and tail of a large log and cuts the middle; a suite with no crash marker
but more than 300,000 characters of output loses whatever failed in the middle, including
its `--- FAIL` line. The parallel step still says `FAIL-FAST: Go tests failed`, so the
red is real and the cut suites beside it are not. Get the names by running the suite
locally with `?coverage=true` (CI's `-race -p 1` variant) and reading the status
message, which lists them. Seen twice on 2026-09-21: a swagger drift guard and a search
test, both invisible in CI.

## A green run that did not finish

- **The status API reports the last run's result while a new one starts.** For the first
  minute or so after a POST, `/api/tests/<suite>/status` can still say `completed` with the
  previous run's message and logs, and it can flip back to that between polls. A poll loop that
  stops on the first non-`running` answer reports a run that has not happened. The Playwright
  container's `/app/test-results/junit.xml` is no better on its own: a restart can bring back
  an old one for a moment before the runner deletes it. Believe a Playwright result only from a
  `junit.xml` written after you started the run whose `tests=` count matches the run you
  asked for.
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
- **The Playwright flag used to flip between discrete states on identical code, because the main
  app and ModTools share file paths.** ModTools extends the main app and overrides some files
  under the same relative path (`app.vue`, `layouts/default.vue`, `pages/index.vue`,
  `pages/communityevents/[[groupid]].vue`, `pages/giftaid.vue`, `pages/partnerships.vue`, ...).
  monocart strips `../` from sourcemap sources and keys its output by that path alone, so
  whichever app's version it handled last replaced the other. That is decided by the order test
  workers finish, so the reported `layouts/default.vue` was sometimes the 79-line main file and
  sometimes the 805-line ModTools one (74 relevant lines instead of 10, branch lines up to 613),
  moving the flag by up to ~1 point with 0 failed, 0 skipped tests. `coverageSourcePath` in
  `iznik-nuxt3/tests/e2e/coverage-source-path.js` reports ModTools-owned sources at their real
  path (`modtools/<path>`), so both versions are kept and ModTools code counts; layer files it
  merely bundles from the main tree keep their path. (Vitest does not have this problem: its
  coverage globs use the real `modtools/...` paths.) If a build still moves, diff
  per-file `relevant_line_count` between two Coveralls jobs (`coveralls.io/jobs/<id>/source_files.json`):
  a file whose count changes is a path collision, a file whose count is stable but whose covered
  count moves is spec timing.
- A major version upgrade of a test runner re-baselines its measurement, so the first comparison
  against master is meaningless.
- **A decrease on a branch that DOES change files of that language is still not automatically
  yours.** Measure the changed file itself, both sides. Put the base version in the tree, run the
  suite filtered to its spec with coverage on, and read `LF/LH`, `BRF/BRH`, `FNF/FNH` for that
  file out of `coverage/lcov.info` in the runner container; then do the same for the branch
  version. A file fully covered on **both** sides cannot have lowered anything, because every
  line the branch adds is a covered one. On the slider fix the component was 28/28 lines and
  11/11 branches before, 33/33 and 17/17 after, and the check still said -0.007%. Without that
  measurement the only moves left are padding the branch with unrelated tests or waiving the
  check, and both are wrong. When it really is wandering, run the whole suite twice with
  coverage on the SAME code and diff the per-file `LH` counts: the culprit is usually a spec that
  never waits for a timer its component schedules, so the lines behind that timer are covered
  only when the timing happens to suit. `PostMap.vue`'s 200ms re-fit debounce was one, worth six
  lines and 0.014% on its own.

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
- **A passing Playwright run deletes every PNG at the top of `SCREENSHOTS_DIR`.** `fixtures.js`
  has a global `afterAll` that calls `cleanupScreenshots()` when the run exits clean, so images
  a spec writes there with `page.screenshot({ path })` vanish seconds after they are logged as
  written. Write images you want to keep into a subdirectory, which the cleanup does not read,
  or attach them with `testInfo.attach`.
- **A spec that presses the lockdown holds every other spec's writes.** Keep it in the
  `lockdown` project, which runs as the teardown of `lockdown-order` after every other project
  has finished, and out of the `chromium` project's match.
- **A unit spec's wrappers are unmounted after every test by `tests/unit/setup.ts`.** A wrapper
  left mounted stays subscribed to the file's shared mock refs, so each `beforeEach` reset
  re-renders every instance mounted so far before the next test body runs, and the file's time
  grows with the square of its test count: 280ms per trivial test in MessageExpanded.spec.js,
  18ms once unmounted. Do not mount in one test and read in the next, and do not call
  `enableAutoUnmount` in a spec; a second call throws.
- **A page that stays blank for 200 seconds while a test waits for a selector, with the trace
  showing `net::ERR_NETWORK_CHANGED` on `_nuxt` chunk loads**, means a container or an image
  build step somewhere else on the host changed the network while the page was loading. The
  Playwright container is host-network, so Chromium sees every interface appearing or
  disappearing on the machine and aborts its in-flight requests; the HTML arrived, the chunks
  did not, and the app never mounted. There is no error on the page and the server logs show
  nothing. The harness (`tests/e2e/utils/app-mount.js`, used by `gotoAndVerify` and the
  ModTools login) now reloads such a page once and otherwise fails naming the URLs. Do not
  build images or start stacks on the host during a Playwright run you intend to believe.
- **A Playwright result that says "N recovered: reload, login-refill"** passed only because the
  harness recovered from something. Each recovery is a `[RECOVERED]` line in the log and an
  annotation on the test. A reload after aborted chunk loads is host noise when it lines up with a
  container event; without one, or several in a run, look for a server resetting connections.
  A login refill means the ModTools login form lost what was typed before submit, about once in
  forty logins, cause not yet found: a member could hit it too. It refills once, then fails.
- **A Laravel test that fails with "left the process environment changed"** is not broken
  itself. `.env.testing` is read once per process, so `TestCase` compares each test's environment
  at teardown, puts it back, and fails the test that changed it. Restore what you `putenv` in the
  test.
- **`CompileTimeDeprecationsTest` failing** names a compile-time deprecation the suite would
  otherwise never report: with opcache on for the CLI, a deprecation raised while compiling is
  printed only on a cold cache and never reaches PHPUnit's report. It lints the tree with opcache
  off.

## CI failures that are about the build, not the branch

- **"Build containers" fails with `additional privileges requested: pass "--allow=network.host"`**:
  the runner has docker-buildx 0.38 or later, which refuses our `network: host` builds. The
  Hetzner runner is built from `scripts/hetzner/cloud-init.yaml` **on whichever branch's pipeline
  provisioned it**, so one push to a branch older than the buildx pin brings up a bad runner and
  every queued build on it fails, master included. Check `docker buildx version` on the runner
  (`ssh -i ~/.ssh/hetzner_ci root@<ip>`), downgrade it to the pinned version, re-run the failed
  workflows, and merge master into the branch that provisioned it.

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

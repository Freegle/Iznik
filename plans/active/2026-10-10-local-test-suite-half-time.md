# Full local test suite in half the time, by making the tests cheaper

Branch `perf/local-test-suite-speed`. Code in `/home/edward/FreegleDocker-faster-tests-dev`
(no containers). Measurement on the stack worktree `/home/edward/FreegleDocker-faster-tests`
(project `freegle-faster-tests`, status API `http://localhost:12687`), detached at
`origin/master` 6c7c64900 until the branch is ready to verify.

## Goal (Edward, 2026-10-10)

"Make the full test suite run locally in half the time while preserving all functional and
coverage tests" and, after a first attempt built on parallelism: "Wrong approach. You're
trying to speed up by running in parallel. I want you to actually speed it up. Otherwise it
won't be any faster on slower CI machines."

So: the four suites, run exactly as today (same runners, same worker counts, same coverage),
must finish in half the time because the tests and their setup do less work or wait less.
Every test stays; coverage artifacts stay. Parallelism is not a lever. The parked first
attempt is on branch `wip/parallel-runners-parked` for reference only.

## Baseline (stack worktree, sequential, 2026-10-10)

| Suite | Wall | What it is |
|---|---|---|
| Playwright | 687s | 205 tests; 66 min of test time on 11 workers with servers nearly idle (prod-local 135 CPU-s total, apiv2 27 CPU-s): the tests are waiting, not computing. 44s setup, chromium phase ~8.9 min, lockdown teardown 100s alone, reporting ~10s. |
| Laravel | 494s | 7,668 tests single process with pcov; PHPUnit 7:50; migrate:fresh run twice (10s each: runner and bootstrap). |
| Vitest | 104s | 43s synchronous `vitest list` + 60s run. Worker time: tests 170s but environment 161s + import 85s + setup 79s + transform 43s per-file overhead. MessageExpanded.spec 34s and NewsThread.spec 24s alone. |
| Go | 37s | test package 28s; TestSwaggerGeneration 3.6s, retry backoff sleeps ~2.5s, ten 100ms sleeps in location_test. |
| **Sum** | **1,322s** | **Target: <= 661s** |

## Approach

1. Profile first (done or running): Playwright per-step durations (monocart report), Laravel
   per-test durations (junit log), vitest per-file/per-test, Go per-test.
2. Attack intrinsic costs in order of size:
   - Playwright: fixed sleeps and settle times, polling intervals the tests wait on
     (`chats:process-incoming` tick, navbar polls), slow page loads, repeated UI login/signup
     where an API path exists, over-long teardown waits, the DB reset, `--list`.
   - Laravel: slow tests (sleeps, HTTP timeouts to unreachable hosts, retries), the duplicate
     migrate, heavy setUp work, anything that waits on wall-clock.
   - Vitest: per-file overhead (`isolate`, environment, setup), the two heavy component specs,
     the synchronous list.
   - Go: real sleeps in retry/location tests, swagger generation test cost.
3. Measure after each change on the stack; final: all four suites sequentially with the
   same runners and settings as the baseline, all green, same test counts, coverage files
   present.

## Status

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Baseline (sequential, 4 suites) | ✅ Complete | 1,322s |
| 2 | Profile Laravel (junit) and Playwright (monocart steps) | ✅ Complete | Laravel: p50 29ms, long tail 147 tests = 104s, opcache.enable_cli=0 in batch; Playwright steps in runs/pw-steps.txt |
| 3 | Vitest: isolate:false experiment | ❌ Dead end | 2,293 failures in 179 files, run slower (environment cost stays per file) |
| 4 | Playwright intrinsic fixes | 🔄 In Progress | ft-pw (13020), perf/pw-intrinsic: 687s -> 384s on a loaded host with uncommitted edits; the 2 ModTools failures are PROVEN from traces to be net::ERR_NETWORK_CHANGED aborting Nuxt chunk loads (another stack's image build changed the host network; Playwright container is host-network), app never mounted, blank page for 202s; fix = harness reloads once on failed chunk loads and fails clearly, no typing retry; agent pw-finish continuing |
| 5 | Laravel intrinsic fixes | 🔄 In Progress | ft-laravel (13045), perf/laravel-intrinsic: 494s -> 222s on a loaded host with the 8 inherited commits (reviewed clean by laravel-finish and by review-laravel); 7 more commits (TNSync in-process not 67 child processes 14.3s->2.5s; no forced gc per test; lockdown batch size from config in tests; Spamhaus lookup via a bound class; .env.testing parsed once; DataProvider attribute; kernel discovery test), count now 7,677; awaiting the three review findings, four open points and the final run figures |
| 6 | Vitest fixes | ✅ Complete, reviewed, merged (7eb0b1786) | perf/go-vitest-intrinsic: global auto-unmount (quadratic re-render fixed; tests bucket 405s -> 138s), runner no longer blocks on the list (43s); under review |
| 7 | Go fixes | ✅ Complete, reviewed, merged (7eb0b1786) | retry sleep injectable, location/userdump/TTL sleeps replaced, isochrone budgets, swagger validate skipped in test; ~7s of test time off |
| 8 | Verify: all suites green, counts match, coverage files; sum <= 661s | ⬜ Pending | |
| 8b | Adversarial review of the WHOLE diff for weakened testing (Edward, 21:20Z) | ⬜ Pending | For every change: what did the test prove before, what does it prove now, what should it have proved that it never did (the swagger validate case: a step that was always advisory should have become a real check, not been dropped). Independent reviewers per suite, not the agents who made the changes; findings fixed before the PR. |
| 9 | Docs, rules, PR (no push before 8 and 8b) | ⬜ Pending | |

## Review of perf/go-vitest-intrinsic (2026-10-10 20:50Z)

Five findings, all fixed on the branch (4 commits, Go 4,753 and unit 17,156 green; unit run 72s and Go 34s on a loaded host) and merged: drivetime breaker test
5ms window can flake under -race (inject the clock); isochrone tests still leave the routing server
in CI because CI boots a Bristol extract (use a Bristol point); the background unit-test list
competes with the workers (drop it); orphan remap task row in one location test (poll for 2);
per-file lines over-counted as passes until the summary. Everything else on the branch was
checked and found sound (retry sleep injection, location polling, swagger guard unchanged in CI,
userdump barrier, global auto-unmount with no spec reusing a wrapper across tests, status fields).
Confirmed from the CircleCI project environment: MAPBOX_KEY is set, so CI isochrone tests with Edinburgh or London points have been reaching Mapbox with the production key; the Bristol point fixes that too.

## Review of perf/laravel-intrinsic 6c7c64900..d304674c2 (2026-10-10 20:55Z)

Three findings, handed to agent laravel-finish to fix as further commits: deploy:refresh's
restartProgram now uses Process::run with Laravel's 60s default timeout and no catch, so a slow
mail-spooler restart (stopwaitsecs=60) aborts the deploy before the version is recorded (fix:
forever or 130s, catch ProcessTimedOutException, test it); the CLI opcache hides compile-time
deprecations from PHPUnit (four implicit-nullable parameters remain in tests; fix them and add a
cache-independent guard); 15 docs pages flagged by the freshness check because every command
file gained an attribute (bump last_reviewed). The Kernel analysis and the rest of the "sound"
list arrived: Kernel changes sound (every artisan entry builds Artisan after loading the
routes; the singleton is stored before afterResolving fires; all 252 attribute names equal the
signature's first token; no closure commands); config kept in memory only, nothing written;
compiled-script cache in each container's /tmp; the removed sleeps were trailing waits; migrate
still fresh per run; the 4 extra tests are tests/Unit/Console/CommandNamesTest.php; no orb change.
Unconfirmed, handed to laravel-finish: clover equality with opcache on/off; side effects in the
constructors of lazily built commands; size of the compiled cache on batch-prod; cold-cache cost
on CI.

## Swagger validation (2026-10-10 21:15Z)

Edward: "surely we want swagger validate in test". Checked: the script's validate step was
advisory (exit 0 regardless) and the test never read it; the committed spec FAILS validation
today (GET /supportai/runs/{id} has no {id} parameter definition). Fixed on the Go branch
(a00015b90, merged 5495027c0): the test runs the validator on the spec it generates and fails
on any error; the route gained its parameters struct. Hazard for the PR: the committed
swagger.json snapshot differs from what go-swagger v0.31.0 in the apiv2 image now generates
(indentation, three renamed definitions, a literal "[]" scope under BearerAuth), and the main
stack's image serves a spec WITHOUT the {id} parameter; which generator output the snapshot
should follow is a separate decision.


## Final runs (2026-10-11)

final2 (a86a94ca6) failed two Playwright tests. The withdraw helper probed for OutcomeModal once
with isVisible(), which ignores its timeout, so a modal still loading its async chunk was missed
and the post was never withdrawn; master had the same probe but only warned. It now waits for
the dialog. The settings test hung ten minutes inside Chromium's context.newPage() with no
container event, OOM or crash logged; the fixture bounds it at 60s and retries once as a
new-page recovery. final3 (f53b9c491): all four suites green, 575s, no recoveries, 41 of 41
withdrawals through the dialog. final4 reruns on the master merge before push.

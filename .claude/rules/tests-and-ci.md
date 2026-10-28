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

- **A job's `playwright-failures/` artifacts can belong to an earlier job.** `~/artifacts` sits
  outside the workspace and survived on the pooled VM, so a red job's traces and screenshots
  were attached to later jobs. Jobs 41657, 41710 and 41774 each ran 267 of 267 Playwright
  tests green; their red was Vitest, while the attached failures named tests from other
  branches. Read `playwright.out` ("N passed") and the failing step before believing an
  artifact. The job now clears `~/artifacts` before checkout.
- **Playwright can drive an app built from another branch.** The tests come from the checkout,
  but the app is `freegle-prod-local` / `modtools-prod-local`, built on a pooled runner VM whose
  BuildKit can reuse a `COPY . .` layer from an earlier branch. Jobs 41657 and 41710 (PR #1855)
  failed on a ModTools chart layout and a support link that exist only in PRs #1853 and #1856,
  and the build step took 129s and said nothing. A red that tests code your branch does not
  touch is this, not a flaky test. `scripts/verify-prod-image-source.sh` now compares every
  tracked file under `iznik-nuxt3/` with the container and rebuilds with `--no-cache` on a
  difference; read its output in the "Verify production containers" step.
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
- **A Go test that races goroutines covers the lost-race branch only on the runs where the race
  is lost there.** `TestCreateChatMessageImageConcurrentSendsOnlyOneWins` sends one image five
  times at once; a loser that stops at the `checkChatImageUnused` pre-check never reaches
  `linkChatImage`'s "no row claimed" branch, so two lines of `chat/chatmessage.go` were covered
  on some runs and not others and the Go flag went red on a branch that changed no Go. The UX
  experiments create had the same shape. Either make the racing branch the only path, or pin it
  directly: `TestLinkChatImageSecondClaimLosesDeterministically` claims a linked image a second
  time.
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
  lines and 0.014% on its own. Two bigger ones, found by diffing per-file counts between
  Coveralls jobs on identical code (`coveralls.io/jobs/<id>/source_files.json`): every ModTools
  spec logged in and left `/` at once, so the home page's config fetches, dashboard and
  `ModYesterday` (23 lines, all or nothing) finished only sometimes; and the browse spec waited
  for cards the store already held, so `PostFilters`, `GroupSelect` and `PostMap`'s first
  `getMessages` ran only when the chunk loaded in time. Each now has a test that waits for the
  thing and checks it (`test-modtools-dashboard.spec.js`, `test-browse.spec.js`). The offline
  indicator used to be covered only when an API blip happened mid-run; `test-offline-indicator`
  makes the poll fail on purpose.

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
- **A seeded pending post that a test edits can leave the pending queue mid-run.** The per-spec
  pending posts are seeded Pending on an unmoderated community. An edit to the subject or text makes
  the batch content check look at the post again, and finding nothing wrong it approves it. A later
  test in the same spec that expects that post in the queue passes or fails on whether the check ran
  in between. Give each test that edits a pending post its own one (`pending.offer`,
  `pending.wanted`), as `test-modtools-edit-message.spec.js` does.
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
  forty logins, cause not yet found: a member could hit it too. It refills once, then fails. When a login modal does
  not close (or a ModTools login times out or is refused), the log carries one `[LOGIN-DIAG]` line from
  `tests/e2e/utils/login-diagnostics.js`: modal count and `data-instance` ids, whether the store holds a
  member, the email field and password length, any error text, and the navigation entry type. Read it
  before theorising. CI 2026-10-17/18 showed no refill in 2084 ModTools logins, so the "one in forty"
  figure is unproven.
  A new-page recovery means Chromium left `context.newPage()` unanswered for a minute before any
  test code ran. Seen once in three runs, with no container event, memory pressure or crash
  logged; the fixture asks once more. More than one in a run is worth chasing in Chromium.
- **Every image CI starts must be built in the orb's build step.** CI turns file sync off, so
  an image the build step leaves out is whatever copy a pooled runner VM already has. Vitest and
  eslint run in modtools-dev-local, which was left out: on a VM that had it, both ran over an
  older frontend and a lint error passed. Adding a service to a CI profile means adding it there.
- **A page left by `page.goto` keeps its coverage only until garbage collection.** Playwright
  reads JS coverage once, at `stopJSCoverage`, and V8 reports only scripts it still holds, so
  with `resetOnNavigation: false` the previous document's lines were counted on the runs where
  its scripts had not yet been collected. That is why `GroupSelect.vue` was 20 or 1 of 26 and
  `ModYesterday.vue` 23 or 0 of 25 with the same specs passing. `fixtures.js` now flushes
  coverage (stop, report, start) before every test-driven `goto` and `reload`. A hard
  navigation the app starts itself (a logout reload, say) is not covered by that; end such a
  test on the page whose lines matter.
- **Compare Playwright coverage file by file, never by its totals.** The number of lines the
  coverage file counts changes from run to run on the same code (19,843 in one run, 27,657 in
  another), so total lines hit can rise or fall by thousands with nothing changed. Per file, two
  master runs still differ in about 14 files, LoginModal alone by 20 lines. A file that loses
  coverage in a few runs and not others is a test that ends before something async finishes:
  make it wait for that thing and check it.
- **A `docker cp` red/green loop against a worktree's apiv2 is not isolated from the
  worktree.** The host-scripts file sync copies `iznik-server-go` edits into that stack's
  apiv2 container as well as the dev frontends, so a test run started after the fix was
  written in the worktree runs against the fix even though only the test file was copied
  in by hand. Four feed tests "passed red" that way on 2026-10-13. For a true red,
  `git stash push -- <handler files>` in the worktree (the sync then puts the old files
  back), run, and `git stash pop stash@{0}`; the stash list is shared across worktrees, so
  name the entry rather than popping the top.
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

- **Builds sit queued with `not_running` and nothing is running**: there is no runner. A
  pipeline only provisions one at the start, in `check-runner`, and only when it sees fewer than
  two. Hetzner has been refusing the second, so in practice one runner serves the whole queue,
  and every pipeline queued behind it provisioned nothing. When that runner deletes itself, at
  its uptime cap or after idling, the queue strands. The `check-runner` log says why Hetzner
  refused (HTTP status and error). To restart the queue, start a new pipeline through the API
  (`POST /project/gh/Freegle/Iznik/pipeline` with the branch); its `check-runner` provisions a
  runner, and that runner works through everything queued. Past its cap a runner now waits for
  five minutes with no job before deleting itself, so it claims the next queued build first.
- **build-and-test red within seconds on a pull request from a fork, with "Task information
  unavailable" and no steps**, is CircleCI refusing fork code on our self-hosted runner
  (`Forked prs not allowed to run on OSS projects`, visible only in the job's `messages` through
  the v2 API). Nothing ran, so there is no test failure to find. A maintainer reads the pull
  request and applies the **Run CI** label (`.github/workflows/fork-pr-ci.yml`). Do not turn on
  fork builds or secrets for forks in the CircleCI settings to get round it.

- **Docker Hub rate limits** mean the pull-through mirror is only wired up for the self-hosted
  runner, so a job that lands elsewhere pulls directly and is throttled.
- **A new compose service that is not in the orb's explicit build list** is never built, and
  fails later in a way that looks unrelated.
- **A per-step `export` in the orb does not reach a later step's compose command.** The variable
  is simply absent, with no error.
- **The swagger generator's fallback binary path** can resolve to a stale build, producing
  nondeterministic output.
- **CI's swagger drift guard reads the spec the image REGENERATES at build, not the committed
  `swagger/swagger.json`.** So a `swagger.go` edit that is wrong in a way the committed file
  hides passes locally against a container built from master and fails only in CI. Removing
  the recommendations block that way swallowed the next block too (its `Responses:` list
  carried 401 and 403 after the 200 the regex stopped on), and the committed file still had
  the path. Prove a `swagger.go` edit by regenerating inside apiv2
  (`SWAGGER_OUT=/tmp/x.json ./generate-swagger.sh`) and comparing path sets with routes.go;
  the full documents differ from the committed file anyway, only the path set matters.

When master is red across many builds, pin the breaking commit by which **test** fails per
build, not by bisecting the boundary.

## The app emulator gate (`scripts/app-e2e`, Playwright project `app`)

- **A green browser suite says nothing about the app.** The same code runs with a different
  plugin set, origin and WebView; `tests/e2e/app/plugins.spec.js` is the check that a plugin
  in `includePlugins` is really registered natively.
- **`chromium.connectOverCDP` into the WebView drops with code 1006** the moment Playwright
  attaches. Only `playwright._android` works, and it needs no `adb forward`.
- **A running app is not necessarily a running WebView.** Android restarts the app for a
  broadcast after killing it (on a just-booted emulator: "depends on provider ...
  FontsProvider in dying proc"), and that process has no activity. Bring the activity up
  with `am start` and wait for `webview_devtools_remote_<pid>` before attaching.
- **`androidDevice.webView({ pkg })` can time out beside a WebView that is there**:
  Playwright takes the package from the last line of `ps -A | grep <pid>`, which a
  process with the same digits in its figures can win. Select by `socketName`
  (`webview_devtools_remote_<pid>`); the fixtures and `attach-check.cjs` do.
- **The app dies on launch without any `google-services.json`**: the push plugin's
  `register()` throws on a plugin thread. The placeholder in `scripts/app-e2e` keeps a
  machine without the CI secret running; push registration then fails by design.
- **A Play Store system image refuses `adb root`**, and without root the device cannot bind
  port 80 for the reverse tunnel or accept the FCM broadcast. The AVD is `google_apis`.
- **A signed-out app answering 401 looks like a Sentry error** in the console-error check;
  the resume handler in `stores/mobile.js` now fetches nothing while nobody is signed in.
- **A background `adb logcat`, or an adb server the run happened to start, holds the
  emulator lock** if it inherits the lock descriptor; a later run then waits 45 minutes for
  a lock nobody will release. `run.sh` starts the server before taking the lock.
- **The emulator needs `/dev/kvm`, which no Hetzner Cloud VM has.** The job runs on
  CircleCI's Android machine image; on a runner it fails at its KVM check.
- **A CircleCI cloud job is killed at one hour**, mid-step, with no `when: always` evidence
  and Playwright's failure summary never printed (job 39371: 59 minutes, of which 31 were an
  uncached stack build). The APK is built in its own job and the emulator job's stack drops
  the services the app never uses (`scripts/app-e2e/docker-compose.app-e2e.yml`); keep
  anything added to it inside that hour. `xlarge` is not in the plan.
- **On that machine a test takes 5 to 10 times as long as on a workstation** (deep links 28s
  against 3s), because the emulator renders in software on the cores the stack is using,
  and the stack takes about 30 minutes before the first test. One machine ran 16 of the 24
  tests in its 20 minutes. The job is split into Playwright shards on parallel machines
  (`shards`, whole spec files per shard); a spec added to the `app` project lands in a
  shard by test count, so check that its shard still fits.
- **An e2e build that reads the release build's variable names gets CircleCI's values.**
  `TUS_UPLOADER` there is the public tus demo server, and every photo test failed on
  `net::ERR_FAILED` until `lib.sh` took overrides only as `APP_E2E_*`.
- **An unset `SENTRY_DSN` is not "no Sentry"**: `config.js` falls back to the live web DSN.
  The e2e build empties the runtime value with `NUXT_PUBLIC_SENTRY_DSN=""`.
- **The scheduled promote must not be cancelled by a push to production.** It is a CircleCI
  scheduled pipeline (`weekly-promote-schedule`, Wednesday 22:00 UTC) that now runs for an
  hour; the cancel passes in `.circleci/config.yml` spare `scheduled_pipeline` as well as
  `api` triggers, or a master auto-merge would cancel it with nothing reported.
- **A compose override that gives a service `build:` back undoes a placeholder.** The orb's
  cache overlay adds `build.cache_from` to eight services, so applying it on top of the
  emulator job's placeholders made Compose look for a Dockerfile that is not there.
- **A share sent with `am start` from a root shell is refused** with a `SecurityException` in
  `MainActivity`, and the give flow never opens: root's URI grant does not take, and `am`
  never moves `EXTRA_STREAM` into the clip data a grant covers. `shareImage` sends as the
  shell user with the URI in the intent data too.
- **Every UI dump on the emulator can be an "isn't responding" dialog.** The CI emulator
  stalls the app or System UI past five seconds, and the dialog stays over every screen
  after the app recovers, so the camera and photo picker helpers saw only the dialog for
  seven minutes (job 39544). `uiNodes` in `tests/e2e/app/device.js` taps Wait and logs it;
  `hide_error_dialogs` would make Android kill the app instead.
- **An uploaded photo's thumbnail 404s on a stack with offset ports** (worktrees, CI on
  9080): the delivery service is asked to fetch `tusd.localhost:<host port>`, which its
  container cannot reach. The upload itself succeeded; check the tusd id in the draft.
- **The Go suite runs against the dev API's own database.** Both read `MYSQL_DBNAME` in the
  apiv2 container, so rows seeded by hand for a screenshot or a manual check can be changed by a
  Go run through the status API. After one, the worktree's Support user was a Moderator again
  and ModTools said "You don't have access to SysAdmin tools". Re-check roles and seeded rows
  after any Go run, before trusting what the dev site shows.
- **A Playwright POST whose database reset dies part-way leaves the database empty, and the
  env endpoint still answers.** Under heavy host load (another session's vitest workers, load
  average over 1,000) the reset's `spawnSync` timed out after dropping the data, so `users` had
  0 rows, and `GET /api/tests/env/<prefix>` went on returning the seeded ids from
  `test-envs.json` with a 200. ModTools then said "We don't know that email address" for a
  moderator the env endpoint had just named. Count `users` before trusting either; a second
  POST, once the load has gone, resets it properly.
- **A browser run through the status API empties rows seeded by hand.** It resets the database
  to its fixtures as it starts. Afterwards the seeded session and every `ux_*` rollup row were
  gone, and the UX tab showed each funnel at 0 started with no error. The UX read API keeps each
  answer for ten minutes, so a page opened before the rows were back went on showing zeros after
  they were. Re-seed, then restart `apiv2` to drop the cached answers.

## See also

- `docs/developers/testing.md` - the four suites and how to run them.
- `.claude/rules/dev-containers.md` - why a local pass can be testing old code.

## A green background step that failed

`Run monitor-fsm unit tests (background)`, `Lint iznik-nuxt3 (eslint, background)`, the two
`Run ... tests (background)` Go service steps and `Upload Go coverage (background)` are
CircleCI background steps, so each one is marked green the moment it starts, whatever
happens afterwards. The real result is the `pass`/`fail` written to
`~/test-output/<name>.result`, read by the parallel block (which stops the suites and prints
`FAIL-FAST: background check <name> failed`) and by `Collect background checks`, which is the
step that actually goes red and prints the output. A job where the parallel suites show as
`killed` and nothing names a test is this: read the collect step, not the suites. For Go
coverage the join is `Wait for Go coverage upload`; a Coveralls job count one short means that
upload did not finish or did not upload, and its own step's log says which.

## "Coveralls grouped 0 parallel job(s)" on a build that has all four

Coveralls only processes the queued uploads once the webhook closes the build, and the
webhook reply counts the jobs before it does that. So the reply said `"jobs":0` on every run
from 2026-08-18 to 2026-10-27 while the four `Coveralls - <flag>` jobs landed 10 to 20s
later and the aggregate was right (jobs 43482 and 43488: webhook, then the four per-flag
commit statuses, then the build's `calculated_at`). Waiting before the webhook does not help;
the statuses never appear until it is sent. The step now checks the real thing after the
webhook: the per-flag commit statuses on GitHub, counted from when the coverage setup
started. If that warns, read the commit's statuses first; four of them means the wait ran
out, not that the ids are wrong.

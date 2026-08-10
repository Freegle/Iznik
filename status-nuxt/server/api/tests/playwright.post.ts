import { spawn, execSync, exec } from 'child_process'
import path from 'path'
import { getTestState, setTestState, appendTestLogs, isTestRunning } from '../../utils/testState'
import { clearTestEnvCache } from '../../utils/testEnvCache'

export default defineEventHandler(async (event) => {
  const query = getQuery(event)
  const body = await readBody(event).catch(() => ({}))

  // Get test file and name from query params or body.
  // The "filter" param is smart: if it looks like a filename (starts with "test-"
  // or contains ".spec"), treat it as a file filter; otherwise treat it as a grep pattern.
  const filterParam = (body?.filter || query.filter) as string | null
  const isFileFilter = filterParam && (filterParam.startsWith('test-') || filterParam.includes('.spec'))

  const testFile = (body?.testFile || query.testSpec || query.spec || (isFileFilter ? filterParam : null)) as string | null
  const testName = (body?.testName || query.testName || (!isFileFilter ? filterParam : null)) as string | null

  let logMessage = 'Received request to run Playwright tests'
  if (testFile) logMessage += ` for file: ${testFile}`
  if (testName) logMessage += ` with grep: "${testName}"`
  if (!testFile && !testName) logMessage += ' (all tests)'
  console.log(logMessage)

  // Check if already running
  if (isTestRunning('playwright')) {
    throw createError({
      statusCode: 409,
      message: 'Playwright tests are already running'
    })
  }

  // Initialize test status
  let statusMessage = 'Initializing test environment...'
  if (testFile && testName) {
    statusMessage = `Running test "${testName}" in ${testFile}`
  } else if (testFile) {
    statusMessage = `Running specific test file: ${testFile}`
  } else if (testName) {
    statusMessage = `Running tests matching: "${testName}"`
  }

  setTestState('playwright', {
    status: 'running',
    message: statusMessage,
    logs: '',
    progress: { completed: 0, total: 0, passed: 0, failed: 0, current: '' },
    startTime: Date.now(),
    endTime: null,
  })

  // Run tests asynchronously
  runPlaywrightTests(testFile, testName).catch((error) => {
    console.error('Test execution error:', error)
    setTestState('playwright', {
      status: 'failed',
      message: `Error: ${error.message}`,
      endTime: Date.now(),
    })
  })

  return { status: 'started', message: 'Playwright tests started successfully' }
})

/**
 * Drop, migrate and re-seed the iznik test database.
 *
 * CI builds a fresh database for every run, so specs there always start from the
 * captured fixtures. Locally the database persists between runs, and tests mutate
 * it: they approve pending posts, and the content-check job auto-approves any
 * clean pending post from an unmoderated member. Left alone, the pending queue
 * the ModTools specs rely on drains away and those specs fail locally while
 * passing in CI - which sends you hunting for a bug in the code under test. Reset
 * before every run so local matches CI.
 *
 * `settleMs` gives MySQL a moment to flush after a heavy parallel run before we
 * issue DDL; without it the DROP can sit on the DDL lock for minutes.
 */
async function resetTestDatabase(pfx: string, label: string, settleMs = 0) {
  appendTestLogs('playwright', `${label}Resetting test database to clean state...\n`)

  if (settleMs > 0) {
    await new Promise((r) => setTimeout(r, settleMs))
  }

  // Kill active connections to iznik before dropping — avoids waiting for InnoDB
  // to flush dirty pages. Best-effort: the DROP succeeds even if connections have
  // already closed between the SELECT and the KILL.
  try {
    execSync(
      `docker exec ${pfx}-percona sh -c "mysql -u root -piznik -e \\"SELECT CONCAT('KILL ',id,';') FROM information_schema.processlist WHERE db='iznik'\\" | mysql -u root -piznik"`,
      { encoding: 'utf8', timeout: 10000 }
    )
  } catch {}

  execSync(
    `docker exec ${pfx}-percona sh -c "mysql -u root -piznik -e 'DROP DATABASE IF EXISTS iznik; CREATE DATABASE iznik;'"`,
    { encoding: 'utf8', timeout: 300000 }
  )
  execSync(
    `docker exec ${pfx}-batch php artisan migrate --force --no-interaction`,
    { encoding: 'utf8', timeout: 300000 }
  )
  // Reload captured fixtures (replaces the retired V1 install/testenv.php seeding).
  execSync(
    `docker cp /project/scripts/test-fixtures.sql ${pfx}-percona:/tmp/test-fixtures.sql && docker exec ${pfx}-percona sh -c "mysql -u root -piznik iznik < /tmp/test-fixtures.sql"`,
    { encoding: 'utf8', timeout: 120000 }
  )
  // Roll the fixture post dates forward, exactly as scripts/setup-test-database.sh does
  // when CI builds its database. The fixtures carry fixed 2026-07-09 dates, and the feed
  // only shows posts from the last MessageSpatialService::RECENT_DAYS (31) days. Once
  // they age past that, upsertRecentMessages stops re-adding them AND removeOldMessages
  // deletes the rows the fixtures ship, so messages_spatial empties and browse/explore
  // return nothing. Locally the batch scheduler runs the reconciler, so it happens within
  // minutes of a reset; CI never runs it, which is why this reset path could stay broken
  // while CI stayed green. Every spec that browses for a seeded post then fails on an
  // empty feed with nothing in the output to say why.
  //
  // Whole days preserve the relative ages the fixtures encode and land the newest post a
  // day old. Self-limiting: once it is a day old the delta is 0 and re-running is a no-op.
  appendTestLogs('playwright', `${label}Rolling fixture post dates into the feed window...\n`)
  const rollForward = `
SET @delta := (
  SELECT GREATEST(DATEDIFF(NOW(), MAX(arrival)) - 1, 0)
  FROM messages_groups
  WHERE arrival <= NOW()
);
UPDATE messages_groups
   SET arrival    = arrival    + INTERVAL @delta DAY,
       approvedat = approvedat + INTERVAL @delta DAY,
       rejectedat = rejectedat + INTERVAL @delta DAY
 WHERE @delta > 0 AND arrival <= NOW();
UPDATE messages
   SET arrival = arrival + INTERVAL @delta DAY,
       date    = date    + INTERVAL @delta DAY
 WHERE @delta > 0 AND arrival <= NOW();
UPDATE messages_spatial
   SET arrival = arrival + INTERVAL @delta DAY
 WHERE @delta > 0 AND arrival <= NOW();
`
  execSync(
    `docker exec -i ${pfx}-percona sh -c "mysql -u root -piznik iznik"`,
    { encoding: 'utf8', timeout: 60000, input: rollForward }
  )

  // Assert it worked. Without this the failure is invisible from the test output and
  // reads as a flake on whichever branch happens to run.
  const FEED_WINDOW_DAYS = 31
  const newestAge = parseInt(
    execSync(
      `docker exec ${pfx}-percona sh -c "mysql -u root -piznik iznik -N -B -e \\"SELECT DATEDIFF(NOW(), MAX(arrival)) FROM messages_groups WHERE arrival <= NOW()\\""`,
      { encoding: 'utf8', timeout: 30000 }
    ).trim(),
    10
  )
  if (Number.isFinite(newestAge) && newestAge >= FEED_WINDOW_DAYS) {
    throw new Error(
      `Newest seeded post is ${newestAge} days old, outside the ${FEED_WINDOW_DAYS}-day feed ` +
        `window (group/groupMessages.go, message/groups.go). Browse and explore would return ` +
        `nothing and every test that browses for a seeded post would fail.`
    )
  }
  appendTestLogs(
    'playwright',
    `${label}Fixture dates rolled forward (newest post ${newestAge} day(s) old)\n`
  )

  // The Go V2 API maintains a MySQL connection pool. Dropping and recreating the
  // database invalidates those connections. Restart the container so it starts
  // fresh — otherwise the location typeahead (used by postcode validation in the
  // /give flow) returns empty results and Playwright tests time out.
  execSync(`docker restart ${pfx}-apiv2`, { encoding: 'utf8', timeout: 60000 })

  const apiv2Start = Date.now()
  let apiv2Ready = false
  while (Date.now() - apiv2Start < 60000) {
    try {
      const health = execSync(
        `docker inspect --format '{{.State.Health.Status}}' ${pfx}-apiv2`,
        { encoding: 'utf8', timeout: 5000 }
      ).trim()
      if (health === 'healthy') { apiv2Ready = true; break }
    } catch {}
    await new Promise((r) => setTimeout(r, 2000))
  }
  if (!apiv2Ready) {
    throw new Error(`${pfx}-apiv2 did not become healthy within 60s after restart`)
  }

  // Clear the in-memory testEnv cache so the run re-reads the seeded postcode/ID
  // data. The fixture reload restores the same ids, so cached values remain valid,
  // but clearing keeps the cache honest after a DB reset.
  clearTestEnvCache()
  appendTestLogs('playwright', `${label}Test database reset complete (apiv2 healthy)\n`)
}

async function runPlaywrightTests(testFile: string | null, testName: string | null) {
  // Drives periodic background-task processing during the run (started/stopped below).
  let bgTasksInterval: ReturnType<typeof setInterval> | null = null
  try {
    // Check both prod containers are running
    const pfx = process.env.COMPOSE_PROJECT_NAME || 'freegle'
    for (const container of [`${pfx}-prod-local`, `${pfx}-modtools-prod-local`]) {
      const check = execSync(
        `docker ps --filter "name=${container}" --format "{{.Status}}"`,
        { encoding: 'utf8', timeout: 5000 }
      ).trim()

      if (!check.includes('Up')) {
        throw new Error(`${container} container is not running`)
      }
    }

    appendTestLogs('playwright', 'Production containers are running\n')

    // Wait for both prod containers to be serving HTTP before starting tests.
    // Docker "Up" status doesn't mean the Nuxt server inside is ready.
    // Use Docker service names with internal ports (works on same Docker network).
    // The .localhost hostnames require Traefik DNS which doesn't resolve inside containers.
    setTestState('playwright', { message: 'Waiting for production containers to be ready...' })

    const prodContainers = [
      { name: `${pfx}-prod-local`, port: 3003 },
      { name: `${pfx}-modtools-prod-local`, port: 3001 },
    ]

    for (const { name, port } of prodContainers) {
      let ready = false
      for (let attempt = 0; attempt < 30; attempt++) {
        try {
          const controller = new AbortController()
          const timeout = setTimeout(() => controller.abort(), 2000)
          const resp = await fetch(`http://${name}:${port}/`, { signal: controller.signal })
          clearTimeout(timeout)

          if (resp.status === 200) {
            ready = true
            break
          }
        } catch {
          // Server not ready yet
        }

        await new Promise(resolve => setTimeout(resolve, 2000))
      }

      if (!ready) {
        throw new Error(`${name}:${port} did not become ready within 60 seconds`)
      }

      appendTestLogs('playwright', `${name}:${port} is ready\n`)
    }

    // Restart Playwright container
    setTestState('playwright', { message: 'Restarting Playwright container...' })
    appendTestLogs('playwright', 'Restarting Playwright container...\n')

    try {
      execSync(`docker restart ${pfx}-playwright`, {
        encoding: 'utf8',
        timeout: 30000,
      })
      appendTestLogs('playwright', 'Playwright container restarted\n')
    } catch (restartError: any) {
      appendTestLogs('playwright', `Warning: Failed to restart container: ${restartError.message}\n`)
    }

    // Sync playwright.config.js from the mounted volume — it's baked in at
    // image build time and not covered by the entrypoint's /host-tests sync,
    // so local changes (e.g. retries, flags) would otherwise be silently ignored.
    try {
      execSync(
        `docker exec ${pfx}-playwright cp /host-playwright-config/playwright.config.js /app/playwright.config.js`,
        { encoding: 'utf8', timeout: 5000 }
      )
      appendTestLogs('playwright', 'playwright.config.js synced from host\n')
    } catch (syncError: any) {
      appendTestLogs('playwright', `Warning: Failed to sync playwright.config.js: ${syncError.message}\n`)
    }

    // Wait for Playwright container to be ready
    await new Promise(resolve => setTimeout(resolve, 3000))

    // Test environments are now created on demand by each test's testEnv fixture
    // via GET /api/tests/env/:prefix (no pre-generation needed).

    // Build test args for both --list and actual run
    // If testFile is a bare name (no path separators), expand to tests/e2e/<name>.spec.js
    let resolvedTestFile = testFile
    if (resolvedTestFile && !resolvedTestFile.includes('/')) {
      const name = resolvedTestFile.replace(/\.spec\.js$/, '')
      resolvedTestFile = `tests/e2e/${name}.spec.js`
    }
    let testArgs = ''
    if (resolvedTestFile) testArgs += ` ${resolvedTestFile}`
    if (testName) testArgs += ` --grep "${testName}"`

    // Get accurate test count using --list before running
    setTestState('playwright', { message: 'Counting tests...' })
    try {
      const listOutput = execSync(
        `docker exec ${pfx}-playwright sh -c "cd /app && export NODE_PATH=/usr/lib/node_modules && npx playwright test --list${testArgs}"`,
        { encoding: 'utf8', timeout: 60000 }
      )
      // Count lines that match test entries (lines with [chromium] marker)
      const testLines = listOutput.split('\n').filter(line => line.includes('[chromium]'))
      if (testLines.length > 0) {
        const state = getTestState('playwright')
        state.progress.total = testLines.length
        setTestState('playwright', state)
        appendTestLogs('playwright', `Test count from --list: ${testLines.length}\n`)
        console.log(`Playwright --list found ${testLines.length} tests`)
      }
    } catch (listError: any) {
      console.warn('Could not get test count from --list:', listError.message)
      appendTestLogs('playwright', 'Could not pre-count tests, will determine from output\n')
    }

    const testCmd = `export ENABLE_MONOCART_REPORTER=true && npx playwright test${testArgs}`

    // Clear freeze-specs file and stale test result files before run.
    // Stale junit.xml / test-status.json from a previous run (possibly weeks old
    // if the container is reused) would otherwise be collected as CI artifacts
    // and reported as failures even when the current run passes.
    try {
      execSync(
        `docker exec ${pfx}-playwright sh -c "rm -f /tmp/playwright-freeze-specs.txt /app/test-results/junit.xml /app/test-results/test-status.json"`,
        { encoding: 'utf8', timeout: 5000 }
      )
    } catch {}

    // Process incoming chat messages throughout the run. apiv2 (Go) creates a reply chat message
    // with processingrequired=1; chats:process-incoming flips it to processingsuccessful=1, which
    // makes the ChatListEntry visible (see iznik-server-go chatmessage.go). The retired V1 apiv1
    // cron used to run this every minute; with apiv1 gone and batch's scheduler disabled in CI,
    // nothing else processes it while Playwright runs, so chat-dependent specs (post-flow reply,
    // user-ratings) hang. We drive short run-once invocations from the (stable) status container
    // event loop rather than a long-lived detached process, which the batch PID 1 can reap. The
    // stale command-lock is cleared each tick (flock isn't reliably released on the bind mount).
    let bgTasksBusy = false
    bgTasksInterval = setInterval(() => {
      if (bgTasksBusy) return
      bgTasksBusy = true
      exec(
        `docker exec ${pfx}-batch sh -c "rm -f storage/framework/command-locks/App-Console-Commands-Chat-ProcessIncomingChatCommand.lock; php artisan chats:process-incoming"`,
        { timeout: 60000 },
        () => { bgTasksBusy = false }
      )
    }, 5000)
    appendTestLogs('playwright', 'Started chats:process-incoming processor for the run\n')

    // Start from the same clean database CI does. Without this the local database
    // drifts run by run and specs fail here that pass in CI.
    //
    // Not on CI, which has just built the database from scratch for this run.
    // Repeating the migrate there is pure duplicated work, and it is slow enough
    // that the run timed out before a single spec executed.
    if (process.env.CI) {
      appendTestLogs('playwright', 'CI: database already built fresh for this run, skipping reset\n')
    } else {
      setTestState('playwright', { message: 'Resetting test database...' })
      try {
        await resetTestDatabase(pfx, '')
      } catch (dbResetError: any) {
        // Running against a half-reset database would produce results nobody can
        // trust, so stop rather than report failures caused by the reset.
        appendTestLogs('playwright', `Database reset FAILED: ${(dbResetError as Error).message}\n`)
        setTestState('playwright', {
          status: 'failed',
          message: `Database reset failed: ${(dbResetError as Error).message}`,
          endTime: Date.now(),
        })
        if (bgTasksInterval) { clearInterval(bgTasksInterval); bgTasksInterval = null }
        return
      }
    }

    setTestState('playwright', { message: 'Running Playwright tests...' })
    appendTestLogs('playwright', `Running: ${testCmd}\n`)

    const initialCode = await spawnPlaywrightProcess(testCmd, pfx)

    // Preserve main-run coverage before any freeze retry can overwrite it.
    // The retry only runs a subset of specs, so its coverage file has lower
    // totals than the full run — restoring the main copy keeps Coveralls accurate.
    const mainCoveragePath = '/app/monocart-report/coverage/lcov.info'
    const backupCoveragePath = '/app/monocart-report/coverage/lcov.info.main'
    try {
      execSync(
        `docker exec ${pfx}-playwright sh -c "test -f ${mainCoveragePath} && cp ${mainCoveragePath} ${backupCoveragePath} || true"`,
        { encoding: 'utf8', timeout: 5000 }
      )
    } catch {}

    // Check whether any specs were recorded as frozen and re-run them in a
    // fresh Playwright process (fresh V8 state, no accumulated async contexts).
    // Up to 2 retry rounds: the first retry can itself encounter a freeze, so a
    // second round handles that without giving up on a run that is otherwise clean.
    let finalCode = initialCode

    // Capture main-run progress before any retry overwrites the counters.
    const stateAfterMain = getTestState('playwright')
    const mainRunPassed = stateAfterMain.progress.passed
    const mainRunFailed = stateAfterMain.progress.failed
    const mainRunTotal  = stateAfterMain.progress.total

    // Accumulate all spec basenames that have ever been identified as frozen across
    // all retry rounds.  A spec that froze in round 0 but passed in round 1 should
    // not be flagged as an "unaccounted failure" in round 2 just because its failure
    // line still appears in the cumulative log from the initial run.
    const allEverFrozenBasenames = new Set<string>()

    for (let freezeRound = 0; freezeRound < 2; freezeRound++) {
      try {
        const freezeOutput = execSync(
          `docker exec ${pfx}-playwright sh -c "cat /tmp/playwright-freeze-specs.txt 2>/dev/null || true"`,
          { encoding: 'utf8', timeout: 5000 }
        ).trim()
        const freezeSpecs = [...new Set(
          freezeOutput.split('\n').filter((s) => s.trim())
        )]

        if (freezeSpecs.length === 0) break  // no frozen specs — done

        // Add newly-frozen specs to the cumulative frozen set.
        for (const f of freezeSpecs) {
          allEverFrozenBasenames.add(path.basename(f))
        }

        // Determine which spec files have failures in the cumulative logs so far.
        // If any failing spec is NOT in ANY round's frozen list it is a genuine
        // failure that the retry cannot fix — leave finalCode non-zero and bail out.
        const currentLogs = getTestState('playwright').logs || ''
        const failedSpecBasenames = new Set<string>()
        const failedLines = currentLogs.match(/[✘✗×]\s+\d+\s+\[chromium\][^\n]*/g) || []
        for (const line of failedLines) {
          const m = line.match(/›\s+(tests\/e2e\/[^:\s]+\.spec\.js)/)
          if (m) failedSpecBasenames.add(path.basename(m[1]))
        }
        const unaccountedFailures = [...failedSpecBasenames].filter((f) => !allEverFrozenBasenames.has(f))
        if (unaccountedFailures.length > 0) {
          appendTestLogs('playwright', `[FREEZE-RETRY round ${freezeRound + 1}] Non-frozen failures present (${unaccountedFailures.join(', ')}) — not retrying\n`)
          break
        }

        // Use full paths for Playwright to find the specs. freezeSpecs already contains
        // full paths like /app/tests/e2e/foo.spec.js; strip /app prefix for Docker context
        const retryFiles = freezeSpecs.map((f) => f.replace(/^\/app\//, '')).join(' ')
        const retryMsg = `[Freeze-retry ${freezeRound + 1}/2] Re-running ${freezeSpecs.length} frozen spec(s) in fresh process`
        appendTestLogs('playwright', `\n${retryMsg}: ${retryFiles}\n`)
        setTestState('playwright', { message: retryMsg })

        // The main suite has already modified the database by this point (created
        // posts, replies, users), so re-running specs against it would produce
        // failures unrelated to the code under test. Same reset the main run does -
        // see resetTestDatabase. The pause lets MySQL settle after 11 parallel
        // workers finish before we issue DDL.
        try {
          await resetTestDatabase(pfx, `[Freeze-retry ${freezeRound + 1}/2] `, 10000)
        } catch (dbResetError: any) {
          // Database reset failure means the retry would run against dirty data — any
          // result would be unreliable. Fail the run so the root cause can be investigated.
          appendTestLogs('playwright', `[Freeze-retry ${freezeRound + 1}/2] Database reset FAILED: ${(dbResetError as Error).message}\n`)
          finalCode = 1
          break
        }

        // Clear freeze file before retry so the next round picks up only NEW freezes.
        try {
          execSync(`docker exec ${pfx}-playwright sh -c "rm -f /tmp/playwright-freeze-specs.txt"`, { encoding: 'utf8', timeout: 5000 })
        } catch {}

        // Run retry without monocart so the main-run coverage (full suite) is not
        // overwritten by partial coverage from just the re-run frozen specs.
        // --last-failed: only re-run the specific tests that failed, not the whole
        // spec file. Without this, a spec with tests 3.1/3.2/3.3 would re-execute
        // 3.1 and 3.2, re-accumulating V8 async context so that 3.3 hits the same
        // freeze threshold again.
        const retryCode = await spawnPlaywrightProcess(`export ENABLE_MONOCART_REPORTER=false && npx playwright test ${retryFiles} --last-failed`, pfx)

        // Restore full-suite coverage — the retry covered fewer tests than the main run.
        try {
          execSync(
            `docker exec ${pfx}-playwright sh -c "test -f ${backupCoveragePath} && cp ${backupCoveragePath} ${mainCoveragePath} || true"`,
            { encoding: 'utf8', timeout: 5000 }
          )
        } catch {}

        if (retryCode === 0) {
          // All frozen specs passed in the fresh process — overall run is a success.
          finalCode = 0
          const totalTests = mainRunTotal || (mainRunPassed + mainRunFailed)
          setTestState('playwright', {
            progress: {
              ...stateAfterMain.progress,
              passed: totalTests,
              failed: 0,
              completed: totalTests,
            },
          })
          appendTestLogs('playwright', `[FREEZE-RETRY round ${freezeRound + 1}] All frozen failures resolved — marking run as passed (${totalTests}✓)\n`)
          break
        }
        // retryCode !== 0: retry itself had issues (possibly more freezes) — loop again
        finalCode = retryCode
      } catch (freezeErr: any) {
        appendTestLogs('playwright', `[FREEZE-RETRY round ${freezeRound + 1}] Could not check freeze file: ${freezeErr.message}\n`)
        break
      }
    }

    // Stop the background-task processor started for this run.
    if (bgTasksInterval) { clearInterval(bgTasksInterval); bgTasksInterval = null }

    const state = getTestState('playwright')
    const p = state.progress
    setTestState('playwright', {
      status: finalCode === 0 ? 'completed' : 'failed',
      success: finalCode === 0,
      endTime: Date.now(),
      message: finalCode === 0
        ? `All tests passed (${p.passed}✓)`
        : `Tests failed (${p.passed}✓ ${p.failed}✗)`,
    })
    console.log(`Playwright tests completed with code ${finalCode}`)
  } catch (error: any) {
    if (bgTasksInterval) { clearInterval(bgTasksInterval); bgTasksInterval = null }
    setTestState('playwright', {
      status: 'failed',
      message: `Error: ${error.message}`,
      endTime: Date.now(),
    })
    throw error
  }
}

// Once Playwright prints its final summary line ("N passed (12.3m)") the result
// is known and every test has finished. If the process then doesn't exit within
// this window we stop waiting for a clean teardown and kill it: a hung teardown
// (lingering browser context / webServer / coverage write) otherwise leaves the
// process alive but idle, which idles the whole CI VM until Katapult reaps the
// job as `infrastructure_fail`. 90s comfortably covers a normal teardown +
// monocart coverage write, so clean runs are never affected.
const TEARDOWN_GRACE_MS = 90_000

function spawnPlaywrightProcess(cmd: string, pfx: string): Promise<number> {
  return new Promise((resolve) => {
    let settled = false
    let graceTimer: NodeJS.Timeout | null = null

    const finish = (code: number) => {
      if (settled) return
      settled = true
      if (graceTimer) clearTimeout(graceTimer)
      resolve(code ?? 1)
    }

    const proc = spawn('sh', ['-c', `
      docker exec ${pfx}-playwright sh -c "cd /app && export NODE_PATH=/usr/lib/node_modules && ${cmd} 2>&1"
    `], { stdio: 'pipe' })

    proc.stdout.on('data', (data) => {
      const text = data.toString()
      appendTestLogs('playwright', text)
      parsePlaywrightOutput(text)

      // Tests are done once the summary line appears ("N passed (time)" /
      // "N failed (time)"). Arm a one-shot timer; if Playwright doesn't exit
      // cleanly within the grace window, record the parsed result and kill it.
      if (!graceTimer && /\b\d+\s+(passed|failed|flaky|skipped)\b[^\n]*\(/.test(getTestState('playwright').logs || '')) {
        graceTimer = setTimeout(() => {
          appendTestLogs('playwright', `\n[force-exit] Tests finished but Playwright did not exit within ${TEARDOWN_GRACE_MS / 1000}s — recording the result and killing it (avoids idling the CI VM into infrastructure_fail).\n`)
          // Best-effort kill of the hung process inside the container; the next
          // run restarts the container anyway, so a lingering pid is harmless.
          try {
            execSync(`docker exec ${pfx}-playwright sh -c "pkill -9 -f playwright || true"`, { timeout: 10000 })
          } catch {}
          try { proc.kill('SIGKILL') } catch {}
          const st = getTestState('playwright')
          finish(st.progress.failed > 0 ? 1 : 0)
        }, TEARDOWN_GRACE_MS)
      }
    })

    proc.stderr.on('data', (data) => {
      appendTestLogs('playwright', data.toString())
    })

    proc.on('close', (code) => {
      finish(code ?? 1)
    })

    proc.on('error', (error) => {
      appendTestLogs('playwright', `Spawn error: ${error.message}\n`)
      finish(1)
    })
  })
}

function parsePlaywrightOutput(text: string) {
  const state = getTestState('playwright')
  const allLogs = state.logs || ''

  // Look for test counts in JSON output (if available)
  const jsonPassedMatch = text.match(/"passed":\s*(\d+)/)
  const jsonFailedMatch = text.match(/"failed":\s*(\d+)/)
  const jsonTotalMatch = text.match(/"total":\s*(\d+)/)

  if (jsonPassedMatch) state.progress.passed = parseInt(jsonPassedMatch[1])
  if (jsonFailedMatch) state.progress.failed = parseInt(jsonFailedMatch[1])
  if (jsonTotalMatch) state.progress.total = parseInt(jsonTotalMatch[1])

  // Look for list reporter summary format: "X passed" or "X failed"
  const listPassedMatch = text.match(/(\d+)\s+passed/)
  const listFailedMatch = text.match(/(\d+)\s+failed/)

  if (listPassedMatch) state.progress.passed = parseInt(listPassedMatch[1])
  if (listFailedMatch) state.progress.failed = parseInt(listFailedMatch[1])

  // Look for test start markers and extract total count.
  // Preserve any "[Freeze-retry N/2]" prefix already set so the status bar
  // stays informative during the retry process.
  const testMatch = text.match(/Running (\d+) tests? using \d+ workers?/)
  if (testMatch) {
    const retryPrefix = state.message?.match(/^\[Freeze-retry \d+\/\d+\][^|]*/)?.[0]
    state.message = retryPrefix ? `${retryPrefix.trim()} | ${testMatch[0]}` : testMatch[0]
    state.progress.total = parseInt(testMatch[1])
  }

  // Check accumulated logs for the final summary line (not just current chunk)
  // The summary line is authoritative because symbol counting over-counts retries
  // Playwright summary format: "  82 passed (5.2m)" — has time in parens
  const allPassedMatch = allLogs.match(/(\d+)\s+passed\s*\(/)
  const allFailedMatch = allLogs.match(/(\d+)\s+failed\s*\(/)

  if (allPassedMatch) {
    state.progress.passed = parseInt(allPassedMatch[1])
  } else {
    // Fall back to counting Playwright list-reporter lines only (format: "  ✓  N [chromium]")
    // Do NOT count bare ✓ symbols — test code (e.g. withdrawPost) also logs ✓.
    const passLines = (allLogs.match(/^\s*✓\s+\d+\s+\[/gm) || []).length
    if (passLines > 0) state.progress.passed = passLines
  }

  if (allFailedMatch) {
    state.progress.failed = parseInt(allFailedMatch[1])
  } else {
    // Count only Playwright list-reporter failure lines (format: "  ✘  N [chromium]")
    const failLines = (allLogs.match(/^\s*[✘✗×]\s+\d+\s+\[/gm) || []).length
    if (failLines > 0) state.progress.failed = failLines
  }

  // Update completed
  state.progress.completed = state.progress.passed + state.progress.failed

  setTestState('playwright', state)
}

import { spawn, execSync } from 'child_process'
import path from 'path'
import { getTestState, setTestState, appendTestLogs, isTestRunning } from '../../utils/testState'

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

async function runPlaywrightTests(testFile: string | null, testName: string | null) {
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

    const testCmd = `npx playwright test${testArgs}`

    // Clear freeze-specs file before run so we only capture freezes from this run
    try {
      execSync(
        `docker exec ${pfx}-playwright sh -c "rm -f /tmp/playwright-freeze-specs.txt"`,
        { encoding: 'utf8', timeout: 5000 }
      )
    } catch {}

    setTestState('playwright', { message: 'Running Playwright tests...' })
    appendTestLogs('playwright', `Running: ${testCmd}\n`)

    const initialCode = await spawnPlaywrightProcess(testCmd, pfx)

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

        // Determine which spec files have failures in the cumulative logs so far.
        // If any failing spec is NOT in the frozen list it is a genuine failure
        // that the retry cannot fix — leave finalCode non-zero and bail out.
        const currentLogs = getTestState('playwright').logs || ''
        const failedSpecBasenames = new Set<string>()
        const failedLines = currentLogs.match(/[✘✗×]\s+\d+\s+\[chromium\][^\n]*/g) || []
        for (const line of failedLines) {
          const m = line.match(/›\s+(tests\/e2e\/[^:\s]+\.spec\.js)/)
          if (m) failedSpecBasenames.add(path.basename(m[1]))
        }
        const frozenBasenames = new Set(freezeSpecs.map((f) => path.basename(f)))
        const unaccountedFailures = [...failedSpecBasenames].filter((f) => !frozenBasenames.has(f))
        if (unaccountedFailures.length > 0) {
          appendTestLogs('playwright', `[FREEZE-RETRY round ${freezeRound + 1}] Non-frozen failures present (${unaccountedFailures.join(', ')}) — not retrying\n`)
          break
        }

        const retryFiles = [...frozenBasenames].join(' ')
        appendTestLogs('playwright', `\n[FREEZE-RETRY round ${freezeRound + 1}] Re-running ${freezeSpecs.length} frozen spec(s) in fresh process: ${retryFiles}\n`)

        // Clear freeze file before retry so the next round picks up only NEW freezes.
        try {
          execSync(`docker exec ${pfx}-playwright sh -c "rm -f /tmp/playwright-freeze-specs.txt"`, { encoding: 'utf8', timeout: 5000 })
        } catch {}

        const retryCode = await spawnPlaywrightProcess(`npx playwright test ${retryFiles}`, pfx)

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
    setTestState('playwright', {
      status: 'failed',
      message: `Error: ${error.message}`,
      endTime: Date.now(),
    })
    throw error
  }
}

function spawnPlaywrightProcess(cmd: string, pfx: string): Promise<number> {
  return new Promise((resolve) => {
    const proc = spawn('sh', ['-c', `
      docker exec ${pfx}-playwright sh -c "cd /app && export NODE_PATH=/usr/lib/node_modules && ${cmd} 2>&1"
    `], { stdio: 'pipe' })

    proc.stdout.on('data', (data) => {
      const text = data.toString()
      appendTestLogs('playwright', text)
      parsePlaywrightOutput(text)
    })

    proc.stderr.on('data', (data) => {
      appendTestLogs('playwright', data.toString())
    })

    proc.on('close', (code) => {
      resolve(code ?? 1)
    })

    proc.on('error', (error) => {
      appendTestLogs('playwright', `Spawn error: ${error.message}\n`)
      resolve(1)
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

  // Look for test start markers and extract total count
  const testMatch = text.match(/Running (\d+) tests? using \d+ workers?/)
  if (testMatch) {
    state.message = testMatch[0]
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

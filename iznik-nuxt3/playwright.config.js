const fs = require('fs')
const path = require('path')
const { defineConfig, devices } = require('@playwright/test')
const { timeouts } = require('./tests/e2e/config')

// Check if we have an ordered test list
const orderedTestsFile = path.join(__dirname, 'tests/e2e/ordered-tests.txt')
let testMatch

if (fs.existsSync(orderedTestsFile)) {
  try {
    const orderedTests = fs
      .readFileSync(orderedTestsFile, 'utf8')
      .split('\n')
      .filter((line) => line.trim())
      .map((testPath) => path.basename(testPath))
    if (orderedTests.length > 0) {
      testMatch = orderedTests
      console.log(
        `Using ordered test execution: ${orderedTests.length} tests, prioritizing previously failed tests`
      )
    }
  } catch (error) {
    console.warn('Could not load ordered test list:', error.message)
  }
}

module.exports = defineConfig({
  testDir: './tests/e2e',
  testMatch,
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0,
  // PW_WORKERS env var takes precedence (set per-executor in CircleCI orb).
  // Fallback: self-hosted runner has more resources; cloud CI needs fewer workers to avoid flakiness.
  workers: process.env.PW_WORKERS
    ? Number(process.env.PW_WORKERS)
    : process.env.SELF_HOSTED_RUNNER === 'true' ? 11 : 6,
  maxFailures: 0,
  reporter: [
    ['list'],
    ['html', { open: process.env.CI ? 'never' : 'always', host: '0.0.0.0' }],
    ['junit', { outputFile: 'test-results/junit.xml' }],
    ['./tests/e2e/status-reporter.js'],
    // Only include monocart reporter when explicitly enabled via env var
    ...(process.env.ENABLE_MONOCART_REPORTER === 'true'
      ? [
          [
            'monocart-reporter',
            {
              name: 'Playwright Code Coverage Report',
              reportDir: 'monocart-report',
              json: true,
              // Disable auto-opening of reports to prevent hanging
              open: 'never',
              coverage: {
                reports: ['lcovonly'],
                lcov: true,
                outputDir: 'coverage',
                entryFilter: (entry) => {
                  // Filter out entries from external domains and problematic URLs
                  if (entry.url) {
                    return (
                      !entry.url.includes('accounts.google.com') &&
                      !entry.url.includes('googleapis.com') &&
                      !entry.url.includes('gstatic.com') &&
                      !entry.url.startsWith('data:') &&
                      !entry.url.startsWith('blob:') &&
                      entry.url.length < 300
                    )
                  }
                  return true
                },
                sourceFilter: (sourcePath) => {
                  // Only include source files from our application, not polyfills or virtual files
                  return (
                    // Include files that look like our source code
                    (sourcePath.includes('.vue') ||
                      sourcePath.includes('/pages/') ||
                      sourcePath.includes('/components/') ||
                      sourcePath.includes('/layouts/') ||
                      sourcePath.includes('/composables/') ||
                      sourcePath.includes('/stores/') ||
                      sourcePath.startsWith('app.vue') ||
                      sourcePath.startsWith('error.vue')) &&
                    // Exclude problematic paths
                    !sourcePath.includes('node_modules/') &&
                    !sourcePath.includes('data:') &&
                    !sourcePath.includes('blob:') &&
                    // Sentry error-filter composable: its branches fire only
                    // on specific browser/3rd-party errors (Leaflet, Freestar,
                    // NotReadableError, etc.) that e2e tests don't trigger.
                    // Counting it in Playwright's denominator means every new
                    // error class we add drops coverage — Vitest unit tests
                    // cover it properly, so exclude from Playwright only.
                    !sourcePath.includes('useSuppressException') &&
                    // Uppy retry-coalesce composable: its branches fire only
                    // on Uppy upload errors / state-corruption exceptions
                    // that Playwright e2e flows don't trigger. Unit-tested
                    // via the host components; excluded from Playwright to
                    // avoid dragging per-job coverage down.
                    !sourcePath.includes('useUppyRetryCoalesce') &&
                    // ChatMobileNavbar: 198 relevant L+B, consistently 0%
                    // covered across every Playwright run (master + PR).
                    // It only renders in the mobile chat layout path that
                    // the e2e suite does not navigate into, so it is pure
                    // denominator noise. Unit tests cover it.
                    !sourcePath.includes('components/ChatMobileNavbar') &&
                    // ModSettingsStandardMessageSet and ModSettingsModConfig:
                    // modtools settings page components. The e2e suite does
                    // not navigate to the modtools settings pages, so these
                    // are consistently 0% covered by Playwright and add only
                    // denominator noise. Both are covered by Vitest unit
                    // tests (ModSettingsStandardMessageSet.spec.js,
                    // ModSettingsModConfig.spec.js).
                    !sourcePath.includes('ModSettingsStandardMessageSet') &&
                    !sourcePath.includes('ModSettingsModConfig') &&
                    sourcePath.length < 300
                  )
                },
                // sourcePathMap not needed since paths are already relative to the correct directory
              },
            },
          ],
        ]
      : []),
  ],
  timeout: 600_000,
  outputDir: 'test-results',
  // Force video directory
  videoDir: 'test-results/videos',
  use: {
    baseURL: process.env.TEST_BASE_URL || 'http://freegle-prod-local.localhost',
    testEmailDomain: process.env.TEST_EMAIL_DOMAIN || 'yahoogroups.com',
    // viewport set at test level for better control
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'on-first-retry',
    // Video recording configuration
    contextOptions: {
      recordVideo: {
        dir: 'test-results',
        size: { width: 1280, height: 720 },
      },
    },
    // Docker-friendly navigation settings
    navigationTimeout: timeouts.navigation.default,
    actionTimeout: timeouts.api.default,
    // Increase expect timeout for Docker environment data loading
    expect: {
      timeout: timeouts.api.slowApi,
    },
  },

  // Use existing Docker server instead of starting our own
  webServer: undefined,

  env: {
    SENTRY_DSN: '',
    SENTRY_ENABLE_DEBUG: 'false',
    SENTRY_TRACES_SAMPLE_RATE: '0',
  },

  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        viewport: null, // Remove viewport constraints to use full screen
        deviceScaleFactor: undefined, // Remove device scale factor when viewport is null
        video: 'on-first-retry',
        // Use Playwright's downloaded Chromium browser with security flags for Docker
        launchOptions: {
          headless: true, // Run in headless mode for CI/Docker environments
          args: [
            '--start-maximized', // Maximize browser window
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu',
            '--disable-web-security',
            '--allow-running-insecure-content',
            '--disable-features=VizDisplayCompositor',
            '--disable-ipc-flooding-protection',
            '--ignore-certificate-errors',
            '--allow-insecure-localhost',
            '--disable-extensions',
            '--disable-plugins',
            // Disable CDP async call stack depth tracking. Playwright enables this by
            // default; V8's PromiseHookAfter fires on every Promise resolution to maintain
            // async context chains. Vue's scheduler (queueFlush/queueJob) resolves a Promise
            // per reactive cycle, so over a long spec run the tracked context list grows until
            // iterating it on every resolution saturates the renderer thread (issue #285).
            '--disable-features=AsyncCallStackDepth',
            // Prevent Chrome from keeping navigated-away pages frozen in the BFCache.
            // Across hundreds of navigations in a long run, accumulated V8 heap
            // (compiled code, closures) builds up and can contribute to GC pauses.
            '--disable-features=BackForwardCache',
            // Stop V8 from scheduling background optimization/GC during idle periods,
            // which can cause latency spikes mid-test.
            '--disable-v8-idle-tasks',
            // Prevent Chrome's background network activity (update checks, safebrowsing
            // fetches, etc.) from generating Promise chains in the renderer.
            '--disable-background-networking',
            // Prevent renderer from being deprioritised when Playwright switches between
            // pages — deprioritisation causes timer/Promise batching which then resolves
            // in a burst and stresses the V8 hook machinery when focus returns.
            '--disable-renderer-backgrounding',
            // Same idea for background timers: keep them firing at normal rate so
            // they don't batch up and produce Promise bursts on re-focus.
            '--disable-background-timer-throttling',
            // Disable Chrome profile sync — generates IPC and network traffic in the
            // background throughout the test run.
            '--disable-sync',
            // Disable the hang monitor — it can kill a renderer that's momentarily
            // slow under load, producing a false crash rather than a recoverable freeze.
            '--disable-hang-monitor',
            // Skip Chrome's first-run setup flow and component update checks.
            '--no-first-run',
            '--disable-component-update',
            // Disable media routing (Chromecast/Cast) background discovery traffic.
            '--disable-features=MediaRouter',
            // Force V8 to eagerly parse/compile all JS. Removing this caused
            // test-reply-flow-existing-user.spec.js 3.1 to hit a 20m timeout
            // (job 5179) because the post-signup gotoAndVerify('/') in
            // logoutIfLoggedIn stalled on lazy V8 parse of the homepage JS
            // bundle. Prior commit with this flag (2fb8f2669, job 5167)
            // passed; removing it for coverage stability regressed test
            // stability. ChatMobileNavbar exclusion above carries the
            // coverage recovery independently.
            '--js-flags=--no-lazy',
          ],
          env: {},
        },
        contextOptions: {
          // Disable background sync and other features that might prevent network idle
          reducedMotion: 'reduce',
        },
      },
    },
  ],
})

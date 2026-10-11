/**
 * Telling a page that will never mount from one that is still loading, and
 * recovering it.
 *
 * Chromium aborts every in-flight request with net::ERR_NETWORK_CHANGED when
 * a network interface appears or disappears in its network namespace. The
 * Playwright container shares the host's namespace, so a container or an
 * image build step starting or stopping anywhere on the machine does it. A
 * script or stylesheet lost that way during a page load leaves the
 * server-rendered page on screen with the app never mounting: no error, no
 * retry, and every later wait in the test runs to its timeout (two ModTools
 * tests each spent 200 seconds waiting for a login modal on a page whose
 * entry chunks had been aborted 0.3s into the load). Only loading the page
 * again brings it back. ERR_CONNECTION_RESET is the same shape from the
 * other end.
 */
const { test } = require('@playwright/test')
const { timeouts } = require('../config')

/**
 * Record that the harness recovered from something instead of failing.
 *
 * Recovering from host noise is legitimate, but a recovery must never be
 * silent: the same symptom can be a real fault (a server resetting
 * connections, a form that loses what was typed), and if it is only retried
 * away nobody sees it. So each recovery is a "[RECOVERED]" line, which the
 * status runner counts and names in the run's final message, and an
 * annotation on the test in the report.
 */
function noteRecovery(kind, detail) {
  console.log(`[RECOVERED] ${kind}: ${detail}`)
  try {
    test
      .info()
      .annotations.push({ type: `recovered-${kind}`, description: detail })
  } catch {
    // Outside a running test (no test.info()): the log line is enough.
  }
}

const LOST_LOAD = /ERR_NETWORK_CHANGED|ERR_CONNECTION_RESET/

// How long a healthy page gets to mount after domcontentloaded. One to two
// seconds is normal; no page in a 257-navigation run took longer.
const MOUNT_WAIT = 20000

/**
 * Start remembering script and stylesheet loads that fail in a way a reload
 * would cure. Idempotent, so helpers that may be handed a page without the
 * fixtures (test-marketing-consent uses a bare Playwright page) can call it.
 */
function trackFailedLoads(page) {
  if (page.__failedLoads) return
  page.__failedLoads = []
  page.on('requestfailed', (request) => {
    const type = request.resourceType()
    if (type !== 'script' && type !== 'stylesheet') return
    const errorText = request.failure()?.errorText || ''
    if (!LOST_LOAD.test(errorText)) return
    page.__failedLoads.push({ url: request.url(), errorText, at: Date.now() })
  })
}

function failedLoadsSince(page, since) {
  return (page.__failedLoads || []).filter((f) => f.at >= since)
}

function describeFailed(failed) {
  const errors = [...new Set(failed.map((f) => f.errorText))].join(', ')
  const urls = failed
    .map((f) => f.url.replace(/^https?:\/\/[^/]+/, ''))
    .join(', ')
  return `${failed.length} load(s) failed with ${errors}: ${urls}`
}

function appMounted(page, timeout) {
  return page
    .waitForFunction(
      () => !!document.getElementById('__nuxt')?.__vue_app__,
      null,
      { timeout }
    )
    .then(() => true)
    .catch(() => false)
}

/**
 * Wait for the Nuxt client app to mount on the page that a navigation
 * started at `navigatedAt` has just loaded.
 *
 * Mounted within MOUNT_WAIT: return. Not mounted, and a script or stylesheet
 * load failed since the navigation started: reload once (forgetting those
 * failures first) and wait again, then fail naming the URLs and the error.
 * Not mounted with nothing lost: give it the rest of the hydration budget
 * and then fail saying so, because a reload would not help and carrying on
 * from an unmounted page only moves the failure 200 seconds down the test.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} path - what was navigated to, for the messages
 * @param {number} navigatedAt - Date.now() taken just before the navigation
 */
async function waitForAppMount(page, path, navigatedAt) {
  if (await appMounted(page, MOUNT_WAIT)) return

  const failed = failedLoadsSince(page, navigatedAt)
  if (failed.length) {
    console.log(
      `[appMount] ${path}: app not mounted ${
        MOUNT_WAIT / 1000
      }s after load, ${describeFailed(failed)}; reloading once`
    )
    page.__failedLoads = page.__failedLoads.filter((f) => f.at < navigatedAt)
    const reloadedAt = Date.now()
    await page.reload({
      waitUntil: 'domcontentloaded',
      timeout: timeouts.navigation.default,
    })
    if (await appMounted(page, MOUNT_WAIT)) {
      noteRecovery(
        'reload',
        `${path} mounted only after a reload; first load: ${describeFailed(failed)}`
      )
      return
    }
    const again = failedLoadsSince(page, reloadedAt)
    throw new Error(
      `App did not mount at ${path}: ${describeFailed(failed)}; ` +
        `after one reload ${
          again.length ? describeFailed(again) : 'no load failed'
        } and it still had not mounted ${MOUNT_WAIT / 1000}s later`
    )
  }

  const rest = Math.max(0, timeouts.ui.hydration - MOUNT_WAIT)
  if (await appMounted(page, rest)) return
  throw new Error(
    `App did not mount within ${
      (MOUNT_WAIT + rest) / 1000
    }s of loading ${path}, and no script or stylesheet load failed`
  )
}

module.exports = { trackFailedLoads, waitForAppMount, noteRecovery }

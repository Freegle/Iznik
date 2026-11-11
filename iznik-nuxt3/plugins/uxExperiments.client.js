/**
 * Fetches the running UX experiments' arms at boot, so a change can start, move traffic, be
 * adopted or be reverted with no deploy. The key is public and holds names, bucket ranges and
 * expiry times only. docs/developers/reference/ux-experiments.md.
 *
 * Not awaited: a visit that starts a flow before the answer arrives uses the copy kept from the
 * last good fetch (composables/useExperiment.js), or no arm at all, which is control.
 *
 * A page that stays open (a tab left in the background, an installed app resumed from sleep) asks
 * again when it comes back to the front, on a route change and at least hourly, so a change that is
 * stopped or reverted stops being served within minutes, not when the page is next loaded. The
 * experiment simply being absent from the new config means control.
 *
 * The fetch is quiet when it fails. The frontend can deploy before the API that serves the key
 * does, in which case every page view gets a 403 or 404; that is expected, not something to report
 * to Sentry once per page view, so the request is made with logging off and the error is dropped.
 */
import { useRuntimeConfig } from '#imports'
import api from '~/api'
import { setLiveExperiments } from '~/composables/useExperiment'

export const CONFIG_KEY = 'ux_experiments'

// Event-driven asks (back to the front, a route change) are at most this often; the timer asks at
// least this often whatever happens.
export const REFETCH_MIN_MS = 5 * 60 * 1000
export const REFETCH_EVERY_MS = 60 * 60 * 1000

let lastAsked = 0

export async function fetchLiveExperiments(config) {
  lastAsked = Date.now()
  try {
    const rows = await api(config).config.fetchv2(CONFIG_KEY, false)
    const value = Array.isArray(rows) && rows.length ? rows[0].value : '{}'
    setLiveExperiments(value)
  } catch {
    // Refused, down or slow: keep the last good copy in localStorage (useExperiment.js), and say
    // nothing.
  }
}

function askAgainIfOld(config) {
  if (Date.now() - lastAsked < REFETCH_MIN_MS) return
  fetchLiveExperiments(config)
}

/**
 * Ask again whenever the page returns to the front, the route changes, and every hour. Returns a
 * function that stops it (tests).
 */
export function keepFresh(config, { doc = document, router = null } = {}) {
  const onVisible = () => {
    if (doc.visibilityState === 'visible') askAgainIfOld(config)
  }
  doc.addEventListener('visibilitychange', onVisible)
  router?.afterEach?.(() => askAgainIfOld(config))
  const timer = setInterval(() => askAgainIfOld(config), REFETCH_EVERY_MS)

  return () => {
    doc.removeEventListener('visibilitychange', onVisible)
    clearInterval(timer)
  }
}

export default defineNuxtPlugin((nuxtApp) => {
  if (import.meta.server) return
  const config = useRuntimeConfig()
  fetchLiveExperiments(config)
  keepFresh(config, { router: nuxtApp?.$router })
})

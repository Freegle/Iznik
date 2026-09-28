// Diagnostics for images that fail to load from the delivery host.
//
// An <img> error event carries no reason. On its own it cannot tell a missing
// image (an HTTP 404) from a host the device cannot reach at all (TLS refused,
// DNS blocked, a content filter). Support needs that distinction on the first
// email, so when an image fails we:
//
//  1. Probe the same URL with fetch() and record whether the host answered and
//     with what status.
//  2. If the host did not answer, check once per session whether the delivery
//     host is unreachable while the API host is fine. That combination means
//     something on this device or network is blocking images specifically
//     (Screen Time and similar filters work per hostname and abort the TLS
//     handshake). We report it once, to Sentry and to the client log, and keep
//     a per-session flag. Nothing is shown to the member: the tiles are too
//     small for an explanation, and support can act on the report.
//  3. Cap the per-image Sentry reports per session and ignore crawlers, whose
//     renderers fire these events by the thousand.
import { ref } from 'vue'
import { captureMessage } from '@sentry/browser'
import { useRuntimeConfig } from '#app'
import { warn as clientLogWarn } from '~/composables/useClientLog'

// Individual image failures reported to Sentry per session before switching to
// an aggregate count. One member scrolling a broken Browse page produced 452
// events in a day, which buries the signal.
export const MAX_INDIVIDUAL_REPORTS = 3

// After the cap, report an aggregate every this many further failures.
export const AGGREGATE_EVERY = 50

const PROBE_TIMEOUT_MS = 8000

const CRAWLER_RE =
  /bot|crawl|spider|slurp|bingpreview|headlesschrome|lighthouse|facebookexternalhit/i

const imageHostBlocked = ref(false)

let failures = 0
let individualReports = 0
let firstFailedUrl = null
let hostCheckStarted = false
let hostCheckResult = null

// Reactive flag: true once this session has shown that the delivery host is
// unreachable from this device while the API is reachable.
export function useImageHostBlocked() {
  return imageHostBlocked
}

export function isCrawler(userAgent) {
  const ua =
    userAgent ?? (typeof navigator !== 'undefined' ? navigator.userAgent : '')
  return CRAWLER_RE.test(ua || '')
}

// The fetch used when a caller does not supply one. Under Vitest that is
// nothing: a component spec that fires an image error must not start real
// network requests, whose abort timers would outlive the spec and stall the
// run. Specs that exercise the probes pass their own fetch.
function defaultFetch() {
  if (import.meta.env?.VITEST) {
    return null
  }

  return globalThis.fetch
}

// Fetch a URL purely to learn whether the host answers. A CORS refusal rejects
// just like a dead host, so a failed cors fetch is retried in no-cors mode: an
// opaque response still proves the host is there.
export async function probeUrl(url, fetchImpl) {
  const doFetch = fetchImpl === undefined ? defaultFetch() : fetchImpl

  if (typeof doFetch !== 'function' || !url) {
    return { probed: false }
  }

  const attempt = async (mode) => {
    const controller =
      typeof AbortController === 'function' ? new AbortController() : null
    const timer = controller
      ? setTimeout(() => controller.abort(), PROBE_TIMEOUT_MS)
      : null

    try {
      const response = await doFetch(url, {
        mode,
        cache: 'no-store',
        credentials: 'omit',
        signal: controller?.signal,
      })
      return {
        ok: true,
        status: response.status,
        opaque: response.type === 'opaque',
      }
    } catch (e) {
      return {
        ok: false,
        error: e?.name === 'AbortError' ? 'timeout' : e?.message || String(e),
      }
    } finally {
      if (timer) {
        clearTimeout(timer)
      }
    }
  }

  const cors = await attempt('cors')

  if (cors.ok) {
    return { probed: true, reachable: true, status: cors.status }
  }

  if (cors.error === 'timeout') {
    return { probed: true, reachable: false, error: 'timeout' }
  }

  const opaque = await attempt('no-cors')

  if (opaque.ok) {
    return { probed: true, reachable: true, status: null, opaque: true }
  }

  return { probed: true, reachable: false, error: opaque.error }
}

function hostProbeUrls() {
  let pub = {}

  try {
    pub = useRuntimeConfig()?.public || {}
  } catch (e) {
    // Outside a Nuxt context there is no config; the probes are skipped.
  }

  return {
    // A tiny image every deployment serves, routed through the delivery host
    // exactly as a post photo would be.
    delivery:
      pub.IMAGE_DELIVERY && pub.USER_SITE
        ? pub.IMAGE_DELIVERY +
          '/?url=' +
          encodeURIComponent(pub.USER_SITE + '/defaultprofile.png') +
          '&w=16&output=png'
        : null,
    api: pub.APIv2 ? pub.APIv2 + '/online' : null,
  }
}

// Once per session: is the delivery host unreachable while the API host is
// reachable? Resolves to the probe results either way.
export async function checkImageHost(fetchImpl) {
  if (hostCheckStarted) {
    return hostCheckResult
  }

  hostCheckStarted = true

  const urls = hostProbeUrls()
  const [delivery, api] = await Promise.all([
    probeUrl(urls.delivery, fetchImpl),
    probeUrl(urls.api, fetchImpl),
  ])

  hostCheckResult = { delivery, api }

  const deliveryDown = delivery.probed && delivery.reachable === false
  const apiUp = api.probed && api.reachable === true

  if (deliveryDown && apiUp) {
    imageHostBlocked.value = true

    const nav = typeof navigator !== 'undefined' ? navigator : null
    const detail = {
      delivery_error: delivery.error,
      failures_so_far: failures,
      first_failed_url: firstFailedUrl,
      online: nav ? nav.onLine : null,
      connection: nav?.connection?.effectiveType || null,
    }

    captureMessage(
      'Image host unreachable: delivery blocked on this device or network',
      { level: 'warning', extra: detail }
    )
    clientLogWarn('image_host_unreachable', detail)
  }

  return hostCheckResult
}

// Called by OurUploadedImage for a real load failure. Resolves to the probe
// result for the first few failures, or null when nothing was probed.
export async function reportImageFailure({ src, url }, fetchImpl) {
  if (isCrawler()) {
    return null
  }

  failures++

  if (!firstFailedUrl && url) {
    firstFailedUrl = url
  }

  if (individualReports < MAX_INDIVIDUAL_REPORTS) {
    individualReports++

    const probe = await probeUrl(url, fetchImpl)

    captureMessage('Failed to fetch image ' + src, {
      level: 'warning',
      extra: {
        url,
        probe,
        failures_in_session: failures,
        image_host_blocked: imageHostBlocked.value,
      },
    })

    if (probe.probed && probe.reachable === false) {
      await checkImageHost(fetchImpl)
    }

    return probe
  }

  if (failures % AGGREGATE_EVERY === 0) {
    captureMessage('Image failures continuing: ' + failures + ' this session', {
      level: 'warning',
      extra: {
        latest_src: src,
        latest_url: url,
        image_host_blocked: imageHostBlocked.value,
        host_check: hostCheckResult,
      },
    })
  }

  return null
}

// Test hook: the state above is per page load by design.
export function resetImageFailureDiagnostics() {
  imageHostBlocked.value = false
  failures = 0
  individualReports = 0
  firstFailedUrl = null
  hostCheckStarted = false
  hostCheckResult = null
}

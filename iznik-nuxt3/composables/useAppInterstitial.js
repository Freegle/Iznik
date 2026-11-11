import { ref } from 'vue'
import { useExperiment } from '~/composables/useExperiment'

// Experiment mobile-web-app-interstitial: on arrival from a phone browser, a full-screen page that
// asks the member to install the app. AppInterstitial.vue, which LayoutCommon mounts, draws it; this
// decides whether it may show and reads the arm.
export const APP_INTERSTITIAL_EXPERIMENT = 'mobile-web-app-interstitial'

// How long after seeing it a member is left alone, on this device.
export const APP_INTERSTITIAL_GAP = 30 * 24 * 60 * 60 * 1000
const SEEN_KEY = 'freegle_app_interstitial_seen'

// The phone width the site already treats as mobile (getDeviceClass in useClientLog).
const PHONE_MAX_WIDTH = 767

// Open while the page is showing, and the store it points at.
export const appInterstitialOpen = ref(false)
export const appInterstitialPlatform = ref(null)

// One look per page view, and a memory of having seen it for when storage is blocked.
let considered = false
let seenInMemoryAt = 0

// 'android' or 'ios' for a phone, else null. A tablet is not a phone: Android tablets leave "Mobile"
// out of the user agent, and an iPad says iPad.
export function platformOf(ua) {
  const agent = String(ua || '')
  if (/iPhone|iPod/.test(agent)) return 'ios'
  if (/Android/.test(agent) && /Mobile/.test(agent)) return 'android'
  return null
}

export function isBot(ua) {
  return /bot|crawl|spider|slurp|lighthouse|pagespeed|facebookexternalhit|preview/i.test(
    String(ua || '')
  )
}

// Pages where a full-screen page would be in the way of something the member came to do, or that
// the app promotion does not belong on. Prefixes, matched on whole path segments.
const EXCLUDED = [
  // Arrived at from an email to finish something.
  '/unsubscribe',
  '/forgot',
  '/marketing-optout',
  '/merge',
  '/chats',
  // Partway through posting, or returning from a payment.
  '/give',
  '/ask',
  '/post',
  '/donate',
  '/donated',
  '/giftaid',
  // Legal and privacy.
  '/privacy',
  '/terms',
  '/disclaimer',
  '/mydata',
  '/security',
  // Already about the app, or not the member site.
  '/mobile',
  '/modtools',
]

export function excludedRoute(path, query = {}) {
  // A login link from an email carries the member's key.
  if (query?.k) return true
  const p = String(path || '/')
  return EXCLUDED.some((e) => p === e || p.startsWith(e + '/'))
}

export function seenRecently(now = Date.now()) {
  if (seenInMemoryAt && now - seenInMemoryAt < APP_INTERSTITIAL_GAP) return true
  for (const store of ['localStorage', 'sessionStorage']) {
    try {
      const at = Number(window[store].getItem(SEEN_KEY))
      if (at && now - at < APP_INTERSTITIAL_GAP) return true
    } catch (e) {
      // Blocked: try the next, then rely on the page view.
    }
  }
  return false
}

export function recordSeen(now = Date.now()) {
  seenInMemoryAt = now
  try {
    window.localStorage.setItem(SEEN_KEY, String(now))
  } catch (e) {
    // Storage blocked: once per session instead of once in 30 days.
    try {
      window.sessionStorage.setItem(SEEN_KEY, String(now))
    } catch (e2) {
      // Nothing to keep it in: once per page view.
    }
  }
}

// Called once on arrival. Reads the arm only when the page could show, so a control member who
// would never see it is not counted. Both arms record the visit, so the 30 days are the same.
// Returns the arm read, or null when nothing was considered.
export function considerAppInterstitial({
  ua,
  width,
  path,
  query,
  native,
  modtools,
}) {
  if (considered) return null
  considered = true

  const platform = platformOf(ua)
  if (
    !platform ||
    native ||
    modtools ||
    isBot(ua) ||
    !(width <= PHONE_MAX_WIDTH) ||
    excludedRoute(path, query) ||
    seenRecently()
  ) {
    return null
  }

  const arm = useExperiment('mobile-web-app-interstitial') || 'control'
  recordSeen()

  if (arm === 'b') {
    appInterstitialPlatform.value = platform
    appInterstitialOpen.value = true
  }

  return arm
}

export function closeAppInterstitial() {
  appInterstitialOpen.value = false
}

// For tests.
export function resetAppInterstitial({ keepConsidered = false } = {}) {
  appInterstitialOpen.value = false
  appInterstitialPlatform.value = null
  seenInMemoryAt = 0
  if (!keepConsidered) considered = false
}

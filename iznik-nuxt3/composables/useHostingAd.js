import { ref } from 'vue'
import { excludedRoute } from '~/composables/useAppInterstitial'

// Experiment krystal-hosting-ad: on arrival, a modal for a signed-in member whose email domain hosts a
// WordPress site that is not on Krystal, linking to Freegle's affiliate link. The server decides, once
// per member, whether to show it (GET /user/hostingad reads the domain facts and the arm, and records
// the exposure in both arms). HostingAd.vue, which LayoutCommon mounts, asks and draws
// HostingAdModal.vue; this decides whether to ask and keeps the memory of having asked.
export const HOSTING_AD_EXPERIMENT = 'krystal-hosting-ad'

// Where the button goes: the shortlink page sends it on to freegle.in, which counts the click and
// redirects to the Awin affiliate link for Krystal's WordPress hosting page.
export const HOSTING_AD_LINK = '/shortlink/krystalhosting'

// How long after asking the server this browser leaves it alone. The server answers once per member
// for good, so this only saves the call on every page view.
export const HOSTING_AD_ASK_GAP = 24 * 60 * 60 * 1000

// How long to wait for another modal that opened meanwhile (the rating nudge, say) to close before
// drawing this one, and how often to look. Past the wait the modal is drawn anyway: the server has
// already counted the member as shown.
export const HOSTING_AD_WAIT_FOR_MODAL = 60 * 1000
export const HOSTING_AD_WAIT_STEP = 500

const ASKED_KEY = 'freegle_hosting_ad_asked'

// Open while the modal is showing.
export const hostingAdOpen = ref(false)

// One look per page view, and a memory of the last ask for when storage is blocked.
let considered = false
let askedInMemory = 0

function readStore(name, key) {
  try {
    return window[name].getItem(key)
  } catch (e) {
    return null
  }
}

function writeStore(key, value) {
  try {
    window.localStorage.setItem(key, value)
    return true
  } catch (e) {
    try {
      window.sessionStorage.setItem(key, value)
      return true
    } catch (e2) {
      return false
    }
  }
}

export function askedRecently(now = Date.now()) {
  if (askedInMemory && now - askedInMemory < HOSTING_AD_ASK_GAP) return true
  for (const store of ['localStorage', 'sessionStorage']) {
    const at = Number(readStore(store, ASKED_KEY))
    if (at && now - at < HOSTING_AD_ASK_GAP) return true
  }
  return false
}

export function recordAsked(now = Date.now()) {
  askedInMemory = now
  writeStore(ASKED_KEY, String(now))
}

// Bootstrap puts modal-open on the body while any modal is showing.
export function anotherModalOpen() {
  try {
    return document.body.classList.contains('modal-open')
  } catch (e) {
    return false
  }
}

// Resolves once no other modal is open, or after the wait.
export function whenNoOtherModal({
  wait = HOSTING_AD_WAIT_FOR_MODAL,
  step = HOSTING_AD_WAIT_STEP,
} = {}) {
  return new Promise((resolve) => {
    if (!anotherModalOpen()) {
      resolve(true)
      return
    }
    const started = Date.now()
    const timer = setInterval(() => {
      if (!anotherModalOpen()) {
        clearInterval(timer)
        resolve(true)
      } else if (Date.now() - started >= wait) {
        clearInterval(timer)
        resolve(false)
      }
    }, step)
  })
}

// Called on arrival, and again when the member signs in on this page view. The server is asked only
// when the modal could show here: signed in, on the web, not in ModTools, not mid-flow, not asked in
// the last day on this browser and no other modal already open. Returns true when the modal was
// opened, false when the server said no, and null when nothing was asked. A signed-out arrival does
// not use up the page view's one look, so the call after signing in still asks.
export async function considerHostingAd({
  loggedIn,
  path,
  query,
  native,
  modtools,
  fetchHostingAd,
}) {
  if (considered || !loggedIn) return null
  considered = true

  if (
    native ||
    modtools ||
    excludedRoute(path, query) ||
    askedRecently() ||
    anotherModalOpen()
  ) {
    return null
  }

  recordAsked()

  let show
  try {
    show = await fetchHostingAd()
  } catch (e) {
    // The API is not there yet, or the member was signed out meanwhile: nothing to show.
    return null
  }

  if (!show) return false

  // The server has counted this member as shown, so the modal must appear; it waits its turn behind
  // one that opened during the fetch rather than stacking on it.
  await whenNoOtherModal()
  hostingAdOpen.value = true

  return true
}

export function closeHostingAd() {
  hostingAdOpen.value = false
}

// For tests.
export function resetHostingAd({ keepConsidered = false } = {}) {
  hostingAdOpen.value = false
  askedInMemory = 0
  if (!keepConsidered) considered = false
}

import { ref } from 'vue'
import { useExperiment } from '~/composables/useExperiment'
import { excludedRoute } from '~/composables/useAppInterstitial'

// Experiment rate-on-return: on a visit after a handover, a modal asking the member to rate the
// people they dealt with and have not rated. RatingNudge.vue, which LayoutCommon mounts, asks the
// server who those are and draws RatingNudgeModal.vue; this decides whether it may show, reads the
// arm and keeps the once-a-day and Skip memories.
export const RATING_NUDGE_EXPERIMENT = 'rate-on-return'

// How long after the modal is shown (or would have been, in control) the member is left alone.
export const RATING_NUDGE_GAP = 24 * 60 * 60 * 1000

// How long a Skip keeps that person out of the modal. The server only asks about the last 14 days,
// so this is "not for this handover".
export const RATING_NUDGE_SKIP_GAP = 30 * 24 * 60 * 60 * 1000

// How many people the modal shows at once.
export const RATING_NUDGE_MAX = 3

const LAST_KEY = 'freegle_rating_nudge_last'
const SKIPPED_KEY = 'freegle_rating_nudge_skipped'

// Open while the modal is showing, and who it is about.
export const ratingNudgeOpen = ref(false)
export const ratingNudgePeople = ref([])

// One look per page view, and a memory of the last show for when storage is blocked.
let considered = false
let lastInMemory = 0
let skippedInMemory = {}

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

export function shownRecently(now = Date.now()) {
  if (lastInMemory && now - lastInMemory < RATING_NUDGE_GAP) return true
  for (const store of ['localStorage', 'sessionStorage']) {
    const at = Number(readStore(store, LAST_KEY))
    if (at && now - at < RATING_NUDGE_GAP) return true
  }
  return false
}

export function recordShown(now = Date.now()) {
  lastInMemory = now
  writeStore(LAST_KEY, String(now))
}

// {userid: when skipped}, dropping entries past the skip gap.
export function skippedUsers(now = Date.now()) {
  let stored = {}
  for (const store of ['localStorage', 'sessionStorage']) {
    try {
      const raw = readStore(store, SKIPPED_KEY)
      if (raw) {
        stored = JSON.parse(raw)
        break
      }
    } catch (e) {
      // Not JSON: treat as nothing skipped.
    }
  }
  const out = {}
  for (const [id, at] of Object.entries({ ...skippedInMemory, ...stored })) {
    if (Number(at) && now - Number(at) < RATING_NUDGE_SKIP_GAP)
      out[id] = Number(at)
  }
  return out
}

export function recordSkipped(userid, now = Date.now()) {
  const skipped = skippedUsers(now)
  skipped[userid] = now
  skippedInMemory = skipped
  writeStore(SKIPPED_KEY, JSON.stringify(skipped))
}

// Called once on arrival. The list is fetched and the arm read only when the modal could show, so
// a member with nobody to rate is not counted in either arm. Both arms record the visit, so the
// 24 hours run on the same schedule. Returns the arm read, or null when nothing was considered.
export async function considerRatingNudge({
  loggedIn,
  path,
  query,
  native,
  modtools,
  fetchUnrated,
}) {
  if (considered) return null
  considered = true

  if (
    !loggedIn ||
    native ||
    modtools ||
    excludedRoute(path, query) ||
    shownRecently()
  ) {
    return null
  }

  let fetched
  try {
    fetched = (await fetchUnrated()) || []
  } catch (e) {
    // The API is not there yet, or the member was signed out meanwhile: nothing to ask.
    return null
  }

  const skipped = skippedUsers()
  const people = fetched
    .filter((p) => p?.userid && !skipped[p.userid])
    .slice(0, RATING_NUDGE_MAX)

  if (!people.length) return null

  // Another modal got there first (the microvolunteering ask, a contact details prompt): two
  // modals on arrival would be the experiment measuring itself against a mess. Treated as "could
  // not show" in both arms, so no arm is read and the day is not used up.
  if (anotherModalOpen()) return null

  const arm = useExperiment('rate-on-return') || 'control'
  recordShown()

  if (arm === 'b') {
    ratingNudgePeople.value = people
    ratingNudgeOpen.value = true
  }

  return arm
}

// Bootstrap puts modal-open on the body while any modal is showing.
function anotherModalOpen() {
  try {
    return document.body.classList.contains('modal-open')
  } catch (e) {
    return false
  }
}

export function removeFromNudge(userid) {
  ratingNudgePeople.value = ratingNudgePeople.value.filter(
    (p) => p.userid !== userid
  )
}

export function closeRatingNudge() {
  ratingNudgeOpen.value = false
  ratingNudgePeople.value = []
}

// For tests.
export function resetRatingNudge({ keepConsidered = false } = {}) {
  ratingNudgeOpen.value = false
  ratingNudgePeople.value = []
  lastInMemory = 0
  skippedInMemory = {}
  if (!keepConsidered) considered = false
}

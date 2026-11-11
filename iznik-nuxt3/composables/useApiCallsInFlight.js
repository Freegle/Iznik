// The API calls that have started and not finished, so that when a button's handler never
// reports back (SpinButton "callback not called") the report can say what it was waiting on.
// Only the method, the path without its query string, and timings are kept.
const MAX_KEPT = 20
const MAX_AGE_MS = 5 * 60 * 1000

let calls = []

export function trackApiCall(method, path) {
  const call = {
    method,
    path: String(path ?? '').split('?')[0],
    startedAt: Date.now(),
    stage: 'waiting_for_online',
  }

  calls.push(call)
  calls = calls
    .filter((c) => c === call || Date.now() - c.startedAt < MAX_AGE_MS)
    .slice(-MAX_KEPT)

  return {
    // The online check has passed and the request is on its way.
    fetching() {
      call.stage = 'fetching'
    },
    done() {
      calls = calls.filter((c) => c !== call)
    },
  }
}

export function apiCallsInFlight() {
  const now = Date.now()

  // A call older than the prune age is almost certainly dead (its caller went away without
  // finishing), so it is left out rather than blamed for a stuck button.
  return calls
    .filter((c) => now - c.startedAt < MAX_AGE_MS)
    .map((c) => ({
      method: c.method,
      path: c.path,
      stage: c.stage,
      ageMs: now - c.startedAt,
    }))
}

export function _resetApiCallsInFlightForTests() {
  calls = []
}

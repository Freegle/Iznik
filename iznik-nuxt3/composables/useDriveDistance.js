// Road drive distance/time from the logged-in member to points on the site
// (post cards, chat headers, profiles), replacing crow-flies miles wherever
// the routing server's reach engine can answer.
//
// Batch-first and event-driven: components call roadDistance(lat, lng) as
// they render; every request registered during the same synchronous render
// pass is flushed as a SINGLE /drivedistance call in a microtask at the end
// of that JS turn (no wall-clock windows, no timers to race). A list of 40
// cards rendering in one pass is one HTTP call. Results are cached per
// coordinate for the session. Everything fails soft: logged out, engine not
// deployed, request error - the returned ref simply stays null and callers
// keep showing crow-flies.

import { ref } from 'vue'
import { useRuntimeConfig } from '#app'
import Api from '~/api'
import { useAuthStore } from '~/stores/auth'

const MAX_BATCH = 100

// Module-level cache and queue: shared across all components.
const cache = new Map() // "lat,lng" -> ref({ mins, miles } | null)
let queue = [] // [{ key, lat, lng }]
let flushScheduled = false

function flush() {
  flushScheduled = false
  const batch = queue.splice(0, MAX_BATCH)
  if (queue.length) {
    scheduleFlush() // oversized page: next chunk goes in the next microtask
  }
  if (!batch.length) {
    return
  }

  const runtimeConfig = useRuntimeConfig()
  if (!runtimeConfig?.public?.APIv2) {
    // No API base configured (unit tests, storybook): stay on crow-flies.
    return
  }
  const api = Api(runtimeConfig)
  const targets = batch.map((b, i) => ({ id: i, lat: b.lat, lng: b.lng }))

  api.driving
    .distances(targets)
    .then((ret) => {
      const results = ret?.results || []
      batch.forEach((b, i) => {
        const r = results.find((x) => x.id === i)
        if (r && r.mins !== null && r.mins !== undefined) {
          cache.get(b.key).value = { mins: r.mins, miles: r.miles ?? null }
        }
        // else: leave null - crow-flies fallback.
      })
    })
    .catch(() => {
      // Fail soft: refs stay null, crow-flies shows.
    })
}

// roadDistance returns a ref that starts null and, when the engine answers,
// becomes { mins, miles } for driving from the logged-in member's home to
// (lat, lng). Same coordinates always return the same (cached) ref.
export function roadDistance(lat, lng) {
  if (import.meta.server || !lat || !lng) {
    return ref(null)
  }
  const authStore = useAuthStore()
  if (!authStore.user?.lat && !authStore.user?.lng) {
    return ref(null)
  }

  const key = lat.toFixed(5) + ',' + lng.toFixed(5)
  if (cache.has(key)) {
    return cache.get(key)
  }
  const r = ref(null)
  cache.set(key, r)
  queue.push({ key, lat, lng })
  scheduleFlush()
  return r
}

function scheduleFlush() {
  if (!flushScheduled) {
    flushScheduled = true
    queueMicrotask(flush)
  }
}

// roadMilesRounded formats road miles with the same rounding the crow-flies
// display uses (1dp under 2 miles, whole numbers above).
export function roadMilesRounded(miles) {
  if (miles === null || miles === undefined) {
    return null
  }
  return miles > 2 ? Math.round(miles) : Math.round(miles * 10) / 10
}

// Test hook: reset module state between specs.
export function _resetDriveDistanceForTest() {
  cache.clear()
  queue = []
  flushScheduled = false
}

export function osmtile() {
  const runtimeConfig = useRuntimeConfig()
  return runtimeConfig.public.OSM_TILE
}

// Options for the small maps we embed inside a scrolling list, e.g. the address
// and postcode maps in chat. Leaflet grabs the wheel event by default, so
// scrolling the conversation with the pointer over a map zooms the map out
// instead of scrolling, one level per notch, until it's showing the whole world.
export const INLINE_MAP_OPTIONS = {
  scrollWheelZoom: false,
  bounceAtZoomLimits: true,
}

export function attribution() {
  return 'Map data &copy; <a href="https://www.openstreetmap.org/" rel="noopener noreferrer">OpenStreetMap</a> contributors'
}

export function toRadian(degree) {
  return (degree * Math.PI) / 180
}

export function getDistance(origin, destination) {
  // return distance in meters
  const lon1 = toRadian(origin[1])
  const lat1 = toRadian(origin[0])
  const lon2 = toRadian(destination[1])
  const lat2 = toRadian(destination[0])

  const deltaLat = lat2 - lat1
  const deltaLon = lon2 - lon1

  const a =
    Math.pow(Math.sin(deltaLat / 2), 2) +
    Math.cos(lat1) * Math.cos(lat2) * Math.pow(Math.sin(deltaLon / 2), 2)
  const c = 2 * Math.asin(Math.sqrt(a))
  const EARTH_RADIUS = 6371
  return c * EARTH_RADIUS * 1000
}

// Miles → metres, matching the showjoin radius convention used on the map.
const METRES_PER_MILE = 1609.34

// Find the communities a user could join near a given point, sorted nearest first.
//
// point   - { lat, lng } (e.g. a searched place or the user's location)
// groups  - array, or object map keyed by id (e.g. groupStore.summaryList)
// options - { limit = 10, isMember = (id) => bool }
//
// A group is included only if it is shown on the map and published, the user is
// not already a member, and it is within its showjoin radius (in miles) of the
// point. Groups with a second centre (altlat/altlng) use whichever centre is
// nearer. Returns the original group objects, nearest first, capped at limit.
export function nearbyGroups(point, groups, options = {}) {
  const { limit = 10, isMember = null } = options

  if (
    !point ||
    point.lat === undefined ||
    point.lat === null ||
    point.lng === undefined ||
    point.lng === null
  ) {
    return []
  }

  const list = Array.isArray(groups) ? groups : Object.values(groups || {})

  const scored = []

  for (const group of list) {
    if (!group || !group.onmap || !group.publish) {
      continue
    }

    if (isMember && isMember(group.id)) {
      continue
    }

    if (
      group.lat === undefined ||
      group.lat === null ||
      group.lng === undefined ||
      group.lng === null
    ) {
      continue
    }

    let distance = getDistance([point.lat, point.lng], [group.lat, group.lng])

    // A few large groups have a second centre - use whichever is nearer.
    if (
      group.altlat !== undefined &&
      group.altlat !== null &&
      group.altlng !== undefined &&
      group.altlng !== null
    ) {
      distance = Math.min(
        distance,
        getDistance([point.lat, point.lng], [group.altlat, group.altlng])
      )
    }

    // showjoin is how far away (miles) the group is willing to be joined from.
    // A falsy value means no limit.
    if (group.showjoin && distance > group.showjoin * METRES_PER_MILE) {
      continue
    }

    scored.push({ group, distance })
  }

  scored.sort((a, b) => a.distance - b.distance)

  return scored.slice(0, limit).map((s) => s.group)
}

export function calculateMapHeight(heightFraction) {
  let height = 0

  if (process.client) {
    height = window.innerHeight / heightFraction - 70
    height = height < 200 ? 200 : height
  }

  return height
}

export async function loadLeaflet() {
  if (process.client && !window.L) {
    // Load Leaflet CSS alongside the JS (lazy-loaded so non-map pages skip it)
    await import('leaflet/dist/leaflet.css')

    // Rebuild the object to avoid "not extensible" issues when loading old-fashioned leaflet plugins.
    window.L = { ...(await import('leaflet/dist/leaflet-src.esm')) }

    // Use our own ESM gesture handler instead of the npm package which accesses
    // global L at module-evaluation time and breaks bundler optimisations.
    const { registerGestureHandling } = await import(
      '~/composables/gestureHandling'
    )
    registerGestureHandling(window.L)

    window.Wkt = await import('wicket')
    await import('wicket/wicket-leaflet')
  }
}

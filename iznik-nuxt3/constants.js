export const EMAIL_REGEX =
  /^(([^<>()[\]\\.,;:\s@"]+(\.[^<>()[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/

export const MT_EMAIL_REGEX =
  /(([^<>()[\]\\.,;:\s@"]+(\.[^<>()[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))/g

export const URL_REGEX =
  /(https?:\/\/(?:www\.|(?!www))[a-zA-Z0-9][a-zA-Z0-9-]+[a-zA-Z0-9]\.[^\s]{2,}|www\.[a-zA-Z0-9][a-zA-Z0-9-]+[a-zA-Z0-9]\.[^\s]{2,}|https?:\/\/(?:www\.|(?!www))[a-zA-Z0-9]+\.[^\s]{2,}|www\.[a-zA-Z0-9]+\.[^\s]{2,})/g
export const POSTCODE_REGEX =
  /([Gg][Ii][Rr] 0[Aa]{2})|((([A-Za-z][0-9]{1,2})|(([A-Za-z][A-Ha-hJ-Yj-y][0-9]{1,2})|(([A-Za-z][0-9][A-Za-z])|([A-Za-z][A-Ha-hJ-Yj-y][0-9][A-Za-z]?))))\s?[0-9][A-Za-z]{2})/
export const SUBJECT_REGEX = /(.*?):([^)].*)\((.*)\)/

// These are the most common words in UK addresses.
export const ADDRESS_WORDS = [
  'house',
  'flat',
  'road',
  'close',
  'lane',
  'drive',
  'avenue',
  'street',
  'way',
  'court',
  'place',
  'gardens',
  'crescent',
  'park',
  'grove',
  'terrace',
]

export const DAY_WORDS = [
  'mon',
  'tue',
  'wed',
  'thu',
  'fri',
  'sat',
  'sun',
  'monday',
  'tuesday',
  'wednesday',
  'thursday',
  'friday',
  'saturday',
  'sunday',
  'today',
  'tomorrow',
  'this afternoon',
  'this evening',
  'tonight',
]

export const MAX_MAP_ZOOM = 14

export const RECENT_MESSAGES = 31
export const OWN_POSTS_AGE = 120
export const MESSAGE_EXPIRE_TIME = 90
export const GROUP_REPOSTS = { offer: 3, wanted: 14, max: 10, chaseups: 2 }

// Sentinel for settings.browseMaxDistance meaning "no distance limit" - the default for
// the Nearby browse distance slider. A MAXINT sentinel (rather than null) means every
// caller can do a plain numeric comparison against the current limit without a null
// check, and the far-right ("Further") position of the slider stores this rather than
// the feed's current max distance, so the server's own reach limit keeps governing and
// newly-arriving distant posts keep showing.
export const BROWSE_DISTANCE_UNLIMITED = Number.MAX_SAFE_INTEGER

// Colour for the reach/isochrone-style map polygons (the former per-user
// isochrone fill, now reused for the browse "coverage" hull). Kept as a
// constant so the map overlays don't hardcode the hex in several places.
export const ISOCHRONE_COLOR = '#3388cc'

export const TYPING_TIME_INVERVAL = 10000

// Stale-build warning thresholds. When a newer production deploy exists we either
// softly nag (newer deploy older than SOFT, so we don't pester right after a
// release) or, once the bundle we're actually running is older than HARD, escalate
// to a forced auto-reload. HARD_RELOAD_COUNTDOWN_SECS is the grace before that reload.
export const STALE_BUILD_SOFT_MS = 12 * 60 * 60 * 1000 // 12 hours
export const STALE_BUILD_HARD_MS = 7 * 24 * 60 * 60 * 1000 // 1 week
export const HARD_RELOAD_COUNTDOWN_SECS = 20

// The 37 miles figure comes from research from someone we shall call Clement.
export const FAR_AWAY = 37

// Job ad icon background colours
export const JOB_ICON_COLOURS = {
  'dark green': '#2d5016',
  'soft sage green': '#7a9a6d',
}

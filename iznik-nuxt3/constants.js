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

// Tooltips for the freegler chips shown in chat - in the header, in the mobile
// profile card and in the reply-from-browse pane. Last seen and reply time both
// render as a bare duration, so say plainly which is which.
export const LAST_SEEN_TOOLTIP = 'When they were last on Freegle'
export const REPLY_TIME_TOOLTIP =
  'How long they usually take to reply to a message'
export const DISTANCE_TOOLTIP =
  'Roughly how far away they are, as the crow flies rather than by road'

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

// The "How far away" slider is TIME-based: a travel-time budget in MINUTES, matching the reach
// system (drive-time isochrones), not miles. The far-right stop means "no limit" - it stores
// BROWSE_DISTANCE_UNLIMITED, deferring to the server's own reach. The chosen minutes are converted
// to a crow-flies mile radius by real routing (location-aware) and stored as
// settings.browseMaxDistance for the fast distance filter; the minutes themselves are stored as
// settings.browseMaxMinutes so the slider restores.
//
// The TOP of the slider is not fixed: the reach engine sizes a post's budget from how thinly
// freeglers are spread around it (20 minutes dense, 30 medium, 45 sparse), so the slider asks the
// server for the member's own cap (town/near cap_minutes) and uses that. A fixed 5-30 was too short
// in the country, where a member could not ask for the 45 minutes they now receive, and too long in
// the city, where the last stops described travel the reach engine no longer honours.
//   MIN          the closest anyone can ask for.
//   FALLBACK_MAX the flat cap, used until the server answers or when density cannot be measured.
//   MAX          the ceiling across all bands - the widest the slider can ever go.
export const BROWSE_MINUTES_MIN = 5
export const BROWSE_MINUTES_FALLBACK_MAX = 30
export const BROWSE_MINUTES_MAX = 45
export const BROWSE_MINUTES_STEP = 5

// The two axes the "How far away" control drives. They are the SAME question asked in opposite
// directions, so they share the slider, the minutes->miles routing conversion and the towns hint;
// they differ only in which settings keys they write and how far they are allowed to go.
//
//   browse  (INBOUND)  how far away a post may be for ME to see it. Tops out at the member's own
//                      density band cap (town/near cap_minutes), because that is the furthest the
//                      reach engine will admit them to - see useReachDistance.
//   myPosts (OUTBOUND) how far away someone may be and still see MY posts. Tops out at
//                      BROWSE_MINUTES_MAX, the ripple ceiling, because a post's reach grows to the
//                      ceiling whatever band its origin is in (DensityService::ceiling()). Capping
//                      this axis at the member's own band would misreport a city member's real
//                      reach as ~20 minutes when their posts already travel 45.
//
// The outbound keys are ABSENT until the member drags the outbound slider. Absent (and JSON null,
// and <= 0) all mean "linked": every outbound reader falls back to browseMaxDistance, which is
// exactly the behaviour before the split. Re-linking sends the keys as null, which apiv2's
// JSON_MERGE_PATCH deletes.
export const DISTANCE_AXES = {
  browse: {
    minutesKey: 'browseMaxMinutes',
    milesKey: 'browseMaxDistance',
    // Band-capped: loadCap() narrows this to the member's own cap_minutes.
    bandCapped: true,
  },
  myPosts: {
    minutesKey: 'myPostsMaxMinutes',
    milesKey: 'myPostsMaxDistance',
    bandCapped: false,
  },
}

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

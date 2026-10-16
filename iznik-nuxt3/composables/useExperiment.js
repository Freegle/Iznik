/**
 * Experiment assignment for UX telemetry (docs/developers/reference/ux-experiments.md).
 *
 * Assignment is crc32("<id>|<name>") % 100, the same function as
 * iznik-batch/app/Support/ExperimentBucket.php and iznik-server-go/ux/serverarm.go, where <id> is
 * the user id when logged in and the client session id otherwise. An entry may carry a `salt`, and
 * the text is then crc32("<id>|<name>|<salt>"): it makes a re-run of an experiment fall into
 * different arms from the run before. Arm ranges are inclusive.
 *
 * Where the arms come from, in order of precedence:
 *   1. The live config key `ux_experiments`, fetched at boot by plugins/uxExperiments.client.js
 *      from GET /config/ux_experiments. The batch rewrites that key every hour as the running
 *      experiments move traffic, so no deploy is needed to start, re-weight, adopt or revert.
 *        running: {"<name>": {"arms": {"control": [0,34], "b": [35,99]}, "until": <unix seconds>}}
 *        adopted: {"<name>": {"arms": {"b": [0,89], "control": [90,99]}, "adopted": true}}
 *   2. The copy of that key kept in localStorage from the last good fetch (with the time it was
 *      fetched), so a deep link straight into a flow has an arm before the fetch returns.
 *   3. Runtime config public.UX_EXPERIMENTS, empty by default, for a deployment with no batch. This
 *      is the only place the old flat shape {"name": {"control": [0,49], "b": [50,99]}} is honoured.
 *
 * Safety rules, because a stuck variant is a member seeing a half-finished change:
 *   - A running entry is control unless its `until` is in the future and less than 25 hours away.
 *     The batch always writes ends_at + 1 hour, so this is a kill switch the batch cannot forget:
 *     if the batch dies, the variant goes off by itself within a day.
 *   - A copy stored more than 2 hours ago is ignored for running entries.
 *   - At most 5 running entries are honoured (the first 5 by name).
 *   - An adopted entry does not expire: expiring it would flip members back to the old design.
 *
 * The arm is sticky. It is worked out once, at the member's first exposure to the experiment, and
 * kept in localStorage with the entry's `until`. Signing in mid-flow, or the hourly bandit moving
 * the bucket boundaries, therefore does not move a member from one arm to another.
 */
import { useRuntimeConfig } from '#imports'
import { useAuthStore } from '~/stores/auth'
import { getSessionId } from '~/composables/useTrace'
import { action } from '~/composables/useClientLog'
import manifest from '~/ux-experiments.manifest.json'

export const LIVE_STORAGE_KEY = 'freegle_ux_experiments'
export const ARM_STORAGE_PREFIX = 'freegle_ux_arm:'

export const MAX_RUNNING = 5
export const RUNNING_MAX_AHEAD_SECONDS = 25 * 60 * 60
export const STORED_COPY_MAX_AGE_MS = 2 * 60 * 60 * 1000

const NAME = /^[A-Za-z0-9_-]{1,64}$/
const ARM = /^[A-Za-z0-9_-]{1,32}$/
// The salt of an entry, when it has one. Server code reads the same pattern; an entry whose salt is
// present and does not match is dropped in all three places, so no runtime guesses at a bucket.
const SALT = /^[A-Za-z0-9_-]{1,16}$/
// Names that would be keys on a plain object in surprising ways.
const BLOCKED_NAMES = new Set(['__proto__', 'constructor', 'prototype'])

let table = null
// {entries, fetchedAt, fromStorage} once a config has been installed or loaded, else null.
let live = null
// Arms already worked out in this page view, for a browser with no usable localStorage.
const memorySticky = new Map()
// Names of useExperiment() calls made in this page view.
const declared = new Set()
// Names an exposure has been sent for in this page view.
const exposed = new Set()

function crcTable() {
  if (table) return table
  table = new Uint32Array(256)
  for (let n = 0; n < 256; n++) {
    let c = n
    for (let k = 0; k < 8; k++) {
      c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
    }
    table[n] = c >>> 0
  }
  return table
}

/**
 * CRC32 of the UTF-8 bytes of a string, as an unsigned integer. Identical to PHP's crc32().
 */
export function crc32(str) {
  const t = crcTable()
  const bytes = new TextEncoder().encode(str)
  let crc = 0xffffffff
  for (const b of bytes) {
    crc = t[(crc ^ b) & 0xff] ^ (crc >>> 8)
  }
  return (crc ^ 0xffffffff) >>> 0
}

export function bucket(id, name, salt = '') {
  return crc32(id + '|' + name + (salt ? '|' + salt : '')) % 100
}

function parse(raw) {
  try {
    if (typeof raw === 'string') {
      raw = JSON.parse(raw || '{}')
    }
    return raw && typeof raw === 'object' && !Array.isArray(raw) ? raw : {}
  } catch {
    return {}
  }
}

function validRange(range) {
  return (
    Array.isArray(range) &&
    range.length === 2 &&
    Number.isInteger(range[0]) &&
    Number.isInteger(range[1]) &&
    range[0] >= 0 &&
    range[1] <= 99 &&
    range[0] <= range[1]
  )
}

// A range written as from > to is how an arm with no share would look. It holds nobody, so it is
// left out; it must not cost the whole entry, which would send every member to control and stop
// both arms collecting data.
function emptyRange(range) {
  return (
    Array.isArray(range) &&
    range.length === 2 &&
    Number.isInteger(range[0]) &&
    Number.isInteger(range[1]) &&
    range[0] >= 0 &&
    range[0] <= 99 &&
    range[1] >= -1 &&
    range[1] <= 99 &&
    range[0] > range[1]
  )
}

function validArms(arms) {
  if (!arms || typeof arms !== 'object' || Array.isArray(arms)) return null
  const keys = Object.keys(arms)
  if (!keys.length || keys.length > 8) return null
  const out = {}
  for (const key of keys) {
    if (!ARM.test(key)) return null
    if (emptyRange(arms[key])) continue
    if (!validRange(arms[key])) return null
    out[key] = [arms[key][0], arms[key][1]]
  }
  return Object.keys(out).length ? out : null
}

/**
 * Turn a config value into {name: {arms, until, adopted, salt}}, dropping anything malformed. An
 * entry that is not understood is left out, which is control: the safe reading. `salt` is '' when
 * the entry has none. `allowFlat` accepts the old shape (arms directly under the name), for the
 * local-testing runtime config only.
 */
export function normaliseConfig(raw, allowFlat = false) {
  const source = parse(raw)
  // No prototype, so a lookup of a name like "constructor" finds only what the config put there.
  const entries = Object.create(null)
  for (const name of Object.keys(source)) {
    if (!NAME.test(name) || BLOCKED_NAMES.has(name)) continue
    const value = source[name]
    if (!value || typeof value !== 'object' || Array.isArray(value)) continue

    if (value.arms !== undefined) {
      const arms = validArms(value.arms)
      if (!arms) continue
      // A salt that is present (not undefined or null) must be a well formed string.
      const salted = value.salt !== undefined && value.salt !== null
      if (salted && !(typeof value.salt === 'string' && SALT.test(value.salt)))
        continue
      const until = Number(value.until)
      entries[name] = {
        arms,
        until: Number.isFinite(until) ? until : null,
        adopted: value.adopted === true,
        flat: false,
        salt: salted ? value.salt : '',
      }
    } else if (allowFlat) {
      const arms = validArms(value)
      if (arms)
        entries[name] = {
          arms,
          until: null,
          adopted: false,
          flat: true,
          salt: '',
        }
    }
  }
  return entries
}

function envEntries() {
  try {
    return normaliseConfig(useRuntimeConfig()?.public?.UX_EXPERIMENTS, true)
  } catch {
    return {}
  }
}

function now() {
  return Date.now()
}

function storedLive() {
  try {
    if (typeof localStorage === 'undefined') return null
    const raw = localStorage.getItem(LIVE_STORAGE_KEY)
    if (raw === null) return null
    const stored = parse(raw)
    // A copy from before arms carried an expiry has no fetch time; it cannot be trusted.
    if (!Number.isFinite(stored.fetchedAt) || !stored.config) return null
    return {
      entries: normaliseConfig(stored.config),
      fetchedAt: stored.fetchedAt,
      fromStorage: true,
    }
  } catch {
    return null
  }
}

/**
 * Install the live arms (the value of the ux_experiments config key, a JSON string or object) and
 * remember them, with the time, for the next visit. Called by the boot plugin after a good fetch;
 * tests call it directly.
 */
export function setLiveExperiments(raw) {
  const config = parse(raw)
  live = {
    entries: normaliseConfig(config),
    fetchedAt: now(),
    fromStorage: false,
  }
  try {
    if (typeof localStorage !== 'undefined') {
      localStorage.setItem(
        LIVE_STORAGE_KEY,
        JSON.stringify({ fetchedAt: live.fetchedAt, config })
      )
    }
  } catch {
    // Storage full or blocked: the in-memory copy still serves this visit.
  }
  return live.entries
}

/** Forget the live arms and everything worked out in this page view (tests). */
export function resetLiveExperiments() {
  live = null
  memorySticky.clear()
  declared.clear()
  exposed.clear()
}

/**
 * The entries in force right now, after the expiry rules. Worked out on every call because time
 * passes: a tab left open past an entry's `until` stops serving the variant by itself.
 */
export function activeEntries() {
  if (!live) live = storedLive()

  const nowMs = now()
  let entries
  if (live) {
    entries = live.entries
  } else {
    // Nothing fetched and nothing stored: the build-time value, which has no expiry.
    entries = envEntries()
  }

  const out = Object.create(null)
  const running = []
  for (const name of Object.keys(entries).sort()) {
    const e = entries[name]
    if (e.adopted) {
      out[name] = e
      continue
    }
    if (e.flat) {
      running.push(name)
      continue
    }
    if (live?.fromStorage && nowMs - live.fetchedAt > STORED_COPY_MAX_AGE_MS) {
      continue
    }
    const nowS = nowMs / 1000
    if (e.until === null || e.until <= nowS) continue
    if (e.until > nowS + RUNNING_MAX_AHEAD_SECONDS) continue
    running.push(name)
  }
  for (const name of running.slice(0, MAX_RUNNING)) out[name] = entries[name]
  return out
}

function assignmentId() {
  try {
    const id = useAuthStore()?.user?.id
    if (id) return String(id)
  } catch {
    // No store: fall back to the session.
  }
  return getSessionId()
}

function armFromRanges(name, ranges, id, salt = '') {
  const b = bucket(id, name, salt)
  for (const [arm, range] of Object.entries(ranges)) {
    if (b >= range[0] && b <= range[1]) return arm
  }
  return null
}

function readSticky(name) {
  try {
    if (typeof localStorage !== 'undefined') {
      const raw = localStorage.getItem(ARM_STORAGE_PREFIX + name)
      if (raw !== null) {
        const st = parse(raw)
        if (typeof st.arm === 'string' && Number.isFinite(st.until)) return st
      }
    }
  } catch {
    // Fall through to the in-memory copy.
  }
  return memorySticky.get(name) || null
}

function writeSticky(name, value) {
  memorySticky.set(name, value)
  try {
    if (typeof localStorage !== 'undefined') {
      localStorage.setItem(ARM_STORAGE_PREFIX + name, JSON.stringify(value))
    }
  } catch {
    // The in-memory copy holds for this page view.
  }
}

function dropSticky(name) {
  memorySticky.delete(name)
  try {
    if (typeof localStorage !== 'undefined') {
      localStorage.removeItem(ARM_STORAGE_PREFIX + name)
    }
  } catch {
    // Nothing to do.
  }
}

/**
 * The member's arm for a configured experiment, or null. The first answer is kept (see the top of
 * the file); an adopted experiment is served straight from the config instead.
 */
function resolveArm(name) {
  const entry = activeEntries()[name]
  if (!entry) return null

  // Adopted, and the old flat shape used for local testing, are not sticky: an adopted entry is
  // the same arm for everyone for as long as it is in the config.
  if (entry.adopted || entry.flat) {
    return armFromRanges(name, entry.arms, assignmentId(), entry.salt)
  }

  const st = readSticky(name)
  if (st) {
    // Same run of the experiment (same expiry), not over, and the arm still exists.
    if (
      st.until === entry.until &&
      st.until > now() / 1000 &&
      entry.arms[st.arm]
    ) {
      return st.arm
    }
    dropSticky(name)
  }

  const arm = armFromRanges(name, entry.arms, assignmentId(), entry.salt)
  if (arm) writeSticky(name, { arm, until: entry.until })
  return arm
}

/**
 * Send the exposure for an experiment: the member's arm was used to decide what to show. Once per
 * page view per experiment, so a component that re-renders does not flood the log.
 */
export function noteExposure(name, arm) {
  if (!arm || exposed.has(name) || typeof window === 'undefined') return
  exposed.add(name)
  try {
    action('experiment_exposure', { experiment: name, arm })
  } catch {
    // Logging must never break the page.
  }
}

/**
 * The member's arm for a configured experiment, or null if it is not configured, has expired or
 * they fall in no arm. Code behind a variant reads `useExperiment('name') === 'b'`; anything else,
 * including null, is control, so a member outside every arm or with no config sees the site as it
 * was.
 *
 * Inside a multi-step flow use flowArm() from useFlowTelemetry instead, so the arm cannot change
 * between the first step and the last.
 */
export function useExperiment(name) {
  declared.add(name)
  const arm = resolveArm(name)
  noteExposure(name, arm)
  return arm
}

// The switches this build contains. The manifest is checked against the source by
// tests/unit/uxExperimentsManifest.spec.js, so it is the build's own list.
const manifestNames = new Set(
  (manifest?.experiments || []).map((e) => e?.name).filter(Boolean)
)

/**
 * Every experiment arm the member is in, as `name:arm` sorted and joined by commas, for the
 * experiments this build contains code for. Empty when there are none. Stamped on every flow event.
 *
 * Only experiments the running bundle declares are stamped. A long-lived tab or an installed app
 * from before the variant was merged fetches the new config too, but has no variant code, so it
 * shows control; stamping it as variant would dilute the variant arm towards zero.
 */
export function experimentVariant() {
  const all = activeEntries()
  const arms = []
  for (const name of Object.keys(all)) {
    if (!declared.has(name) && !manifestNames.has(name)) continue
    const arm = resolveArm(name)
    if (arm) arms.push(`${name}:${arm}`)
  }
  return arms.sort().join(',')
}

/** The arm for one name inside a stamped variant string such as "a:b,c:control", or null. */
export function armInVariant(variant, name) {
  if (!variant) return null
  for (const part of String(variant).split(',')) {
    const i = part.indexOf(':')
    if (i > 0 && part.slice(0, i) === name) return part.slice(i + 1)
  }
  return null
}

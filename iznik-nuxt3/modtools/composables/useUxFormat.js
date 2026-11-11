// Formatting shared by the SysAdmin UX panels. Percentages are always shown
// with the counts behind them so a small base is obvious.
import { flowTitle } from '~/modtools/composables/useUxFlows'

export function pct(n, d) {
  if (!d) return '–'
  return `${(((n || 0) / d) * 100).toFixed(1)}%`
}

export function withCount(n, d) {
  return `${pct(n, d)} (${(n || 0).toLocaleString()}/${(d || 0).toLocaleString()})`
}

export function ms(v) {
  if (v === null || v === undefined) return '–'
  if (v < 1000) return `${Math.round(v)} ms`
  return `${(v / 1000).toFixed(1)} s`
}

export function signed(v) {
  if (v === null || v === undefined || Number.isNaN(v)) return '–'
  const s = Number(v).toFixed(1)
  return v > 0 ? `+${s}` : s
}

export function sumBy(rows, key) {
  return (rows || []).reduce((t, r) => t + (r?.[key] || 0), 0)
}

// Every { flow, ...step } in a funnels response, paired with the same step in
// the previous period (or null when the previous period has no such step).
export function stepsWithPrevious(funnels) {
  const out = []
  for (const f of funnels?.flows || []) {
    const pf = funnels?.previous?.flows?.find((p) => p.flow === f.flow)
    for (const s of f.steps || []) {
      out.push({
        flow: f.flow,
        ...s,
        prev: pf?.steps?.find((p) => p.step === s.step) || null,
      })
    }
  }
  return out
}

// ---- Words the tab prints ----

// What each value of the Device filter is called. The values are what the API takes.
export const DEVICE_LABELS = {
  web: 'Website, all devices',
  app: 'App, iOS and Android',
  mobile: 'Website on a phone',
  tablet: 'Website on a tablet',
  desktop: 'Website on a desktop',
  'app-ios': 'iOS app',
  'app-android': 'Android app',
}

const REGRET_LABELS = {
  withdrawn_24h: 'Post withdrawn within 24 hours',
  duplicate_10m: 'Posted the same thing twice within 10 minutes',
  promise_reneged: 'Promise broken',
  reply_after_taken: 'Replied after the item was taken',
}

// A regret signal as staff read it. A key we do not know prints as it is.
export const regretLabel = (key) => REGRET_LABELS[key] || key

// What each kind of event the data-health check counts is called. The kinds are the event types the
// rollup checks (UxRollupService::EVENT_TYPES).
const HEALTH_EVENT_LABELS = {
  page_view: 'Page views',
  interaction: 'Clicks and scrolls',
  impressions: 'Things shown on screen',
  flow: 'Funnel steps',
  session_start: 'Visits starting',
}

/**
 * A data-health check as staff read it. The batch stores the name of what it counted:
 * `flow_start:give` for the starts of a funnel and `event:interaction` for a kind of event. Neither
 * is for people, and the first says "flow" where staff say "funnel". A name we do not know prints as
 * it is.
 */
export function healthLabel(metric) {
  const name = String(metric ?? '')
  const colon = name.indexOf(':')
  if (colon > 0) {
    const kind = name.slice(0, colon)
    const key = name.slice(colon + 1)
    if (kind === 'flow_start' && key) return `Starts of ${flowTitle(key)}`
    if (kind === 'event' && HEALTH_EVENT_LABELS[key]) {
      return HEALTH_EVENT_LABELS[key]
    }
  }
  return name
}

export const OLD_SERVER_NOTE =
  'The server did not say which filters it used, so these figures may not be filtered.'
const NOTE_DEVICE = 'Not filtered by device. Shows every device.'
const NOTE_SIGNED =
  'Not filtered by sign-in. Shows signed-in and signed-out people together.'
const NOTE_BOTH = 'Not filtered by device or sign-in. Shows everyone.'
const NOTE_OUTCOMES_SIGNED =
  'Started and finished count everyone. Signed in changes the steps only.'

/**
 * Plain sentences about what the server did with the filters we asked for. `data` is the slot's
 * answer, `requested` the params it was asked with ({ device?, signed_in? } among them). The server
 * says what it used (`filters`) and what it left alone (`ignored`); a server without those keys is
 * an old one, and its figures must not be passed off as filtered. Nothing asked gives no notes.
 */
export function filterNotes(slotName, data, requested = {}) {
  if (!data) return []
  const asked = ['device', 'signed_in'].filter((name) => requested?.[name])
  if (!asked.length) return []

  const echoed = data.filters
  const ignored = (data.ignored || []).filter((name) => asked.includes(name))
  // Asked for, not left alone, and not echoed back as asked: nobody has said it was applied.
  const unconfirmed = asked.some(
    (name) => !ignored.includes(name) && echoed?.[name] !== requested[name]
  )

  const notes = []
  if (unconfirmed) notes.push(OLD_SERVER_NOTE)
  if (ignored.length === 2) {
    notes.push(NOTE_BOTH)
  } else if (ignored[0] === 'device') {
    notes.push(NOTE_DEVICE)
  } else if (ignored[0] === 'signed_in') {
    notes.push(slotName === 'outcomes' ? NOTE_OUTCOMES_SIGNED : NOTE_SIGNED)
  }
  return notes
}

const MONTHS = [
  'Jan',
  'Feb',
  'Mar',
  'Apr',
  'May',
  'Jun',
  'Jul',
  'Aug',
  'Sep',
  'Oct',
  'Nov',
  'Dec',
]

function parts(iso) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '')
  return m ? { y: Number(m[1]), m: Number(m[2]), d: Number(m[3]) } : null
}

// "8 Oct to 14 Oct 2026". The year is written once, at the end, unless the dates are in different
// years. `yearOf` leaves the year out when it is the one the reader already has.
function rangeText(start, end, yearOf = null) {
  const a = parts(start)
  const b = parts(end)
  if (!a || !b) return ''
  const day = (p) => `${p.d} ${MONTHS[p.m - 1]}`
  if (start === end) {
    return yearOf === b.y ? day(b) : `${day(b)} ${b.y}`
  }
  if (a.y !== b.y) return `${day(a)} ${a.y} to ${day(b)} ${b.y}`
  return yearOf === b.y
    ? `${day(a)} to ${day(b)}`
    : `${day(a)} to ${day(b)} ${b.y}`
}

function daysBetween(start, end) {
  const a = parts(start)
  const b = parts(end)
  return Math.round(
    (Date.UTC(b.y, b.m - 1, b.d) - Date.UTC(a.y, a.m - 1, a.d)) / 86400000 + 1
  )
}

/**
 * "Compared with 1 Oct to 7 Oct." from the dates the server gave for the period before, or an empty
 * string when it did not give any. The year is left out when it is the year of the period itself.
 */
export function comparedWith(data) {
  const before = data?.previous
  const end = parts(data?.end)
  if (!end || !parts(before?.start) || !parts(before?.end)) return ''
  return `Compared with ${rangeText(before.start, before.end, end.y)}.`
}

const PERIOD_TEXT = {
  '7days': 'the last 7 days',
  '30days': 'the last 30 days',
  '90days': 'the last 90 days',
  custom: 'the dates you chose',
}

/**
 * The one line under the filter bar. `data` is an answer that carries the server's dates (`start`,
 * `end`, and `previous.start` and `previous.end` when it has them), or null before anything has
 * loaded. Only the filters the open section uses (`view.uses`) are printed: a Signed in value left
 * in the address is not shown on a section that has no Signed in select.
 */
export function viewSummary(view, data) {
  const out = []
  const start = data?.start || (view.period === 'custom' ? view.from : '')
  const end = data?.end || (view.period === 'custom' ? view.to : '')
  if (parts(start) && parts(end)) {
    const n = daysBetween(start, end)
    out.push(
      `Showing ${rangeText(start, end)} (${n} ${n === 1 ? 'day' : 'days'}, UTC).`
    )
    const compared = comparedWith(data)
    if (compared) out.push(compared)
  } else {
    out.push(`Showing ${PERIOD_TEXT[view.period] || PERIOD_TEXT['7days']}.`)
  }
  const uses = view.uses || []
  if (uses.includes('device') && view.device) {
    out.push(`Device: ${DEVICE_LABELS[view.device] || view.device}.`)
  }
  if (uses.includes('signed') && view.signed) {
    out.push(view.signed === 'in' ? 'Signed in only.' : 'Not signed in only.')
  }
  return out.join(' ')
}

/**
 * Whether a click on a link should be handled by the tab. A click with a modifier key, or a button
 * other than the main one, is the browser's: it opens the link in a new tab or window.
 */
export const isPlainClick = (e) =>
  !(e?.ctrlKey || e?.metaKey || e?.shiftKey || e?.altKey) &&
  (e?.button === undefined || e.button === 0)

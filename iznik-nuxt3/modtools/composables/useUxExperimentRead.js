// The plain words for an experiment on the SysAdmin UX tab, worked out in the browser from what the
// batch stored. The routes send figures, not sentences (docs/developers/reference/ux-experiments.md,
// "Reading the result").
//
// The structured read is the experiment's `result`, for a running experiment and a finished one
// alike. While an experiment runs the hourly tick rewrites `result` as the whole read, a start
// resets it to the start facts (`mde_points` only), and a finished experiment keeps its final read in
// it. So a `result` with no `arms` is no read yet: a draft, an experiment started less than an hour
// ago, and one just started again. `latest_read` is only the paragraph behind "Show the full read".
// It is never used for a figure.
//
// Every function here is pure. Staff words say funnel, never flow.

export const STATUS_WORDS = {
  draft: 'Not started',
  running: 'Running',
  adopted: 'Kept',
  closed: 'Kept, cleaned up',
  reverted: 'Taken off',
  inconclusive: 'Could not tell',
  stopped: 'Stopped',
}

export const KEPT_FOR_NOW = 'Kept for now'
export const NO_READ_YET = 'No count yet. The first one comes within the hour.'

// A chance of being better at or above this reads as ahead, and at or below its mirror as behind.
const AHEAD = 0.8
const BEHIND = 0.2
// A guardrail that is at least this likely to be worse is worth watching.
const WORRYING = 0.5

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

const isNumber = (v) => typeof v === 'number' && Number.isFinite(v)

// ---------------------------------------------------------------------------------------- status

/** The word for a status. An adopted experiment is only kept for now while its holdout lasts. */
export function statusWord(exp) {
  if (exp?.status === 'adopted' && exp.holdout_until) return KEPT_FOR_NOW
  return STATUS_WORDS[exp?.status] || String(exp?.status ?? '')
}

/** Which chip an experiment is counted under: running, draft (not started) or finished. */
export function estateOf(exp) {
  if (exp?.status === 'running') return 'running'
  if (exp?.status === 'draft') return 'draft'
  return 'finished'
}

// ----------------------------------------------------------------------------------- the numbers

const whole = (n) => String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ',')

/** A rate between 0 and 1 as a whole percent. */
const percent = (rate) =>
  isNumber(rate) ? `${Math.round(rate * 100)}%` : 'unknown'

/** A guardrail rate as a percent to one decimal place, without a trailing zero. */
const rateText = (rate) =>
  isNumber(rate) ? `${Number((rate * 100).toFixed(1))}%` : 'unknown'

/**
 * A probability as a whole percent. A day of data is never certain, so one that would round to 100%
 * reads "over 99%" and one that would round to 0% reads "under 1%", as in the batch's own sentences.
 */
function probText(p) {
  const n = Math.round(p * 100)
  if (n >= 100) return 'over 99%'
  if (n <= 0) return 'under 1%'
  return `${n}%`
}

/** Points with a sign and one decimal place: +5.4, -5.0, 0.0. */
export function signedPoints(v) {
  const r = Math.round(v * 10) / 10
  if (r === 0) return '0.0'
  return `${r > 0 ? '+' : ''}${r.toFixed(1)}`
}

/** "1 point" for exactly one and "2.5 points" otherwise; a trailing zero is dropped. */
const pointsText = (n) => {
  const v = Number(Math.abs(n).toFixed(1))
  return `${v} ${v === 1 ? 'point' : 'points'}`
}

/** The text up to the first full stop that ends a sentence. A decimal point does not end one. */
export function firstSentence(text) {
  const t = typeof text === 'string' ? text.trim() : ''
  const m = /^(.*?[.!?])(\s|$)/s.exec(t)
  return m ? m[1] : t
}

// -------------------------------------------------------------------------------------- the read

/**
 * The read for the variant that is most likely to be better, or null when there is no read yet.
 * Rates are fractions between 0 and 1. A guardrail's label is the one the batch stored with it, so a
 * guardrail the batch adds later needs no change here.
 */
export function readOf(exp) {
  const r = exp?.result
  if (!r || typeof r !== 'object' || !r.arms || typeof r.arms !== 'object') {
    return null
  }
  let arm = null
  let best = -1
  for (const [name, p] of Object.entries(r.primary || {})) {
    const pBetter = p?.p_better
    if (isNumber(pBetter) && pBetter > best) {
      best = pBetter
      arm = name
    }
  }
  if (arm === null) return null
  const p = r.primary[arm]
  return {
    arm,
    controlStarts: r.arms.control?.starts ?? 0,
    changeStarts: r.arms[arm]?.starts ?? 0,
    controlRate: isNumber(p.control_rate) ? p.control_rate : null,
    changeRate: isNumber(p.variant_rate) ? p.variant_rate : null,
    diffPoints: isNumber(p.diff_points) ? p.diff_points : null,
    pBetter: p.p_better,
    enough: !!p.enough,
    shareOff: !!p.share_off,
    observedShare: isNumber(p.observed_share) ? p.observed_share : null,
    expectedShare: isNumber(p.expected_share) ? p.expected_share : null,
    guardrails: (Array.isArray(r.guardrails) ? r.guardrails : [])
      .filter((g) => g?.arm === arm)
      .map((g) => ({
        label: String(g.label ?? ''),
        change: g.variant_rate,
        control: g.control_rate,
        pWorse: g.p_worse,
      })),
  }
}

/** Where the main comparison stands. Too early until enough people have started on both sides. */
export function verdictOf(read) {
  if (!read || !read.enough || !isNumber(read.pBetter))
    return 'Too early to tell'
  if (read.pBetter >= AHEAD) return 'Change is ahead'
  if (read.pBetter <= BEHIND) return 'Change is behind'
  return 'No clear difference yet'
}

/** The guardrail that is most likely to be worse, if any is at least half as likely as not. */
export function worryOf(read) {
  let worst = null
  for (const g of read?.guardrails || []) {
    if (isNumber(g.pWorse) && g.pWorse >= WORRYING) {
      if (worst === null || g.pWorse > worst.pWorse) worst = g
    }
  }
  return worst
}

const hasFigures = (read) =>
  !!read &&
  isNumber(read.controlRate) &&
  isNumber(read.changeRate) &&
  isNumber(read.diffPoints)

// "51% finished against 46% (+5.4 points)"
const figuresOf = (read) =>
  `${percent(read.changeRate)} finished against ${percent(read.controlRate)} (${signedPoints(read.diffPoints)} points)`

// ----------------------------------------------------------------------------------- the one line

function runningLine(exp, watch) {
  const read = readOf(exp)
  if (!read) return NO_READ_YET
  let line = 'Too few people so far to say.'
  if (read.enough && hasFigures(read)) {
    let verb = 'No clear difference yet'
    if (read.pBetter >= AHEAD) verb = 'Looking better'
    else if (read.pBetter <= BEHIND) verb = 'Looking worse'
    line = `${verb}: ${figuresOf(read)}.`
  }
  const worry = watch ? worryOf(read) : null
  return worry ? `${line} Watch: ${worry.label}.` : line
}

// Why the batch decided as it did: the first sentence of the decision it stored, or nothing. The
// figures cannot say it. A guardrail can take a change off whatever the main result was, and a change
// can be called inconclusive for four different reasons.
const reasonOf = (exp) => firstSentence(exp?.decision)

function decidedLine(exp) {
  const read = readOf(exp)
  const reason = reasonOf(exp)
  if (!hasFigures(read)) {
    return reason || `${STATUS_WORDS[exp.status]}.`
  }
  if (exp.status === 'reverted') {
    return `Taken off: ${figuresOf(read)}.${reason ? ` ${reason}` : ''}`
  }
  const held = exp.holdout_until ? KEPT_FOR_NOW : 'Kept'
  let line = `${held}: ${figuresOf(read)}.`
  if (exp.holdout_until) {
    line += ` Some members keep the old version until ${dateWord(exp.holdout_until)}.`
  }
  return line
}

/**
 * The one line on a row, for any status. A running experiment with a guardrail that may be worse
 * ends with "Watch: <label>." unless `watch` is false, for a caller that shows the warning itself.
 * An experiment that was taken off, or could not be told apart, gives the reason the batch stored
 * with its decision.
 */
export function lineOf(exp, { watch = true } = {}) {
  switch (exp?.status) {
    case 'draft':
      return 'Not started.'
    case 'running':
      return runningLine(exp, watch)
    case 'stopped':
      return 'Stopped by hand.'
    case 'inconclusive':
      return reasonOf(exp) || 'Could not tell. The change was taken off.'
    case 'closed':
      return 'Kept. The old code has been removed.'
    case 'adopted':
    case 'reverted':
      return decidedLine(exp)
    default:
      return firstSentence(exp?.decision) || `${statusWord(exp)}.`
  }
}

// ------------------------------------------------------------------------------------ the read-out

const capital = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : s)

/**
 * One guardrail as a line: the batch's label with a capital, the rate with and without the change,
 * and whether it may be worse. A line says nothing about being worse when it has no chance to go on.
 */
export function guardrailLine(g) {
  const head = `${capital(g.label)}: ${rateText(g.change)} with the change, ${rateText(g.control)} without.`
  if (!isNumber(g.pWorse)) return head
  return `${head} ${g.pWorse >= WORRYING ? 'It may be worse.' : 'Fine.'}`
}

/**
 * The warning that the share of starts on the change is not the share served, so the two finishing
 * rates cannot be compared yet. Null when the share is as expected.
 */
export function shareWarning(read) {
  if (!read?.shareOff) return null
  const { observedShare: seen, expectedShare: expected } = read
  const known = seen !== null && expected !== null
  let head =
    'The people who started with the change are not the share expected.'
  if (known && seen < expected) {
    head = 'Fewer people started with the change than expected.'
  } else if (known && seen > expected) {
    head = 'More people started with the change than expected.'
  }
  let text = `${head} The rates cannot be compared yet.`
  if (known) {
    text += ` ${Math.round(seen)}% of starts were on the change, and about ${Math.round(expected)}% were expected.`
  }
  return text
}

// That is 6 points more. / That is 5.2 points fewer. / That is no difference we can measure.
function differenceText(diff) {
  if (!isNumber(diff) || Math.abs(diff) < 0.05) {
    return 'no difference we can measure'
  }
  return `${pointsText(diff)} ${diff > 0 ? 'more' : 'fewer'}`
}

/**
 * The sentences of the read-out, for the read from readOf. Any of them can be null: a side with no
 * rate yet has no finished line, and an unread chance has no chance line.
 */
export function readLines(read) {
  if (!read) return null
  return {
    started: `Started: ${whole(read.changeStarts)} with the change, ${whole(read.controlStarts)} without.`,
    finished:
      isNumber(read.controlRate) && isNumber(read.changeRate)
        ? `Finished: ${percent(read.changeRate)} with the change, ${percent(read.controlRate)} without. That is ${differenceText(read.diffPoints)}.`
        : null,
    chance: isNumber(read.pBetter)
      ? `Chance the change is better: ${probText(read.pBetter)}.`
      : null,
    others: read.guardrails.map(guardrailLine),
    share: shareWarning(read),
  }
}

// ------------------------------------------------------------------------------- what it was set up to watch

/**
 * The funnels an experiment was set up to watch besides its own, as keys. Null when it does not say:
 * a draft made on the command line with no list, which takes the batch's default when it starts.
 */
export function watchedFunnels(exp) {
  const touches = exp?.design?.touches
  if (!Array.isArray(touches)) return null
  return touches.filter((f, i) => f !== exp.flow && touches.indexOf(f) === i)
}

// ----------------------------------------------------------------------------------------- the order

// Running first, then not started, then finished.
const GROUP = { running: 0, draft: 1, finished: 2 }

// When each group is ordered by: the start, the day it was made, the decision (else the start, else
// the day it was made). A stored time reads "2026-10-14 10:00:00", so text order is time order.
const WHEN = {
  running: (e) => e.started_at || e.created_at || '',
  draft: (e) => e.created_at || '',
  finished: (e) => e.decided_at || e.started_at || e.created_at || '',
}

/**
 * The experiments in the order the section lists them: running first, then not started (newest
 * first), then finished (newest decision first). Within a group the newest comes first, and the
 * higher id wins a tie. The list given is not changed.
 */
export function orderExperiments(list) {
  return [...(list || [])].sort((a, b) => {
    const ga = estateOf(a)
    const gb = estateOf(b)
    if (ga !== gb) return GROUP[ga] - GROUP[gb]
    const wa = WHEN[ga](a)
    const wb = WHEN[gb](b)
    if (wa !== wb) return wa < wb ? 1 : -1
    return (b.id || 0) - (a.id || 0)
  })
}

// -------------------------------------------------------------------------------------- the holdout

/** Who still has the old version, until when, and what the last daily look found. */
export function holdoutText(exp) {
  let text = `Some members still have the old version until ${dateWord(exp.holdout_until)}, and it is compared with the new one once a day. The change is taken off again if the old version does clearly better on repeated looks.`
  const check = exp.result?.holdout_check
  if (check) {
    const p = isNumber(check.p_better)
      ? `chance the change is better: ${probText(check.p_better)}`
      : 'too few people to say'
    const days = check.days === 1 ? '1 day' : `${check.days} days`
    text += ` Last look, ${days} in: ${p}, ${check.strikes || 0} against so far.`
  } else {
    text += ' The first look is a day after it was kept.'
  }
  return text
}

// ------------------------------------------------------------------------------------ dates, links

const STAMP = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/

/** A stored time (UTC, "2026-10-20 09:00:00") as day and month: "20 Oct". Empty when not a time. */
export function dateWord(s) {
  const m = STAMP.exec(typeof s === 'string' ? s : '')
  if (!m || Number(m[2]) < 1 || Number(m[2]) > 12) return ''
  return `${Number(m[3])} ${MONTHS[Number(m[2]) - 1]}`
}

/** A stored time as "14 Oct 2026, 10:05 UTC". Empty when not a time. */
export function stampWord(s) {
  const m = STAMP.exec(typeof s === 'string' ? s : '')
  const day = dateWord(s)
  return m && day ? `${day} ${m[1]}, ${m[4]}:${m[5]} UTC` : ''
}

/** How long it has run: minutes under an hour, then hours. */
export function durationWord(hours) {
  if (!isNumber(hours)) return ''
  if (hours < 1) {
    const minutes = Math.round(hours * 60)
    return `${minutes} ${minutes === 1 ? 'minute' : 'minutes'}`
  }
  const h = Number(hours.toFixed(1))
  return `${h} ${h === 1 ? 'hour' : 'hours'}`
}

/**
 * An address that is safe to draw as a link: the address when it starts with https://, else nothing.
 * pr_url and cleanup_pr_url come from a command line that can store any text, and a javascript:
 * address would run when a moderator clicked it. Go refuses such an address too.
 */
export function safeLink(url) {
  return typeof url === 'string' && url.startsWith('https://') ? url : ''
}

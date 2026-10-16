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
// An experiment decided on the server (mail, push, server code) is counted from the database by a
// metric source, which sends its own nouns in `result.words` and how many members were exposed and
// counted in `result.detail`. The lines below use those nouns when there are some, and the funnel's
// own words when there are none.
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

// ---------------------------------------------------------------------------------------- channel

// The channel of an experiment decided on the server, named in the funnel column beside the funnel it feeds. Server
// is code that runs on the server, as design.channel says. A change to the site has no channel.
export const CHANNEL_WORDS = { email: 'Email', push: 'Push', server: 'Server' }

/** Email, Push or Server for an experiment decided on the server, and empty for a change to the site. */
export function channelWord(exp) {
  return CHANNEL_WORDS[exp?.design?.channel] || ''
}

/**
 * The slot an experiment holds while it runs, worked out as the batch does it
 * (UxExperimentService::slotOf): one running experiment per slot. Its own slot if it has one, else
 * the channel and type, else its funnel.
 */
export function slotOf(exp) {
  const d = exp?.design || {}
  if (d.slot) return String(d.slot)
  if (d.type) return `${d.channel || 'web'}:${d.type}`
  return String(exp?.flow ?? '')
}

/**
 * What a taken slot stands for, as a sentence for staff: "Another email experiment is already running for
 * UnifiedDigestDaily". The tab already uses "slot" for the cap on running experiments, so the word is not used
 * here. Empty when the slot is a funnel, which has its own words, and for an experiment with neither a slot nor
 * a type.
 */
export function sharedSlotWords(exp) {
  const d = exp?.design || {}
  const label = d.slot ? String(d.slot) : ''
  const type = d.type ? String(d.type) : ''
  if (label) return `Another experiment is already running for ${label}`
  if (!type) return ''
  const channel = CHANNEL_WORDS[d.channel]
  return `Another ${channel ? `${channel.toLowerCase()} ` : ''}experiment is already running for ${type}`
}

// ----------------------------------------------------------------------------------- the numbers

const whole = (n) => String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ',')

/** A rate between 0 and 1 as a whole percent. */
const percent = (rate) =>
  isNumber(rate) ? `${Math.round(rate * 100)}%` : 'unknown'

/**
 * A rate of the main comparison. A funnel read from Loki is a whole percent. A rate counted by a metric source is
 * to one decimal place, with the zero kept, because a click rate of 3.0% against 3.4% is a real difference that
 * whole percents would print as "3% against 3%".
 */
const rateOf = (read, rate) =>
  read.words && isNumber(rate) ? `${(rate * 100).toFixed(1)}%` : percent(rate)

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

// The nouns a metric source writes its experiment with, or null for a flow read from Loki.
function wordsOf(r) {
  const w = r?.words
  if (!w || typeof w !== 'object' || Array.isArray(w)) return null
  const text = (k) => (typeof w[k] === 'string' ? w[k].trim() : '')
  const out = {
    who: text('who'),
    finished: text('finished'),
    tabStarted: text('tab_started'),
    tabFinished: text('tab_finished'),
    note: text('note'),
  }
  return out.tabStarted && out.tabFinished ? out : null
}

const lowerFirst = (s) => (s ? s.charAt(0).toLowerCase() + s.slice(1) : s)

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
    words: wordsOf(r),
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

// "51% finished against 46% (+5.4 points)", or "18% clicked within a day against 10% (+8.0 points)"
const figuresOf = (read) =>
  `${rateOf(read, read.changeRate)} ${read.words ? lowerFirst(read.words.tabFinished) : 'finished'} against ${rateOf(read, read.controlRate)} (${signedPoints(read.diffPoints)} points)`

// ----------------------------------------------------------------------------------- the one line

function runningLine(exp, watch) {
  const read = readOf(exp)
  if (!read) return NO_READ_YET
  let line = `Too few ${read.words?.who || 'people'} so far to say.`
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
  // A metric source counts members, not starts, so it names them as it does elsewhere.
  const who = read.words?.who
  let head = who
    ? `The ${who} counted with the change are not the share expected.`
    : 'The people who started with the change are not the share expected.'
  if (known && seen < expected) {
    head = who
      ? `Fewer ${who} were counted with the change than expected.`
      : 'Fewer people started with the change than expected.'
  } else if (known && seen > expected) {
    head = who
      ? `More ${who} were counted with the change than expected.`
      : 'More people started with the change than expected.'
  }
  let text = `${head} The rates cannot be compared yet.`
  if (known) {
    text += who
      ? ` ${Math.round(seen)}% of those counted were on the change, and about ${Math.round(expected)}% were expected.`
      : ` ${Math.round(seen)}% of starts were on the change, and about ${Math.round(expected)}% were expected.`
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
 * rate yet has no finished line, an unread chance has no chance line, and only an experiment counted
 * by a metric source has a note on how it counts.
 */
export function readLines(read) {
  if (!read) return null
  const w = read.words
  return {
    started: `${w ? w.tabStarted : 'Started'}: ${whole(read.changeStarts)} with the change, ${whole(read.controlStarts)} without.`,
    finished:
      isNumber(read.controlRate) && isNumber(read.changeRate)
        ? `${w ? w.tabFinished : 'Finished'}: ${rateOf(read, read.changeRate)} with the change, ${rateOf(read, read.controlRate)} without. That is ${differenceText(read.diffPoints)}.`
        : null,
    note: w?.note || null,
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
 * a draft made on the command line with no list, which takes the batch's default when it starts, and
 * an experiment counted by a metric source, which watches nothing but itself.
 */
export function watchedFunnels(exp) {
  // Counted from the database, nothing else is watched: another funnel's completion cannot be split by
  // a server arm.
  if (exp?.design?.source) return null
  const touches = exp?.design?.touches
  if (!Array.isArray(touches)) return null
  return touches.filter((f, i) => f !== exp.flow && touches.indexOf(f) === i)
}

// ----------------------------------------------------------------------------- a channel experiment

/** A window as the batch writes it: "a day", "7 days", "1 hour" or "N hours". */
export function windowWord(hours) {
  if (!isNumber(hours) || hours < 1) return ''
  if (hours === 24) return 'a day'
  if (hours === 168) return '7 days'
  return `${hours} ${hours === 1 ? 'hour' : 'hours'}`
}

/**
 * What each version shows, as lines: "Control: Your daily digest". Control first, then the others by
 * name. Only the arms the design gave text for.
 */
export function copyLines(exp) {
  const copy = exp?.design?.copy
  if (!copy || typeof copy !== 'object' || Array.isArray(copy)) return []
  return Object.keys(copy)
    .filter((arm) => typeof copy[arm] === 'string' && copy[arm].trim() !== '')
    .sort((a, b) =>
      a === 'control' ? -1 : b === 'control' ? 1 : a.localeCompare(b)
    )
    .map((arm) => `${capital(arm)}: ${copy[arm].trim()}`)
}

// A stored time ("2026-10-20 09:00:00", UTC) a number of hours later, in the same form.
function addHours(stamp, hours) {
  const m = STAMP.exec(typeof stamp === 'string' ? stamp : '')
  if (!m || !isNumber(hours)) return ''
  const t = new Date(
    Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]) + hours * 3600000
  )
  const pad = (n) => String(n).padStart(2, '0')
  return `${t.getUTCFullYear()}-${pad(t.getUTCMonth() + 1)}-${pad(t.getUTCDate())} ${pad(t.getUTCHours())}:${pad(t.getUTCMinutes())}:00`
}

/**
 * How long members are reached and when it is decided: "Served until 21 Oct 2026, 10:00 UTC.
 * Decision on 22 Oct 2026, 10:00 UTC." Empty before it starts. The decision date is the due time, so
 * it is only given while the experiment runs.
 */
export function servingLine(exp) {
  const until = stampWord(addHours(exp?.started_at, exp?.design?.intake_hours))
  if (!until) return ''
  const due = exp.status === 'running' ? stampWord(exp.ends_at) : ''
  return `Served until ${until}.${due ? ` Decision on ${due}.` : ''}`
}

// Arm names with control first, then the others by name.
const armOrder = (names) =>
  [...names].sort((a, b) =>
    a === 'control' ? -1 : b === 'control' ? 1 : a.localeCompare(b)
  )

/**
 * How many members were exposed and how many were counted, from the read's detail. With one change against the
 * old version it reads as the read-out does, the change first: "Exposed: 12,512 with the change, 12,500
 * without." With more than two versions there is no single change, so each is named: "Exposed: control 4,100,
 * b 4,090, c 4,110." Null before the first read. Exposed is every member the experiment has reached so far;
 * counted is those it first reached at least a whole window ago, who have had time to act.
 */
export function exposureLines(exp) {
  const d = exp?.result?.detail
  if (!d || typeof d !== 'object') return null
  const line = (label, by) => {
    if (!by || typeof by !== 'object') return null
    const arms = armOrder(Object.keys(by).filter((a) => isNumber(by[a])))
    if (!arms.length) return null
    if (arms.length === 2 && arms[0] === 'control') {
      return `${label}: ${whole(by[arms[1]])} with the change, ${whole(by.control)} without.`
    }
    return `${label}: ${arms.map((a) => `${a} ${whole(by[a])}`).join(', ')}.`
  }
  const exposed = line('Exposed', d.exposed)
  const counted = line('Counted', d.counted)
  return exposed || counted ? { exposed, counted } : null
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

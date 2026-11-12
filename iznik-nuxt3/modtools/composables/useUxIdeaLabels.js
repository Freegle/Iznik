// The plain words for an idea on the SysAdmin UX tab. The route sends words as keys (a status, a type,
// a source, a category); these turn them into what staff read. Staff words say funnel, never flow.
// Every function here is pure and imports nothing.

// The statuses, in the order the strip shows them.
export const STATUS_ORDER = ['open', 'scheduled', 'running', 'done', 'rejected']

const STATUS_WORDS = {
  open: 'Waiting',
  scheduled: 'Queued',
  running: 'Being tried',
  done: 'Tried',
  rejected: 'Set aside',
}

const TYPE_WORDS = { fix: 'Fix', experiment: 'Experiment' }

// The words for the status of the experiment an idea became. They are the Experiments section's
// words, so the two sections agree about the same experiment: an adopted experiment is "Kept for now"
// while its holdout lasts, which the route says with holdout_until.
const EXPERIMENT_WORDS = {
  draft: 'Not started',
  running: 'Running',
  adopted: 'Kept',
  closed: 'Kept, cleaned up',
  reverted: 'Taken off',
  inconclusive: 'Could not tell',
  stopped: 'Stopped',
}

// The kinds of change the idea generator names, in words. A kind not listed prints as it is.
const CATEGORY_WORDS = {
  fix: 'A defect',
  recovery: 'When something goes wrong',
  copy: 'Wording',
  defaults: 'Defaults',
  steps: 'The steps',
  retire: 'Taking something away',
  disclosure: 'Showing less at first',
  trust: 'Reassurance',
  device: 'One kind of device',
  speed: 'Speed',
  loggedout: 'People not signed in',
  naming: 'Names',
  discover: 'Finding things',
  feature: 'A new feature',
}

export const KEPT_FOR_NOW = 'Kept for now'

// What the number at the start of a row is, said once above the list.
export const SCORE_NOTE =
  'Score is the expected gain, times how sure, times how small the change is. The highest score is listed first.'

export const NO_IDEAS = 'No ideas yet. The idea generator adds them once a day.'
export const NO_IDEAS_HERE = 'No ideas here.'

export const statusWord = (status) =>
  STATUS_WORDS[status] || String(status ?? '')
export const typeWord = (type) => TYPE_WORDS[type] || String(type ?? '')
/** The word for the experiment an idea became: its status, with the holdout of an adopted one. */
export const experimentWord = (experiment) => {
  if (experiment?.status === 'adopted' && experiment.holdout_until) {
    return KEPT_FOR_NOW
  }
  return (
    EXPERIMENT_WORDS[experiment?.status] || String(experiment?.status ?? '')
  )
}
export const categoryWord = (category) =>
  CATEGORY_WORDS[category] || String(category ?? '')

/** Where an idea came from. The idea generator's are `ai`; any other source prints as it is. */
export const ideaSourceLabel = (source) =>
  source === 'ai' ? 'From the idea generator' : String(source ?? '')

// A number, or a string that holds one. null, '' and a word are not numbers.
const isNum = (v) =>
  typeof v === 'number'
    ? Number.isFinite(v)
    : typeof v === 'string' && v.trim() !== '' && Number.isFinite(Number(v))

/** A score as the database keeps it, to two places, with no trailing zeros: 8.25, 4, 1.6. */
export function scoreText(score) {
  return isNum(score) ? String(Number(Number(score).toFixed(2))) : ''
}

/**
 * An expected change in how many finish, which the generator gives in percentage points, as people in
 * every 100 who start: "6 more people finish in every 100 who start". One is singular, a fall says fewer,
 * and no change says so. A gain is shown to one place, with no trailing zero.
 */
export function gainText(points) {
  if (!isNum(points)) return ''
  const n = Number(Math.abs(Number(points)).toFixed(1))
  if (n === 0) return 'No change in how many finish'
  const more = Number(points) > 0 ? 'more' : 'fewer'
  const people = n === 1 ? 'person finishes' : 'people finish'
  return `${n} ${more} ${people} in every 100 who start`
}

/** A share between 0 and 1 as a whole percent, or '' when it is not a number. */
export function percentText(share) {
  return isNum(share) ? `${Math.round(Number(share) * 100)}%` : ''
}

/**
 * The line for one funnel when no funnel is chosen: its name, how many ideas are waiting and the best
 * one, or that none is. `title` is the funnel's long name and `row` is its entry in the answer's flows.
 */
export function funnelLine(title, row) {
  if (!row || !(Number(row.open) > 0)) return `${title}. No ideas waiting.`
  const best = row.best
    ? ` Best: ${row.best.title} (score ${scoreText(row.best.score)})`
    : ''
  return `${title}. ${row.open} waiting.${best}`
}

/** How many files the evidence lists before it says "and N more". */
export const FILES_SHOWN = 5

/**
 * What the idea generator wrote about an idea, as lines of words for the Evidence block. A line is
 * left out when the generator gave nothing for it.
 */
export function evidenceLines(evidence) {
  const e = evidence && typeof evidence === 'object' ? evidence : {}
  const lines = []
  if (e.category) {
    lines.push({
      key: 'category',
      label: 'Kind of change',
      text: categoryWord(e.category),
    })
  }
  if (isNum(e.expected_points)) {
    lines.push({
      key: 'points',
      label: 'Expected gain',
      text: gainText(e.expected_points),
    })
  }
  if (isNum(e.confidence)) {
    lines.push({
      key: 'confidence',
      label: 'How sure',
      text: percentText(e.confidence),
    })
  }
  if (isNum(e.locality)) {
    lines.push({
      key: 'locality',
      label: 'How small the change is',
      text: `${Number(e.locality)} of 5`,
    })
  }
  if (e.risk) lines.push({ key: 'risk', label: 'Risk', text: String(e.risk) })
  return lines
}

/** The files the idea would change: the first few, and how many more there are. */
export function filesOf(evidence) {
  const all = Array.isArray(evidence?.files)
    ? evidence.files.filter((f) => typeof f === 'string' && f.trim())
    : []
  return {
    shown: all.slice(0, FILES_SHOWN),
    more: Math.max(0, all.length - FILES_SHOWN),
  }
}

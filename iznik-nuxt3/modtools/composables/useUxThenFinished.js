// Whether second thoughts end in giving up. The Second thoughts and Help panels use this to set the
// visits that showed a sign (went back, changed a field, asked for help) against the visits at the
// same step that did not, by how many of each went on to finish the flow. A visit that finishes
// anyway is much less of a worry than one that gives up.
//
// The figures are separate visits, from the signal rollup (iznik-server-go/ux/rollups.go, Then): a
// visit that went back three times is one. They are not the event counts in the rest of the panel.

import {
  MIN_VISITS_FOR_WORST,
  MIN_VISITS_FILTERED,
} from '~/modtools/composables/useUxFunnel'

// How far below the others, in share of visits, those with the sign must finish before it is called
// giving up. Under this the difference is too small to read anything into.
export const GAP = 0.1

// Visits that finished at least this share are called mostly finishing.
export const MOSTLY = 0.5

const num = (v) => Number(v) || 0

/** How many visits each side needs. A filter thins the data, so it needs more. */
export function minPeople(filtered) {
  return filtered ? MIN_VISITS_FILTERED : MIN_VISITS_FOR_WORST
}

/**
 * Sets the visits with the sign against the others at the step.
 *   signal  { people, finished } for the visits with the sign, or null when none had it
 *   base    { people, finished } for every visit that entered the step, or null when not rolled up
 * Returns null when the server did not send the figures (an older server, or a period the rollup has
 * not covered), so the caller leaves the columns out. Otherwise
 *   { state, people, finished, share, others, othersFinished, othersShare, gap }
 * where state is
 *   'few'      one side has too few visits to compare
 *   'gave-up'  those with the sign finished at least GAP less often than the others
 *   'finished' they finished about as often, and most of them finished
 *   'similar'  they finished about as often as the others, and fewer than half did
 * `gap` is the others' share less theirs, so a positive gap is harm. `lost` is how many more of them
 * stopped than would have if they had finished as often as the others.
 */
export function compareFinish(
  signal,
  base,
  { min = MIN_VISITS_FOR_WORST } = {}
) {
  if (!base || !num(base.people)) return null

  const people = num(signal?.people)
  const finished = Math.min(num(signal?.finished), people)
  const others = Math.max(num(base.people) - people, 0)
  const othersFinished = Math.min(
    Math.max(num(base.finished) - finished, 0),
    others
  )
  const share = people ? finished / people : null
  const othersShare = others ? othersFinished / others : null

  const out = {
    people,
    finished,
    share,
    others,
    othersFinished,
    othersShare,
    gap: null,
    lost: 0,
    state: 'few',
  }
  if (people < min || others < min) return out

  out.gap = othersShare - share
  out.lost = Math.max(Math.round(out.gap * people), 0)
  if (out.gap >= GAP) out.state = 'gave-up'
  else if (share >= MOSTLY) out.state = 'finished'
  else out.state = 'similar'
  return out
}

/** A share as a whole percent, for a sentence: 0.3 is "30%". */
export function pc(v) {
  return `${Math.round(v * 100)}%`
}

/** The table cell: the share and its count, or a dash with nobody. */
export function finishedCell(c) {
  if (!c || !c.people) return '–'
  return `${pc(c.share)} (${c.finished.toLocaleString()}/${c.people.toLocaleString()})`
}

export function othersCell(c) {
  if (!c || !c.others) return '–'
  return `${pc(c.othersShare)} (${c.othersFinished.toLocaleString()}/${c.others.toLocaleString()})`
}

const VERDICTS = {
  'gave-up': 'Many gave up',
  finished: 'Most still finished',
  similar: 'No worse than the rest',
  few: 'Too few to say',
}

/** The words for the Verdict column. Empty with no figures at all. */
export function verdictCell(c) {
  if (!c) return ''
  if (!c.people) return ''
  return VERDICTS[c.state]
}

/**
 * Rows with a verdict, the ones who gave up first and the worst of those first, by how many more
 * stopped than the others would have. The rest keep their order. Each row is { c, ... } where c is a
 * compareFinish result; rows without one (c null) count as the rest.
 */
export function harmFirst(rows) {
  const indexed = rows.map((r, i) => ({ r, i }))
  indexed.sort((a, b) => {
    const ah = a.r.c?.state === 'gave-up'
    const bh = b.r.c?.state === 'gave-up'
    if (ah !== bh) return ah ? -1 : 1
    if (ah && bh && a.r.c.lost !== b.r.c.lost) return b.r.c.lost - a.r.c.lost
    return a.i - b.i
  })
  return indexed.map((x) => x.r)
}

/**
 * The sentence for the closed row, from the compared rows: [{ label, verb, c }]. `label` is where
 * ("Details") and `verb` what they did there ("went back"). It names the worst place when some gave
 * up, says it is less of a worry when every place that can be compared mostly finished, and says
 * nothing where there is nothing to compare. `what` is the same in general ("went back"), for the two
 * sentences that are about all the places. Returns '' for nothing to say.
 */
export function flagSentence(rows, what) {
  const cmp = rows.filter((r) => r.c && r.c.state !== 'few' && r.c.people)
  if (!cmp.length) return ''
  const bad = cmp.filter((r) => r.c.state === 'gave-up')
  if (bad.length) {
    const worst = harmFirst(bad)[0]
    return `At ${worst.label}, ${pc(worst.c.share)} of those who ${worst.verb} finished, against ${pc(worst.c.othersShare)} who did not.`
  }
  if (cmp.every((r) => r.c.state === 'finished')) {
    return `Most who ${what} still finished, so this is less of a worry.`
  }
  return `Those who ${what} finished about as often as those who did not.`
}

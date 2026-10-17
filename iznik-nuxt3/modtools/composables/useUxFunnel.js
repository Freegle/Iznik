// Turns the per-step rollup rows of one flow into funnels a person can read: steps in the order
// members meet them, one funnel per route family (the desktop and mobile Give and Ask flows are
// different pages), the loss at each step, and a headline sentence naming the worst step.
// worstStepOf is the only definition of the biggest loss: the headline, the all-funnels table and
// the tag on the chart all take their answer from it.
import { FLOW_ROUTES } from '~/composables/useFlowRoutes'
import { FLOW_TITLES, flowTitle } from '~/modtools/composables/useUxFlows'
import { DEVICE_LABELS, withCount } from '~/modtools/composables/useUxFormat'

export { FLOW_TITLES, flowTitle }

// Flows whose steps are states rather than routes, in the order they happen. An entry that is an
// array is a set of alternatives: members take one of them, not each in turn, so they share a rank
// and are drawn as one stage (see funnelsFor).
const STATE_ORDER = {
  // The order of useReplyStateMachine: the flow starts when the member types, they are only
  // joined to the community and given a chat after sending and, if need be, signing in.
  reply: [
    'COMPOSING',
    'VALIDATING',
    'AUTHENTICATING',
    'JOINING_GROUP',
    'CREATING_CHAT',
    'SHOWING_WELCOME',
    'COMPLETED',
  ],
  login: [['signup', 'signin']],
  chat_promise: ['choose'],
  chat_outcome: ['choose'],
  settings: ['settings'],
}

// Steps that are a way out of the flow rather than a step along it, and what to call the exit.
// Someone who goes to reset their password has not been lost: they are on their way back. They are
// drawn beside the funnel and are never named as the worst step.
export const EXIT_STEPS = {
  login: { forgot: 'Went to reset their password' },
}

// What to call a step on the chart. Route names are for developers; these are for the people
// reading the tab.
const STEP_LABELS = {
  give: 'Item and photos',
  'give-mobile': 'Start',
  'give-mobile-photos': 'Photos',
  'give-clearance': 'Clearance start',
  'give-whereami': 'Location',
  'give-mobile-details': 'Details',
  'give-options': 'Options',
  'give-mobile-options': 'Options',
  'give-whoami': 'Who you are',
  'give-mobile-whereami': 'Location and who you are',
  ask: 'Item and photos',
  'ask-mobile': 'Start',
  'ask-mobile-photos': 'Photos',
  'ask-whereami': 'Location',
  'ask-mobile-details': 'Details',
  'ask-whoami': 'Who you are',
  'ask-mobile-whereami': 'Location and who you are',
  JOINING_GROUP: 'Joining the community',
  CREATING_CHAT: 'Opening the chat',
  COMPOSING: 'Writing the reply',
  VALIDATING: 'Checking the reply',
  AUTHENTICATING: 'Signing in',
  SHOWING_WELCOME: 'Welcome',
  COMPLETED: 'Sent',
  signup: 'Joining',
  signin: 'Signing in',
  forgot: 'Forgotten password',
  choose: 'Choosing',
  settings: 'Settings',
}

export function stepLabel(step) {
  return STEP_LABELS[step] || step
}

// The family a routed step belongs to: 'mobile' for the mobile pages, 'desktop' otherwise. Flows
// driven by states have one family, ''.
function family(flow, step) {
  if (!FLOW_ROUTES[flow]) return ''
  return step.includes('mobile') ? 'mobile' : 'desktop'
}

/**
 * The name of a step where no heading says which page family it is on: the headline, the table of all
 * funnels, the step detail, and Second thoughts and Help, which list the steps of both families in
 * one table. The desktop and the mobile Offering pages both have an Options page, and without the
 * family two rows would read alike. A label shared by a desktop and a mobile page of one funnel gets
 * the family added. The chart keeps the plain label: it has a heading for each family.
 */
export function stepName(flow, step) {
  const label = stepLabel(step)
  const own = family(flow, step)
  const pages = FLOW_ROUTES[flow]
  if (!own || !pages) return label
  const shared = Object.keys(pages).some(
    (other) =>
      other !== step &&
      stepLabel(other) === label &&
      family(flow, other) !== own
  )
  return shared ? `${label} (${own})` : label
}

function rank(flow, step) {
  if (FLOW_ROUTES[flow]) {
    const r = FLOW_ROUTES[flow][step]
    return r === undefined ? 1000 : r
  }
  const order = STATE_ORDER[flow] || []
  const i = order.findIndex((o) =>
    Array.isArray(o) ? o.includes(step) : o === step
  )
  return i === -1 ? 1000 : i + 1
}

const num = (v) => v || 0

function figures(s) {
  return {
    step: s.step,
    label: stepLabel(s.step),
    entered: num(s.entered),
    visits: num(s.visits),
    abandoned: num(s.abandoned),
    backed: num(s.backed),
    completed: num(s.completed),
    lossRate: s.visits ? num(s.abandoned) / s.visits : 0,
  }
}

// Steps that share a rank are different ways into the same stage, not stages in a row. Their
// figures are added up for the stage, and each way in is kept in `alternatives` with its own
// figures, so a reader never sees one drawn as a drop-off from the other.
function stageOf(members, base) {
  if (members.length === 1) {
    const m = figures(members[0])
    return { ...m, reach: base ? Math.min(1, m.entered / base) : 0 }
  }
  const alts = members
    .map(figures)
    .sort((a, b) => b.entered - a.entered)
    .map((m) => ({ ...m, reach: base ? Math.min(1, m.entered / base) : 0 }))
  const sum = (k) => alts.reduce((t, a) => t + a[k], 0)
  const visits = sum('visits')
  const abandoned = sum('abandoned')
  const entered = sum('entered')
  return {
    // The commonest way in stands for the stage when something needs one key.
    step: alts[0].step,
    label: alts.map((a) => a.label).join(' or '),
    entered,
    visits,
    abandoned,
    backed: sum('backed'),
    completed: sum('completed'),
    reach: base ? Math.min(1, entered / base) : 0,
    lossRate: visits ? abandoned / visits : 0,
    alternatives: alts,
  }
}

/**
 * The funnels for one flow: [{ family, steps: [stage], exits: [{ step, label, entered, visits }] }],
 * stages ordered as members meet them. A stage is { step, label, entered, visits, abandoned, backed,
 * completed, reach, lossRate, alternatives? }. `reach` is the share of the first stage's first
 * arrivals that reached this stage, which is what the bar widths show. `lossRate` is abandoned over
 * visits. A stage with `alternatives` is several steps members take instead of one another (the
 * normal Give start and the clearance start; signing in and joining), shown as one stage.
 * Exits (forgotten password) are not stages. Steps nobody entered are left out.
 */
export function funnelsFor(flow, steps) {
  const exitNames = EXIT_STEPS[flow] || {}
  const groups = {}
  const exitGroups = {}
  for (const s of steps || []) {
    if (!s.entered && !s.visits) continue
    if (exitNames[s.step]) {
      exitGroups[''] ??= []
      exitGroups[''].push(s)
      continue
    }
    const f = family(flow, s.step)
    groups[f] ??= []
    groups[f].push(s)
  }
  const out = []
  for (const f of ['', 'desktop', 'mobile']) {
    if (!groups[f]) continue
    // Group by rank; unknown steps (rank 1000) each stand alone rather than being run together.
    const byRank = new Map()
    for (const s of groups[f]) {
      const r = rank(flow, s.step)
      const key = r === 1000 ? `x:${s.step}` : r
      if (!byRank.has(key)) byRank.set(key, { r, members: [] })
      byRank.get(key).members.push(s)
    }
    const slots = [...byRank.values()].sort(
      (a, b) =>
        a.r - b.r ||
        Math.max(...b.members.map((m) => num(m.entered))) -
          Math.max(...a.members.map((m) => num(m.entered)))
    )
    const base = slots[0].members.reduce((t, m) => t + num(m.entered), 0)
    out.push({
      family: f,
      steps: slots.map((slot) => stageOf(slot.members, base)),
      exits: (exitGroups[f] || []).map((s) => ({
        step: s.step,
        label: exitNames[s.step],
        entered: num(s.entered),
        visits: num(s.visits),
      })),
    })
  }
  // A flow with only an exit step still shows it.
  if (!out.length && exitGroups['']) {
    out.push({
      family: '',
      steps: [],
      exits: exitGroups[''].map((s) => ({
        step: s.step,
        label: exitNames[s.step],
        entered: num(s.entered),
        visits: num(s.visits),
      })),
    })
  }
  return out.filter((fn) => fn.steps.length || fn.exits.length)
}

function pct(v) {
  return `${Math.round(v * 100)}%`
}

// A step needs this many visits before it can be called the biggest loss, so a tiny step cannot win
// on noise. A filter thins the data, so it needs more.
export const MIN_VISITS_FOR_WORST = 20
export const MIN_VISITS_FILTERED = 50

/**
 * The biggest loss of one flow: the step with the highest share of its visits that ended there.
 * It works on the raw steps from the API, both page families together, so there is one answer for
 * the flow. Exit steps (forgotten password) are never a loss. A step needs `minVisits` visits and at
 * least one abandon. On a tie the step with more visits wins, then the one earlier in `steps`.
 * Returns the step, or null when none qualifies.
 */
export function worstStepOf(
  steps,
  { flow, minVisits = MIN_VISITS_FOR_WORST } = {}
) {
  const exits = EXIT_STEPS[flow] || {}
  let worst = null
  for (const s of steps || []) {
    const visits = num(s.visits)
    const abandoned = num(s.abandoned)
    if (exits[s.step] || visits < minVisits || !abandoned) continue
    if (!worst) {
      worst = s
      continue
    }
    // abandoned / visits compared by cross-multiplying, so equal shares are exactly equal.
    const here = abandoned * num(worst.visits)
    const best = num(worst.abandoned) * visits
    if (here > best || (here === best && visits > num(worst.visits))) {
      worst = s
    }
  }
  return worst
}

/**
 * Among the steps with enough visits, the one where the biggest share of visits had `key` (went
 * back, asked for help): what the closed rows of Second thoughts and Help name. A step with too few
 * visits cannot win on noise. On a tie the earlier step wins. Returns
 *   { state: 'few' }                 no step has enough visits to say
 *   { state: 'step', step }          the step
 *   { state: 'none', elsewhere }     the steps with enough visits had none; `elsewhere` is true when a
 *                                    step with too few visits had some
 */
export function stepWithMost(
  steps,
  key,
  { minVisits = MIN_VISITS_FOR_WORST } = {}
) {
  let top = null
  let enough = false
  let elsewhere = false
  for (const s of steps || []) {
    const count = num(s[key])
    if (num(s.visits) < minVisits) {
      if (count) elsewhere = true
      continue
    }
    enough = true
    if (!count) continue
    if (!top || count / s.visits > num(top[key]) / top.visits) top = s
  }
  if (!enough) return { state: 'few' }
  if (!top) return { state: 'none', elsewhere }
  return { state: 'step', step: top }
}

/**
 * One flow in a form both the all-funnels table and the headline are built from.
 * `outcome` and `previous` are the flow's rows from the outcomes response, for this period and the
 * one before. `filtered` is true when a filter is set, which raises the visits a step needs.
 * Rates are fractions, or null when there is nothing to divide by. `loss.state` is 'nobody' (no
 * outcome, or nobody started), 'few' (no step has enough visits), 'none' (steps qualify and none
 * lost anybody) or 'step' (`loss` then names it).
 */
export function summariseFlow(
  flow,
  outcome,
  steps,
  previous,
  { filtered = false } = {}
) {
  const started = num(outcome?.started)
  const finished = num(outcome?.completed)
  const out = {
    flow,
    started,
    finished,
    finishedRate: started ? finished / started : null,
    beforeRate: previous?.started
      ? num(previous.completed) / previous.started
      : null,
  }
  if (!started) return { ...out, loss: { state: 'nobody' } }

  const minVisits = filtered ? MIN_VISITS_FILTERED : MIN_VISITS_FOR_WORST
  const exits = EXIT_STEPS[flow] || {}
  const enough = (steps || []).some(
    (s) => !exits[s.step] && num(s.visits) >= minVisits
  )
  if (!enough) return { ...out, loss: { state: 'few' } }

  const worst = worstStepOf(steps, { flow, minVisits })
  if (!worst) return { ...out, loss: { state: 'none' } }
  return {
    ...out,
    loss: {
      state: 'step',
      step: worst.step,
      label: stepName(flow, worst.step),
      lossRate: num(worst.abandoned) / worst.visits,
      visits: worst.visits,
      abandoned: num(worst.abandoned),
    },
  }
}

/**
 * One sentence per flow, from the outcomes (started, completed) and the step with the biggest loss
 * (see worstStepOf), with its worst device. `previous` is the same flow's outcome in the period
 * before, or null. `filtered` has the same meaning as for summariseFlow.
 */
export function headline(
  flow,
  outcome,
  steps,
  previous,
  { filtered = false } = {}
) {
  const sum = summariseFlow(flow, outcome, steps, previous, { filtered })
  if (sum.loss.state === 'nobody') {
    return `${flowTitle(flow)}: nobody started in this period.`
  }
  let text = `${flowTitle(flow)}: ${sum.started.toLocaleString()} started, ${sum.finished.toLocaleString()} finished (${pct(
    sum.finishedRate
  )}`
  if (sum.beforeRate !== null) {
    text += `, before that ${pct(sum.beforeRate)}`
  }
  text += ').'

  if (sum.loss.state === 'few') {
    return `${text} Too few visits to say which step lost most.`
  }
  if (sum.loss.state === 'none') {
    return `${text} No step lost many people.`
  }
  const { label, lossRate, visits } = sum.loss
  text += ` Biggest loss: ${pct(lossRate)} of the ${visits.toLocaleString()} visits to ${label} ended there`
  const worst = worstStepOf(steps, {
    flow,
    minVisits: filtered ? MIN_VISITS_FILTERED : MIN_VISITS_FOR_WORST,
  })
  const byDevice = (worst?.by?.device_class || []).filter(
    (d) => d.key && (d.visits || 0) >= 20
  )
  if (byDevice.length > 1) {
    const w = byDevice.reduce((a, b) =>
      (b.abandoned || 0) / b.visits > (a.abandoned || 0) / a.visits ? b : a
    )
    text += `, worst for ${deviceLabel(w.key)} (${pct(w.abandoned / w.visits)})`
  }
  return text + '.'
}

/**
 * The outcome figures of one flow as a sentence, in the forms the old Funnels panel used: a percent
 * and a count over its base. Returns null when there is no row or nobody started, since there is
 * nothing to divide by.
 */
export function outcomeLine(outcome) {
  const started = num(outcome?.started)
  if (!started) return null
  const submitted = num(outcome.submitted)
  const failed = num(outcome.submit_failed)
  const completed = num(outcome.completed)
  const abandoned = num(outcome.abandoned)
  const resumed = num(outcome.resumed)

  const parts = []
  if (submitted) {
    parts.push(`Submitted ${withCount(submitted, started)}.`)
    parts.push(
      failed
        ? `${withCount(failed, submitted)} of those failed.`
        : 'None of those failed.'
    )
  } else {
    parts.push('Nobody submitted.')
  }
  parts.push(
    completed
      ? `Finished ${withCount(completed, started)}.`
      : 'Nobody finished.'
  )
  parts.push(
    abandoned
      ? `${withCount(abandoned, started)} left without finishing.`
      : 'Nobody left without finishing.'
  )
  parts.push(
    resumed
      ? `${resumed.toLocaleString()} came back later.`
      : 'Nobody came back later.'
  )
  return parts.join(' ')
}

// A device as the Device filter names it, so the select, the headline and the table read alike.
export function deviceLabel(key) {
  return DEVICE_LABELS[key] || key
}

export function familyLabel(f) {
  return { desktop: 'Desktop pages', mobile: 'Mobile pages' }[f] || ''
}

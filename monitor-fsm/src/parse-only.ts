/**
 * PARSE_ONLY=1 runs the Discourse/Sentry analysis half of an iteration and must stop
 * before the FSM does anything that changes the world: no fix delegates, no coverage PR,
 * no wrap-up. The decision lives here, as plain functions, so the driver can ask it at two
 * points and the tests can exercise it without booting the engine.
 *
 * Two places need it in the step loop:
 *  - BEFORE a step runs, on the state the engine is sitting in. Tool nodes (WORK_ROUTER,
 *    CI_ROUTER, ...) execute and `continue` past everything below them, so a WORK_ROUTER
 *    that routed to PARALLEL_FIX_BUGS handed the next step a fix state with nothing in the
 *    way. On 2026-09-12 a scan-only run launched five fix delegates that way.
 *  - AFTER an LLM step, on the state it moved to plus any `_transition` its actions
 *    proposed, so a stop fires as early as the intent is visible.
 */

/** States a PARSE_ONLY run is allowed to execute. Everything else is a stop. */
export const PARSE_ONLY_ANALYSIS_STATES: ReadonlySet<string> = new Set([
  'LOAD_STATE',
  'CHECK_CI',
  'CI_ROUTER',
  'PARALLEL_ANALYZE_AND_FIX',
  'COLLATE_RESULTS',
  'WORK_ROUTER',
])

/**
 * Master red is not a reason to stop parsing: the run should skip the fix and go straight
 * to the Discourse analysis. The driver force-transitions when this is set.
 */
export const PARSE_ONLY_BYPASS_STATE = 'FIX_MASTER_CI'
export const PARSE_ONLY_BYPASS_TARGET = 'PARALLEL_ANALYZE_AND_FIX'

export type ParseOnlyDecision =
  | { action: 'continue' }
  | { action: 'bypass'; from: string; to: string }
  | { action: 'stop'; offender: string }

/**
 * Decide what a PARSE_ONLY run does given the states in play: the current state first,
 * then any transitions the last step proposed. Bypass wins over stop so that a red master
 * never ends the scan, and the first non-analysis state is reported as the offender.
 */
export function parseOnlyDecision(candidateStates: readonly string[]): ParseOnlyDecision {
  const states = candidateStates.filter((s): s is string => typeof s === 'string' && s.length > 0)
  if (states.includes(PARSE_ONLY_BYPASS_STATE)) {
    return { action: 'bypass', from: PARSE_ONLY_BYPASS_STATE, to: PARSE_ONLY_BYPASS_TARGET }
  }
  const offender = states.find(s => !PARSE_ONLY_ANALYSIS_STATES.has(s))
  return offender ? { action: 'stop', offender } : { action: 'continue' }
}

/** The `_transition` targets a step's executed actions proposed, in order. */
export function proposedTransitions(actionsExecuted: ReadonlyArray<{ result?: any } | undefined>): string[] {
  return actionsExecuted
    .map(a => a?.result?._transition)
    .filter((t: any): t is string => typeof t === 'string' && t.length > 0)
}

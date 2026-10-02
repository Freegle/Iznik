import { describe, it, expect } from 'vitest'
import {
  PARSE_ONLY_ANALYSIS_STATES,
  parseOnlyDecision,
  proposedTransitions,
} from '../parse-only.js'

describe('parseOnlyDecision', () => {
  it('lets every analysis state run', () => {
    for (const s of PARSE_ONLY_ANALYSIS_STATES) {
      expect(parseOnlyDecision([s])).toEqual({ action: 'continue' })
    }
  })

  // 2026-09-12: WORK_ROUTER (a tool node) transitioned to PARALLEL_FIX_BUGS and the next
  // step ran it, launching five fix delegates in a scan-only run. Judged on the current
  // state alone, before anything executes, that state is a stop.
  it('stops on a fix state that is the CURRENT state, with no proposed transition', () => {
    expect(parseOnlyDecision(['PARALLEL_FIX_BUGS'])).toEqual({
      action: 'stop',
      offender: 'PARALLEL_FIX_BUGS',
    })
  })

  it('stops when an analysis state proposes a transition into a fixing state', () => {
    expect(parseOnlyDecision(['WORK_ROUTER', 'PARALLEL_FIX_BUGS'])).toEqual({
      action: 'stop',
      offender: 'PARALLEL_FIX_BUGS',
    })
  })

  it('stops before coverage and wrap-up too', () => {
    for (const s of ['COVERAGE_GATE', 'WRITE_COVERAGE', 'WRAP_UP', 'DIAGNOSE_BUG', 'FIX_SENTRY_ISSUE']) {
      expect(parseOnlyDecision([s]).action).toBe('stop')
    }
  })

  it('bypasses FIX_MASTER_CI rather than stopping, and bypass beats stop', () => {
    expect(parseOnlyDecision(['CI_ROUTER', 'FIX_MASTER_CI'])).toEqual({
      action: 'bypass',
      from: 'FIX_MASTER_CI',
      to: 'PARALLEL_ANALYZE_AND_FIX',
    })
    expect(parseOnlyDecision(['FIX_MASTER_CI', 'PARALLEL_FIX_BUGS']).action).toBe('bypass')
  })

  it('ignores empty and non-string candidates', () => {
    expect(parseOnlyDecision(['LOAD_STATE', '', undefined as any, null as any])).toEqual({ action: 'continue' })
  })
})

describe('proposedTransitions', () => {
  it('collects _transition targets from executed actions, in order, skipping the rest', () => {
    expect(
      proposedTransitions([
        { result: { _transition: 'PARALLEL_FIX_BUGS' } },
        { result: { ok: true } },
        undefined,
        { result: { _transition: '' } },
        { result: { _transition: 'COVERAGE_GATE' } },
      ])
    ).toEqual(['PARALLEL_FIX_BUGS', 'COVERAGE_GATE'])
  })
})

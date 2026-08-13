import { describe, it, expect } from 'vitest'
import workflow from '../../workflow.json'

// The coverage delegate walked into the same trap the PR-fix delegate was warned about, and
// the warning had never been copied across.
//
// 2026-08-13, twice in a row: it wrote tests, started the Laravel suite through the status
// API, then ended its turn with "waiting for background poll ... to report completion".
// A `claude -p` one-shot has no next turn, so the run was abandoned - logged as
// "exited 0 but no marker - 71 tools, no PR (24m19s)". Two iterations, 95 minutes, 0 PRs.
//
// PARALLEL_ANALYZE_AND_FIX has carried this warning since a delegate did the identical thing
// there; workflow.content.test.ts pins it. These pin the same rules on WRITE_COVERAGE, so the
// two briefs cannot drift apart again.

describe('WRITE_COVERAGE prompt — the coverage delegate gets one turn too', () => {
  const prompt: string = (workflow as any).states.WRITE_COVERAGE.prompt

  it('tells the delegate it has exactly one turn', () => {
    expect(prompt).toContain('YOU GET EXACTLY ONE TURN')
  })

  it('forbids ending the turn while a test run is pending', () => {
    expect(prompt).toContain('never end your turn while anything is pending')
  })

  it('requires blocking on the suite within the same turn', () => {
    expect(prompt).toContain('block until it finishes inside this same turn')
  })

  it('says a run that pushes nothing is a wasted iteration', () => {
    expect(prompt).toContain('PUSH BEFORE YOU FINISH')
  })

  it('warns against running suites it does not need', () => {
    // Ten minutes per suite is most of the iteration's budget, and a test-only change
    // needs only the one suite covering the files touched.
    expect(prompt).toMatch(/Do not run suites you do not need/i)
  })
})

describe('the two delegate briefs carry the same one-shot rules', () => {
  const fix: string = (workflow as any).states.PARALLEL_ANALYZE_AND_FIX.prompt
  const coverage: string = (workflow as any).states.WRITE_COVERAGE.prompt

  // Any brief that dispatches a one-shot delegate needs these. Listing them here means a new
  // brief copied from the wrong template fails this suite rather than silently burning
  // iterations in production.
  const RULES = [
    'YOU GET EXACTLY ONE TURN',
    'never end your turn while anything is pending',
    'block until it finishes inside this same turn',
  ]

  it.each(RULES)('both briefs contain %s', (rule) => {
    expect(fix).toContain(rule)
    expect(coverage).toContain(rule)
  })
})

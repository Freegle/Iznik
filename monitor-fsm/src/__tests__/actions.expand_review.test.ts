import { describe, it, expect } from 'vitest'
import {
  classifyReviewBlockers,
  decideReviewAction,
  buildFixExpansionBrief,
} from '../actions/index'

/**
 * "Expand fixes, don't close them." When the adversarial review finds a fix is on the
 * right track but incomplete (partial implementation, a sibling call site with the same
 * bug, a missing reproduction test), the FSM should push a follow-up commit on the SAME
 * branch to finish it — not close the PR and re-dispatch from scratch, which just churns
 * out the same partial fix (the #1164 / #1167 / #1171 pattern). It still closes on a
 * terminal blocker (security / wrong approach) or when expansion can't clear it.
 */

describe('classifyReviewBlockers', () => {
  it('treats partial-implementation and sibling-call-site findings as completable', () => {
    const { completable, terminal, allCompletable } = classifyReviewBlockers([
      { category: 'partial-implementation', description: 'root cause left in sibling handler' },
      { category: 'other call sites', description: 'addMemberToGroup has the same fake-success' },
    ])
    expect(terminal).toHaveLength(0)
    expect(completable).toHaveLength(2)
    expect(allCompletable).toBe(true)
  })

  it('treats a missing/weak reproduction test as completable', () => {
    const { allCompletable } = classifyReviewBlockers([
      { category: 'test proves nothing', description: 'test only asserts status 200' },
    ])
    expect(allCompletable).toBe(true)
  })

  it('treats security and wrong-approach findings as terminal', () => {
    const { terminal, allCompletable } = classifyReviewBlockers([
      { category: 'security regression', description: 'SQL injection via unescaped input' },
    ])
    expect(terminal).toHaveLength(1)
    expect(allCompletable).toBe(false)
  })

  it('is terminal if ANY blocker is terminal, even mixed with completable ones', () => {
    const { completable, terminal, allCompletable } = classifyReviewBlockers([
      { category: 'partial-implementation', description: 'misses one branch' },
      { category: 'security', description: 'auth bypass' },
    ])
    expect(completable).toHaveLength(1)
    expect(terminal).toHaveLength(1)
    expect(allCompletable).toBe(false)
  })

  it('defaults an unrecognised category to completable (expand by default)', () => {
    const { allCompletable } = classifyReviewBlockers([
      { category: 'something-new', description: 'a finding we have no pattern for' },
    ])
    expect(allCompletable).toBe(true)
  })

  it('reports allCompletable=false when there are no blockers at all', () => {
    // Nothing to expand — a passed review is handled by decideReviewAction, not here.
    expect(classifyReviewBlockers([]).allCompletable).toBe(false)
  })
})

describe('decideReviewAction', () => {
  it('passes through a review with no blockers', () => {
    expect(decideReviewAction({ passed: true, blockers: [] }).action).toBe('pass')
  })

  it('expands when all blockers are completable and the budget is untouched', () => {
    const d = decideReviewAction({
      passed: false,
      blockers: [{ category: 'partial-implementation', description: 'sibling handler unfixed' }],
    })
    expect(d.action).toBe('expand')
  })

  it('closes when a terminal blocker is present', () => {
    const d = decideReviewAction({
      passed: false,
      blockers: [{ category: 'security regression', description: 'path traversal' }],
    })
    expect(d.action).toBe('close')
  })

  it('closes once the expansion budget is exhausted (no infinite loop)', () => {
    const d = decideReviewAction({
      passed: false,
      blockers: [{ category: 'partial-implementation', description: 'still incomplete' }],
      expansionAttempts: 1,
      maxExpansions: 1,
    })
    expect(d.action).toBe('close')
  })

  it('closes a failed review that somehow has no blockers to act on', () => {
    const d = decideReviewAction({ passed: false, blockers: [] })
    expect(d.action).toBe('close')
  })
})

describe('buildFixExpansionBrief', () => {
  it('tells the delegate to complete the fix on the SAME branch and not open a new PR', () => {
    const brief = buildFixExpansionBrief(1171, 'fix/trashnothing-partner-banned-9961', [
      { category: 'other call sites', description: 'addMemberToGroup still fake-succeeds for banned' },
    ])
    expect(brief).toContain('#1171')
    expect(brief).toContain('fix/trashnothing-partner-banned-9961')
    expect(brief).toContain('addMemberToGroup still fake-succeeds for banned')
    expect(brief.toLowerCase()).toContain('do not open a new pr')
    // Instructs a same-branch follow-up commit (the COMMIT_PUSHED output marker itself is
    // appended by runFixExpansion's prompt wrapper, not the brief).
    expect(brief.toLowerCase()).toContain('same branch')
  })
})

import { describe, it, expect } from 'vitest'
import { detectWontfixClose, buildFailedReviewCloseComment, FSM_AUTOCLOSE_MARKER } from '../actions/index'

/**
 * detectWontfixClose distinguishes a human closing an FSM PR because the report
 * is NOT an actionable code bug ("park it, never retry") from a plain close
 * ("the fix was wrong — retry once then escalate").
 *
 * Regression target: Discourse #9753 ("AI drawings assumed to be spam") — a
 * perception complaint. PR #631 was closed with "No actionable code bug … →
 * closing", but the FSM re-attempted it and produced PR #660. With this signal,
 * sync_pr_states parks the bug instead of retrying.
 */

const NETLIFY = { author: { login: 'netlify' }, body: 'Deploy Preview ready! no fix required wink' }

describe('detectWontfixClose', () => {
  it('flags the real #631 close comment as wontfix and quotes it', () => {
    const r = detectWontfixClose([
      NETLIFY,
      {
        author: { login: 'edwh' },
        body:
          'Closing as a bad fix (re-reviewed against the actual Discourse #9753 report).\n\n' +
          '#9753 is a perception complaint ... No actionable code bug for #9753 → closing.',
      },
    ])
    expect(r.wontfix).toBe(true)
    expect(r.reason).toContain('No actionable code bug')
    expect(r.reason).not.toContain('\n') // whitespace collapsed
  })

  it.each([
    'no fix required',
    'This is not a bug, working as designed.',
    "wontfix — won't fix this",
    'Closing, no actionable change here',
    'by design',
  ])('flags "%s"', (body) => {
    expect(detectWontfixClose([{ author: { login: 'edwh' }, body }]).wontfix).toBe(true)
  })

  it('does NOT flag a plain "redo this" close (genuine retry case)', () => {
    const r = detectWontfixClose([
      { author: { login: 'edwh' }, body: 'This fix is incomplete — please rework the query and reopen.' },
    ])
    expect(r.wontfix).toBe(false)
  })

  it('ignores bot comments even if they contain trigger phrases', () => {
    expect(detectWontfixClose([NETLIFY]).wontfix).toBe(false)
    expect(detectWontfixClose([{ author: { login: 'github-actions[bot]' }, body: 'not a bug' }]).wontfix).toBe(false)
    expect(detectWontfixClose([{ author: { login: 'coderabbitai' }, body: 'no fix needed' }]).wontfix).toBe(false)
  })

  it('handles empty / missing comments and authors safely', () => {
    expect(detectWontfixClose([]).wontfix).toBe(false)
    expect(detectWontfixClose([{ body: 'no fix required' }]).wontfix).toBe(false) // no author → skip
    expect(detectWontfixClose([{ author: { login: 'edwh' }, body: null }]).wontfix).toBe(false)
  })

  it('is case-insensitive', () => {
    expect(detectWontfixClose([{ author: { login: 'edwh' }, body: 'NOT A BUG' }]).wontfix).toBe(true)
  })

  it('does NOT treat the FSM auto-close comment as a human wontfix (so the bug retries)', () => {
    // Auto-close quotes blocker text that can contain wontfix-ish phrases (e.g.
    // "working-as-designed override"), but the FSM marker means retry, not park.
    const autoClose = buildFailedReviewCloseComment(700, [
      { category: 'Incomplete diff / working-as-designed override', description: 'reverses intended behaviour', severity: 'error' },
    ])
    expect(autoClose).toContain(FSM_AUTOCLOSE_MARKER)
    expect(detectWontfixClose([{ author: { login: 'edwh' }, body: autoClose }]).wontfix).toBe(false)
  })
})

describe('buildFailedReviewCloseComment', () => {
  it('lists the blockers and carries the FSM auto-close marker', () => {
    const c = buildFailedReviewCloseComment(701, [
      { category: 'Partial implementation', description: 'symptom not root', severity: 'error' },
      { category: 'Test proves nothing', description: 'stubbed', severity: 'error' },
      { category: 'Naming', description: 'minor', severity: 'warning' }, // not a blocker
    ])
    expect(c).toContain('#701')
    expect(c).toContain('Partial implementation')
    expect(c).toContain('Test proves nothing')
    expect(c).not.toContain('Naming') // only error-severity blockers listed
    expect(c).toContain(FSM_AUTOCLOSE_MARKER)
  })

  it('handles a failed review with no recorded blockers', () => {
    const c = buildFailedReviewCloseComment(702, [])
    expect(c).toContain('no specific blockers recorded')
    expect(c).toContain(FSM_AUTOCLOSE_MARKER)
  })
})

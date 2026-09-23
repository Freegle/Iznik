import { describe, it, expect } from 'vitest'
import { matchDirectMasterFixCommits } from '../actions/index'

// The matcher is the precision-critical part of reconcile_direct_master_fixes:
// it must link a master commit to a Discourse bug ONLY on an explicit, deliberate
// reference — never on an incidental number — or it repeats the ~75% false-positive
// failure that got the old keyword heuristic (checkGitAlreadyFixed) disabled.
describe('matchDirectMasterFixCommits', () => {
  it('matches an explicit topic/post reference', () => {
    const m = matchDirectMasterFixCommits(
      [{ sha: 'aaa1111', subj: 'fix(modtools): cap MT search (9518/366)', body: '' }],
      [{ topic: 9518, post: 366 }],
    )
    expect(m).toEqual([
      { topic: 9518, post: 366, sha: 'aaa1111', subj: 'fix(modtools): cap MT search (9518/366)' },
    ])
  })

  // Discourse topic ids passed 10000 in September 2026. A matcher that only knew 9xxx
  // credited none of that day's fixes on topics 10102, 10142 and 10148, so no reporter
  // was told; every reply had to be linked by hand.
  it('matches five-digit topics, explicit and topic-only', () => {
    const m = matchDirectMasterFixCommits(
      [
        { sha: 'eee5555', subj: 'fix(mail): one group per email', body: 'Discourse 10142.' },
        { sha: 'fff6666', subj: 'fix(moderation): rippled copy (10102/7)', body: '' },
      ],
      [{ topic: 10142, post: 1 }, { topic: 10102, post: 7 }, { topic: 10102, post: 11 }],
    )
    expect(m).toEqual([
      { topic: 10142, post: 1, sha: 'eee5555', subj: 'fix(mail): one group per email' },
      { topic: 10102, post: 7, sha: 'fff6666', subj: 'fix(moderation): rippled copy (10102/7)' },
    ])
  })

  it('does not read a date as a topic/post reference', () => {
    const m = matchDirectMasterFixCommits(
      [{ sha: 'abc0001', subj: 'fix(x): dated 2026/09 change', body: '' }],
      [{ topic: 2026, post: 9 }],
    )
    expect(m).toEqual([])
  })

  it('matches a topic-only structured ref when the topic has exactly one unfixed bug', () => {
    const m = matchDirectMasterFixCommits(
      [{ sha: 'bbb2222', subj: 'fix(modtools): ghost edit counts (9839)', body: '' }],
      [{ topic: 9839, post: 3 }],
    )
    expect(m).toEqual([
      { topic: 9839, post: 3, sha: 'bbb2222', subj: 'fix(modtools): ghost edit counts (9839)' },
    ])
  })

  it('does NOT match a topic-only ref when the topic has multiple unfixed bugs (ambiguous)', () => {
    const m = matchDirectMasterFixCommits(
      [{ sha: 'ccc3333', subj: 'fix(x): something (9518)', body: '' }],
      [{ topic: 9518, post: 1 }, { topic: 9518, post: 2 }],
    )
    expect(m).toEqual([])
  })

  it('still matches the exact post even when the topic has multiple unfixed bugs', () => {
    const m = matchDirectMasterFixCommits(
      [{ sha: 'ddd4444', subj: 'fix(x): something (9518/2)', body: '' }],
      [{ topic: 9518, post: 1 }, { topic: 9518, post: 2 }],
    )
    expect(m).toEqual([
      { topic: 9518, post: 2, sha: 'ddd4444', subj: 'fix(x): something (9518/2)' },
    ])
  })

  it('excludes non-fix commits (test/chore/revert) even with an explicit ref', () => {
    const m = matchDirectMasterFixCommits(
      [
        { sha: 'eee5555', subj: 'test(foo): cover 9839/3', body: '' },
        { sha: 'fff6666', subj: 'Revert "fix: 9839/3"', body: '' },
        { sha: 'ggg7777', subj: 'chore: bump (9839)', body: '' },
      ],
      [{ topic: 9839, post: 3 }],
    )
    expect(m).toEqual([])
  })

  it('does NOT match a bare topic number (no paren / "discourse" / # prefix)', () => {
    // e.g. a build/test commit that incidentally contains a 4-digit number.
    const m = matchDirectMasterFixCommits(
      [{ sha: 'hhh8888', subj: 'fix: bump gradle to 9426', body: 'changed 9426 things' }],
      [{ topic: 9426, post: 1 }],
    )
    expect(m).toEqual([])
  })

  it('matches "Discourse <topic>/<post>" and "#topic" prose forms', () => {
    const m = matchDirectMasterFixCommits(
      [
        { sha: 'jjj1010', subj: 'fix(ripple): suspicious flag', body: 'Discourse 9808/250 Neville' },
        { sha: 'kkk1111', subj: 'fix(site): badge', body: 'addresses #9806' },
      ],
      [{ topic: 9808, post: 250 }, { topic: 9806, post: 6 }],
    )
    expect(m).toEqual([
      { topic: 9808, post: 250, sha: 'jjj1010', subj: 'fix(ripple): suspicious flag' },
      { topic: 9806, post: 6, sha: 'kkk1111', subj: 'fix(site): badge' },
    ])
  })

  it('does NOT match a scoped topic ref to an unrelated post via the single-bug fallback', () => {
    // Regression: a reach-slider commit that referenced Neville's OTHER posts
    // "(9808 #585/#600)" and "(Neville, 9808 #584)" was matched to the only open
    // 9808 bug at the time (9808/633, a different problem), falsely marking it
    // fixed and auto-posting "please retest" — which Neville rejected (2026-07-22).
    const m = matchDirectMasterFixCommits(
      [
        {
          sha: '0370f67',
          subj: "fix(reach-slider): show the rippling explainer at all widths + 'Close to' wording",
          body: 'Un-hide the line Neville reported missing (9808 #585/#600). Wording per (Neville, 9808 #584).',
        },
      ],
      [{ topic: 9808, post: 633 }],
    )
    expect(m).toEqual([])
  })

  it('still matches the exact scoped post it does reference', () => {
    const m = matchDirectMasterFixCommits(
      [{ sha: 'abc1234', subj: 'fix(reach): explainer', body: 'the missing line (9808 #585)' }],
      [{ topic: 9808, post: 585 }],
    )
    expect(m).toEqual([
      { topic: 9808, post: 585, sha: 'abc1234', subj: 'fix(reach): explainer' },
    ])
  })

  it('returns nothing when no commit references the bug', () => {
    const m = matchDirectMasterFixCommits(
      [{ sha: 'iii9999', subj: 'fix(x): unrelated', body: '' }],
      [{ topic: 9518, post: 366 }],
    )
    expect(m).toEqual([])
  })
})

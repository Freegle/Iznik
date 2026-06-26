import { describe, it, expect } from 'vitest'
import { selectExtraPrsToClose } from '../actions/index.js'

/**
 * Regression tests for `selectExtraPrsToClose` — the filter that decides which
 * open PRs `close_extra_prs` will sweep.
 *
 * Incident this guards against: on 2026-05-30 the FSM closed PR #577
 * (`fix/eee-label-target-classified-items`) with "duplicate PR for same bug —
 * FSM enforces one PR per bug fix", but #577 was an entirely unrelated
 * manually-authored PR for a different bug. Root cause: when the FSM's own
 * expected PR branch had no `\d+-\d+` topic-post suffix, the bug-suffix
 * `if` clause short-circuited and every recent edwh-authored PR was swept.
 *
 * Conservative fix: if we can't extract a bug suffix from the expected
 * branch, sweep nothing.
 */

const iterStart = '2026-05-30T20:00:00Z'

function pr(opts: { number: number; branch: string; createdAt?: string; author?: string }) {
  return {
    number: opts.number,
    headRefName: opts.branch,
    createdAt: opts.createdAt ?? '2026-05-30T22:00:00Z',
    author: { login: opts.author ?? 'edwh' },
  }
}

describe('selectExtraPrsToClose', () => {
  it('closes a same-bug duplicate PR opened during the iteration', () => {
    const expected = pr({ number: 600, branch: 'fix/post-expiry-9481-531' })
    const duplicate = pr({ number: 601, branch: 'fix/post-expiry-9481-531-retry' })

    const toClose = selectExtraPrsToClose(
      [expected, duplicate],
      expected.number,
      iterStart,
      expected.headRefName,
    )

    expect(toClose.map(p => p.number)).toEqual([601])
  })

  it('preserves a PR for a different bug even if also opened by edwh in the same iteration', () => {
    const expected = pr({ number: 600, branch: 'fix/post-expiry-9481-531' })
    const otherBug = pr({ number: 601, branch: 'fix/avatar-overwrite-9679-7' })

    const toClose = selectExtraPrsToClose(
      [expected, otherBug],
      expected.number,
      iterStart,
      expected.headRefName,
    )

    expect(toClose).toEqual([])
  })

  it('preserves a manual PR with no topic-post suffix when the expected branch HAS a suffix', () => {
    // The actual PR #577 incident — manual PR for an unrelated feature.
    const expected = pr({ number: 600, branch: 'fix/post-expiry-9481-531' })
    const manual = pr({ number: 577, branch: 'fix/eee-label-target-classified-items' })

    const toClose = selectExtraPrsToClose(
      [expected, manual],
      expected.number,
      iterStart,
      expected.headRefName,
    )

    expect(toClose).toEqual([])
  })

  it('CONSERVATIVE FALLBACK: closes nothing when the expected branch has no extractable bug suffix', () => {
    // Coverage iterations / refactors / non-bug fixes — branch name doesn't
    // carry topic-post. We cannot reliably identify which other PRs are
    // duplicates; previously every recent edwh PR was swept. This is the
    // exact scenario that nuked #577.
    const expected = pr({ number: 700, branch: 'chore/coverage-shortlinks' })
    const otherEdwhPr = pr({ number: 701, branch: 'fix/post-expiry-9481-531' })
    const manualPr = pr({ number: 577, branch: 'fix/eee-label-target-classified-items' })

    const toClose = selectExtraPrsToClose(
      [expected, otherEdwhPr, manualPr],
      expected.number,
      iterStart,
      expected.headRefName,
    )

    expect(toClose).toEqual([])
  })

  it('does not sweep PRs opened BEFORE iteration start', () => {
    const expected = pr({ number: 600, branch: 'fix/post-expiry-9481-531' })
    const old = pr({
      number: 601,
      branch: 'fix/post-expiry-9481-531',
      createdAt: '2026-05-30T10:00:00Z',
    })

    const toClose = selectExtraPrsToClose(
      [expected, old],
      expected.number,
      iterStart,
      expected.headRefName,
    )

    expect(toClose).toEqual([])
  })

  it('does not sweep PRs authored by someone other than edwh', () => {
    const expected = pr({ number: 600, branch: 'fix/post-expiry-9481-531' })
    const dependabot = pr({
      number: 601,
      branch: 'fix/post-expiry-9481-531',
      author: 'dependabot[bot]',
    })

    const toClose = selectExtraPrsToClose(
      [expected, dependabot],
      expected.number,
      iterStart,
      expected.headRefName,
    )

    expect(toClose).toEqual([])
  })

  it('never includes the expected PR in the sweep list', () => {
    const expected = pr({ number: 600, branch: 'fix/post-expiry-9481-531' })

    const toClose = selectExtraPrsToClose(
      [expected],
      expected.number,
      iterStart,
      expected.headRefName,
    )

    expect(toClose).toEqual([])
  })

  it('returns nothing when expectedBugBranch is empty (defensive — same as suffix-less)', () => {
    const expected = pr({ number: 600, branch: 'fix/post-expiry-9481-531' })
    const duplicate = pr({ number: 601, branch: 'fix/post-expiry-9481-531' })

    const toClose = selectExtraPrsToClose(
      [expected, duplicate],
      expected.number,
      iterStart,
      '',
    )

    expect(toClose).toEqual([])
  })
})

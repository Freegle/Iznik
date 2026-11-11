import { describe, it, expect } from 'vitest'
import {
  hasBlockingLabel,
  isCoverageJitterCheck,
  isCoverageJitterOnly,
  isDeliberateBlockCheck,
  partitionFailedChecks,
  redCoverageSuites,
  type FailedCheck,
} from '../coverage-checks'

const f = (context: string): FailedCheck => ({ context, state: 'failure', url: '' })

describe('isCoverageJitterCheck', () => {
  it('matches Coveralls per-suite and aggregate checks', () => {
    expect(isCoverageJitterCheck('Coveralls - laravel')).toBe(true)
    expect(isCoverageJitterCheck('Coveralls - vitest')).toBe(true)
    expect(isCoverageJitterCheck('coverage/coveralls')).toBe(true)
  })

  it('does not match real test/build checks', () => {
    expect(isCoverageJitterCheck('ci/circleci: build-and-test')).toBe(false)
    expect(isCoverageJitterCheck('ci/circleci: check-runner')).toBe(false)
    expect(isCoverageJitterCheck('build')).toBe(false)
  })
})

describe('partitionFailedChecks', () => {
  it('treats a coverage-only red PR as jitter (no real failures)', () => {
    const { realFailed, coverageFailed } = partitionFailedChecks([
      f('Coveralls - laravel'),
      f('Coveralls - vitest'),
      f('coverage/coveralls'),
    ])
    expect(realFailed).toHaveLength(0)
    expect(coverageFailed).toHaveLength(3)
  })

  it('keeps a PR with a genuine test failure in the real-failure bucket', () => {
    const { realFailed, coverageFailed } = partitionFailedChecks([
      f('ci/circleci: build-and-test'),
      f('Coveralls - laravel'),
    ])
    expect(realFailed.map(c => c.context)).toEqual(['ci/circleci: build-and-test'])
    expect(coverageFailed.map(c => c.context)).toEqual(['Coveralls - laravel'])
  })

  it('returns empty buckets for no failures', () => {
    const { realFailed, coverageFailed } = partitionFailedChecks([])
    expect(realFailed).toHaveLength(0)
    expect(coverageFailed).toHaveLength(0)
  })
})

describe('redCoverageSuites', () => {
  it('extracts the failing suite names', () => {
    expect(
      redCoverageSuites([f('Coveralls - laravel'), f('Coveralls - vitest')]).sort(),
    ).toEqual(['laravel', 'vitest'])
  })

  it('ignores the aggregate coverage/coveralls check (no suite name)', () => {
    expect(redCoverageSuites([f('coverage/coveralls')])).toEqual([])
  })
})

// A PR carrying a blocking label fails the "blocked-label" guard by design: the
// guard is asserting that the PR must not merge. There is nothing to fix, and
// every fix attempt spends the iteration's single PR slot, three times over,
// before the PR is written off as exhausted. On 2026-09-22 every PR in the
// monitor's red list was red on this and nothing else - five of them, so up to
// fifteen delegate runs aimed at a guard that was working correctly.
describe('isDeliberateBlockCheck', () => {
  it('matches the blocking-label guard', () => {
    expect(isDeliberateBlockCheck('blocked-label')).toBe(true)
    expect(isDeliberateBlockCheck('Blocked-Label')).toBe(true)
  })

  it('does not match real test, build or coverage checks', () => {
    expect(isDeliberateBlockCheck('ci/circleci: build-and-test')).toBe(false)
    expect(isDeliberateBlockCheck('Coveralls - go')).toBe(false)
    expect(isDeliberateBlockCheck('guard')).toBe(false)
    expect(isDeliberateBlockCheck('build')).toBe(false)
  })

  // "guard" is the workflow that RUNS the blocked-label check, and it passes on
  // ordinary PRs. Matching on it would hide real failures from a job that
  // happened to be named that way.
  it('does not match on a substring of a longer check name', () => {
    expect(isDeliberateBlockCheck('blocked-label-something-else')).toBe(false)
  })
})

describe('partitionFailedChecks - deliberate blocks', () => {
  it('treats a blocked-label-only red PR as not a real failure', () => {
    const { realFailed, blockedFailed } = partitionFailedChecks([f('blocked-label')])
    expect(realFailed).toHaveLength(0)
    expect(blockedFailed).toHaveLength(1)
  })

  // A blocked PR that is ALSO genuinely broken still needs fixing: the label says
  // do not merge it, not stop testing it.
  it('keeps a genuine failure on a blocked PR', () => {
    const { realFailed, blockedFailed } = partitionFailedChecks([
      f('blocked-label'),
      f('ci/circleci: build-and-test'),
    ])
    expect(realFailed).toHaveLength(1)
    expect(realFailed[0].context).toBe('ci/circleci: build-and-test')
    expect(blockedFailed).toHaveLength(1)
  })

  it('separates all three kinds at once', () => {
    const { realFailed, coverageFailed, blockedFailed } = partitionFailedChecks([
      f('blocked-label'),
      f('Coveralls - go'),
      f('ci/circleci: build-and-test'),
    ])
    expect(realFailed).toHaveLength(1)
    expect(coverageFailed).toHaveLength(1)
    expect(blockedFailed).toHaveLength(1)
  })

  // The coverage booster must not be handed a blocked PR to "raise coverage" on.
  it('does not file a deliberate block as coverage jitter', () => {
    const { coverageFailed } = partitionFailedChecks([f('blocked-label')])
    expect(coverageFailed).toHaveLength(0)
  })
})

// The booster's own target test. check_my_open_pr_ci used to hand the booster any PR
// whose only non-coverage failure was the blocked-label guard, so on 2026-10-16 three
// PRs parked for review (Blocked, tests green, a Coveralls delta red) were about to get
// coverage commits pushed onto branches their author had asked everyone to leave alone.
describe('isCoverageJitterOnly', () => {
  it('is true for a PR red only on Coveralls', () => {
    expect(isCoverageJitterOnly([f('Coveralls - laravel'), f('coverage/coveralls')])).toBe(true)
  })

  it('is false when a real check is red, even with a coverage dip', () => {
    expect(isCoverageJitterOnly([f('ci/circleci: build-and-test'), f('Coveralls - laravel')])).toBe(false)
  })

  it('is false for a blocked PR whose coverage dipped: a parked branch is not pushed to', () => {
    expect(isCoverageJitterOnly([f('blocked-label'), f('Coveralls - laravel')])).toBe(false)
  })

  it('is false for a blocked PR with no coverage dip', () => {
    expect(isCoverageJitterOnly([f('blocked-label')])).toBe(false)
  })

  it('is false with no failures at all', () => {
    expect(isCoverageJitterOnly([])).toBe(false)
  })
})

// The rebase step force-pushes a rebase onto master for every dirty PR of mine. A PR
// carrying the blocking label is parked, and the framework PRs that carry it are stacked
// on each other, so a force-pushed rebase of one of them onto master would break the
// branches above it. The label list mirrors .github/workflows/blocked-label-guard.yml.
describe('hasBlockingLabel', () => {
  it('matches the Blocked label whatever its case', () => {
    expect(hasBlockingLabel(['Blocked'])).toBe(true)
    expect(hasBlockingLabel(['CI: Passed', 'blocked'])).toBe(true)
    expect(hasBlockingLabel(['BLOCKED'])).toBe(true)
  })

  it('does not match other labels, or a label that merely contains the word', () => {
    expect(hasBlockingLabel(['CI: Passed'])).toBe(false)
    expect(hasBlockingLabel(['unblocked'])).toBe(false)
    expect(hasBlockingLabel([])).toBe(false)
  })
})

import { describe, it, expect } from 'vitest'
import {
  isCoverageJitterCheck,
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

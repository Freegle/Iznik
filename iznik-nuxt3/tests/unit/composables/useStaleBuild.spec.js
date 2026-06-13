import { describe, it, expect } from 'vitest'
import { classifyStaleBuild } from '~/composables/useStaleBuild'

const NOW = Date.parse('2026-06-12T12:00:00Z')
const HOUR = 60 * 60 * 1000
const DAY = 24 * HOUR

const iso = (ms) => new Date(ms).toISOString()

describe('classifyStaleBuild', () => {
  it('returns hard when the running build is over a week old', () => {
    // Newer deploy fresh, but our bundle is 8 days old -> force reload.
    expect(
      classifyStaleBuild(iso(NOW - 8 * DAY), iso(NOW - 1 * HOUR), NOW)
    ).toBe('hard')
  })

  it('hard wins even when the newer deploy is brand new', () => {
    expect(
      classifyStaleBuild(iso(NOW - 10 * DAY), iso(NOW - 1 * 1000), NOW)
    ).toBe('hard')
  })

  it('returns soft when build is recent but newer deploy is older than 12h', () => {
    expect(
      classifyStaleBuild(iso(NOW - 2 * DAY), iso(NOW - 13 * HOUR), NOW)
    ).toBe('soft')
  })

  it('returns none when the newer deploy is too fresh to nag about', () => {
    expect(
      classifyStaleBuild(iso(NOW - 2 * DAY), iso(NOW - 6 * HOUR), NOW)
    ).toBe('none')
  })

  it('does not escalate at exactly the 7 day boundary', () => {
    // buildAge == HARD threshold is not strictly greater, so not hard.
    expect(
      classifyStaleBuild(iso(NOW - 7 * DAY), iso(NOW - 1 * HOUR), NOW)
    ).toBe('none')
  })

  it('fails open to soft when BUILD_DATE is unparseable but a deploy is stale', () => {
    expect(classifyStaleBuild('not-a-date', iso(NOW - 13 * HOUR), NOW)).toBe(
      'soft'
    )
  })

  it('fails open to none when both dates are unparseable', () => {
    expect(classifyStaleBuild('not-a-date', 'also-bad', NOW)).toBe('none')
  })
})

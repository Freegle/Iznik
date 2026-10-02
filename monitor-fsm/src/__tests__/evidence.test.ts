import { describe, it, expect } from 'vitest'
import { assessPrEvidence, liveEvidenceSection } from '../evidence.js'

const body = (live: string, extra = '') =>
  `## Root Cause\nThe thing.\n\n## Live evidence\n${live}\n\n## Evidence\nfailing test\n\n## Fix\nx${extra}\n`

describe('assessPrEvidence', () => {
  // PRs #1654 #1657 #1658 #1659 were all closed as guesses. Each had a test it wrote
  // from its own hypothesis and nothing from production.
  it('refuses a description with no Live evidence section', () => {
    const r = assessPrEvidence('## Root Cause\nx\n\n## Evidence\nA test reproduces it.\n')
    expect(r.ok).toBe(false)
    expect(r.problems.join(' ')).toMatch(/Live evidence/)
  })

  it.each([
    'I did not check live data in this run.',
    'Could not be reproduced on production.',
    'Live data unavailable (tunnel down).',
    'n/a',
    'None.',
    'Inferred from the code.',
  ])('refuses a Live evidence section that says it was not checked: %s', (live) => {
    const r = assessPrEvidence(body(live))
    expect(r.ok).toBe(false)
  })

  it('refuses a section that describes production without showing anything', () => {
    const r = assessPrEvidence(body('Production behaves exactly as the report says.'))
    expect(r.ok).toBe(false)
    expect(r.problems.join(' ')).toMatch(/query|Sentry|screenshot/)
  })

  it('refuses a query with no result', () => {
    const r = assessPrEvidence(body('Query: SELECT COUNT(*) FROM messages WHERE x = 1'))
    expect(r.ok).toBe(false)
  })

  it('accepts a SQL query with its result', () => {
    const r = assessPrEvidence(body('Query: SELECT COUNT(*) FROM rippling_held_replies WHERE status = \'held\'\nResult: 412 rows, all on posts whose reach is still growing.'))
    expect(r).toEqual({ ok: true, problems: [], confidential: false })
  })

  it('accepts a Loki query with its result', () => {
    const r = assessPrEvidence(body('Query: {app="freegle", source="api"} |= "donate"\nResult: 37 failed confirm calls on 1 Oct, none before.'))
    expect(r.ok).toBe(true)
  })

  it('accepts a Sentry event', () => {
    const r = assessPrEvidence(body('Sentry issue 7683112976: 212 events since 28 Sep, all Chrome on Windows.'))
    expect(r.ok).toBe(true)
  })

  it('accepts the reporter screenshot', () => {
    const r = assessPrEvidence(body('The reporter\'s screenshot shows the blank pane: ![blank](upload://abc123.png)'))
    expect(r.ok).toBe(true)
  })

  // The repo is public. A description is shared and cached the moment it is written.
  it.each([
    ['an email address', 'jane.smith@gmail.com'],
    ['a full postcode', 'CB1 2AB'],
    ['a mobile number', '07700 900123'],
    ['an IP address', '81.2.69.142'],
  ])('refuses a description containing %s anywhere', (_what, pii) => {
    const r = assessPrEvidence(body('Sentry issue 7683112976: 3 events.', `\nReporter is ${pii}.`))
    expect(r.ok).toBe(false)
    expect(r.confidential).toBe(true)
    // The problem names the kind of thing, never repeats it.
    expect(r.problems.join(' ')).not.toContain(pii)
  })

  it('does not count a postcode district or example address as personal data', () => {
    const r = assessPrEvidence(body('Sentry issue 7683112976: 3 events, all in CB1.', '\nTest uses test@example.org and 127.0.0.1.'))
    expect(r.ok).toBe(true)
  })
})

describe('liveEvidenceSection', () => {
  it('stops at the next heading', () => {
    expect(liveEvidenceSection(body('Sentry issue 123456789: 3 events.'))).toBe('Sentry issue 123456789: 3 events.')
  })

  it('is null when there is no such section', () => {
    expect(liveEvidenceSection('## Evidence\nx')).toBeNull()
  })
})

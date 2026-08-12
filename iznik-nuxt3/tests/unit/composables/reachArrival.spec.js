import { describe, it, expect } from 'vitest'
import {
  reachArrivalWording,
  reachNoticeSentence,
} from '~/composables/reachArrival'

// A Wednesday, so the weekday branch has somewhere unambiguous to land.
const NOW = new Date('2026-08-12T09:00:00Z')
const inHours = (h) => new Date(NOW.getTime() + h * 60 * 60 * 1000)

describe('reachArrivalWording', () => {
  describe('when there is nothing sensible to say', () => {
    it.each([
      ['null', null],
      ['undefined', undefined],
      ['empty string', ''],
      ['an unparseable date', 'next Tuesday-ish'],
    ])('returns null for %s', (_label, input) => {
      expect(reachArrivalWording(input, NOW)).toBeNull()
    })

    it('returns null beyond the schedule ceiling rather than quoting it', () => {
      // The hazard schedule tops out at a week. A fortnight away means the row or the
      // clock is wrong, and a number we cannot justify is worse than silence.
      expect(reachArrivalWording(inHours(24 * 15), NOW)).toBeNull()
    })
  })

  it('treats a moment already past as due, not as an error', () => {
    // The reach engine runs every minute and feeds are cached, so slightly-past is
    // normal rather than wrong.
    expect(reachArrivalWording(inHours(-1), NOW)).toBe('any moment now')
  })

  it('describes the next hour without false precision', () => {
    expect(reachArrivalWording(inHours(0.2), NOW)).toBe('within the hour')
    expect(reachArrivalWording(inHours(0.9), NOW)).toBe('within the hour')
  })

  it('counts hours up to half a day', () => {
    expect(reachArrivalWording(inHours(3), NOW)).toBe('in about 3 hours')
    expect(reachArrivalWording(inHours(6), NOW)).toBe('in about 6 hours')
  })

  it('switches to days once hours stop being useful', () => {
    expect(reachArrivalWording(inHours(13), NOW)).toBe('later today')
    expect(reachArrivalWording(inHours(30), NOW)).toBe('tomorrow')
  })

  it('names the weekday within the week ahead', () => {
    // Wednesday 09:00 plus three days is Saturday.
    expect(reachArrivalWording(inHours(24 * 3), NOW)).toBe('by Saturday')
  })

  it('counts days once a weekday name would be ambiguous', () => {
    // Seven days on is the same weekday again, so naming it would read as "in two days".
    expect(reachArrivalWording(inHours(24 * 7), NOW)).toBe(
      'within about 7 days'
    )
  })
})

describe('reachNoticeSentence', () => {
  it('talks about the post arriving when a tick will actually cover them', () => {
    expect(reachNoticeSentence(inHours(3), true, NOW)).toBe(
      "It's due to reach you in about 3 hours, and we'll pass your reply on then."
    )
  })

  it('talks about the reply being passed on when the reach never covers them', () => {
    // The common case: no tick's drive-time budget ever reaches them, so what they are
    // waiting for is the reach finishing, not arriving.
    expect(reachNoticeSentence(inHours(24 * 3), false, NOW)).toBe(
      "People closer to it get first go, so we'll pass yours on by Saturday at the latest."
    )
  })

  it('marks the reach finishing as an upper bound, because the buffer releases sooner', () => {
    // RippleReplyService::releaseDue frees a held reply on its own timer, and a reach also
    // ends early when the post gathers enough repliers or is taken. Stating the finish
    // flatly would understate the delivery by days.
    expect(reachNoticeSentence(inHours(24 * 3), false, NOW)).toContain(
      'at the latest'
    )
  })

  it('says "by", never a clock time, because the finish is an upper bound', () => {
    // A reach also ends early when the post gathers enough repliers or is taken, so a
    // definite time would be a promise the schedule cannot make.
    expect(reachNoticeSentence(inHours(24 * 3), false, NOW)).toMatch(/\bby\b/)
    expect(reachNoticeSentence(inHours(24 * 3), false, NOW)).not.toMatch(
      /\d:\d/
    )
  })

  it('gives no sentence when there is no usable estimate', () => {
    expect(reachNoticeSentence(null, true, NOW)).toBeNull()
    expect(reachNoticeSentence(inHours(24 * 20), false, NOW)).toBeNull()
  })
})

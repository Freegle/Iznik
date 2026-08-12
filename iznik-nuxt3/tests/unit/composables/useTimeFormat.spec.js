import { afterEach, beforeEach, describe, it, expect, vi } from 'vitest'
import {
  earliestDate,
  timeago,
  timeagoShort,
  timeagoMedium,
  timeadapt,
  timeadaptChat,
  dateonly,
  datetime,
  datetimeshort,
  dateshort,
  dateonlyNoYear,
  dateshortNoYear,
  weekdayshort,
  durationMinutes,
} from '~/composables/useTimeFormat'

// Fixed reference instant — Friday 2026-04-17 12:00:00 UTC
const FIXED_NOW = new Date('2026-04-17T12:00:00.000Z')

describe('useTimeFormat', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(FIXED_NOW)
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  describe('earliestDate', () => {
    it('returns null for empty or missing input', () => {
      expect(earliestDate(undefined)).toBeNull()
      expect(earliestDate(null)).toBeNull()
      expect(earliestDate([])).toBeNull()
    })

    it('finds the earliest future date when ofall=false', () => {
      const dates = [
        { start: '2026-04-10T10:00:00Z' }, // past
        { start: '2026-04-20T10:00:00Z' }, // future, later
        { start: '2026-04-18T10:00:00Z' }, // future, earliest
      ]
      expect(earliestDate(dates, false)).toEqual(dates[2])
    })

    it('returns null when all dates are past and ofall=false', () => {
      const dates = [
        { start: '2026-04-10T10:00:00Z' },
        { start: '2026-04-12T10:00:00Z' },
      ]
      expect(earliestDate(dates, false)).toBeNull()
    })

    it('returns earliest of all dates (past or future) when ofall=true', () => {
      const dates = [
        { start: '2026-04-10T10:00:00Z' }, // earliest overall
        { start: '2026-04-20T10:00:00Z' },
        { start: '2026-04-18T10:00:00Z' },
      ]
      expect(earliestDate(dates, true)).toEqual(dates[0])
    })
  })

  describe('timeago', () => {
    it('de-pluralises singular units (1 hours → 1 hour)', () => {
      // 1 hour before now → "1 hour ago"
      const oneHourAgo = new Date(FIXED_NOW.getTime() - 60 * 60 * 1000)
      const result = timeago(oneHourAgo)
      expect(result).not.toMatch(/1 hours/)
      expect(result).toMatch(/hour/)
    })

    it('rewrites "in a few seconds" to "just now" when past=true', () => {
      const almostNow = new Date(FIXED_NOW.getTime() + 2000)
      expect(timeago(almostNow, true)).toBe('just now')
    })

    it('leaves "in a few seconds" as-is when past=false', () => {
      const almostNow = new Date(FIXED_NOW.getTime() + 2000)
      expect(timeago(almostNow, false)).not.toBe('just now')
    })
  })

  describe('timeagoShort', () => {
    it('returns "now" for under a minute', () => {
      const t = new Date(FIXED_NOW.getTime() - 30 * 1000)
      expect(timeagoShort(t)).toBe('now')
    })

    it('returns minutes "Nm" for under an hour', () => {
      const t = new Date(FIXED_NOW.getTime() - 5 * 60 * 1000)
      expect(timeagoShort(t)).toBe('5m')
    })

    it('returns hours "Nh" for under a day', () => {
      const t = new Date(FIXED_NOW.getTime() - 3 * 60 * 60 * 1000)
      expect(timeagoShort(t)).toBe('3h')
    })

    it('returns days "Nd" for under a week', () => {
      const t = new Date(FIXED_NOW.getTime() - 3 * 24 * 60 * 60 * 1000)
      expect(timeagoShort(t)).toBe('3d')
    })

    it('returns weeks "Nw" for under 5 weeks', () => {
      const t = new Date(FIXED_NOW.getTime() - 14 * 24 * 60 * 60 * 1000)
      expect(timeagoShort(t)).toBe('2w')
    })

    it('returns months "Nmo" beyond 5 weeks', () => {
      const t = new Date(FIXED_NOW.getTime() - 120 * 24 * 60 * 60 * 1000)
      expect(timeagoShort(t)).toBe('3mo')
    })
  })

  describe('timeagoMedium', () => {
    it('returns "just now" for under a minute', () => {
      const t = new Date(FIXED_NOW.getTime() - 30 * 1000)
      expect(timeagoMedium(t)).toBe('just now')
    })

    it('singularises 1 min and pluralises others', () => {
      expect(timeagoMedium(new Date(FIXED_NOW.getTime() - 60 * 1000))).toBe(
        '1 min'
      )
      expect(timeagoMedium(new Date(FIXED_NOW.getTime() - 5 * 60 * 1000))).toBe(
        '5 mins'
      )
    })

    it('singularises 1 hour and pluralises others', () => {
      expect(
        timeagoMedium(new Date(FIXED_NOW.getTime() - 60 * 60 * 1000))
      ).toBe('1 hour')
      expect(
        timeagoMedium(new Date(FIXED_NOW.getTime() - 3 * 60 * 60 * 1000))
      ).toBe('3 hours')
    })

    it('singularises 1 day and pluralises others', () => {
      expect(
        timeagoMedium(new Date(FIXED_NOW.getTime() - 24 * 60 * 60 * 1000))
      ).toBe('1 day')
      expect(
        timeagoMedium(new Date(FIXED_NOW.getTime() - 3 * 24 * 60 * 60 * 1000))
      ).toBe('3 days')
    })

    it('singularises 1 week and pluralises others', () => {
      expect(
        timeagoMedium(new Date(FIXED_NOW.getTime() - 7 * 24 * 60 * 60 * 1000))
      ).toBe('1 week')
      expect(
        timeagoMedium(new Date(FIXED_NOW.getTime() - 14 * 24 * 60 * 60 * 1000))
      ).toBe('2 weeks')
    })

    it('falls back to months past 5 weeks', () => {
      expect(
        timeagoMedium(new Date(FIXED_NOW.getTime() - 35 * 24 * 60 * 60 * 1000))
      ).toBe('1 month')
      expect(
        timeagoMedium(new Date(FIXED_NOW.getTime() - 120 * 24 * 60 * 60 * 1000))
      ).toBe('3 months')
    })
  })

  describe('timeadapt', () => {
    it('shows HH:mm for today', () => {
      const t = new Date('2026-04-17T09:15:00Z')
      // Output depends on local timezone interpretation; just assert HH:mm shape
      expect(timeadapt(t)).toMatch(/^\d{2}:\d{2}$/)
    })

    it('shows DD MMM YYYY HH:mm for non-today', () => {
      const t = new Date('2026-04-10T09:15:00Z')
      expect(timeadapt(t)).toMatch(/^\d{2} [A-Z][a-z]{2} 2026 \d{2}:\d{2}$/)
    })
  })

  describe('timeadaptChat', () => {
    it('today → HH:mm', () => {
      const t = new Date('2026-04-17T09:15:00Z')
      expect(timeadaptChat(t)).toMatch(/^\d{2}:\d{2}$/)
    })

    it('within last week → ddd HH:mm', () => {
      const t = new Date('2026-04-14T09:15:00Z')
      expect(timeadaptChat(t)).toMatch(/^[A-Z][a-z]{2} \d{2}:\d{2}$/)
    })

    it('same year, older than a week → D MMM HH:mm', () => {
      const t = new Date('2026-01-10T09:15:00Z')
      expect(timeadaptChat(t)).toMatch(/^\d{1,2} [A-Z][a-z]{2} \d{2}:\d{2}$/)
    })

    it('different year → D MMM YYYY HH:mm', () => {
      const t = new Date('2024-11-10T09:15:00Z')
      expect(timeadaptChat(t)).toMatch(
        /^\d{1,2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}$/
      )
    })
  })

  describe('date formatters', () => {
    const d = new Date('2026-04-17T12:00:00Z')

    it('dateonly — full month + year', () => {
      expect(dateonly(d)).toMatch(/17(st|nd|rd|th) April, 2026/)
    })

    it('datetime — full month, year, HH:mm:ss', () => {
      expect(datetime(d)).toMatch(
        /17(st|nd|rd|th) April, 2026 \d{2}:\d{2}:\d{2}/
      )
    })

    it('datetimeshort — short month, year, HH:mm', () => {
      expect(datetimeshort(d)).toMatch(/17(st|nd|rd|th) Apr, 2026 \d{2}:\d{2}/)
    })

    it('dateshort — short month DD, YYYY', () => {
      expect(dateshort(d)).toMatch(/Apr 17, 2026/)
    })

    it('dateonlyNoYear — full month, no year', () => {
      expect(dateonlyNoYear(d)).toMatch(/17(st|nd|rd|th) April/)
      expect(dateonlyNoYear(d)).not.toMatch(/2026/)
    })

    it('dateshortNoYear — short month, no year', () => {
      expect(dateshortNoYear(d)).toMatch(/17 Apr/)
      expect(dateshortNoYear(d)).not.toMatch(/2026/)
    })

    it('weekdayshort — weekday with ordinal and time', () => {
      // 2026-04-17 is a Friday
      expect(weekdayshort(d)).toMatch(
        /^Friday 17(st|nd|rd|th) \d{2}:\d{2} (am|pm)$/
      )
    })
  })

  // durationMinutes renders an ELAPSED SPAN (a count of minutes), not a distance from now.
  // timeagoMedium can't do this: it takes a date. Rippling reply-holds report heldminutes,
  // computed server-side so it works for a still-open hold without trusting the client clock.
  describe('durationMinutes', () => {
    it('renders minutes below an hour, singular and plural', () => {
      expect(durationMinutes(1)).toBe('1 min')
      expect(durationMinutes(35)).toBe('35 mins')
      expect(durationMinutes(59)).toBe('59 mins')
    })

    it('renders hours below a day', () => {
      expect(durationMinutes(60)).toBe('1 hour')
      expect(durationMinutes(150)).toBe('2 hours')
      expect(durationMinutes(23 * 60)).toBe('23 hours')
    })

    // A delay is not a date. timeagoMedium switches to days at 24h, which is right for "when
    // did this happen" but wrong for "how long was this stuck": "2 days" hides whether a hold
    // straddled one night or three. So hours run to 48h before days take over.
    it('keeps using hours past 24h, up to 48h', () => {
      expect(durationMinutes(24 * 60)).toBe('24 hours')
      expect(durationMinutes(2812)).toBe('46 hours')
      expect(durationMinutes(47 * 60)).toBe('47 hours')
    })

    it('renders days from 48 hours', () => {
      expect(durationMinutes(48 * 60)).toBe('2 days')
      expect(durationMinutes(60 * 60)).toBe('2 days')
      expect(durationMinutes(6 * 24 * 60)).toBe('6 days')
    })

    it('renders weeks beyond a week — 283h was the worst observed hold', () => {
      expect(durationMinutes(7 * 24 * 60)).toBe('1 week')
      expect(durationMinutes(283 * 60)).toBe('1 week')
      expect(durationMinutes(21 * 24 * 60)).toBe('3 weeks')
    })

    it('handles zero, sub-minute and missing values without throwing', () => {
      expect(durationMinutes(0)).toBe('less than a minute')
      expect(durationMinutes(null)).toBe('')
      expect(durationMinutes(undefined)).toBe('')
    })

    it('ignores a negative span rather than rendering nonsense', () => {
      expect(durationMinutes(-5)).toBe('less than a minute')
    })
  })
})

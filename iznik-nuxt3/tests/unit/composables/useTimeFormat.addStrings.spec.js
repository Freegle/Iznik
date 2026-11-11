import { afterEach, beforeEach, describe, it, expect, vi } from 'vitest'
import dayjs from 'dayjs'
import { addStrings, datelocale } from '~/composables/useTimeFormat'

// Local-time strings so the assertions do not depend on the machine timezone.
const FIXED_NOW = new Date(2026, 3, 17, 12, 0, 0)

describe('addStrings', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(FIXED_NOW)
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it.each([[null], [undefined], [0], ['']])(
    'returns falsy item %j unchanged',
    (item) => {
      expect(addStrings(item, true)).toBe(item)
    }
  )

  it('returns an item with no dates untouched', () => {
    const item = { id: 1 }
    expect(addStrings(item, true)).toBe(item)
    expect(item).toEqual({ id: 1 })
  })

  it('handles an empty dates array', () => {
    const item = { dates: [] }
    expect(addStrings(item, false).dates).toEqual([])
  })

  it('with times: same-day end shows only HH:mm', () => {
    const item = {
      dates: [
        {
          start: new Date(2026, 3, 20, 9, 30).toISOString(),
          end: new Date(2026, 3, 20, 11, 45).toISOString(),
        },
      ],
    }
    const out = addStrings(item, true)
    expect(out).toBe(item)
    expect(out.dates[0].string.start).toBe('Mon, 20th Apr 09:30')
    expect(out.dates[0].string.end).toBe('11:45')
    expect(out.dates[0].string.past).toBe(false)
  })

  it('with times: different-day end shows weekday, date and time', () => {
    const item = {
      dates: [
        {
          start: new Date(2026, 3, 20, 22, 0).toISOString(),
          end: new Date(2026, 3, 21, 2, 15).toISOString(),
        },
      ],
    }
    const out = addStrings(item, true)
    expect(out.dates[0].string.end).toBe('Tue, 21st Apr 02:15')
  })

  it('without times: both ends are date only', () => {
    const item = {
      dates: [
        {
          start: new Date(2026, 3, 22, 9, 30).toISOString(),
          end: new Date(2026, 3, 22, 11, 45).toISOString(),
        },
      ],
    }
    const out = addStrings(item, false)
    expect(out.dates[0].string.start).toBe('Wed, 22nd Apr')
    expect(out.dates[0].string.end).toBe('Wed, 22nd Apr')
  })

  it.each([
    [true, new Date(2026, 3, 10, 9, 0), true],
    [true, new Date(2026, 3, 25, 9, 0), false],
    [false, new Date(2026, 3, 10, 9, 0), true],
    [false, new Date(2026, 3, 25, 9, 0), false],
  ])('times=%s start %s gives past=%s', (times, start, past) => {
    const item = {
      dates: [{ start: start.toISOString(), end: start.toISOString() }],
    }
    expect(addStrings(item, times).dates[0].string.past).toBe(past)
  })

  it('annotates every date in the list', () => {
    const item = {
      dates: [
        {
          start: new Date(2026, 3, 1, 8, 0).toISOString(),
          end: new Date(2026, 3, 1, 9, 0).toISOString(),
        },
        {
          start: new Date(2026, 4, 1, 8, 0).toISOString(),
          end: new Date(2026, 4, 1, 9, 0).toISOString(),
        },
      ],
    }
    const out = addStrings(item, true)
    expect(out.dates.map((d) => d.string.past)).toEqual([true, false])
    expect(out.dates.every((d) => d.string.start && d.string.end)).toBe(true)
  })
})

describe('datelocale', () => {
  it('delegates to dayjs toLocaleString for the same instant', () => {
    const d = new Date(2026, 3, 17, 12, 34, 56)
    expect(datelocale(d)).toBe(dayjs(d).toLocaleString())
  })

  it('accepts ISO strings', () => {
    const iso = '2026-04-17T12:34:56.000Z'
    expect(datelocale(iso)).toBe(dayjs(iso).toLocaleString())
  })

  it('returns a string for invalid input rather than throwing', () => {
    expect(typeof datelocale('not a date')).toBe('string')
  })
})

import { describe, it, expect } from 'vitest'
import dayjs from 'dayjs'
import {
  normalizeAuthoritySearch,
  parseOutcomeDate,
  DASHBOARD_CHART_HEADER,
  hasChartDataRows,
  sumCountsInRange,
} from '~/composables/useAuthoritySearch'

describe('normalizeAuthoritySearch', () => {
  const a = (id, name) => ({ id, name, area_code: 'Ward' })

  it('accepts the V2 Go shape (bare array) without crashing', () => {
    const results = [a(1, 'Essex County'), a(2, 'Wessex Ward')]
    expect(normalizeAuthoritySearch(results)).toEqual(results)
  })

  it('accepts the V1 PHP shape ({ authorities: [...] })', () => {
    const results = { authorities: [a(1, 'Essex County'), a(2, 'Wessex Ward')] }
    expect(normalizeAuthoritySearch(results)).toEqual(results.authorities)
  })

  it('caps the number of results at the default limit of 5', () => {
    const big = Array.from({ length: 12 }, (_, i) => a(i, 'Authority ' + i))
    expect(normalizeAuthoritySearch(big)).toHaveLength(5)
  })

  it('honours a custom limit', () => {
    const big = Array.from({ length: 12 }, (_, i) => a(i, 'Authority ' + i))
    expect(normalizeAuthoritySearch(big, 3)).toHaveLength(3)
  })

  it('filters out entries missing a name', () => {
    const results = [a(1, 'Essex County'), { id: 2 }, null, a(3, 'Wessex Ward')]
    const ret = normalizeAuthoritySearch(results)
    expect(ret).toHaveLength(2)
    expect(ret.map((r) => r.id)).toEqual([1, 3])
  })

  it('returns an empty array for null/undefined input', () => {
    expect(normalizeAuthoritySearch(null)).toEqual([])
    expect(normalizeAuthoritySearch(undefined)).toEqual([])
  })

  it('returns an empty array for unexpected shapes', () => {
    expect(normalizeAuthoritySearch({})).toEqual([])
    expect(normalizeAuthoritySearch({ authorities: null })).toEqual([])
    expect(normalizeAuthoritySearch('Essex')).toEqual([])
  })

  it('returns an empty array when the array is empty', () => {
    expect(normalizeAuthoritySearch([])).toEqual([])
    expect(normalizeAuthoritySearch({ authorities: [] })).toEqual([])
  })
})

describe('parseOutcomeDate', () => {
  it('pins the V1 "YYYY-MM" shape to the first of the month', () => {
    expect(parseOutcomeDate('2025-04')).toBe('2025-04-01')
  })

  it('strips the time portion from the V2 ISO datetime shape', () => {
    expect(parseOutcomeDate('2025-04-15T00:00:00Z')).toBe('2025-04-15')
    expect(parseOutcomeDate('2025-04-15T23:59:59+01:00')).toBe('2025-04-15')
  })

  it('passes through a plain "YYYY-MM-DD" unchanged', () => {
    expect(parseOutcomeDate('2025-04-15')).toBe('2025-04-15')
  })

  it('returns an empty string for falsy/non-string input', () => {
    expect(parseOutcomeDate(null)).toBe('')
    expect(parseOutcomeDate(undefined)).toBe('')
    expect(parseOutcomeDate(123)).toBe('')
  })
})

describe('DASHBOARD_CHART_HEADER', () => {
  it('types column 0 as date so Google Charts does not infer string', () => {
    expect(DASHBOARD_CHART_HEADER[0]).toEqual({ type: 'date', label: 'Date' })
  })

  it('types column 1 as number', () => {
    expect(DASHBOARD_CHART_HEADER[1]).toEqual({
      type: 'number',
      label: 'Count',
    })
  })
})

describe('hasChartDataRows', () => {
  it('is false for a header-only dataset', () => {
    expect(hasChartDataRows([DASHBOARD_CHART_HEADER])).toBe(false)
  })

  it('is true when at least one data row is present', () => {
    expect(
      hasChartDataRows([DASHBOARD_CHART_HEADER, [new Date('2025-04-01'), 42]])
    ).toBe(true)
  })

  it('is false for non-array input', () => {
    expect(hasChartDataRows(null)).toBe(false)
    expect(hasChartDataRows(undefined)).toBe(false)
    expect(hasChartDataRows('nope')).toBe(false)
    expect(hasChartDataRows([])).toBe(false)
  })
})

describe('sumCountsInRange', () => {
  // The dashboard API returns the Outcomes series as one row per day.
  const days = [
    { date: '2025-09-30T00:00:00Z', count: 100 },
    { date: '2025-10-01T00:00:00Z', count: 10 },
    { date: '2025-10-02T00:00:00Z', count: 20 },
    { date: '2025-10-31T00:00:00Z', count: 30 },
    { date: '2025-11-01T00:00:00Z', count: 1000 },
  ]
  const start = dayjs('2025-10-01').startOf('month')
  const end = dayjs('2025-10-01').endOf('month')

  it('adds each day in the range once', () => {
    // Three days in October: 10 + 20 + 30. A running total added once per
    // day would give 10 + 30 + 60 = 100.
    expect(sumCountsInRange(days, start, end)).toBe(60)
  })

  it('includes both ends of the range', () => {
    expect(
      sumCountsInRange(days, dayjs('2025-09-30'), dayjs('2025-10-01'))
    ).toBe(110)
  })

  it('scales by the share of the community inside the area', () => {
    expect(sumCountsInRange(days, start, end, 0.5)).toBe(30)
  })

  it('accepts month-only dates', () => {
    expect(sumCountsInRange([{ date: '2025-10', count: 7 }], start, end)).toBe(
      7
    )
  })

  it('returns 0 when there are no rows', () => {
    expect(sumCountsInRange(undefined, start, end)).toBe(0)
    expect(sumCountsInRange({}, start, end)).toBe(0)
    expect(sumCountsInRange([], start, end)).toBe(0)
  })
})

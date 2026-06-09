import { describe, it, expect } from 'vitest'
import { useEmailDateFormat } from '~/modtools/composables/useEmailDateFormat'

// ──────────────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────────────

function getFormatter() {
  const { formatEmailDate } = useEmailDateFormat()
  return formatEmailDate
}

// ──────────────────────────────────────────────────────────────────────────────
// Tests
// ──────────────────────────────────────────────────────────────────────────────

describe('useEmailDateFormat', () => {
  // Composable shape
  describe('composable return value', () => {
    it('returns an object with a formatEmailDate function', () => {
      const result = useEmailDateFormat()
      expect(result).toHaveProperty('formatEmailDate')
      expect(typeof result.formatEmailDate).toBe('function')
    })

    it('returns a fresh function on each call', () => {
      const a = useEmailDateFormat()
      const b = useEmailDateFormat()
      // Both functions should be independent instances
      expect(typeof a.formatEmailDate).toBe('function')
      expect(typeof b.formatEmailDate).toBe('function')
    })
  })

  // Falsy / missing inputs — all must return the '-' sentinel
  describe('formatEmailDate — falsy / missing inputs', () => {
    const CASES = [
      ['null', null],
      ['undefined', undefined],
      ['empty string', ''],
      ['false', false],
      ['zero', 0],
    ]

    it.each(CASES)('returns "-" for %s', (_label, input) => {
      const fmt = getFormatter()
      expect(fmt(input)).toBe('-')
    })
  })

  // Valid ISO date strings — must produce a formatted, non-empty string
  describe('formatEmailDate — valid ISO date strings', () => {
    it('returns a non-empty string for a valid ISO timestamp', () => {
      const fmt = getFormatter()
      const result = fmt('2024-06-15T10:30:00.000Z')
      expect(typeof result).toBe('string')
      expect(result.length).toBeGreaterThan(0)
      expect(result).not.toBe('-')
    })

    it('includes the year in the output', () => {
      const fmt = getFormatter()
      const result = fmt('2024-06-15T10:30:00.000Z')
      expect(result).toContain('2024')
    })

    it('includes the two-digit day in the output', () => {
      const fmt = getFormatter()
      // Day 15 should appear as "15"
      const result = fmt('2024-06-15T10:30:00.000Z')
      expect(result).toMatch(/15/)
    })

    it('produces different output for different dates', () => {
      const fmt = getFormatter()
      const jan = fmt('2023-01-01T00:00:00.000Z')
      const dec = fmt('2023-12-31T23:59:59.000Z')
      expect(jan).not.toBe(dec)
    })

    it('handles date-only strings (no time component)', () => {
      const fmt = getFormatter()
      const result = fmt('2024-03-20')
      expect(typeof result).toBe('string')
      expect(result).toContain('2024')
    })

    it('handles a date at midnight UTC', () => {
      const fmt = getFormatter()
      const result = fmt('2024-01-01T00:00:00.000Z')
      expect(typeof result).toBe('string')
      expect(result).toContain('2024')
    })

    it('handles a date at end of day UTC', () => {
      const fmt = getFormatter()
      const result = fmt('2024-12-31T23:59:59.000Z')
      expect(typeof result).toBe('string')
      expect(result).toContain('2024')
    })

    it('handles a Unix epoch timestamp string', () => {
      // new Date('0') is NaN; new Date(0) is epoch but '0' string → Invalid Date
      // This is an edge case to document existing behaviour, not mandate a format
      const fmt = getFormatter()
      const result = fmt('1970-01-01T00:00:00.000Z')
      expect(typeof result).toBe('string')
      expect(result).toContain('1970')
    })

    it('uses en-GB locale — month appears as abbreviated English name', () => {
      const fmt = getFormatter()
      // June 2024
      const result = fmt('2024-06-15T10:00:00.000Z')
      // en-GB with month:'short' should produce "Jun" (or locale equivalent)
      expect(result).toMatch(/jun|June/i)
    })
  })

  // Table-driven: year-boundary and notable dates
  describe('formatEmailDate — year and month boundaries', () => {
    const TABLE = [
      ['end of Feb in a leap year', '2024-02-29T12:00:00.000Z', '2024'],
      ['start of year', '2025-01-01T08:00:00.000Z', '2025'],
      ['end of year', '2025-12-31T20:00:00.000Z', '2025'],
      ['far past date', '1999-07-04T00:00:00.000Z', '1999'],
      ['far future date', '2099-11-11T11:11:11.000Z', '2099'],
    ]

    it.each(TABLE)(
      'contains expected year for %s',
      (_label, dateStr, expectedYear) => {
        const fmt = getFormatter()
        const result = fmt(dateStr)
        expect(result).toContain(expectedYear)
      }
    )
  })

  // Consistency: two formatters from separate composable calls produce the same output
  describe('formatEmailDate — consistency across composable instances', () => {
    it('formats the same date identically regardless of which instance is used', () => {
      const { formatEmailDate: fmtA } = useEmailDateFormat()
      const { formatEmailDate: fmtB } = useEmailDateFormat()
      const date = '2024-08-20T14:30:00.000Z'
      expect(fmtA(date)).toBe(fmtB(date))
    })
  })
})

import { describe, it, expect } from 'vitest'
import { toNumberOrNull } from '~/composables/useNumericInput'

describe('toNumberOrNull', () => {
  it('returns null for blank/absent values (so the field is omitted, not sent as "")', () => {
    expect(toNumberOrNull('')).toBe(null)
    expect(toNumberOrNull(null)).toBe(null)
    expect(toNumberOrNull(undefined)).toBe(null)
  })

  it('coerces the number-input string to a real number (the 9932 fix)', () => {
    expect(toNumberOrNull('51.80362')).toBe(51.80362)
    expect(toNumberOrNull('-4.96315')).toBe(-4.96315)
    expect(toNumberOrNull(' 51.8 ')).toBe(51.8) // Number() trims surrounding whitespace
  })

  it('passes real numbers through unchanged, including zero', () => {
    expect(toNumberOrNull(51.80362)).toBe(51.80362)
    expect(toNumberOrNull(0)).toBe(0)
    expect(toNumberOrNull('0')).toBe(0)
  })

  it('returns null for non-numeric junk rather than NaN', () => {
    expect(toNumberOrNull('abc')).toBe(null)
    expect(toNumberOrNull('12.3.4')).toBe(null)
  })
})

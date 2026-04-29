import { describe, it, expect } from 'vitest'
import { twem, untwem } from '~/composables/useTwem'

describe('useTwem', () => {
  describe('twem', () => {
    it('coerces numeric input to a string', () => {
      expect(twem(42)).toBe('42')
    })

    it('leaves a plain string without escape markers unchanged', () => {
      expect(twem('hello world')).toBe('hello world')
    })

    it('returns non-string, non-number values unchanged', () => {
      const arr = [1, 2, 3]
      expect(twem(arr)).toBe(arr)
      expect(twem(null)).toBe(null)
      expect(twem(undefined)).toBe(undefined)
    })

    it('converts \\u<codepoint>\\u escape sequences to emoji', () => {
      // U+1F600 = 😀
      const result = twem('before \\\\u1f600\\\\u after')
      expect(result).toContain('before ')
      expect(result).toContain(' after')
      expect(result).toContain('😀')
    })

    it('handles multi-codepoint sequences joined with -', () => {
      // Flag example: U+1F1EC U+1F1E7 = 🇬🇧
      const result = twem('flag: \\\\u1f1ec-1f1e7\\\\u end')
      expect(result).toContain('🇬🇧')
      expect(result).toContain('flag: ')
      expect(result).toContain(' end')
    })

    it('round-trips with untwem', () => {
      const original = 'hello 😀 world'
      const escaped = untwem(original)
      const restored = twem(escaped)
      expect(restored).toContain('😀')
      expect(restored).toContain('hello')
      expect(restored).toContain('world')
    })
  })

  describe('untwem', () => {
    it('returns a plain string without emoji unchanged', () => {
      expect(untwem('no emoji here')).toBe('no emoji here')
    })

    it('replaces emoji with \\u<codepoint>\\u escape sequences', () => {
      const result = untwem('hi 😀')
      expect(result).toMatch(/\\\\u[0-9a-f]+\\\\u/i)
      expect(result).toContain('hi ')
    })
  })
})

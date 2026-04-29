import { describe, it, expect } from 'vitest'
import {
  BlurThresholds,
  shouldWarnBlur,
  useBlurDetector,
} from '~/composables/useBlurDetector'

describe('BlurThresholds', () => {
  it('exports the expected threshold values in descending order', () => {
    expect(BlurThresholds.SHARP).toBe(500)
    expect(BlurThresholds.ACCEPTABLE).toBe(200)
    expect(BlurThresholds.WARNING).toBe(100)
    expect(BlurThresholds.CRITICAL).toBe(50)
    expect(BlurThresholds.SHARP).toBeGreaterThan(BlurThresholds.ACCEPTABLE)
    expect(BlurThresholds.ACCEPTABLE).toBeGreaterThan(BlurThresholds.WARNING)
    expect(BlurThresholds.WARNING).toBeGreaterThan(BlurThresholds.CRITICAL)
  })
})

describe('shouldWarnBlur', () => {
  it('returns critical warning below the CRITICAL threshold', () => {
    const result = shouldWarnBlur(10)
    expect(result.warn).toBe(true)
    expect(result.severity).toBe('critical')
    expect(result.message).toMatch(/very blurry/i)
  })

  it('returns warning between CRITICAL and WARNING thresholds', () => {
    const result = shouldWarnBlur(75)
    expect(result.warn).toBe(true)
    expect(result.severity).toBe('warning')
    expect(result.message).toMatch(/slightly blurry/i)
  })

  it('returns info between WARNING and ACCEPTABLE thresholds', () => {
    const result = shouldWarnBlur(150)
    expect(result.warn).toBe(true)
    expect(result.severity).toBe('info')
    expect(result.message).toMatch(/clarity could be better/i)
  })

  it('returns no warning at or above ACCEPTABLE threshold', () => {
    const result = shouldWarnBlur(200)
    expect(result.warn).toBe(false)
    expect(result.severity).toBe('none')
    expect(result.message).toMatch(/clear/i)
  })

  it('returns no warning for very sharp images', () => {
    const result = shouldWarnBlur(1000)
    expect(result.warn).toBe(false)
    expect(result.severity).toBe('none')
  })

  it('treats the CRITICAL boundary (exactly 50) as warning severity, not critical', () => {
    // 50 is NOT < CRITICAL(50), so falls through to next branch (< WARNING 100)
    const result = shouldWarnBlur(50)
    expect(result.severity).toBe('warning')
  })

  it('treats the WARNING boundary (exactly 100) as info severity, not warning', () => {
    // 100 is NOT < WARNING(100), so falls through to next branch (< ACCEPTABLE 200)
    const result = shouldWarnBlur(100)
    expect(result.severity).toBe('info')
  })

  it('treats zero as the most-critical case', () => {
    const result = shouldWarnBlur(0)
    expect(result.severity).toBe('critical')
    expect(result.warn).toBe(true)
  })
})

describe('useBlurDetector composable', () => {
  it('returns an object exposing the public helpers and thresholds', () => {
    const api = useBlurDetector()
    expect(api).toHaveProperty('analyzeBlur')
    expect(api).toHaveProperty('shouldWarnBlur')
    expect(api).toHaveProperty('BlurThresholds')
    expect(typeof api.analyzeBlur).toBe('function')
    expect(typeof api.shouldWarnBlur).toBe('function')
    expect(api.BlurThresholds).toBe(BlurThresholds)
  })

  it('exposes shouldWarnBlur with the same behaviour as the named export', () => {
    const api = useBlurDetector()
    expect(api.shouldWarnBlur(10)).toEqual(shouldWarnBlur(10))
    expect(api.shouldWarnBlur(300)).toEqual(shouldWarnBlur(300))
  })
})

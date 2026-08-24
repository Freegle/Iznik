import { describe, it, expect, vi } from 'vitest'
import {
  BlurThresholds,
  analyzeBlur,
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

// The image loader must never hang forever: on the app share path a
// capacitor:// file URL can fire neither onload nor onerror (native side
// still flushing the file), and an unsettled promise here blocked the whole
// quality check and with it the upload - a photo tile stuck at 0% with no
// error and no retry. The loader now rejects after a timeout, which
// analyzePhotoQuality's existing catch turns into "continue without quality
// warnings".
describe('analyzeBlur image-load timeout', () => {
  it('rejects instead of hanging when the image never loads or errors', async () => {
    vi.useFakeTimers()
    const RealImage = globalThis.Image
    // An Image that never fires onload or onerror - the stall case.
    globalThis.Image = class {
      get src() {
        return undefined
      }

      set src(_) {
        // Never calls back.
      }
    }

    try {
      const pending = analyzeBlur(
        'capacitor://localhost/_capacitor_file_/x.jpg'
      )
      // Attach the rejection expectation before advancing time so the
      // rejection is observed, not unhandled.
      const assertion = expect(pending).rejects.toThrow(/timed out/i)
      await vi.advanceTimersByTimeAsync(8001)
      await assertion
    } finally {
      globalThis.Image = RealImage
      vi.useRealTimers()
    }
  })
})

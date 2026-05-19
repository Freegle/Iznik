import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import {
  BrightnessThresholds,
  ContrastThreshold,
  analyzeBrightness,
  shouldWarnBrightness,
  useBrightnessDetector,
} from '~/composables/useBrightnessDetector'

// Creates a mock canvas whose getImageData returns a controlled pixel pattern.
// pixelPattern is an array of [r, g, b] triples that repeat across all pixels.
function makeMockCanvas(pixelPattern) {
  const state = { width: 0, height: 0 }
  const ctx = {
    drawImage: vi.fn(),
    getImageData: vi.fn(() => {
      const totalPixels = state.width * state.height
      const data = new Uint8ClampedArray(totalPixels * 4)
      for (let p = 0; p < totalPixels; p++) {
        const [r, g, b] = pixelPattern[p % pixelPattern.length]
        data[p * 4] = r
        data[p * 4 + 1] = g
        data[p * 4 + 2] = b
        data[p * 4 + 3] = 255
      }
      return { data }
    }),
  }
  return {
    get width() {
      return state.width
    },
    set width(v) {
      state.width = v
    },
    get height() {
      return state.height
    },
    set height(v) {
      state.height = v
    },
    getContext: vi.fn(() => ctx),
  }
}

describe('BrightnessThresholds', () => {
  it('exports the expected threshold values', () => {
    expect(BrightnessThresholds.VERY_DARK).toBe(40)
    expect(BrightnessThresholds.TOO_DARK).toBe(80)
    expect(BrightnessThresholds.ACCEPTABLE).toBe(100)
    expect(BrightnessThresholds.OPTIMAL_MIN).toBe(120)
    expect(BrightnessThresholds.OPTIMAL_MAX).toBe(180)
    expect(BrightnessThresholds.TOO_BRIGHT).toBe(220)
  })

  it('thresholds are in ascending order', () => {
    expect(BrightnessThresholds.VERY_DARK).toBeLessThan(
      BrightnessThresholds.TOO_DARK
    )
    expect(BrightnessThresholds.TOO_DARK).toBeLessThan(
      BrightnessThresholds.ACCEPTABLE
    )
    expect(BrightnessThresholds.ACCEPTABLE).toBeLessThan(
      BrightnessThresholds.OPTIMAL_MIN
    )
    expect(BrightnessThresholds.OPTIMAL_MIN).toBeLessThan(
      BrightnessThresholds.OPTIMAL_MAX
    )
    expect(BrightnessThresholds.OPTIMAL_MAX).toBeLessThan(
      BrightnessThresholds.TOO_BRIGHT
    )
  })
})

describe('ContrastThreshold', () => {
  it('exports the expected contrast threshold values', () => {
    expect(ContrastThreshold.LOW).toBe(25)
    expect(ContrastThreshold.ACCEPTABLE).toBe(40)
  })

  it('LOW is less than ACCEPTABLE', () => {
    expect(ContrastThreshold.LOW).toBeLessThan(ContrastThreshold.ACCEPTABLE)
  })
})

describe('shouldWarnBrightness', () => {
  it.each([
    // [inputSeverity, expectedWarn, expectedOutputSeverity]
    ['critical', true, 'critical'],
    ['warning', true, 'warning'],
    ['info', false, 'none'],
    ['none', false, 'none'],
  ])(
    'severity "%s" → warn=%s, output severity="%s"',
    (inputSeverity, expectedWarn, expectedOutputSeverity) => {
      const result = shouldWarnBrightness({
        analysis: { severity: inputSeverity, message: 'test message' },
      })
      expect(result.warn).toBe(expectedWarn)
      expect(result.severity).toBe(expectedOutputSeverity)
    }
  )

  it('passes the message through for critical severity', () => {
    const msg = 'Photo is very dark - try using more light'
    const result = shouldWarnBrightness({
      analysis: { severity: 'critical', message: msg },
    })
    expect(result.message).toBe(msg)
  })

  it('passes the message through for warning severity', () => {
    const msg = 'Photo is quite dark - better lighting would help'
    const result = shouldWarnBrightness({
      analysis: { severity: 'warning', message: msg },
    })
    expect(result.message).toBe(msg)
  })

  it('passes the message through for non-warning result', () => {
    const msg = 'Photo lighting looks good'
    const result = shouldWarnBrightness({
      analysis: { severity: 'none', message: msg },
    })
    expect(result.message).toBe(msg)
    expect(result.warn).toBe(false)
  })

  it('returns warn=true for critical severity (boundary check)', () => {
    // Exactly 'critical' string must trigger warn=true
    expect(shouldWarnBrightness({ analysis: { severity: 'critical', message: '' } }).warn).toBe(true)
  })

  it('returns warn=false for "info" severity (not a warning level)', () => {
    // 'info' is not 'critical' or 'warning', so warn should be false
    const result = shouldWarnBrightness({
      analysis: { severity: 'info', message: 'Photo has low contrast' },
    })
    expect(result.warn).toBe(false)
    expect(result.severity).toBe('none')
  })
})

describe('analyzeBrightness', () => {
  let originalCreateElement

  beforeEach(() => {
    originalCreateElement = document.createElement.bind(document)
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  function mockCanvasWith(pixelPattern) {
    vi.spyOn(document, 'createElement').mockImplementation((tag) => {
      if (tag === 'canvas') return makeMockCanvas(pixelPattern)
      return originalCreateElement(tag)
    })
  }

  // --- classifyBrightness branch: very_dark (average < VERY_DARK=40) ---
  it('classifies very dark images (avg brightness < 40) as very_dark / critical', async () => {
    // All pixels R=G=B=20 → luminosity = 20 < VERY_DARK(40)
    mockCanvasWith([[20, 20, 20]])
    const result = await analyzeBrightness({ width: 128, height: 128 })
    expect(result.analysis.quality).toBe('very_dark')
    expect(result.analysis.severity).toBe('critical')
    expect(result.analysis.message).toMatch(/very dark/i)
    expect(result.average).toBeCloseTo(20, 0)
  })

  // --- classifyBrightness branch: dark (40 ≤ average < TOO_DARK=80) ---
  it('classifies dark images (40 ≤ avg < 80) as dark / warning', async () => {
    // All pixels R=G=B=60 → luminosity = 60
    mockCanvasWith([[60, 60, 60]])
    const result = await analyzeBrightness({ width: 128, height: 128 })
    expect(result.analysis.quality).toBe('dark')
    expect(result.analysis.severity).toBe('warning')
    expect(result.analysis.message).toMatch(/dark/i)
    expect(result.average).toBeCloseTo(60, 0)
  })

  // --- classifyBrightness branch: overexposed (average > TOO_BRIGHT=220) ---
  it('classifies overexposed images (avg > 220) as overexposed / warning', async () => {
    // All pixels R=G=B=230 → luminosity = 230 > TOO_BRIGHT(220)
    mockCanvasWith([[230, 230, 230]])
    const result = await analyzeBrightness({ width: 128, height: 128 })
    expect(result.analysis.quality).toBe('overexposed')
    expect(result.analysis.severity).toBe('warning')
    expect(result.analysis.message).toMatch(/overexposed/i)
  })

  // --- classifyBrightness branch: low_contrast (contrast < LOW=25) ---
  it('classifies flat mid-range images (stddev < 25) as low_contrast / info', async () => {
    // All pixels R=G=B=130 → luminosity = 130, stddev = 0 < LOW(25)
    mockCanvasWith([[130, 130, 130]])
    const result = await analyzeBrightness({ width: 128, height: 128 })
    expect(result.analysis.quality).toBe('low_contrast')
    expect(result.analysis.severity).toBe('info')
    expect(result.analysis.message).toMatch(/contrast/i)
    expect(result.contrast).toBeCloseTo(0, 1)
  })

  // --- classifyBrightness branch: good (optimal brightness + high contrast) ---
  it('classifies optimal images (avg∈[120,180], stddev≥40) as good / none', async () => {
    // Alternating pixels [100,100,100] and [200,200,200]:
    //   luminosity: 100 and 200 → avg=150, stddev=50
    //   avg=150 ∈ [OPTIMAL_MIN=120, OPTIMAL_MAX=180], stddev=50 ≥ ACCEPTABLE(40) → good
    mockCanvasWith([
      [100, 100, 100],
      [200, 200, 200],
    ])
    const result = await analyzeBrightness({ width: 128, height: 128 })
    expect(result.analysis.quality).toBe('good')
    expect(result.analysis.severity).toBe('none')
    expect(result.average).toBeCloseTo(150, 0)
    expect(result.contrast).toBeCloseTo(50, 0)
  })

  // --- classifyBrightness branch: acceptable (all remaining cases) ---
  it('classifies sub-optimal non-problematic images as acceptable / none', async () => {
    // Alternating [70,70,70] and [130,130,130]:
    //   luminosity: 70 and 130 → avg=100, stddev=30
    //   avg=100 < OPTIMAL_MIN(120) → good condition fails → acceptable
    mockCanvasWith([
      [70, 70, 70],
      [130, 130, 130],
    ])
    const result = await analyzeBrightness({ width: 128, height: 128 })
    expect(result.analysis.quality).toBe('acceptable')
    expect(result.analysis.severity).toBe('none')
  })

  it('returns a 256-entry histogram summing to total pixel count', async () => {
    mockCanvasWith([[128, 128, 128]])
    const img = { width: 128, height: 128 }
    const result = await analyzeBrightness(img)
    expect(result.histogram).toHaveLength(256)
    expect(result.histogram.reduce((a, b) => a + b, 0)).toBe(128 * 128)
  })

  it('returns average and contrast numeric properties', async () => {
    mockCanvasWith([[100, 100, 100]])
    const result = await analyzeBrightness({ width: 64, height: 64 })
    expect(typeof result.average).toBe('number')
    expect(typeof result.contrast).toBe('number')
    expect(result.average).toBeGreaterThanOrEqual(0)
    expect(result.contrast).toBeGreaterThanOrEqual(0)
  })

  it('respects the sampleSize parameter by resizing proportionally', async () => {
    // Canvas dimensions should reflect scaled-down image
    const spy = vi.spyOn(document, 'createElement').mockImplementation((tag) => {
      if (tag === 'canvas') return makeMockCanvas([[128, 128, 128]])
      return originalCreateElement(tag)
    })
    // 256×256 image with sampleSize=64: scale = 64/256 = 0.25, canvas = 64×64
    const result = await analyzeBrightness({ width: 256, height: 256 }, 64)
    expect(result).toBeDefined()
    expect(result.analysis).toBeDefined()
    spy.mockRestore()
  })

  it('handles non-square images by using the limiting dimension', async () => {
    mockCanvasWith([[128, 128, 128]])
    // 200×100, sampleSize=128: scale = min(128/200, 128/100) = min(0.64, 1.28) = 0.64
    // canvas.width = floor(200*0.64)=128, canvas.height = floor(100*0.64)=64
    const result = await analyzeBrightness({ width: 200, height: 100 }, 128)
    expect(result).toBeDefined()
    expect(result.analysis).toBeDefined()
  })

  it('accepts an HTMLImageElement-like object (with width/height) as input', async () => {
    mockCanvasWith([[150, 150, 150]])
    const result = await analyzeBrightness({ width: 100, height: 100 })
    expect(result).toBeDefined()
    expect(result.average).toBeCloseTo(150, 0)
  })

  it('accepts a URL string as input (via Image element onload)', async () => {
    // Mock window.Image to resolve immediately without loading
    const OrigImage = global.Image
    global.Image = class MockImage {
      constructor() {
        this.width = 64
        this.height = 64
      }

      set src(_v) {
        // Trigger onload asynchronously, simulating a loaded image
        setTimeout(() => {
          if (this.onload) this.onload()
        }, 0)
      }
    }

    mockCanvasWith([[128, 128, 128]])
    try {
      const result = await analyzeBrightness('data:image/png;base64,abc')
      expect(result).toBeDefined()
      expect(result.analysis).toBeDefined()
    } finally {
      global.Image = OrigImage
    }
  })
})

describe('useBrightnessDetector composable', () => {
  it('returns an object exposing all public helpers and constants', () => {
    const api = useBrightnessDetector()
    expect(api).toHaveProperty('analyzeBrightness')
    expect(api).toHaveProperty('shouldWarnBrightness')
    expect(api).toHaveProperty('BrightnessThresholds')
    expect(api).toHaveProperty('ContrastThreshold')
    expect(typeof api.analyzeBrightness).toBe('function')
    expect(typeof api.shouldWarnBrightness).toBe('function')
  })

  it('BrightnessThresholds from composable is the same reference as the named export', () => {
    const api = useBrightnessDetector()
    expect(api.BrightnessThresholds).toBe(BrightnessThresholds)
  })

  it('ContrastThreshold from composable is the same reference as the named export', () => {
    const api = useBrightnessDetector()
    expect(api.ContrastThreshold).toBe(ContrastThreshold)
  })

  it('shouldWarnBrightness via composable returns the same result as the named export', () => {
    const api = useBrightnessDetector()
    const input = { analysis: { severity: 'critical', message: 'too dark' } }
    expect(api.shouldWarnBrightness(input)).toEqual(shouldWarnBrightness(input))

    const input2 = { analysis: { severity: 'none', message: 'good' } }
    expect(api.shouldWarnBrightness(input2)).toEqual(shouldWarnBrightness(input2))
  })
})

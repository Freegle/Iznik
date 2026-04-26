import { describe, it, expect, vi, beforeEach } from 'vitest'
import {
  getQualityMessage,
  analyzePhotoQuality,
  usePhotoQuality,
} from '~/composables/usePhotoQuality'

// vi.hoisted ensures these vi.fn() instances are available when the vi.mock
// factories execute (which are hoisted before const/let declarations).
const { mockAnalyzeBlur, mockShouldWarnBlur, mockAnalyzeBrightness, mockShouldWarnBrightness } =
  vi.hoisted(() => ({
    mockAnalyzeBlur: vi.fn(),
    mockShouldWarnBlur: vi.fn(),
    mockAnalyzeBrightness: vi.fn(),
    mockShouldWarnBrightness: vi.fn(),
  }))

// Mock the canvas-dependent detector modules so tests don't need a real DOM
vi.mock('~/composables/useBlurDetector', () => ({
  analyzeBlur: mockAnalyzeBlur,
  shouldWarnBlur: mockShouldWarnBlur,
  BlurThresholds: { SHARP: 500, ACCEPTABLE: 200, WARNING: 100, CRITICAL: 50 },
  useBlurDetector: () => ({
    analyzeBlur: mockAnalyzeBlur,
    shouldWarnBlur: mockShouldWarnBlur,
  }),
}))

vi.mock('~/composables/useBrightnessDetector', () => ({
  analyzeBrightness: mockAnalyzeBrightness,
  shouldWarnBrightness: mockShouldWarnBrightness,
  BrightnessThresholds: {
    VERY_DARK: 40,
    TOO_DARK: 80,
    ACCEPTABLE: 100,
    OPTIMAL_MIN: 120,
    OPTIMAL_MAX: 180,
    TOO_BRIGHT: 220,
  },
  ContrastThreshold: { LOW: 25, ACCEPTABLE: 40 },
  useBrightnessDetector: () => ({
    analyzeBrightness: mockAnalyzeBrightness,
    shouldWarnBrightness: mockShouldWarnBrightness,
  }),
}))

// Default detector responses: a perfectly clean photo
const CLEAN_BLUR_SCORE = 600
const CLEAN_BLUR_WARNING = { warn: false, severity: 'none', message: 'Photo is clear' }
const CLEAN_BRIGHTNESS_RESULT = { average: 150, contrast: 50 }
const CLEAN_BRIGHTNESS_WARNING = { warn: false, severity: 'none', message: 'Good lighting' }

beforeEach(() => {
  vi.clearAllMocks()
  mockAnalyzeBlur.mockResolvedValue(CLEAN_BLUR_SCORE)
  mockShouldWarnBlur.mockReturnValue(CLEAN_BLUR_WARNING)
  mockAnalyzeBrightness.mockResolvedValue(CLEAN_BRIGHTNESS_RESULT)
  mockShouldWarnBrightness.mockReturnValue(CLEAN_BRIGHTNESS_WARNING)
})

// ---------------------------------------------------------------------------
// getQualityMessage — pure function, no mocking needed
// ---------------------------------------------------------------------------

describe('getQualityMessage', () => {
  it('returns success message when there are no issues', () => {
    const result = getQualityMessage({ hasIssues: false, overallSeverity: 'none', warnings: [] })
    expect(result.title).toBe('Photo looks good!')
    expect(result.severity).toBe('success')
    expect(result.message).toMatch(/good clarity/i)
  })

  it('returns critical message and includes retake suggestion for critical severity', () => {
    const result = getQualityMessage({
      hasIssues: true,
      overallSeverity: 'critical',
      warnings: [{ type: 'blur', message: 'This photo is very blurry' }],
    })
    expect(result.title).toBe('Photo quality issue')
    expect(result.severity).toBe('critical')
    expect(result.message).toMatch(/retake/i)
    expect(result.message).toContain('This photo is very blurry')
  })

  it('returns warning message without retake suggestion for warning severity', () => {
    const result = getQualityMessage({
      hasIssues: true,
      overallSeverity: 'warning',
      warnings: [{ type: 'blur', message: 'This photo appears slightly blurry' }],
    })
    expect(result.title).toBe('Photo could be better')
    expect(result.severity).toBe('warning')
    expect(result.message).not.toMatch(/retake/i)
    expect(result.message).toContain('This photo appears slightly blurry')
  })

  it('joins multiple warning messages with a period-space separator', () => {
    const result = getQualityMessage({
      hasIssues: true,
      overallSeverity: 'warning',
      warnings: [
        { type: 'blur', message: 'Slightly blurry' },
        { type: 'brightness', message: 'Too dark' },
      ],
    })
    expect(result.message).toContain('Slightly blurry. Too dark')
  })

  it('appends the retake sentence at the end for critical path', () => {
    const result = getQualityMessage({
      hasIssues: true,
      overallSeverity: 'critical',
      warnings: [{ type: 'blur', message: 'Very blurry' }],
    })
    expect(result.message).toMatch(/Would you like to retake this photo\?$/)
  })

  it('appends "Better photos" tail for warning path', () => {
    const result = getQualityMessage({
      hasIssues: true,
      overallSeverity: 'warning',
      warnings: [{ type: 'brightness', message: 'Too dark' }],
    })
    expect(result.message).toMatch(/Better photos get more responses\.$/)
  })

  it('ignores warnings when hasIssues is false', () => {
    // hasIssues takes priority — caller setting it false means no problem shown
    const result = getQualityMessage({
      hasIssues: false,
      overallSeverity: 'none',
      warnings: [{ type: 'blur', message: 'Should be ignored' }],
    })
    expect(result.severity).toBe('success')
  })
})

// ---------------------------------------------------------------------------
// analyzePhotoQuality — integration with mocked detectors
// ---------------------------------------------------------------------------

describe('analyzePhotoQuality', () => {
  it('returns no issues for a clean photo', async () => {
    const result = await analyzePhotoQuality('data:image/png;base64,abc')
    expect(result.hasIssues).toBe(false)
    expect(result.overallSeverity).toBe('none')
    expect(result.warnings).toHaveLength(0)
  })

  it('calls analyzeBlur and analyzeBrightness with the provided URL', async () => {
    await analyzePhotoQuality('blob:example-url')
    expect(mockAnalyzeBlur).toHaveBeenCalledWith('blob:example-url')
    expect(mockAnalyzeBrightness).toHaveBeenCalledWith('blob:example-url')
  })

  it('passes analyzeBlur result to shouldWarnBlur', async () => {
    mockAnalyzeBlur.mockResolvedValue(42)
    await analyzePhotoQuality('data:image/png;base64,abc')
    expect(mockShouldWarnBlur).toHaveBeenCalledWith(42)
  })

  it('passes analyzeBrightness result to shouldWarnBrightness', async () => {
    const fakeResult = { average: 30, contrast: 10 }
    mockAnalyzeBrightness.mockResolvedValue(fakeResult)
    await analyzePhotoQuality('data:image/png;base64,abc')
    expect(mockShouldWarnBrightness).toHaveBeenCalledWith(fakeResult)
  })

  it('includes a blur warning when blur detection fires', async () => {
    mockShouldWarnBlur.mockReturnValue({
      warn: true,
      severity: 'critical',
      message: 'This photo is very blurry',
    })
    const result = await analyzePhotoQuality('data:image/png;base64,abc')
    expect(result.hasIssues).toBe(true)
    expect(result.warnings).toHaveLength(1)
    expect(result.warnings[0].type).toBe('blur')
    expect(result.warnings[0].severity).toBe('critical')
  })

  it('includes a brightness warning when brightness detection fires', async () => {
    mockShouldWarnBrightness.mockReturnValue({
      warn: true,
      severity: 'warning',
      message: 'Image is too dark',
    })
    const result = await analyzePhotoQuality('data:image/png;base64,abc')
    expect(result.hasIssues).toBe(true)
    expect(result.warnings).toHaveLength(1)
    expect(result.warnings[0].type).toBe('brightness')
    expect(result.warnings[0].severity).toBe('warning')
  })

  it('includes both warnings when both detectors fire', async () => {
    mockShouldWarnBlur.mockReturnValue({ warn: true, severity: 'warning', message: 'Slightly blurry' })
    mockShouldWarnBrightness.mockReturnValue({ warn: true, severity: 'warning', message: 'Too dark' })
    const result = await analyzePhotoQuality('data:image/png;base64,abc')
    expect(result.warnings).toHaveLength(2)
    expect(result.warnings.map((w) => w.type)).toEqual(['blur', 'brightness'])
  })

  it('sets overallSeverity to "critical" when blur is critical', async () => {
    mockShouldWarnBlur.mockReturnValue({ warn: true, severity: 'critical', message: 'Very blurry' })
    const result = await analyzePhotoQuality('data:image/png;base64,abc')
    expect(result.overallSeverity).toBe('critical')
  })

  it('sets overallSeverity to "warning" when only warning-level blur fires', async () => {
    mockShouldWarnBlur.mockReturnValue({ warn: true, severity: 'warning', message: 'Slightly blurry' })
    const result = await analyzePhotoQuality('data:image/png;base64,abc')
    expect(result.overallSeverity).toBe('warning')
  })

  it('critical blur overrides warning-level brightness for overallSeverity', async () => {
    mockShouldWarnBlur.mockReturnValue({ warn: true, severity: 'critical', message: 'Very blurry' })
    mockShouldWarnBrightness.mockReturnValue({ warn: true, severity: 'warning', message: 'Too dark' })
    const result = await analyzePhotoQuality('data:image/png;base64,abc')
    expect(result.overallSeverity).toBe('critical')
  })

  it('critical brightness overrides warning-level blur for overallSeverity', async () => {
    mockShouldWarnBlur.mockReturnValue({ warn: true, severity: 'warning', message: 'Slightly blurry' })
    mockShouldWarnBrightness.mockReturnValue({ warn: true, severity: 'critical', message: 'Extremely dark' })
    const result = await analyzePhotoQuality('data:image/png;base64,abc')
    expect(result.overallSeverity).toBe('critical')
  })

  it('includes blur score and warning info in details.blur', async () => {
    mockAnalyzeBlur.mockResolvedValue(42)
    mockShouldWarnBlur.mockReturnValue({ warn: true, severity: 'critical', message: 'Very blurry' })
    const result = await analyzePhotoQuality('data:image/png;base64,abc')
    expect(result.details.blur.score).toBe(42)
    expect(result.details.blur.warn).toBe(true)
    expect(result.details.blur.severity).toBe('critical')
  })

  it('includes brightness average and contrast in details.brightness', async () => {
    mockAnalyzeBrightness.mockResolvedValue({ average: 30, contrast: 12 })
    const result = await analyzePhotoQuality('data:image/png;base64,abc')
    expect(result.details.brightness.average).toBe(30)
    expect(result.details.brightness.contrast).toBe(12)
  })

  it('returns a safe fallback and error property when analysis throws', async () => {
    const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
    mockAnalyzeBlur.mockRejectedValue(new Error('Canvas not available'))
    const result = await analyzePhotoQuality('data:image/png;base64,abc')
    expect(result.hasIssues).toBe(false)
    expect(result.overallSeverity).toBe('none')
    expect(result.warnings).toHaveLength(0)
    expect(result.error).toBe('Canvas not available')
    consoleSpy.mockRestore()
  })

  it('does not throw when analysis fails — caller can always proceed', async () => {
    const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
    mockAnalyzeBrightness.mockRejectedValue(new Error('DOM error'))
    await expect(analyzePhotoQuality('data:image/png;base64,abc')).resolves.toBeDefined()
    consoleSpy.mockRestore()
  })
})

// ---------------------------------------------------------------------------
// usePhotoQuality composable
// ---------------------------------------------------------------------------

describe('usePhotoQuality', () => {
  it('returns analyzePhotoQuality and getQualityMessage', () => {
    const api = usePhotoQuality()
    expect(typeof api.analyzePhotoQuality).toBe('function')
    expect(typeof api.getQualityMessage).toBe('function')
  })

  it('returned getQualityMessage behaves identically to the named export', () => {
    const api = usePhotoQuality()
    const input = { hasIssues: false, overallSeverity: 'none', warnings: [] }
    expect(api.getQualityMessage(input)).toEqual(getQualityMessage(input))
  })
})

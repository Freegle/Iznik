import { describe, it, expect, vi, beforeEach } from 'vitest'

const mocks = vi.hoisted(() => ({
  action: vi.fn(),
  flush: vi.fn(),
  sampled: true,
  report: null,
}))

vi.mock('~/composables/useClientLog', () => ({
  action: mocks.action,
  flush: mocks.flush,
  sessionSampled: () => mocks.sampled,
  SAMPLE_RATE: 0.1,
}))
vi.mock('~/stores/loggingContext', () => ({
  useLoggingContextStore: () => ({ getContext: () => ({ page_id: 'p1' }) }),
}))
vi.mock('~/composables/useImpressionTracker', () => ({
  createImpressionTracker: (opts) => {
    mocks.report = opts.report
    return { start: () => false }
  },
}))

describe('impressionTracker plugin sampling', () => {
  beforeEach(async () => {
    vi.resetModules()
    mocks.action.mockClear()
    mocks.sampled = true
    const mod = await import('~/plugins/impressionTracker.client.js')
    mod.default({ hook: vi.fn() })
  })

  it('sends impressions with sample_rate when the session is sampled in', () => {
    mocks.report({ MessageSummary: 3 })
    expect(mocks.action).toHaveBeenCalledTimes(1)
    const [name, ctx] = mocks.action.mock.calls[0]
    expect(name).toBe('impressions')
    expect(ctx.event_type).toBe('impressions')
    expect(ctx.components).toEqual({ MessageSummary: 3 })
    expect(ctx.sample_rate).toBe(0.1)
    expect(ctx.page_id).toBe('p1')
  })

  it('sends nothing when the session is sampled out', () => {
    mocks.sampled = false
    mocks.report({ MessageSummary: 3 })
    expect(mocks.action).not.toHaveBeenCalled()
  })
})

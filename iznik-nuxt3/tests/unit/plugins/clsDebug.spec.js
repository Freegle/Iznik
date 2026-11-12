import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'

const mocks = vi.hoisted(() => ({
  action: vi.fn(),
  observerCallback: null,
}))

vi.mock('~/composables/useClientLog', () => ({ action: mocks.action }))

// A stand-in for the browser's PerformanceObserver that lets the spec push layout-shift entries.
class FakePerformanceObserver {
  constructor(cb) {
    mocks.observerCallback = cb
  }

  observe() {}
}

function shift(value, startTime, hadRecentInput = false, className = 'box') {
  return {
    value,
    startTime,
    hadRecentInput,
    sources: [{ node: { tagName: 'DIV', className } }],
  }
}

function push(...entries) {
  mocks.observerCallback({ getEntries: () => entries })
}

function hide() {
  Object.defineProperty(document, 'visibilityState', {
    value: 'hidden',
    configurable: true,
  })
  document.dispatchEvent(new Event('visibilitychange'))
}

describe('plugins/cls-debug.client', () => {
  // Once: the plugin adds a document listener, which would otherwise pile up per test.
  beforeAll(async () => {
    globalThis.defineNuxtPlugin = (fn) => fn
    vi.stubGlobal('PerformanceObserver', FakePerformanceObserver)
    const mod = await import('~/plugins/cls-debug.client.js')
    mod.default()
  })

  beforeEach(() => {
    mocks.action.mockClear()
  })

  it('logs nothing when the page is hidden and the worst window is under 0.1', () => {
    push(shift(0.04, 1000), shift(0.04, 9000))
    hide()
    expect(mocks.action).not.toHaveBeenCalled()
  })

  it('logs the largest window to Loki once, with the top shifts', () => {
    push(shift(0.06, 20000, false, 'jobs-slot'), shift(0.07, 20300))
    push(shift(0.5, 30000, true))
    hide()
    hide()

    expect(mocks.action).toHaveBeenCalledTimes(1)
    const [name, context] = mocks.action.mock.calls[0]
    expect(name).toBe('cls_exceeds_threshold')
    expect(context.cls_value).toBeCloseTo(0.13)
    expect(context.cls_window_shifts).toBe(2)
    // Two earlier shifts plus these two; the one that followed user input is ignored, as
    // the browser defines it.
    expect(context.shift_count).toBe(4)
    // Largest first.
    expect(context.top_shifts[0]).toContain('0.0700')
    expect(context.top_shifts[1]).toContain('div.jobs-slot')
    // Each shift carries the path it happened on.
    expect(context.top_shifts[0]).toContain(`ms ${window.location.pathname}: `)
  })
})

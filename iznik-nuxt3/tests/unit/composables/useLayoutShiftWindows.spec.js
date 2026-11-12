import { describe, it, expect } from 'vitest'
import {
  createLayoutShiftWindows,
  WINDOW_GAP_MS,
  WINDOW_MAX_MS,
} from '~/composables/useLayoutShiftWindows'

describe('useLayoutShiftWindows', () => {
  it('scores nothing before any shift', () => {
    const w = createLayoutShiftWindows()
    expect(w.result()).toEqual({
      cls: 0,
      windowStart: null,
      windowShifts: 0,
      cumulative: 0,
      shiftCount: 0,
    })
  })

  it('adds shifts less than a second apart into one window', () => {
    const w = createLayoutShiftWindows()
    w.add(0.03, 1000)
    w.add(0.04, 1500)
    w.add(0.02, 2200)
    const r = w.result()
    expect(r.cls).toBeCloseTo(0.09)
    expect(r.windowShifts).toBe(3)
    expect(r.windowStart).toBe(1000)
  })

  it('starts a new window after a gap of a second or more', () => {
    const w = createLayoutShiftWindows()
    w.add(0.05, 1000)
    w.add(0.04, 1000 + WINDOW_GAP_MS)
    const r = w.result()
    expect(r.cls).toBeCloseTo(0.05)
    expect(r.cumulative).toBeCloseTo(0.09)
  })

  it('closes a window that has been open for five seconds even without a gap', () => {
    const w = createLayoutShiftWindows()
    for (let t = 0; t < WINDOW_MAX_MS; t += 900) {
      w.add(0.01, t)
    }
    // 0, 900, 1800, 2700, 3600, 4500 all fall in the first window.
    w.add(0.01, 5400)
    const r = w.result()
    expect(r.windowShifts).toBe(6)
    expect(r.cls).toBeCloseTo(0.06)
    expect(r.shiftCount).toBe(7)
  })

  it('reports the largest window, not the latest or the total', () => {
    const w = createLayoutShiftWindows()
    w.add(0.02, 1000)
    w.add(0.2, 10000)
    w.add(0.01, 20000)
    const r = w.result()
    expect(r.cls).toBeCloseTo(0.2)
    expect(r.windowStart).toBe(10000)
    expect(r.cumulative).toBeCloseTo(0.23)
  })

  it('lists the largest individual shifts with their detail, biggest first', () => {
    const w = createLayoutShiftWindows()
    w.add(0.01, 100, { selectors: ['a'] })
    w.add(0.3, 200, { selectors: ['b'] })
    w.add(0.1, 300, { selectors: ['c'] })
    const top = w.topShifts(2)
    expect(top.map((s) => s.detail.selectors[0])).toEqual(['b', 'c'])
  })
})

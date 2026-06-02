import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import {
  useDwellView,
  DEFAULT_VIEW_DWELL_MS,
} from '~/composables/useDwellView.js'

describe('useDwellView', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('records the view only after the post has dwelled for the threshold', () => {
    const record = vi.fn()
    const { onVisibilityChange } = useDwellView(record, 2000)

    onVisibilityChange(true)
    vi.advanceTimersByTime(1999)
    expect(record).not.toHaveBeenCalled() // not yet — still under the dwell

    vi.advanceTimersByTime(1)
    expect(record).toHaveBeenCalledTimes(1) // crossed the threshold
  })

  it('does NOT record a fast scroll-past (left before the dwell elapsed)', () => {
    const record = vi.fn()
    const { onVisibilityChange } = useDwellView(record, 2000)

    onVisibilityChange(true)
    vi.advanceTimersByTime(500)
    onVisibilityChange(false) // scrolled away
    vi.advanceTimersByTime(5000)

    expect(record).not.toHaveBeenCalled()
  })

  it('schedules only one view even if "visible" fires repeatedly', () => {
    const record = vi.fn()
    const { onVisibilityChange } = useDwellView(record, 2000)

    onVisibilityChange(true)
    vi.advanceTimersByTime(1000)
    onVisibilityChange(true) // repeat visible while pending — must not re-arm
    onVisibilityChange(true)
    vi.advanceTimersByTime(1000)

    expect(record).toHaveBeenCalledTimes(1)
  })

  it('records again on a fresh visibility cycle after the first view fired', () => {
    const record = vi.fn()
    const { onVisibilityChange } = useDwellView(record, 1000)

    onVisibilityChange(true)
    vi.advanceTimersByTime(1000)
    expect(record).toHaveBeenCalledTimes(1)

    // Comes back into view later — a new dwell can record a fresh view.
    onVisibilityChange(true)
    vi.advanceTimersByTime(1000)
    expect(record).toHaveBeenCalledTimes(2)
  })

  it('cancel() stops a pending view from firing (e.g. on unmount)', () => {
    const record = vi.fn()
    const { onVisibilityChange, cancel } = useDwellView(record, 2000)

    onVisibilityChange(true)
    vi.advanceTimersByTime(1000)
    cancel()
    vi.advanceTimersByTime(5000)

    expect(record).not.toHaveBeenCalled()
  })

  it('defaults the dwell to DEFAULT_VIEW_DWELL_MS when none is given', () => {
    const record = vi.fn()
    const { onVisibilityChange } = useDwellView(record)

    onVisibilityChange(true)
    vi.advanceTimersByTime(DEFAULT_VIEW_DWELL_MS - 1)
    expect(record).not.toHaveBeenCalled()
    vi.advanceTimersByTime(1)
    expect(record).toHaveBeenCalledTimes(1)
  })
})

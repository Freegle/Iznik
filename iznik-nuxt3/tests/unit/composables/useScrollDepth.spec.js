import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

import { useScrollDepth } from '~/composables/useScrollDepth'

// useScrollDepth registers an onBeforeUnmount hook; called outside a component it
// just warns and is a no-op, which is fine - we drive record()/send() directly.

describe('useScrollDepth', () => {
  let beacon
  let visibilityHandler

  beforeEach(() => {
    vi.useFakeTimers()
    beacon = vi.fn(() => true)
    global.navigator = { sendBeacon: beacon }
    visibilityHandler = null
    global.document = {
      addEventListener: vi.fn((evt, cb) => {
        if (evt === 'visibilitychange') visibilityHandler = cb
      }),
      removeEventListener: vi.fn(),
      visibilityState: 'visible',
    }
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  async function payloadOf(call) {
    return JSON.parse(await call[1].text())
  }

  it('debounces a send as we scroll, reporting the furthest position with a session', async () => {
    const { record, session } = useScrollDepth(
      'https://api.test/apiv2',
      () => 'search',
      {
        debounceMs: 500,
      }
    )
    expect(typeof session).toBe('string')
    expect(session.length).toBeGreaterThan(0)

    record(3, 50)
    record(12, 60)
    record(7, 60) // lower than the max - ignored

    expect(beacon).not.toHaveBeenCalled() // not yet - debounced
    vi.advanceTimersByTime(500)

    expect(beacon).toHaveBeenCalledTimes(1)
    const [url, blob] = beacon.mock.calls[0]
    expect(url).toBe('https://api.test/apiv2/scrolldepth')
    expect(blob.type).toBe('application/json')
    expect(await payloadOf(beacon.mock.calls[0])).toEqual({
      session,
      maxposition: 12,
      itemsavailable: 60,
      context: 'search',
    })
  })

  it('re-sends (same session) when the furthest position grows, but not when it is unchanged', async () => {
    const { record } = useScrollDepth(
      'https://api.test/apiv2',
      () => 'browse',
      {
        debounceMs: 200,
      }
    )
    record(5, 30)
    vi.advanceTimersByTime(200)
    expect(beacon).toHaveBeenCalledTimes(1)

    // Scroll deeper -> new send with the higher max, same session id.
    record(20, 40)
    vi.advanceTimersByTime(200)
    expect(beacon).toHaveBeenCalledTimes(2)
    const p1 = await payloadOf(beacon.mock.calls[0])
    const p2 = await payloadOf(beacon.mock.calls[1])
    expect(p1.session).toBe(p2.session)
    expect(p2.maxposition).toBe(20)

    // Re-record the same (or shallower) max -> nothing new to report.
    record(20, 40)
    record(8, 40)
    vi.advanceTimersByTime(200)
    expect(beacon).toHaveBeenCalledTimes(2)
  })

  it('flushes immediately when the tab is hidden (does not wait for the debounce)', () => {
    const { record } = useScrollDepth(
      'https://api.test/apiv2',
      () => 'browse',
      {
        debounceMs: 5000,
      }
    )
    record(9, 20)
    expect(beacon).not.toHaveBeenCalled()

    global.document.visibilityState = 'hidden'
    visibilityHandler()
    expect(beacon).toHaveBeenCalledTimes(1)
  })

  it('does not send when nothing was recorded', () => {
    const { send } = useScrollDepth('https://api.test/apiv2', () => 'browse')
    send()
    expect(beacon).not.toHaveBeenCalled()
  })

  it('does not send when there is no API base', () => {
    const { record, send } = useScrollDepth(undefined, () => 'browse')
    record(5, 10)
    send()
    expect(beacon).not.toHaveBeenCalled()
  })

  it('defaults the context to browse and exposes a stable session id', async () => {
    const { record, send, session } = useScrollDepth('https://api.test/apiv2')
    expect(typeof session).toBe('string')
    expect(session.length).toBeGreaterThan(0)
    record(2, 10)
    send()
    const payload = await payloadOf(beacon.mock.calls[0])
    expect(payload.context).toBe('browse')
    expect(payload.session).toBe(session)
  })
})

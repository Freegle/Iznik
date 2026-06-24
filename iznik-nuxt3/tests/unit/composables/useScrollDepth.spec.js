import { describe, it, expect, vi, beforeEach } from 'vitest'

import { useScrollDepth } from '~/composables/useScrollDepth'

// useScrollDepth registers an onBeforeUnmount hook; called outside a component it
// just warns and is a no-op, which is fine — we drive record()/send() directly.

describe('useScrollDepth', () => {
  let beacon

  beforeEach(() => {
    beacon = vi.fn(() => true)
    global.navigator = { sendBeacon: beacon }
    global.document = {
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      visibilityState: 'visible',
    }
  })

  it('reports the furthest recorded position once, as JSON, on send()', async () => {
    const { record, send } = useScrollDepth('https://api.test/apiv2', () => 'search')
    record(3, 50)
    record(12, 60)
    record(7, 60) // lower than the max — ignored

    send()

    expect(beacon).toHaveBeenCalledTimes(1)
    const [url, blob] = beacon.mock.calls[0]
    expect(url).toBe('https://api.test/apiv2/scrolldepth')
    expect(blob.type).toBe('application/json')
    const payload = JSON.parse(await blob.text())
    expect(payload).toEqual({ maxposition: 12, itemsavailable: 60, context: 'search' })
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

  it('sends at most once per session', () => {
    const { record, send } = useScrollDepth('https://api.test/apiv2', () => 'browse')
    record(5, 10)
    send()
    send()
    expect(beacon).toHaveBeenCalledTimes(1)
  })

  it('defaults the context to browse', async () => {
    const { record, send } = useScrollDepth('https://api.test/apiv2')
    record(2, 10)
    send()
    const payload = JSON.parse(await beacon.mock.calls[0][1].text())
    expect(payload.context).toBe('browse')
  })
})

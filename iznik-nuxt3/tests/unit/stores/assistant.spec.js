import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const turnMock = vi.fn()
vi.mock('~/api', () => ({
  default: () => ({ assistant: { turn: turnMock } }),
}))

import { useAssistantStore, MAX_LINES } from '~/stores/assistant'

describe('assistant store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    turnMock.mockReset()
  })

  it('shows the member line at once, streams the reply, then applies the turn', async () => {
    const store = useAssistantStore()
    store.init({})
    turnMock.mockImplementation(async (body, { onDelta }) => {
      expect(body.text).toBe('grey sofa')
      onDelta('A grey ')
      onDelta('sofa, lovely.')
      return { conversation: 'c1', state: 'GIVE_PHOTO', say: 'A grey sofa, lovely.', chips: [{ value: 'no_photo', label: 'No photo' }], progress: { label: 'Giving', step: 1, total: 5 }, slots: { item: 'grey sofa' }, facts: {}, widget: {} }
    })
    const p = store.sendText('grey sofa')
    expect(store.lines[0]).toMatchObject({ who: 'member', text: 'grey sofa' })
    expect(store.busy).toBe(true)
    await p
    expect(store.streaming).toBe(false)
    expect(store.conversation).toBe('c1')
    expect(store.state).toBe('GIVE_PHOTO')
    expect(store.lastFreegleLine.text).toBe('A grey sofa, lovely.')
    expect(store.chips[0].value).toBe('no_photo')
    expect(store.inFlow).toBe(true)
  })

  it('records an error and keeps the member line when the service fails', async () => {
    const store = useAssistantStore()
    store.init({})
    turnMock.mockRejectedValue(new Error('boom'))
    await store.sendTap({ value: 'give', label: 'Give' })
    expect(store.error).toBe('boom')
    expect(store.lines).toHaveLength(1)
    expect(store.busy).toBe(false)
  })

  it('sends taps and events with the conversation id and caps the transcript', async () => {
    const store = useAssistantStore()
    store.init({})
    store.conversation = 'c9'
    turnMock.mockResolvedValue({ conversation: 'c9', state: 'HUB', say: 'ok', chips: [] })
    await store.sendEvent({ type: 'posted', msgid: 5 }, null)
    expect(turnMock.mock.calls[0][0]).toEqual({ event: { type: 'posted', msgid: 5 }, conversation: 'c9' })
    for (let i = 0; i < MAX_LINES + 20; i++) store.push({ who: 'member', text: 'x' })
    expect(store.lines.length).toBe(MAX_LINES)
    store.reset()
    expect(store.lines).toHaveLength(0)
    expect(store.conversation).toBeNull()
  })
})

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'

import {
  useInterested,
  listInterested,
  removeInterested,
} from '~/composables/useInterested'

const { mockState, mockSet, mockList, mockAction } = vi.hoisted(() => ({
  mockState: vi.fn(),
  mockSet: vi.fn(),
  mockList: vi.fn(),
  mockAction: vi.fn(),
}))

vi.mock('~/api', () => ({
  default: () => ({
    message: {
      interestedState: (...a) => mockState(...a),
      setInterested: (...a) => mockSet(...a),
      interestedList: (...a) => mockList(...a),
    },
  }),
}))

vi.mock('~/composables/useClientLog', () => ({
  action: (...a) => mockAction(...a),
}))

vi.stubGlobal('useRuntimeConfig', () => ({ public: {} }))

// The state is kept per post for the page, so each case uses a post of its own.
let nextId = 100

// Mounts a component that uses the composable, as a real one would.
function use(id) {
  let result
  const Comp = defineComponent({
    setup() {
      result = useInterested(id)
      return () => h('div')
    },
  })
  mount(Comp)
  return result
}

describe('useInterested', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('asks once on mount and offers the button in the arm button', async () => {
    const id = ++nextId
    mockState.mockResolvedValue({
      arm: 'button',
      interested: false,
      eligible: true,
    })
    const i = use(id)
    await flushPromises()
    expect(mockState).toHaveBeenCalledTimes(1)
    expect(mockState).toHaveBeenCalledWith(id)
    expect(i.showButton.value).toBe(true)
    expect(i.interested.value).toBe(false)
  })

  it('shares one request between two users of the same post', async () => {
    const id = ++nextId
    mockState.mockResolvedValue({
      arm: 'button',
      interested: false,
      eligible: true,
    })
    const a = use(id)
    const b = use(id)
    await flushPromises()
    expect(mockState).toHaveBeenCalledTimes(1)
    expect(a.showButton.value).toBe(true)
    expect(b.showButton.value).toBe(true)
  })

  it('offers no button in control or when not eligible', async () => {
    const control = ++nextId
    mockState.mockResolvedValue({ arm: '', interested: false, eligible: true })
    const a = use(control)
    await flushPromises()
    expect(a.showButton.value).toBe(false)

    const ineligible = ++nextId
    mockState.mockResolvedValue({
      arm: 'button',
      interested: false,
      eligible: false,
    })
    const b = use(ineligible)
    await flushPromises()
    expect(b.showButton.value).toBe(false)
  })

  it('shows the marked state whatever the arm', async () => {
    mockState.mockResolvedValue({ arm: '', interested: true, eligible: true })
    const i = use(++nextId)
    await flushPromises()
    expect(i.interested.value).toBe(true)
    expect(i.showButton.value).toBe(false)
    expect(i.justMarked.value).toBe(false)
  })

  it('treats any failure as no button', async () => {
    mockState.mockRejectedValue(new Error('404'))
    const i = use(++nextId)
    await flushPromises()
    expect(i.showButton.value).toBe(false)
    expect(i.interested.value).toBe(false)
  })

  it('marks and undoes, logging only the post id', async () => {
    const id = ++nextId
    mockState.mockResolvedValue({
      arm: 'button',
      interested: false,
      eligible: true,
    })
    const i = use(id)
    await flushPromises()

    mockSet.mockResolvedValue({ interested: true })
    expect(await i.mark()).toBe(true)
    expect(mockSet).toHaveBeenLastCalledWith(id, true)
    expect(i.interested.value).toBe(true)
    expect(i.justMarked.value).toBe(true)
    expect(mockAction).toHaveBeenLastCalledWith('interested_mark', {
      msgid: id,
    })

    mockSet.mockResolvedValue({ interested: false })
    expect(await i.undo()).toBe(true)
    expect(mockSet).toHaveBeenLastCalledWith(id, false)
    expect(i.interested.value).toBe(false)
    expect(i.justMarked.value).toBe(false)
    expect(mockAction).toHaveBeenLastCalledWith('interested_undo', {
      msgid: id,
    })
  })

  it('leaves the state alone when marking fails', async () => {
    mockState.mockResolvedValue({
      arm: 'button',
      interested: false,
      eligible: true,
    })
    const i = use(++nextId)
    await flushPromises()
    mockSet.mockRejectedValue(new Error('400'))
    expect(await i.mark()).toBe(false)
    expect(i.interested.value).toBe(false)
    expect(mockAction).not.toHaveBeenCalled()
  })

  it('lists marks, and gives an empty list when the API cannot', async () => {
    mockList.mockResolvedValue([{ msgid: 1, open: true }])
    expect(await listInterested()).toEqual([{ msgid: 1, open: true }])
    mockList.mockRejectedValue(new Error('404'))
    expect(await listInterested()).toEqual([])
    mockList.mockResolvedValue({ not: 'a list' })
    expect(await listInterested()).toEqual([])
  })

  it('removes a mark from outside the post and keeps the state in step', async () => {
    const id = ++nextId
    mockState.mockResolvedValue({ arm: '', interested: true, eligible: true })
    const i = use(id)
    await flushPromises()
    mockSet.mockResolvedValue({ interested: false })
    expect(await removeInterested(id)).toBe(true)
    expect(i.interested.value).toBe(false)
  })
})

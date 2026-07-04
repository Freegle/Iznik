import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import WantedMatches from '~/components/WantedMatches.vue'

const mockMatches = vi.fn()
const mockFetch = vi.fn()
const mockMarkSeen = vi.fn()
let mockList = {}

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    matches: mockMatches,
    fetch: mockFetch,
    markSeen: mockMarkSeen,
    get list() {
      return mockList
    },
  }),
}))

function mountWM(props = {}) {
  return mount(WantedMatches, {
    props: { query: 'sofa', lat: 51.5, lng: -0.1, ...props },
    global: {
      stubs: {
        MessageSummary: {
          template: '<div class="msg-summary" @click="$emit(\'expand\')" />',
          props: ['id'],
        },
        'b-button': {
          template:
            '<button class="btn" @click="$emit(\'click\')"><slot /></button>',
        },
      },
    },
  })
}

describe('WantedMatches', () => {
  let openSpy
  beforeEach(() => {
    vi.clearAllMocks()
    mockList = {}
    // markSeen is async in the real store; return a promise so the component's
    // fire-and-forget .catch() has something to attach to.
    mockMarkSeen.mockResolvedValue(undefined)
    mockFetch.mockImplementation((id) => {
      mockList[id] = { id }
      return Promise.resolve({ id })
    })
    openSpy = vi.spyOn(window, 'open').mockImplementation(() => {})
  })
  afterEach(() => {
    openSpy.mockRestore()
  })

  it('renders nothing when there are no matching offers', async () => {
    mockMatches.mockResolvedValue([])
    const wrapper = mountWM()
    await flushPromises()
    expect(wrapper.text()).not.toContain('Good news')
    expect(wrapper.findAll('.msg-summary')).toHaveLength(0)
    expect(mockMarkSeen).not.toHaveBeenCalled()
  })

  it('renders a card per matching offer and calls matches with the query + location', async () => {
    mockMatches.mockResolvedValue([{ id: 1 }, { id: 2 }])
    const wrapper = mountWM()
    await flushPromises()
    expect(mockMatches).toHaveBeenCalledWith('sofa', 51.5, -0.1, 6)
    expect(wrapper.text()).toContain('Good news')
    expect(wrapper.findAll('.msg-summary')).toHaveLength(2)
  })

  it('counts the impression once, source-tagged wanted_match', async () => {
    mockMatches.mockResolvedValue([{ id: 1 }, { id: 2 }])
    mountWM()
    await flushPromises()
    expect(mockMarkSeen).toHaveBeenCalledTimes(1)
    expect(mockMarkSeen).toHaveBeenCalledWith([1, 2], 'wanted_match')
  })

  it('opens a clicked offer in a NEW TAB tagged wanted_match (draft preserved)', async () => {
    mockMatches.mockResolvedValue([{ id: 42 }])
    const wrapper = mountWM()
    await flushPromises()
    await wrapper.find('.msg-summary').trigger('click')
    expect(openSpy).toHaveBeenCalledWith(
      '/message/42?src=wanted_match',
      '_blank',
      'noopener'
    )
  })

  it('hides when dismissed', async () => {
    mockMatches.mockResolvedValue([{ id: 1 }, { id: 2 }])
    const wrapper = mountWM()
    await flushPromises()
    expect(wrapper.text()).toContain('Good news')
    await wrapper.find('.btn').trigger('click')
    expect(wrapper.text()).not.toContain('Good news')
  })

  it('does not fetch without a location', async () => {
    mockMatches.mockResolvedValue([{ id: 1 }])
    mountWM({ lat: 0, lng: 0 })
    await flushPromises()
    expect(mockMatches).not.toHaveBeenCalled()
  })
})

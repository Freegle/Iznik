import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import SimilarPosts from '~/components/SimilarPosts.vue'

const mockSimilar = vi.fn()
const mockFetch = vi.fn()
const mockMarkSeen = vi.fn()
let mockList = {}

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    similar: mockSimilar,
    fetch: mockFetch,
    markSeen: mockMarkSeen,
    get list() {
      return mockList
    },
  }),
}))

const mockMyid = ref(null)
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ myid: mockMyid }),
}))

const mockNavigateTo = vi.fn()
vi.mock('#imports', () => ({
  navigateTo: (...args) => mockNavigateTo(...args),
}))

function mountSP(props = {}) {
  return mount(SimilarPosts, {
    props: { msgid: 100, ...props },
    global: {
      stubs: {
        MessageSummary: {
          template: '<div class="msg-summary" @click="$emit(\'expand\')" />',
          props: ['id'],
        },
        'client-only': { template: '<div><slot /></div>' },
      },
      directives: {
        // Fire the visibility callback immediately with visible=true.
        'observe-visibility': {
          mounted(el, binding) {
            binding.value.callback(true)
          },
        },
      },
    },
  })
}

describe('SimilarPosts', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMyid.value = null
    mockList = {}
    // fetch() resolves and populates the store list, like the real store.
    mockFetch.mockImplementation((id) => {
      mockList[id] = { id }
      return Promise.resolve({ id })
    })
  })

  it('renders nothing when fewer than three matches are returned', async () => {
    mockSimilar.mockResolvedValue([{ id: 1 }, { id: 2 }])
    const wrapper = mountSP()
    await flushPromises()
    expect(wrapper.text()).not.toContain('More like this nearby')
    expect(wrapper.findAll('.msg-summary')).toHaveLength(0)
    expect(mockMarkSeen).not.toHaveBeenCalled()
  })

  it('renders a card per match when at least three are returned', async () => {
    mockSimilar.mockResolvedValue([{ id: 1 }, { id: 2 }, { id: 3 }])
    const wrapper = mountSP()
    await flushPromises()
    expect(wrapper.text()).toContain('More like this nearby')
    expect(wrapper.findAll('.msg-summary')).toHaveLength(3)
  })

  it('opens a clicked match with the similar_posts source tag', async () => {
    mockSimilar.mockResolvedValue([{ id: 11 }, { id: 12 }, { id: 13 }])
    const wrapper = mountSP()
    await flushPromises()
    await wrapper.findAll('.msg-summary')[0].trigger('click')
    expect(mockNavigateTo).toHaveBeenCalledWith('/message/11?src=similar_posts')
  })

  it('counts the impression once, source-tagged', async () => {
    mockSimilar.mockResolvedValue([{ id: 1 }, { id: 2 }, { id: 3 }])
    mountSP()
    await flushPromises()
    expect(mockMarkSeen).toHaveBeenCalledTimes(1)
    expect(mockMarkSeen).toHaveBeenCalledWith([1, 2, 3], 'similar_posts')
  })

  it('holds out logged-in users whose id ends in 0 (no render, no fetch)', async () => {
    mockMyid.value = 20
    mockSimilar.mockResolvedValue([{ id: 1 }, { id: 2 }, { id: 3 }])
    const wrapper = mountSP()
    await flushPromises()
    expect(mockSimilar).not.toHaveBeenCalled()
    expect(wrapper.text()).not.toContain('More like this nearby')
  })

  it('shows and fetches for an anonymous user', async () => {
    mockMyid.value = null
    mockSimilar.mockResolvedValue([{ id: 1 }, { id: 2 }, { id: 3 }])
    const wrapper = mountSP()
    await flushPromises()
    expect(mockSimilar).toHaveBeenCalledWith(100, 8)
    expect(wrapper.text()).toContain('More like this nearby')
  })
})

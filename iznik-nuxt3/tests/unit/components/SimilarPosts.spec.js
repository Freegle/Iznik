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
const mockMod = ref(false)
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ myid: mockMyid, mod: mockMod }),
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
        SimilarPostCard: {
          template: '<div class="msg-summary" @click="$emit(\'click\')" />',
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
    vi.restoreAllMocks()
    mockMyid.value = null
    mockMod.value = false
    mockList = {}
    // markSeen is async in the real store; return a promise so the component's
    // fire-and-forget .catch() has something to attach to.
    mockMarkSeen.mockResolvedValue(undefined)
    // fetch() resolves and populates the store list, like the real store.
    mockFetch.mockImplementation((id) => {
      // Default: a distinct subject and a real (non-AI) photo, so the look-alike
      // dedupe never collapses these. Tests that exercise the dedupe set their own
      // AI/placeholder attachments.
      mockList[id] = {
        id,
        subject: `Item ${id}`,
        attachments: [{ id: id * 100, ai: false }],
      }
      return Promise.resolve(mockList[id])
    })
  })

  it('renders nothing when there are no matches', async () => {
    mockSimilar.mockResolvedValue([])
    const wrapper = mountSP()
    await flushPromises()
    expect(wrapper.text()).not.toContain('More like this nearby')
    expect(wrapper.findAll('.msg-summary')).toHaveLength(0)
    expect(mockMarkSeen).not.toHaveBeenCalled()
  })

  it('shows whatever matches it has, even fewer than the max', async () => {
    mockSimilar.mockResolvedValue([{ id: 1 }, { id: 2 }])
    const wrapper = mountSP()
    await flushPromises()
    expect(wrapper.text()).toContain('More like this nearby')
    expect(wrapper.findAll('.msg-summary')).toHaveLength(2)
    expect(mockMarkSeen).toHaveBeenCalledWith([1, 2], 'similar_posts')
  })

  it('renders a card per match when at least three are returned', async () => {
    mockSimilar.mockResolvedValue([{ id: 1 }, { id: 2 }, { id: 3 }])
    const wrapper = mountSP()
    await flushPromises()
    expect(wrapper.text()).toContain('More like this nearby')
    expect(wrapper.findAll('.msg-summary')).toHaveLength(3)
  })

  it('collapses same-item cards with AI/placeholder photos but keeps real photos', async () => {
    mockSimilar.mockResolvedValue([{ id: 1 }, { id: 2 }, { id: 3 }, { id: 4 }])
    const data = {
      1: {
        id: 1,
        subject: 'WANTED: Garden furniture (Chapel Hill PR3)',
        attachments: [{ id: 11, ai: true }],
      },
      2: {
        id: 2,
        subject: 'WANTED: Garden furniture (Orrell Mount L20)',
        attachments: [{ id: 12, ai: true }],
      },
      3: {
        id: 3,
        subject: 'OFFER: Garden furniture (Preston PR1)',
        attachments: [{ id: 13, ai: false }],
      },
      4: {
        id: 4,
        subject: 'WANTED: Rugs (Skerton LA1)',
        attachments: [{ id: 14, ai: true }],
      },
    }
    mockFetch.mockImplementation((id) => {
      mockList[id] = data[id]
      return Promise.resolve(data[id])
    })
    const wrapper = mountSP()
    await flushPromises()
    // #2 (AI, same item name as #1) drops; #1 (AI) and #4 (AI, different name) kept;
    // #3 (real photo, same name as #1) kept — its own photo distinguishes it.
    expect(wrapper.findAll('.msg-summary')).toHaveLength(3)
    expect(mockMarkSeen).toHaveBeenCalledWith([1, 3, 4], 'similar_posts')
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

  it('holds out a random slice of logged-in members: records the control, renders nothing', async () => {
    mockMyid.value = 5
    mockMod.value = false
    // Force the RNG below the holdout fraction so this view is the control.
    vi.spyOn(Math, 'random').mockReturnValue(0)
    mockSimilar.mockResolvedValue([{ id: 1 }, { id: 2 }, { id: 3 }])
    const wrapper = mountSP()
    await flushPromises()
    // It still fetches (the control must be over the same eligible population), but
    // it renders nothing and records the withheld view under the holdout source.
    expect(mockSimilar).toHaveBeenCalledWith(100, 12)
    expect(wrapper.text()).not.toContain('More like this nearby')
    expect(wrapper.findAll('.msg-summary')).toHaveLength(0)
    expect(mockMarkSeen).toHaveBeenCalledWith(
      [1, 2, 3],
      'similar_posts_holdout'
    )
    expect(mockMarkSeen).not.toHaveBeenCalledWith([1, 2, 3], 'similar_posts')
  })

  it('shows a logged-in member who does not fall in the holdout draw', async () => {
    mockMyid.value = 5
    mockMod.value = false
    // RNG at/above the fraction → shown, not held out.
    vi.spyOn(Math, 'random').mockReturnValue(0.5)
    mockSimilar.mockResolvedValue([{ id: 1 }, { id: 2 }, { id: 3 }])
    const wrapper = mountSP()
    await flushPromises()
    expect(wrapper.text()).toContain('More like this nearby')
    expect(mockMarkSeen).toHaveBeenCalledWith([1, 2, 3], 'similar_posts')
  })

  it('never holds out a moderator, even on a holdout draw', async () => {
    mockMyid.value = 5
    mockMod.value = true
    // Even with the RNG forced into the holdout band, a mod is always shown.
    vi.spyOn(Math, 'random').mockReturnValue(0)
    mockSimilar.mockResolvedValue([{ id: 1 }, { id: 2 }, { id: 3 }])
    const wrapper = mountSP()
    await flushPromises()
    expect(wrapper.text()).toContain('More like this nearby')
    expect(mockMarkSeen).toHaveBeenCalledWith([1, 2, 3], 'similar_posts')
    expect(mockMarkSeen).not.toHaveBeenCalledWith(
      [1, 2, 3],
      'similar_posts_holdout'
    )
  })

  it('never holds out an anonymous user, even on a holdout draw', async () => {
    mockMyid.value = null
    // The RNG would land in the holdout band, but logged-out views are excluded.
    vi.spyOn(Math, 'random').mockReturnValue(0)
    mockSimilar.mockResolvedValue([{ id: 1 }, { id: 2 }, { id: 3 }])
    const wrapper = mountSP()
    await flushPromises()
    expect(mockSimilar).toHaveBeenCalledWith(100, 12)
    expect(wrapper.text()).toContain('More like this nearby')
    expect(mockMarkSeen).toHaveBeenCalledWith([1, 2, 3], 'similar_posts')
  })

  it('caps the number of rendered cards with the max prop', async () => {
    mockSimilar.mockResolvedValue([
      { id: 1 },
      { id: 2 },
      { id: 3 },
      { id: 4 },
      { id: 5 },
    ])
    const wrapper = mountSP({ max: 3 })
    await flushPromises()
    expect(wrapper.findAll('.msg-summary')).toHaveLength(3)
    // The impression is counted for exactly the capped set.
    expect(mockMarkSeen).toHaveBeenCalledWith([1, 2, 3], 'similar_posts')
  })

  it('hides its own heading in the modal variant (the modal supplies the title)', async () => {
    mockSimilar.mockResolvedValue([{ id: 1 }, { id: 2 }, { id: 3 }])
    const wrapper = mountSP({ variant: 'modal' })
    await flushPromises()
    expect(wrapper.text()).not.toContain('More like this nearby')
    expect(wrapper.findAll('.msg-summary')).toHaveLength(3)
  })

  it('loads eagerly on mount when eager, without waiting for visibility', async () => {
    mockSimilar.mockResolvedValue([{ id: 1 }, { id: 2 }, { id: 3 }])
    // No-op the visibility directive so ONLY the eager path can trigger the load.
    const wrapper = mount(SimilarPosts, {
      props: { msgid: 100, eager: true },
      global: {
        stubs: {
          SimilarPostCard: {
            template: '<div class="msg-summary" @click="$emit(\'click\')" />',
            props: ['id'],
          },
          'client-only': { template: '<div><slot /></div>' },
        },
        directives: {
          'observe-visibility': { mounted() {} },
        },
      },
    })
    await flushPromises()
    expect(mockSimilar).toHaveBeenCalledWith(100, 12)
    expect(wrapper.findAll('.msg-summary')).toHaveLength(3)
  })
})

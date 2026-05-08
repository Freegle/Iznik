import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'

globalThis.definePageMeta = vi.fn()
globalThis.useHead = vi.fn()
globalThis.useRuntimeConfig = () => ({ public: { BUILD_DATE: '2026-01-01' } })

vi.mock('#imports', async () => {
  const vue = await vi.importActual('vue')
  return {
    ...vue,
    useRoute: () => ({ params: {}, query: {} }),
    useHead: vi.fn(),
    useRuntimeConfig: () => ({ public: { BUILD_DATE: '2026-01-01' } }),
  }
})

vi.mock('~/components/Spinner.vue', () => ({
  default: { name: 'Spinner', template: '<div class="spinner" />' },
}))

vi.mock('~/components/impact/TopSlide.vue', () => ({
  default: {
    name: 'TopSlide',
    template: '<div class="top-slide" />',
    props: ['year', 'communities', 'scale'],
  },
}))

const mockFetchStats = vi.fn()
const mockLoading = ref(false)
const mockCommunities = ref([])
const mockReturnedYear = ref(null)

vi.mock('~/composables/useTopImpact', () => ({
  useTopImpact: () => ({
    loading: mockLoading,
    communities: mockCommunities,
    returnedYear: mockReturnedYear,
    fetchStats: mockFetchStats,
  }),
}))

vi.mock('~/composables/useBuildHead', () => ({
  buildHead: () => ({ title: 'Test' }),
}))

import TopPage from '~/pages/impact/top.vue'

function resetMockState() {
  mockLoading.value = false
  mockCommunities.value = []
  mockReturnedYear.value = null
}

describe('pages/impact/top.vue — rendering', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    resetMockState()
    mockFetchStats.mockResolvedValue(undefined)
  })

  it('shows loading state while loading', async () => {
    mockLoading.value = true
    const wrapper = mount(TopPage, {
      global: { plugins: [createPinia()] },
    })
    await flushPromises()
    expect(wrapper.find('.impact-loading').exists()).toBe(true)
    expect(wrapper.find('.top-slide').exists()).toBe(false)
  })

  it('shows top slide when not loading', async () => {
    mockLoading.value = false
    const wrapper = mount(TopPage, {
      global: { plugins: [createPinia()] },
    })
    await flushPromises()
    expect(wrapper.find('.top-slide').exists()).toBe(true)
    expect(wrapper.find('.impact-loading').exists()).toBe(false)
  })

  it('calls fetchStats on mount', async () => {
    mount(TopPage, {
      global: { plugins: [createPinia()] },
    })
    await flushPromises()
    expect(mockFetchStats).toHaveBeenCalledOnce()
  })

  it('shows year and limit selectors in controls', async () => {
    const wrapper = mount(TopPage, {
      global: { plugins: [createPinia()] },
    })
    await flushPromises()
    const selects = wrapper.findAll('.impact-controls select')
    expect(selects).toHaveLength(2)
  })
})

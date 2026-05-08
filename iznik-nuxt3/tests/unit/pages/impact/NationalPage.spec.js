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

vi.mock('~/components/impact/NationalSlide.vue', () => ({
  default: {
    name: 'NationalSlide',
    template: '<div class="national-slide" />',
    props: [
      'year',
      'totalWeight',
      'totalBenefit',
      'totalCO2',
      'totalGifts',
      'totalMembers',
      'groupCount',
      'scale',
    ],
  },
}))

const mockFetchStats = vi.fn()
const mockLoading = ref(false)
const mockTotalWeight = ref(0)
const mockTotalBenefit = ref(0)
const mockTotalCO2 = ref(0)
const mockTotalGifts = ref(0)
const mockTotalMembers = ref(0)
const mockGroupCount = ref(0)

vi.mock('~/composables/useNationalImpact', () => ({
  useNationalImpact: () => ({
    loading: mockLoading,
    totalWeight: mockTotalWeight,
    totalBenefit: mockTotalBenefit,
    totalCO2: mockTotalCO2,
    totalGifts: mockTotalGifts,
    totalMembers: mockTotalMembers,
    groupCount: mockGroupCount,
    fetchStats: mockFetchStats,
  }),
}))

vi.mock('~/composables/useBuildHead', () => ({
  buildHead: () => ({ title: 'Test' }),
}))

import NationalPage from '~/pages/impact/national.vue'

function resetMockState() {
  mockLoading.value = false
  mockTotalWeight.value = 0
  mockTotalBenefit.value = 0
  mockTotalCO2.value = 0
  mockTotalGifts.value = 0
  mockTotalMembers.value = 0
  mockGroupCount.value = 0
}

describe('pages/impact/national.vue — rendering', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    resetMockState()
    mockFetchStats.mockResolvedValue(undefined)
  })

  it('shows loading state while loading', async () => {
    mockLoading.value = true
    const wrapper = mount(NationalPage, {
      global: { plugins: [createPinia()] },
    })
    await flushPromises()
    expect(wrapper.find('.impact-loading').exists()).toBe(true)
    expect(wrapper.find('.national-slide').exists()).toBe(false)
  })

  it('shows national slide when not loading', async () => {
    mockLoading.value = false
    const wrapper = mount(NationalPage, {
      global: { plugins: [createPinia()] },
    })
    await flushPromises()
    expect(wrapper.find('.national-slide').exists()).toBe(true)
    expect(wrapper.find('.impact-loading').exists()).toBe(false)
  })

  it('calls fetchStats on mount', async () => {
    mount(NationalPage, {
      global: { plugins: [createPinia()] },
    })
    await flushPromises()
    expect(mockFetchStats).toHaveBeenCalledOnce()
  })

  it('shows year selector in controls', async () => {
    const wrapper = mount(NationalPage, {
      global: { plugins: [createPinia()] },
    })
    await flushPromises()
    expect(wrapper.find('.impact-controls select').exists()).toBe(true)
  })
})

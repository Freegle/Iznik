import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'

// Global stubs needed by all pages
globalThis.definePageMeta = vi.fn()
globalThis.useHead = vi.fn()
globalThis.useRuntimeConfig = () => ({ public: { BUILD_DATE: '2026-01-01' } })

// Route mock — mutable per test
let mockRoute = { params: { id: '123' }, query: {} }

vi.mock('#imports', async () => {
  const vue = await vi.importActual('vue')
  return {
    ...vue,
    useRoute: () => mockRoute,
    useHead: vi.fn(),
    useRuntimeConfig: () => ({ public: { BUILD_DATE: '2026-01-01' } }),
  }
})

// Mock child components to keep tests focused on page logic
vi.mock('~/components/OurDatePicker.vue', () => ({
  default: {
    name: 'OurDatePicker',
    template: '<input class="date-picker" />',
    props: ['modelValue'],
    emits: ['update:modelValue'],
  },
}))

vi.mock('~/components/Spinner.vue', () => ({
  default: { name: 'Spinner', template: '<div class="spinner" />' },
}))

vi.mock('~/components/impact/CouncilSlide.vue', () => ({
  default: {
    name: 'CouncilSlide',
    template: '<div class="council-slide" />',
    props: [
      'authorityName',
      'yearLabel',
      'totalWeight',
      'totalBenefit',
      'totalCO2',
      'totalGifts',
      'totalMembers',
      'groupCount',
      'partialGroupCount',
      'testimonials',
      'photoUrl',
      'scale',
    ],
  },
}))

// Use actual Vue refs so the template auto-unwraps them correctly.
// Plain objects { value: false } are truthy and break v-if="loading" etc.
const mockFetchStats = vi.fn()
const mockLoading = ref(false)
const mockAuthority = ref(null)
const mockTotalWeight = ref(0)
const mockTotalBenefit = ref(0)
const mockTotalCO2 = ref(0)
const mockTotalGifts = ref(0)
const mockTotalMembers = ref(0)
const mockGroupCount = ref(0)
const mockPartialGroupCount = ref(0)

vi.mock('~/composables/useAuthorityImpact', () => ({
  useAuthorityImpact: () => ({
    loading: mockLoading,
    authority: mockAuthority,
    totalWeight: mockTotalWeight,
    totalBenefit: mockTotalBenefit,
    totalCO2: mockTotalCO2,
    totalGifts: mockTotalGifts,
    totalMembers: mockTotalMembers,
    groupCount: mockGroupCount,
    partialGroupCount: mockPartialGroupCount,
    fetchStats: mockFetchStats,
    getTestimonials: () => [],
    getPhotoUrl: () => null,
  }),
}))

vi.mock('~/composables/useBuildHead', () => ({
  buildHead: () => ({ title: 'Test' }),
}))

import CouncilPage from '~/pages/impact/council/[id].vue'

function resetMockState() {
  mockLoading.value = false
  mockAuthority.value = null
  mockTotalWeight.value = 0
  mockTotalBenefit.value = 0
  mockTotalCO2.value = 0
  mockTotalGifts.value = 0
  mockTotalMembers.value = 0
  mockGroupCount.value = 0
  mockPartialGroupCount.value = 0
}

describe('pages/impact/council/[id].vue — yearLabel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    resetMockState()
    mockAuthority.value = { name: 'Test Council', groups: [] }
    mockFetchStats.mockResolvedValue(undefined)
  })

  it('passes a valid yearLabel string to CouncilSlide', async () => {
    const wrapper = mount(CouncilPage, {
      global: { plugins: [createPinia()] },
    })
    await flushPromises()

    // Access the prop directly — HTML attrs don't carry Vue component props
    const slide = wrapper.findComponent({ name: 'CouncilSlide' })
    const yearLabel = slide.props('yearLabel') ?? ''

    // Should be a 4-digit year, or "YYYY – YYYY" for cross-year ranges
    if (yearLabel.includes('–')) {
      const parts = yearLabel.split('–').map((s) => s.trim())
      expect(parts).toHaveLength(2)
      expect(parts[0]).toMatch(/^\d{4}$/)
      expect(parts[1]).toMatch(/^\d{4}$/)
    } else {
      expect(yearLabel).toMatch(/^\d{4}$/)
    }
  })
})

describe('pages/impact/council/[id].vue — rendering', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    resetMockState()
    mockFetchStats.mockResolvedValue(undefined)
  })

  it('shows loading state while loading', async () => {
    mockLoading.value = true
    mockAuthority.value = null
    const wrapper = mount(CouncilPage, {
      global: { plugins: [createPinia()] },
    })
    await flushPromises()
    // .impact-loading is the page's own div — always present when v-if="loading" is true
    expect(wrapper.find('.impact-loading').exists()).toBe(true)
    expect(wrapper.find('.council-slide').exists()).toBe(false)
  })

  it('shows council slide when authority is loaded', async () => {
    mockLoading.value = false
    mockAuthority.value = { name: 'Surrey', groups: [] }
    const wrapper = mount(CouncilPage, {
      global: { plugins: [createPinia()] },
    })
    await flushPromises()
    expect(wrapper.find('.council-slide').exists()).toBe(true)
    expect(wrapper.find('.impact-loading').exists()).toBe(false)
  })

  it('shows not-found message when authority is null and not loading', async () => {
    mockLoading.value = false
    mockAuthority.value = null
    const wrapper = mount(CouncilPage, {
      global: { plugins: [createPinia()] },
    })
    await flushPromises()
    expect(wrapper.find('.impact-not-found').exists()).toBe(true)
    expect(wrapper.find('.council-slide').exists()).toBe(false)
  })

  it('calls fetchStats on mount', async () => {
    mockAuthority.value = { name: 'Test', groups: [] }
    mount(CouncilPage, {
      global: { plugins: [createPinia()] },
    })
    await flushPromises()
    expect(mockFetchStats).toHaveBeenCalledOnce()
  })
})

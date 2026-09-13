import { describe, it, expect, vi, beforeEach } from 'vitest'
import { shallowMount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ModSysAdminModerationStats from '~/modtools/components/ModSysAdminModerationStats.vue'

const sampleStats = {
  arrived: 100,
  manualApproved: 20,
  manualRejected: 5,
  trusted: 10,
  autoApproved: 65,
  autoModChecked: 30,
  autoLaterActioned: 2,
  qualitySampled: 40,
  qualitySampleBad: 1,
}

const mockMessageStore = {
  fetchModerationStats: vi.fn().mockResolvedValue(sampleStats),
}
vi.mock('@/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

describe('ModSysAdminModerationStats.vue', () => {
  function mountComponent() {
    return shallowMount(ModSysAdminModerationStats, {
      global: {
        plugins: [createPinia()],
        stubs: {
          'b-form-group': true,
          'b-form-input': true,
          'b-button': true,
          'b-card': true,
          'b-table-simple': true,
          'b-tbody': true,
          'b-tr': true,
          'b-td': true,
          NoticeMessage: true,
        },
      },
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    mockMessageStore.fetchModerationStats.mockResolvedValue(sampleStats)
  })

  it('fetches stats for the default 30-day range on mount', async () => {
    const wrapper = mountComponent()
    await flushPromises()
    expect(mockMessageStore.fetchModerationStats).toHaveBeenCalledTimes(1)
    const params = mockMessageStore.fetchModerationStats.mock.calls[0][0]
    expect(params.start).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    expect(params.end).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    expect(new Date(params.start) <= new Date(params.end)).toBe(true)
    expect(wrapper.vm.stats).toEqual(sampleStats)
    // autoApproved is the single real auto-approved figure (no autoChecked/autoFallback).
    expect(wrapper.vm.stats.autoApproved).toBe(65)
  })

  it('formats percentages and guards divide-by-zero', () => {
    const wrapper = mountComponent()
    expect(wrapper.vm.pct(1, 2)).toBe('50.0%')
    expect(wrapper.vm.pct(2, 65)).toBe('3.1%')
    expect(wrapper.vm.pct(5, 0)).toBe('—')
  })

  it('re-fetches when Update is triggered', async () => {
    const wrapper = mountComponent()
    await flushPromises()
    mockMessageStore.fetchModerationStats.mockClear()
    wrapper.vm.startDate = '2026-01-01'
    wrapper.vm.endDate = '2026-01-31'
    await wrapper.vm.fetchStats()
    expect(mockMessageStore.fetchModerationStats).toHaveBeenCalledWith({
      start: '2026-01-01',
      end: '2026-01-31',
    })
  })
})

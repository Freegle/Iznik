import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import ModSysAdminRecommendations from '~/modtools/components/ModSysAdminRecommendations.vue'

const mockFetchStats = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    recommendations: {
      fetchStats: mockFetchStats,
    },
  }),
}))

vi.mock('#imports', () => ({
  useRuntimeConfig: () => ({ public: { apiUrl: 'http://test' } }),
}))

function mountComponent() {
  return mount(ModSysAdminRecommendations, {
    global: {
      stubs: {
        NoticeMessage: { template: '<div class="notice"><slot /></div>' },
        'b-card': {
          template: '<div class="card"><div class="card-title">{{ title }}</div><slot /></div>',
          props: ['title'],
        },
        'b-spinner': { template: '<div class="spinner" />' },
        'b-form-select': {
          template: '<select><slot /></select>',
          props: ['modelValue', 'options'],
        },
        'b-table-simple': { template: '<table><slot /></table>' },
        'b-thead': { template: '<thead><slot /></thead>' },
        'b-tbody': { template: '<tbody><slot /></tbody>' },
        'b-tr': { template: '<tr><slot /></tr>' },
        'b-th': { template: '<th><slot /></th>' },
        'b-td': { template: '<td><slot /></td>' },
      },
    },
  })
}

describe('ModSysAdminRecommendations', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockFetchStats.mockResolvedValue({
      sources: [
        {
          source: 'similar_posts',
          impressions: 100,
          clicks: 5,
          ctr: 5.0,
          attributedReplies: 2,
          daily: [],
        },
      ],
      holdout: {
        shownUsers: 90,
        shownReplies: 30,
        shownRepliesPerUser: 0.33,
        holdoutUsers: 10,
        holdoutReplies: 2,
        holdoutRepliesPerUser: 0.2,
      },
    })
  })

  it('fetches stats on mount with the default 30-day window', async () => {
    mountComponent()
    await flushPromises()
    expect(mockFetchStats).toHaveBeenCalledWith(30)
  })

  it('renders the funnel metrics and a human-readable source label', async () => {
    const wrapper = mountComponent()
    await flushPromises()
    const text = wrapper.text()
    expect(text).toContain('More like this (post page)')
    expect(text).toContain('100') // impressions
    expect(text).toContain('5.0%') // CTR
  })

  it('renders the holdout comparison', async () => {
    const wrapper = mountComponent()
    await flushPromises()
    const text = wrapper.text()
    expect(text).toContain('Holdout comparison')
    expect(text).toContain('90') // shown users
    expect(text).toContain('0.33') // shown replies per user
    expect(text).toContain('0.20') // holdout replies per user
  })

  it('surfaces an error when the fetch fails', async () => {
    mockFetchStats.mockRejectedValue(new Error('boom'))
    const wrapper = mountComponent()
    await flushPromises()
    expect(wrapper.find('.notice').text()).toContain('boom')
  })
})

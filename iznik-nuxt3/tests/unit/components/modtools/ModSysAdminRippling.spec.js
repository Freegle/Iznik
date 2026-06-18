import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import ModSysAdminRippling from '~/modtools/components/ModSysAdminRippling.vue'

const mockFetchMetrics = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    rippling: {
      fetchMetrics: mockFetchMetrics,
    },
  }),
}))

vi.mock('#app', () => ({
  useRuntimeConfig: () => ({ public: { apiUrl: 'http://test' } }),
}))

function mountComponent() {
  return mount(ModSysAdminRippling, {
    global: {
      stubs: {
        'b-table-simple': { template: '<table><slot /></table>' },
        'b-thead': { template: '<thead><slot /></thead>' },
        'b-tbody': { template: '<tbody><slot /></tbody>' },
        'b-tr': { template: '<tr><slot /></tr>' },
        'b-th': { template: '<th><slot /></th>' },
        'b-td': { template: '<td><slot /></td>' },
        'b-spinner': { template: '<div class="spinner" />' },
      },
    },
  })
}

describe('ModSysAdminRippling', () => {
  beforeEach(() => {
    mockFetchMetrics.mockReset()
  })

  it('renders the event totals from the metrics endpoint', async () => {
    mockFetchMetrics.mockResolvedValue({
      totals: [
        { day: '', event: 'reply_blocked', count: 7 },
        { day: '', event: 'held', count: 3 },
      ],
      recent: [{ day: '2026-06-18', event: 'reply_blocked', count: 7 }],
    })

    const wrapper = mountComponent()
    await flushPromises()

    expect(mockFetchMetrics).toHaveBeenCalled()
    const html = wrapper.html()
    expect(html).toContain('reply_blocked')
    expect(html).toContain('held')
    expect(html).toContain('7')
  })

  it('shows an empty state when there are no events', async () => {
    mockFetchMetrics.mockResolvedValue({ totals: [], recent: [] })

    const wrapper = mountComponent()
    await flushPromises()

    expect(wrapper.html()).toContain('No rippling events recorded yet')
  })
})

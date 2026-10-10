import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ModSysAdminAutomod from '~/modtools/components/ModSysAdminAutomod.vue'

const mockFetchAutomodAgreement = vi.fn()
vi.mock('@/stores/message', () => ({
  useMessageStore: () => ({ fetchAutomodAgreement: mockFetchAutomodAgreement }),
}))

const tableStub = { template: '<div><slot /></div>' }
const stubs = {
  'b-form-group': tableStub,
  'b-form-select': { template: '<select />', props: ['modelValue', 'options'] },
  'b-card': { template: '<div class="card"><slot /></div>', props: ['header'] },
  'b-table-simple': { template: '<table><slot /></table>' },
  'b-thead': { template: '<thead><slot /></thead>' },
  'b-tbody': { template: '<tbody><slot /></tbody>' },
  'b-tr': { template: '<tr><slot /></tr>' },
  'b-th': { template: '<th><slot /></th>' },
  'b-td': { template: '<td><slot /></td>' },
  'b-spinner': true,
  NoticeMessage: { template: '<div class="notice"><slot /></div>' },
}

const stats = {
  days: 30,
  total: 12,
  shadow: { count: 8 },
  approve: { count: 4 },
  byEnd: [
    { end: 'APPROVE', verdict: 'approve', count: 9, modDisagreed: 1 },
    { end: 'HOLD_LOAN', verdict: 'hold', count: 3, modDisagreed: 0 },
  ],
  feedback: [{ node: 'LOAN', count: 2 }],
}

describe('ModSysAdminAutomod', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockFetchAutomodAgreement.mockResolvedValue(stats)
  })

  it('fetches the last 30 days on mount', async () => {
    mount(ModSysAdminAutomod, { global: { stubs } })
    await flushPromises()
    expect(mockFetchAutomodAgreement).toHaveBeenCalledWith({ days: 30 })
  })

  it('shows the totals, one row per end node with the disagreement rate, and the feedback', async () => {
    const wrapper = mount(ModSysAdminAutomod, { global: { stubs } })
    await flushPromises()
    const text = wrapper.text()
    expect(text).toContain('12')
    expect(text).toContain('HOLD_LOAN')
    expect(text).toContain('11.1%')
    expect(text).toContain('LOAN')
  })

  it('shows the error when the request fails', async () => {
    mockFetchAutomodAgreement.mockRejectedValue(new Error('boom'))
    const wrapper = mount(ModSysAdminAutomod, { global: { stubs } })
    await flushPromises()
    expect(wrapper.find('.notice').text()).toContain('boom')
  })
})

import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({ bulkInterestState: vi.fn().mockResolvedValue({}) }),
}))
vi.mock('~/stores/user', () => ({
  useUserStore: () => ({ byId: () => ({ displayname: 'Sam' }) }),
}))

import ClearanceCandidate from '~/components/ClearanceCandidate.vue'

const mountRow = (props) =>
  mount(ClearanceCandidate, {
    props: {
      messageId: 1,
      bulkitemid: 10,
      interest: { userid: 7, quantity: 2, state: 'Interested' },
      ...props,
    },
    global: {
      stubs: {
        'b-button': true,
        'b-badge': { template: '<span class="badge-stub"><slot /></span>' },
      },
    },
  })

describe('ClearanceCandidate — Helper overlay', () => {
  it('shows the FSM state badge and score when Helper data is present', () => {
    const w = mountRow({ helperState: 'QUALIFIED', score: 87.5 })
    expect(w.vm.helperLabel).toBe('Ready to decide')
    expect(w.vm.scoreText).toBe('88')
    expect(w.find('[data-testid="helper-state"]').exists()).toBe(true)
    expect(w.find('[data-testid="helper-score"]').exists()).toBe(true)
  })

  it('shows the AI badge only when the Helper has messaged them', () => {
    const with_ai = mountRow({ helperState: 'GATHERING', aiSent: true })
    expect(with_ai.find('[data-testid="ai-badge"]').exists()).toBe(true)
    const without = mountRow({ helperState: 'GATHERING', aiSent: false })
    expect(without.find('[data-testid="ai-badge"]').exists()).toBe(false)
  })

  it('hides Helper badges when no Helper data is supplied', () => {
    const w = mountRow({})
    expect(w.find('[data-testid="helper-state"]').exists()).toBe(false)
    expect(w.find('[data-testid="helper-score"]').exists()).toBe(false)
    expect(w.find('[data-testid="ai-badge"]').exists()).toBe(false)
  })
})

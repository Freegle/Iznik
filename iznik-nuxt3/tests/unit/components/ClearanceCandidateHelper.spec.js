import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

const h = vi.hoisted(() => ({
  bulkInterestState: vi.fn().mockResolvedValue({}),
  bulkInterest: vi.fn().mockResolvedValue({}),
}))

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({ bulkInterestState: h.bulkInterestState, bulkInterest: h.bulkInterest }),
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
      interest: { userid: 7, quantity: 2, state: 'Interested', cancollect: 'Tue', chatid: 55 },
      ...props,
    },
    global: {
      stubs: {
        'b-button': true,
        'b-form-input': true,
        'b-badge': { template: '<span class="badge-stub"><slot /></span>' },
      },
    },
  })

describe('ClearanceCandidate — Helper overlay', () => {
  it('shows ONE merged status (helper FSM) and a clickable score', () => {
    const w = mountRow({ helperState: 'QUALIFIED', score: 87.5 })
    expect(w.vm.status).toMatchObject({ label: 'Ready to decide' })
    expect(w.vm.scoreText).toBe('88')
    expect(w.find('[data-testid="cand-status"]').exists()).toBe(true)
    expect(w.find('[data-testid="helper-score"]').exists()).toBe(true)
  })

  it('clicking the score reveals the breakdown', async () => {
    const w = mountRow({
      helperState: 'QUALIFIED',
      score: 90,
      scoreBreakdown: '{"criteria":30,"transport":20}',
    })
    expect(w.find('[data-testid="score-breakdown"]').exists()).toBe(false)
    expect(w.vm.breakdown.map((f) => f.k)).toEqual(['criteria', 'transport'])
    w.vm.showBreakdown = true
    await w.vm.$nextTick()
    expect(w.find('[data-testid="score-breakdown"]').exists()).toBe(true)
  })

  it('editing the quantity saves on behalf of the replier', async () => {
    const w = mountRow({ helperState: 'QUALIFIED', score: 90 })
    w.vm.qty = 5
    await w.vm.saveQty()
    expect(h.bulkInterest).toHaveBeenCalledWith(
      1,
      [{ bulkitemid: 10, quantity: 5, cancollect: 'Tue' }],
      7
    )
  })

  it('uses "Exclude" wording and shows a "not told yet" note when excluded', () => {
    const w = mountRow({ interest: { userid: 7, quantity: 2, state: 'Interested', cancollect: 'Tue' } })
    expect(w.vm.actions.find((a) => a.state === 'Rejected').label).toBe('Exclude')
    const excluded = mountRow({ interest: { userid: 7, quantity: 0, state: 'Rejected' } })
    expect(excluded.find('[data-testid="excluded-note"]').exists()).toBe(true)
  })

  it('shows the AI badge only when the Helper has messaged them', () => {
    expect(mountRow({ aiSent: true }).find('[data-testid="ai-badge"]').exists()).toBe(true)
    expect(mountRow({ aiSent: false }).find('[data-testid="ai-badge"]').exists()).toBe(false)
  })

  it('surfaces the escalation reason for a Needs-you candidate', () => {
    const w = mountRow({ helperState: 'ESCALATED', note: 'Asked for photos' })
    expect(w.vm.needsYou).toBe(true)
    expect(w.find('[data-testid="escalation-note"]').text()).toContain('Asked for photos')
  })

  it('hides Helper extras when no Helper data is supplied', () => {
    const w = mountRow({})
    expect(w.find('[data-testid="cand-status"]').exists()).toBe(false)
    expect(w.find('[data-testid="helper-score"]').exists()).toBe(false)
    expect(w.find('[data-testid="ai-badge"]').exists()).toBe(false)
  })
})

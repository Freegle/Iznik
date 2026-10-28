import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSysAdminUxInterestedDetail from '~/modtools/components/ModSysAdminUxInterestedDetail.vue'
import { uxStubs } from './uxStubs'

const mountIt = (name, interested) =>
  mount(ModSysAdminUxInterestedDetail, {
    props: {
      experiment: {
        name,
        result: { detail: interested ? { interested } : {} },
      },
    },
    global: { stubs: uxStubs },
  })

describe('ModSysAdminUxInterestedDetail', () => {
  it('draws nothing for another experiment', () => {
    const w = mountIt('confirm-reply', { marks: 5 })
    expect(w.find('[data-testid="ux-interested"]').exists()).toBe(false)
  })

  it('says nothing is counted before the first read', () => {
    const w = mountIt('interested-button', null)
    expect(w.text()).toContain('No marks counted yet.')
    expect(w.find('table').exists()).toBe(false)
  })

  it('shows the figures and the share of marks that led to a reply', () => {
    const w = mountIt('interested-button', {
      marks: 200,
      members: 120,
      replied: 50,
      replied_before_chase: 30,
      replied_after_chase: 20,
      chased: 90,
    })
    const rows = w
      .findAll('tr')
      .map((r) => r.findAll('td').map((c) => c.text()))
    expect(rows).toEqual([
      ['Members who marked', '120'],
      ['Posts marked', '200'],
      ['Marks that led to a reply', '50 (25%)'],
      ['Replied before the chase-up', '30'],
      ['Replied after the chase-up', '20'],
      ['Chase-ups sent', '90'],
    ])
  })

  it('gives no percentage when nothing was marked', () => {
    const w = mountIt('interested-button', { marks: 0, members: 0 })
    expect(w.text()).toContain('Marks that led to a reply')
    expect(w.text()).not.toContain('%')
  })
})

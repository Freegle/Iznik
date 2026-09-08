import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import PersonCard from '~/components/chatshell/PersonCard.vue'

vi.mock('~/stores/user', () => ({ useUserStore: () => ({ byId: (id) => (id === 2 ? { id: 2, info: { ratings: { Up: 5, Down: 1 } } } : null) }) }))

describe('PersonCard', () => {
  it('shows name, distance, ratings, what they said and the reasons', () => {
    const w = mount(PersonCard, { props: { reply: { userid: 2, name: 'Jane', miles: 1.4, snippet: 'Could I have two' }, reasons: ['Nearest', '5 thumbs up'] }, global: { stubs: { ProfileImage: true } } })
    expect(w.text()).toContain('Jane')
    expect(w.text()).toContain('about 1 mile away')
    expect(w.text()).toContain('5 up, 1 down')
    expect(w.text()).toContain('"Could I have two"')
    expect(w.findAll('.person-reason').map((r) => r.text())).toEqual(['Nearest', '5 thumbs up'])
  })
})
